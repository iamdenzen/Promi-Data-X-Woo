<?php

namespace PromiDataXWoo\Pricing;

use PromiDataXWoo\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Every price of a product, variation by variation, with the level that
 * produced each one (default / supplier / category / print option /
 * product / fixed).
 *
 * Variations are paged with offset + limit and the total is always
 * returned, so a client can read all of them.
 *
 * Used by REST, MCP and the admin Pricing Calculator.
 */
final class ProductPriceReport {

	public const MAX_LIMIT = 100;

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function build(
		int $product_id,
		int $offset = 0,
		int $limit = 25,
		bool $include_print = true
	) {

		$product_id = PriceLocks::parent_id( $product_id );
		$product    = $product_id ? wc_get_product( $product_id ) : null;
		$pricing    = Plugin::instance()->pricing();

		if ( ! $product || ! $pricing ) {
			return new \WP_Error( 'pdxw_bad_product', 'Unknown product.', [ 'status' => 404 ] );
		}

		$limit  = max( 1, min( self::MAX_LIMIT, $limit ) );
		$offset = max( 0, $offset );

		$all_ids = $product->is_type( 'variable' )
			? array_map( 'intval', $product->get_children() )
			: [ 0 ];

		$total = count( $all_ids );
		$slice = array_slice( $all_ids, $offset, $limit );

		$variations = [];

		foreach ( $slice as $variation_id ) {

			$variation = $variation_id ? wc_get_product( $variation_id ) : null;

			$row = [
				'variation_id' => $variation_id,
				'sku'          => $variation ? (string) $variation->get_sku() : (string) $product->get_sku(),
				'status'       => $variation ? $variation->get_status() : $product->get_status(),
				'attributes'   => $variation ? $variation->get_attributes() : [],
				'wc_price'     => $variation ? $variation->get_price() : $product->get_price(),
				'article'      => self::article( $pricing, $product_id, (int) $variation_id ),
			];

			if ( $include_print ) {
				$row['print'] = self::print_options( $pricing, $product_id, (int) $variation_id );
			}

			$variations[] = $row;
		}

		$article_rule = $pricing->markup_rules()->resolve_article( $product_id );

		return [
			'product_id'      => $product_id,
			'sku'             => (string) $product->get_sku(),
			'name'            => $product->get_name(),
			'locked'          => PriceLocks::is_locked( $product_id ),
			'article_markup'  => $article_rule,
			'total'           => $total,
			'offset'          => $offset,
			'limit'           => $limit,
			'returned'        => count( $variations ),
			'has_more'        => ( $offset + count( $slice ) ) < $total,
			'next_offset'     => ( $offset + count( $slice ) ) < $total ? $offset + count( $slice ) : null,
			'variations'      => $variations,
		];
	}


