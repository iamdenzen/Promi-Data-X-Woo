<?php

namespace PromiDataXWoo\Suppliers\A36;

use PromiDataXWoo\Catalog\Catalog;
use PromiDataXWoo\Suppliers\Contracts\SupplierAdapter;
use PromiDataXWoo\Suppliers\Logger;
use PromiDataXWoo\Suppliers\Support\HttpJson;

defined( 'ABSPATH' ) || exit;

/**
 * MidOcean (SKU prefix "A36-").
 *
 * https://api.midocean.com/gateway/stock/2.0 is a flat bulk dump,
 * refreshed hourly on their side: { "stock": [ { "sku": "...", "qty": N } ] }.
 *
 * The feed's own "sku" values do not include our "A36-" prefix — only our
 * system distinguishes suppliers by prefix — so it is prepended before
 * matching against existing WooCommerce SKUs.
 */
final class MidOceanAdapter implements SupplierAdapter {

	private const PREFIX = 'A36-';

	private Logger $logger;

	private HttpJson $http;

	public function __construct( Logger $logger, HttpJson $http ) {
		$this->logger = $logger;
		$this->http   = $http;
	}


	public function fetch( object $source, Catalog $catalog ): array|\WP_Error {

		$url = trim( (string) ( $source->endpoint_url ?? '' ) );

		if ( '' === $url ) {

			return new \WP_Error(
				'pdxw_supplier_missing_url',
				__( 'No endpoint URL is configured for this supplier.', 'promi-data-x-woo' )
			);
		}

		$headers = [];

		$credential = trim( (string) ( $source->credential ?? '' ) );

		if ( '' !== $credential ) {
			$headers['x-Gateway-APIKey'] = $credential;
		}

		$response = $this->http->get( $url, $headers );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$rows = $response['stock'] ?? null;

		if ( ! is_array( $rows ) ) {

			return new \WP_Error(
				'pdxw_supplier_invalid_response',
				__( 'Unexpected MidOcean stock response shape.', 'promi-data-x-woo' )
			);
		}

		$normalized = [];

		foreach ( $rows as $row ) {

			if ( ! is_array( $row ) ) {
				continue;
			}

			$suffix = trim( (string) ( $row['sku'] ?? '' ) );

			if ( '' === $suffix ) {
				continue;
			}

			$qty = is_numeric( $row['qty'] ?? null ) ? (int) $row['qty'] : null;

			$normalized[ self::PREFIX . $suffix ] = [
				'stock_quantity' => $qty,
				'in_stock'       => null === $qty ? null : $qty > 0,
				'delivery_days'  => null,
				'delivery_text'  => null,
				'purchase_price' => null,
			];
		}

		return $normalized;
	}
}
