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
 */
final class GivingEuropeAdapter implements SupplierAdapter {

	private const PREFIX = 'A58-';

	/**
	 * Defensive limit on product_codes sent per request, to keep the
	 * query string a reasonable length. Not a documented API constraint.
	 */
	private const PRODUCT_CODES_CHUNK_SIZE = 200;

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

		foreach ( array_chunk( $product_codes, self::PRODUCT_CODES_CHUNK_SIZE ) as $chunk ) {

			$result = $this->fetch_chunk( $url, $headers, $chunk, $existing, $normalized );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return $normalized;
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
	 * Fetch (and page through) stock for one chunk of product_codes,
	 * writing matches directly into $normalized.
	 *
	 * @param array<int,string>  $product_codes
	 * @param array<string,int>  $existing
	 * @param array<string,array> $normalized
	 */
	private function fetch_chunk(
		string $url,
		array $headers,
		array $product_codes,
		array $existing,
		array &$normalized
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
					$this->apply_item( $item, $existing, $normalized );
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