	/**
	 * @param mixed $value
	 */
	private static function nullable_float( $value ): ?float {

		return is_numeric( $value ) ? (float) $value : null;
	}


	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function article( Pricing $pricing, int $product_id, int $variation_id ): array {

		$repository = $pricing->repository();

		$quantities = FixedPrices::tier_quantities(
			FixedPrices::KIND_ARTICLE,
			$product_id,
			$variation_id,
			0
		);

		if ( empty( $quantities ) ) {

			$quantities = $repository->get_target_quantities( $product_id, $variation_id );

			if ( empty( $quantities ) && $variation_id ) {
				$quantities = $repository->get_target_quantities( $product_id, 0 );
			}
		}

		$tiers = [];

		foreach ( $quantities as $qty ) {

			$result = $pricing->costs()->calculate( $product_id, $variation_id, (int) $qty );

			$tiers[] = [
				'qty'            => (int) $qty,
				'price'          => $result['article_price'] ?? null,
				'status'         => $result['status'] ?? '',
				'purchase_price' => self::nullable_float( $result['tier']->purchase_price ?? null ),
					'cost'           => $result['cost'] ?? null,
				'cost_source'    => $result['source'] ?? null,
				'discount_percent' => $result['manufacturer_discount'] ?? 0,
				'markup_percent' => $result['article_markup'] ?? 0,
				'level'          => $result['markup_level'] ?? '',
				'level_label'    => $result['markup_label'] ?? '',
				'fixed'          => ! empty( $result['fixed'] ),
			];
		}

		return $tiers;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function print_options( Pricing $pricing, int $product_id, int $variation_id ): array {

		$printing = Plugin::instance()->printing();

		if ( ! $printing ) {
			return [];
		}

		$repository = $printing->repository();
		$calculator = $printing->calculator();

		$positions = $repository->get_positions( $product_id, $variation_id );

		if ( empty( $positions ) && $variation_id ) {
			$positions = $repository->get_positions( $product_id, 0 );
		}

		$result = [];
		$seen   = [];

		foreach ( $positions as $position ) {

			$options = $repository->get_options_by_position( $product_id, $variation_id, (int) $position->id );

			if ( empty( $options ) && $variation_id ) {
				$options = $repository->get_options_by_position( $product_id, 0, (int) $position->id );
			}

			foreach ( $options as $option ) {

				$option_id = (int) $option->id;

				if ( isset( $seen[ $option_id ] ) ) {
					$result[ $seen[ $option_id ] ]['positions'][] = (string) $position->position_label;
					continue;
				}

				$qtys = FixedPrices::tier_quantities(
					FixedPrices::KIND_PRINT_PRICE,
					$product_id,
					$variation_id,
					$option_id
				);

				if ( empty( $qtys ) ) {
					foreach ( $repository->get_selling_prices( $option_id ) as $tier ) {
						$qtys[] = max( 1, (int) $tier->min_qty );
					}
				}

				$prices = [];

				foreach ( $qtys as $qty ) {

					$unit = $calculator->selling_unit_price( $product_id, $variation_id, $option_id, $qty );

					$prices[] = [
						'qty'            => $qty,
						'purchase_price' => self::nullable_float(
								$repository->get_applicable_purchase_price( $option_id, $qty )
							),
							'price'          => $unit['price'],
						'markup_percent' => $unit['markup'],
						'level'          => $unit['level'],
						'level_label'    => $unit['label'],
						'fixed'          => 'fixed' === $unit['level'],
					];
				}

				$fees = [];

				foreach ( $repository->get_fees( $option_id ) as $fee ) {

					$fee_id = (int) ( $fee->id ?? 0 );

					$base =
						isset( $fee->purchase_amount ) && is_numeric( $fee->purchase_amount )
							? (float) $fee->purchase_amount
							: (float) ( $fee->amount ?? 0 );

					$kind = $calculator->fee_kind( (string) $fee->fee_type, (string) $fee->fee_label );

					$selling = $calculator->selling_fee(
						$kind,
						$base,
						$fee_id,
						$product_id,
						$variation_id,
						$option_id,
						$base
					);

					$fees[] = [
						'fee_id'             => $fee_id,
						'label'              => (string) $fee->fee_label,
						'type'               => $kind,
						'calculation'        => (string) ( $fee->calculation ?? '' ),
						'calculation_type'   => (string) ( $fee->calculation_type ?? '' ),
						'purchase_amount'    => $base,
						'unit_price'         => $base > 0 || 'fixed' === $selling['level'] ? $selling['amount'] : 0.0,
						'unit_price_rounded'   => $base > 0 || 'fixed' === $selling['level'] ? $selling['amount'] : 0.0,
						'unit_price_unrounded' => $base > 0 || 'fixed' === $selling['level'] ? $selling['amount_unrounded'] : 0.0,
						'markup_percent'     => $selling['markup'],
						'level'              => $selling['level'],
						'level_label'        => $selling['label'],
						'fixed'              => $selling['fixed'],
					];
				}

				$seen[ $option_id ] = count( $result );

				$result[] = [
					'option_id' => $option_id,
					'name'      => (string) $option->name,
					'positions' => [ (string) $position->position_label ],
					'prices'    => $prices,
					'fees'      => $fees,
				];
			}
		}

		return $result;
	}
}
