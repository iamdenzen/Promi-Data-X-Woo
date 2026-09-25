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
 *
 * PFConcept also publishes a *separate* price feed (optional
 * $source->price_endpoint_url), shaped similarly but one level deeper:
 *
 *     PFCPriceFeed.priceInfo[].models[].model[].items[].item[].scales[].scale[]
 *
 * Each item's "scale" entries are quantity-break prices: {priceBar,
 * nettPrice}, where priceBar is the minimum order quantity that price
 * applies from. The item identifier is lower-cased "itemcode" here, unlike
 * "itemCode" in the stock feed. If this second feed fails to fetch, the
 * sync continues with stock-only data — price is a lower-priority signal
 * than stock and shouldn't block it.
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

		$this->merge_price_feed( $source, $normalized );

		return $normalized;
	}


	/**
	 * Fetch and merge the optional separate price feed. Failures here are
	 * logged and swallowed — price is secondary to stock and shouldn't
	 * fail the whole sync.
	 *
	 * @param array<string,array> $normalized
	 */
	private function merge_price_feed( object $source, array &$normalized ): void {

		$price_url = trim( (string) ( $source->price_endpoint_url ?? '' ) );

		if ( '' === $price_url ) {
			return;
		}

		$response = $this->http->get( $price_url );

		if ( is_wp_error( $response ) ) {

			$this->logger->warning(
				'PFConcept price feed request failed; continuing with stock-only data.',
				[ 'error' => $response->get_error_message() ]
			);

			return;
		}

		$price_infos = $response['PFCPriceFeed']['priceInfo'] ?? null;

		if ( ! is_array( $price_infos ) ) {

			$this->logger->warning( 'Unexpected PFConcept price feed shape; continuing with stock-only data.' );

			return;
		}

		$ladders = [];

		foreach ( $price_infos as $price_info ) {
			$this->walk_price_models( is_array( $price_info ) ? ( $price_info['models'] ?? [] ) : [], $ladders );
		}

		foreach ( $ladders as $item_code => $ladder ) {

			$sku = self::PREFIX . $item_code;

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


	/**
	 * @param array<string,array<int,float>> $ladders
	 */
	private function walk_price_models( mixed $model_groups, array &$ladders ): void {

		if ( ! is_array( $model_groups ) ) {
			return;
		}

		foreach ( $model_groups as $model_group ) {

			$models = is_array( $model_group ) ? ( $model_group['model'] ?? [] ) : [];

			if ( ! is_array( $models ) ) {
				continue;
			}

			foreach ( $models as $model ) {
				$this->walk_price_items( is_array( $model ) ? ( $model['items'] ?? [] ) : [], $ladders );
			}
		}
	}


	/**
	 * @param array<string,array<int,float>> $ladders
	 */
	private function walk_price_items( mixed $item_groups, array &$ladders ): void {

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
					$this->apply_price_item( $item, $ladders );
				}
			}
		}
	}


	/**
	 * @param array<string,array<int,float>> $ladders
	 */
	private function apply_price_item( array $item, array &$ladders ): void {

		$item_code = trim( (string) ( $item['itemcode'] ?? '' ) );

		if ( '' === $item_code ) {
			return;
		}

		$scale_groups = $item['scales'] ?? [];

		if ( ! is_array( $scale_groups ) ) {
			return;
		}

		$ladder = [];

		foreach ( $scale_groups as $scale_group ) {

			$scales = is_array( $scale_group ) ? ( $scale_group['scale'] ?? [] ) : [];

			if ( ! is_array( $scales ) ) {
				continue;
			}

			foreach ( $scales as $scale ) {

				if ( ! is_array( $scale ) ) {
					continue;
				}

				$price_bar  = $scale['priceBar'] ?? null;
				$nett_price = $scale['nettPrice'] ?? null;

				if ( ! is_numeric( $price_bar ) || ! is_numeric( $nett_price ) ) {
					continue;
				}

				$ladder[ (int) $price_bar ] = (float) $nett_price;
			}
		}

		if ( ! empty( $ladder ) ) {
			$ladders[ $item_code ] = $ladder;
		}
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
