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
 *
 * MidOcean also publishes a *separate* pricelist feed (optional
 * $source->price_endpoint_url, e.g.
 * https://api.midocean.com/gateway/pricelist/2.0/), same flat-bulk-dump
 * shape but no quantity tiers at all — one flat price per SKU:
 * { currency, date, price: [ { sku, variant_id, price, valid_until } ] }.
 * "sku" uses the same unprefixed identifier as the stock feed. "price" is
 * a string with a comma decimal separator (e.g. "3,80"), not directly
 * castable. "valid_until" rows in the past are skipped. Since there are no
 * quantity breaks, the flat price is represented as a single-entry
 * "min_qty => price" ladder ([1 => price]) so it flows through the same
 * generic per-tier purchase-price pipeline PFConcept/Giving Europe use. If
 * this second feed fails to fetch, the sync continues with stock-only
 * data — price is a lower-priority signal than stock and shouldn't block
 * it.
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

		$this->merge_price_feed( $source, $headers, $normalized );

		return $normalized;
	}


	/**
	 * Fetch and merge the optional separate pricelist feed. Failures here
	 * are logged and swallowed — price is secondary to stock and shouldn't
	 * fail the whole sync.
	 *
	 * @param array<string,string> $headers
	 * @param array<string,array>  $normalized
	 */
	private function merge_price_feed( object $source, array $headers, array &$normalized ): void {

		$price_url = trim( (string) ( $source->price_endpoint_url ?? '' ) );

		if ( '' === $price_url ) {
			return;
		}

		$response = $this->http->get( $price_url, $headers );

		if ( is_wp_error( $response ) ) {

			$this->logger->warning(
				'MidOcean pricelist feed request failed; continuing with stock-only data.',
				[ 'error' => $response->get_error_message() ]
			);

			return;
		}

		$rows = $response['price'] ?? null;

		if ( ! is_array( $rows ) ) {

			$this->logger->warning( 'Unexpected MidOcean pricelist feed shape; continuing with stock-only data.' );

			return;
		}

		$today = strtotime( 'today' );

		foreach ( $rows as $row ) {

			if ( is_array( $row ) ) {
				$this->apply_price_row( $row, $today, $normalized );
			}
		}
	}


	/**
	 * @param array<string,array> $normalized
	 */
	private function apply_price_row( array $row, int $today, array &$normalized ): void {

		$suffix = trim( (string) ( $row['sku'] ?? '' ) );

		if ( '' === $suffix ) {
			return;
		}

		$valid_until = trim( (string) ( $row['valid_until'] ?? '' ) );

		if ( '' !== $valid_until ) {

			$valid_until_ts = strtotime( $valid_until );

			if ( false !== $valid_until_ts && $valid_until_ts < $today ) {
				return;
			}
		}

		$raw_price = $row['price'] ?? null;

		if ( ! is_string( $raw_price ) || '' === trim( $raw_price ) ) {
			return;
		}

		$price = (float) str_replace( ',', '.', str_replace( '.', '', $raw_price ) );

		if ( $price <= 0 ) {
			return;
		}

		$sku    = self::PREFIX . $suffix;
		$ladder = [ 1 => $price ];

		if ( isset( $normalized[ $sku ] ) ) {

			$normalized[ $sku ]['purchase_price'] = $ladder;

		} else {

			$normalized[ $sku ] = [
				'stock_quantity' => null,
				'in_stock'       => null,
				'delivery_days'  => null,
				'delivery_text'  => null,
				'purchase_price' => $ladder,
			];
		}
	}
}
