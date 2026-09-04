<?php

namespace PromiDataXWoo\Suppliers\A34;

use PromiDataXWoo\Catalog\Catalog;
use PromiDataXWoo\Suppliers\Contracts\SupplierAdapter;
use PromiDataXWoo\Suppliers\Logger;
use PromiDataXWoo\Suppliers\Support\HttpJson;

defined( 'ABSPATH' ) || exit;

/**
 * PFConcept (SKU prefix "A34-").
 *
 * A public, unauthenticated, full-catalog JSON dump (no filtering, updated
 * twice daily) shaped as a nested tree rather than a flat list of rows:
 *
 *     PFCStockFeed.stockFeed[].models[].model[].items[].item[]
 *
 * "model" carries modelCode (our parent SKU minus prefix); each "item"
 * under it carries itemCode (our child/variation SKU minus prefix) plus
 * its own stock fields. Only itemCode is needed to match — modelCode is
 * not required for matching since itemCode alone is globally unique and
 * maps directly to one WooCommerce SKU once prefixed.
 */
final class PfConceptAdapter implements SupplierAdapter {

	private const PREFIX = 'A34-';

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

		$response = $this->http->get( $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$feed_entries = $response['PFCStockFeed']['stockFeed'] ?? null;

		if ( ! is_array( $feed_entries ) ) {

			return new \WP_Error(
				'pdxw_supplier_invalid_response',
				__( 'Unexpected PFConcept feed shape.', 'promi-data-x-woo' )
			);
		}

		$normalized = [];

		foreach ( $feed_entries as $feed_entry ) {
			$this->walk_models( is_array( $feed_entry ) ? ( $feed_entry['models'] ?? [] ) : [], $normalized );
		}

		return $normalized;
	}


	/**
	 * @param array<string,array> $normalized
	 */
	private function walk_models( mixed $model_groups, array &$normalized ): void {

		if ( ! is_array( $model_groups ) ) {
			return;
		}

		foreach ( $model_groups as $model_group ) {

			$models = is_array( $model_group ) ? ( $model_group['model'] ?? [] ) : [];

			if ( ! is_array( $models ) ) {
				continue;
			}

			foreach ( $models as $model ) {
				$this->walk_items( is_array( $model ) ? ( $model['items'] ?? [] ) : [], $normalized );
			}
		}
	}


	/**
	 * @param array<string,array> $normalized
	 */
	private function walk_items( mixed $item_groups, array &$normalized ): void {

		if ( ! is_array( $item_groups ) ) {
			return;
		}

		foreach ( $item_groups as $item_group ) {

			$items = is_array( $item_group ) ? ( $item_group['item'] ?? [] ) : [];

			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {

				if ( is_array( $item ) ) {
					$this->apply_item( $item, $normalized );
				}
			}
		}
	}


	/**
	 * @param array<string,array> $normalized
	 */
	private function apply_item( array $item, array &$normalized ): void {

		$item_code = trim( (string) ( $item['itemCode'] ?? '' ) );

		if ( '' === $item_code ) {
			return;
		}

		$stock = is_numeric( $item['stockDirect'] ?? null ) ? (int) $item['stockDirect'] : null;

		$normalized[ self::PREFIX . $item_code ] = [
			'stock_quantity' => $stock,
			'in_stock'       => null === $stock ? null : $stock > 0,
			'delivery_days'  => null,
			'delivery_text'  => null,
			'purchase_price' => null,
		];
	}
}
