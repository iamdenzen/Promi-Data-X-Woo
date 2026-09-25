<?php

namespace PromiDataXWoo\Suppliers\A58;

use PromiDataXWoo\Catalog\Catalog;
use PromiDataXWoo\Suppliers\Contracts\SupplierAdapter;
use PromiDataXWoo\Suppliers\Logger;
use PromiDataXWoo\Suppliers\Support\HttpJson;

defined( 'ABSPATH' ) || exit;

/**
 * Giving Europe (SKU prefix "A58-").
 *
 * Unlike the other two suppliers, this API does not hand back a feed to
 * filter — it is a query endpoint: GET with product_codes[] (array),
 * offset and limit (max 1000, default 100) as query parameters, returning
 * stock rows for those specific products.
 *
 * SKU shape:
 *
 *     Our SKU:            A58-{product_code}-{variant_code}
 *     product_code alone: A58-{product_code}          (parent)
 *
 * The API only knows product_code/variant_code, never our full SKU, so
 * this adapter first has to work out which product_codes are even worth
 * asking about (Catalog::skus_by_prefix()), then reconstruct the full SKU
 * from each returned row to match it back.
 *
 * The result set itself is paginated (offset/limit/total) independently
 * of how many product_codes are sent — one product_code can have several
 * variant_code rows — so this always requests the documented max page
 * size and still loops on offset/total. product_codes are additionally
 * chunked per request as a defensive measure against very long query
 * strings; that chunk size is not documented and should be revisited if
 * it turns out to matter for a large catalog.
 *
 * Giving Europe also publishes a *separate* pricing endpoint (optional
 * $source->price_endpoint_url, e.g. ".../v1/products"), using the same
 * product_codes/offset/limit query parameters but returning a much
 * heavier, deeply nested payload (full product data — descriptions,
 * images, dimensions, certifications, packaging — not just price), shaped
 * as items[] (one per product) -> variants[] (one per SKU) ->
 * price_tiers[] ({min_units, max_units, unit_net_price}). Fetching this
 * unfiltered is what returns a >15MB response, so it's fetched with a
 * *smaller* product_codes chunk size than the stock feed's, using the
 * same "only ask about product_codes we already carry" strategy. The
 * min/max ranges are contiguous and exhaustive, so they translate
 * directly into the same "min_qty => price" ladder shape PFConcept's
 * price feed uses. If this second feed fails to fetch, the sync continues
 * with stock-only data — price is a lower-priority signal than stock and
 * shouldn't block it.
 */
final class GivingEuropeAdapter implements SupplierAdapter {

	private const PREFIX = 'A58-';

	/**
	 * Defensive limit on product_codes sent per request, to keep the
	 * query string a reasonable length. Not a documented API constraint.
	 */
	private const PRODUCT_CODES_CHUNK_SIZE = 200;

	/**
	 * Smaller chunk size for the price feed: each product's payload there
	 * is far heavier (full product data, not just a quantity), so fewer
	 * product_codes are requested per call to keep response size
	 * reasonable. Starting point only — tune against real sandbox response
	 * sizes.
	 */
	private const PRICE_PRODUCT_CODES_CHUNK_SIZE = 50;

	/**
	 * Documented maximum for the "limit" query parameter.
	 */
	private const RESULT_PAGE_LIMIT = 1000;

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

		$existing = $catalog->products()->skus_by_prefix( self::PREFIX );

		$product_codes = $this->product_codes( $existing );

		if ( empty( $product_codes ) ) {
			return [];
		}

		$headers = [];

		$credential = trim( (string) ( $source->credential ?? '' ) );

		if ( '' !== $credential ) {
			$headers['Authorization'] = 'Bearer ' . $credential;
		}

		$normalized = [];

		$stock_result = $this->fetch_paginated(
			$url,
			$headers,
			$product_codes,
			self::PRODUCT_CODES_CHUNK_SIZE,
			function ( array $item ) use ( $existing, &$normalized ) {
				$this->apply_item( $item, $existing, $normalized );
			}
		);

