<?php

namespace PromiDataXWoo\Suppliers\A36;

use PromiDataXWoo\Catalog\Catalog;
use PromiDataXWoo\Suppliers\Contracts\PrintPriceProvider;
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
final class MidOceanAdapter implements SupplierAdapter, PrintPriceProvider {

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
	 * Highest colour count a NumberOfColours technique is expanded to.
	 * Promi currently exposes 1-4; the headroom is harmless.
	 */
	private const MAX_COLORS = 8;

	/**
	 * Fetch the print price list
	 * (e.g. https://api.midocean.com/gateway/printpricelist/2.0/).
	 *
	 * { print_manipulations: [ {code, price} ],
	 *   print_techniques: [ {id, pricing_type, setup, var_costs: [
	 *       {range_id, scales: [ {minimum_quantity, price, next_price} ]} ]} ] }
	 *
	 * "id" is the print code Promi sends as SupplierPrintCode. Numbers are
	 * strings with comma decimals and dot thousands ("1.000", "0,87").
	 * Verified against Promi A36 imprints: selling price = 1.5 x these.
	 *
	 *  - NumberOfColours: price + (colours - 1) * next_price, per colour count
	 *  - NumberOfPositions: one flat ladder
	 *  - AreaRange: one ladder per range_id (the SupplierSku suffix)
	 *
	 * ColourAreaRange / Area techniques are skipped on purpose (no verified
	 * Promi mapping yet), so those options keep their Promi pricing.
	 * print_manipulations become the per-piece handling purchase prices,
	 * matched to Promi's handling fee SKU (e.g. "B").
	 */
	public function fetch_print_prices( object $source ): array|\WP_Error {

		$url = trim( (string) ( $source->print_price_endpoint_url ?? '' ) );

		if ( '' === $url ) {

			return new \WP_Error(
				'pdxw_supplier_missing_url',
				__( 'No print price feed URL is configured for this supplier.', 'promi-data-x-woo' )
			);
		}

		$headers    = [];
		$credential = trim( (string) ( $source->credential ?? '' ) );

		if ( '' !== $credential ) {
			$headers['x-Gateway-APIKey'] = $credential;
		}

		$response = $this->http->get( $url, $headers );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$techniques = $response['print_techniques'] ?? null;

		if ( ! is_array( $techniques ) ) {

			return new \WP_Error(
				'pdxw_supplier_invalid_response',
				__( 'Unexpected MidOcean print price feed shape.', 'promi-data-x-woo' )
			);
		}

		$codes = [];

		foreach ( $techniques as $technique ) {

			if ( ! is_array( $technique ) ) {
				continue;
			}

			$id   = trim( (string) ( $technique['id'] ?? '' ) );
			$type = (string) ( $technique['pricing_type'] ?? '' );

			if ( '' === $id ) {
				continue;
			}

			$entry = $this->technique_entry( $technique, $type );

			if ( $entry ) {
				$codes[ $id ] = $entry;
			}
		}

		$handling = [];

		foreach ( (array) ( $response['print_manipulations'] ?? [] ) as $manipulation ) {

			$code  = trim( (string) ( $manipulation['code'] ?? '' ) );
			$price = $this->parse_number( $manipulation['price'] ?? '' );

			if ( '' !== $code && null !== $price && $price > 0 ) {
				$handling[ $code ] = $price;
			}
		}

		return [
			'codes'    => $codes,
			'handling' => $handling,
		];
	}


	/**
	 * @return array{variant_by:string,variants:array<string,array{setup:?float,prices:array<int,float>}>}|null
	 */
	private function technique_entry( array $technique, string $type ): ?array {

		$setup     = $this->parse_number( $technique['setup'] ?? '' );
		$var_costs = array_values( array_filter( (array) ( $technique['var_costs'] ?? [] ), 'is_array' ) );

		if ( empty( $var_costs ) ) {
			return null;
		}

		$variants = [];

		switch ( $type ) {

			case 'NumberOfColours':

				$variant_by = 'colors';

				for ( $colors = 1; $colors <= self::MAX_COLORS; $colors++ ) {

					$prices = $this->ladder( $var_costs[0], $colors - 1 );

					if ( $prices ) {

						$variants[ (string) $colors ] = [
							'setup'  => $setup,
							'prices' => $prices,
						];
					}
				}

				break;

			case 'NumberOfPositions':

				$variant_by = 'none';
				$prices     = $this->ladder( $var_costs[0], 0 );

				if ( $prices ) {

					$variants['0'] = [
						'setup'  => $setup,
						'prices' => $prices,
					];
				}

				break;

			case 'AreaRange':

				$variant_by = 'range';

				foreach ( $var_costs as $var_cost ) {

					$range  = trim( (string) ( $var_cost['range_id'] ?? '' ) );
					$prices = $this->ladder( $var_cost, 0 );

					if ( '' !== $range && $prices ) {

						$variants[ $range ] = [
							'setup'  => $setup,
							'prices' => $prices,
						];
					}
				}

				break;

			default:
				return null;
		}

		return empty( $variants )
			? null
			: [
				'variant_by' => $variant_by,
				'variants'   => $variants,
			];
	}


	/**
	 * One quantity-break ladder from a var_costs entry:
	 * price + $extra_colours * next_price at every scale.
	 *
	 * Placeholder prices below 0.01 (the feed uses "0,001" for unused
	 * techniques) are dropped.
	 *
	 * @return array<int,float>
	 */
	private function ladder( array $var_cost, int $extra_colors ): array {

		$ladder = [];

		foreach ( (array) ( $var_cost['scales'] ?? [] ) as $scale ) {

			$qty   = $this->parse_number( $scale['minimum_quantity'] ?? '' );
			$price = $this->parse_number( $scale['price'] ?? '' );
			$next  = $this->parse_number( $scale['next_price'] ?? '' ) ?? 0.0;

			if ( null === $qty || $qty < 1 || null === $price ) {
				continue;
			}

			$total = $price + $extra_colors * $next;

			if ( $total >= 0.01 ) {
				$ladder[ (int) $qty ] = round( $total, 4 );
			}
		}

		ksort( $ladder );

		return $ladder;
	}


	/**
	 * "1.000" -> 1000.0, "0,87" -> 0.87, "" -> null.
	 */
	private function parse_number( mixed $value ): ?float {

		$value = trim( (string) $value );

		if ( '' === $value ) {
			return null;
		}

		$value = str_replace( ',', '.', str_replace( '.', '', $value ) );

		return is_numeric( $value ) ? (float) $value : null;
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