		if ( is_wp_error( $stock_result ) ) {
			return $stock_result;
		}

		$this->merge_price_feed( $source, $headers, $product_codes, $existing, $normalized );

		return $normalized;
	}


	/**
	 * Fetch and merge the optional separate price feed. Failures here are
	 * logged and swallowed — price is secondary to stock and shouldn't
	 * fail the whole sync.
	 *
	 * @param array<int,string>   $product_codes
	 * @param array<string,int>   $existing
	 * @param array<string,array> $normalized
	 */
	private function merge_price_feed(
		object $source,
		array $headers,
		array $product_codes,
		array $existing,
		array &$normalized
	): void {

		$price_url = trim( (string) ( $source->price_endpoint_url ?? '' ) );

		if ( '' === $price_url ) {
			return;
		}

		$result = $this->fetch_paginated(
			$price_url,
			$headers,
			$product_codes,
			self::PRICE_PRODUCT_CODES_CHUNK_SIZE,
			function ( array $item ) use ( $existing, &$normalized ) {
				$this->apply_price_item( $item, $existing, $normalized );
			}
		);

		if ( is_wp_error( $result ) ) {

			$this->logger->warning(
				'Giving Europe price feed request failed; continuing with stock-only data.',
				[ 'error' => $result->get_error_message() ]
			);
		}
	}


	/**
	 * Extract distinct product_codes from every existing "A58-" SKU we
	 * already have (parent "A58-{code}" and child "A58-{code}-{variant}"
	 * both start with the same code).
	 *
	 * @param array<string,int> $existing
	 * @return array<int,string>
	 */
	private function product_codes( array $existing ): array {

		$codes = [];

		foreach ( array_keys( $existing ) as $sku ) {

			$rest = substr( $sku, strlen( self::PREFIX ) );
			$code = strtok( $rest, '-' );

			if ( is_string( $code ) && '' !== $code ) {
				$codes[ $code ] = true;
			}
		}

		return array_keys( $codes );
	}


	/**
	 * Chunk product_codes and page through offset/limit/total for one
	 * endpoint, calling $on_item for every returned item. Shared by the
	 * stock and price feeds — they differ only in URL, chunk size, and
	 * what to do with each item.
	 *
	 * @param array<int,string> $product_codes
	 */
	private function fetch_paginated(
		string $url,
		array $headers,
		array $product_codes,
		int $chunk_size,
		callable $on_item
	): true|\WP_Error {

		foreach ( array_chunk( $product_codes, $chunk_size ) as $chunk ) {

			$result = $this->fetch_chunk( $url, $headers, $chunk, $on_item );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}


	/**
	 * Fetch (and page through) one chunk of product_codes against one
	 * endpoint, calling $on_item for every returned item.
	 *
	 * @param array<int,string> $product_codes
	 */
	private function fetch_chunk(
		string $url,
		array $headers,
		array $product_codes,
		callable $on_item
	): true|\WP_Error {

		$offset = 0;
		$total  = null;

		do {

			$request_url = add_query_arg(
				[
					'product_codes' => $product_codes,
					'offset'        => $offset,
					'limit'         => self::RESULT_PAGE_LIMIT,
				],
				$url
			);

			$response = $this->http->get( $request_url, $headers );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$items = $response['items'] ?? [];

			if ( ! is_array( $items ) ) {
				break;
			}

			foreach ( $items as $item ) {

				if ( is_array( $item ) ) {
					$on_item( $item );
				}
			}

			$total   = is_numeric( $response['total'] ?? null ) ? (int) $response['total'] : count( $items );
			$offset += count( $items );

		} while ( ! empty( $items ) && $offset < $total );

		return true;
	}


	/**
	 * @param array<string,int>   $existing
	 * @param array<string,array> $normalized
	 */
	private function apply_item( array $item, array $existing, array &$normalized ): void {

		$product_code = trim( (string) ( $item['product_code'] ?? '' ) );
		$variant_code = trim( (string) ( $item['variant_code'] ?? '' ) );

		if ( '' === $product_code || '' === $variant_code ) {
			return;
		}

		$sku = self::PREFIX . $product_code . '-' . $variant_code;

		if ( ! isset( $existing[ $sku ] ) ) {
			return;
		}

		$available = is_numeric( $item['available_quantity'] ?? null )
			? (int) $item['available_quantity']
			: null;

		[ $delivery_days, $delivery_text ] = $this->earliest_delivery( $item['expected_deliveries'] ?? [] );

		$normalized[ $sku ] = [
			'stock_quantity' => $available,
			'in_stock'       => null === $available ? null : $available > 0,
			'delivery_days'  => $delivery_days,
			'delivery_text'  => $delivery_text,
			'purchase_price' => null,
		];
	}


	/**
	 * One price-feed "item" is a product with its own variants[], unlike
	 * the flat product_code/variant_code rows the stock feed returns.
	 *
	 * @param array<string,int>   $existing
	 * @param array<string,array> $normalized
	 */
	private function apply_price_item( array $item, array $existing, array &$normalized ): void {

		$product_code = trim( (string) ( $item['code'] ?? '' ) );

		if ( '' === $product_code ) {
			return;
		}

		$variants = $item['variants'] ?? [];

		if ( ! is_array( $variants ) ) {
			return;
		}

		foreach ( $variants as $variant ) {

			if ( is_array( $variant ) ) {
				$this->apply_price_variant( $product_code, $variant, $existing, $normalized );
			}
		}
	}


	/**
	 * @param array<string,int>   $existing
	 * @param array<string,array> $normalized
	 */
	private function apply_price_variant(
		string $product_code,
		array $variant,
		array $existing,
		array &$normalized
	): void {

		$variant_code = trim( (string) ( $variant['code'] ?? '' ) );

		if ( '' === $variant_code ) {
			return;
		}

		$sku = self::PREFIX . $product_code . '-' . $variant_code;

		if ( ! isset( $existing[ $sku ] ) ) {
			return;
		}

		$tiers = $variant['price_tiers'] ?? [];

		if ( ! is_array( $tiers ) ) {
			return;
		}

		$ladder = [];

		foreach ( $tiers as $tier ) {

			if ( ! is_array( $tier ) ) {
				continue;
			}

			$min_units = $tier['min_units'] ?? null;
			$price     = $tier['unit_net_price'] ?? null;

			if ( ! is_numeric( $min_units ) || ! is_numeric( $price ) ) {
				continue;
			}

			$ladder[ (int) $min_units ] = (float) $price;
		}

		if ( empty( $ladder ) ) {
			return;
		}

		ksort( $ladder );

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


	/**
	 * Reduce expected_deliveries[] to the single earliest date.
	 *
	 * @return array{0:?int,1:?string}
	 */
	private function earliest_delivery( mixed $deliveries ): array {

		if ( ! is_array( $deliveries ) ) {
			return [ null, null ];
		}

		$dates = [];

		foreach ( $deliveries as $delivery ) {

			$date = is_array( $delivery ) ? ( $delivery['expected_at'] ?? null ) : null;

			if ( is_string( $date ) && '' !== $date ) {
				$dates[] = $date;
			}
		}

		if ( empty( $dates ) ) {
			return [ null, null ];
		}

		sort( $dates );

		$earliest  = $dates[0];
		$timestamp = strtotime( $earliest );

		if ( false === $timestamp ) {
			return [ null, $earliest ];
		}

		$days = (int) ceil( ( $timestamp - time() ) / DAY_IN_SECONDS );

		return [ max( 0, $days ), $earliest ];
	}
}
