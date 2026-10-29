<?php

namespace PromiDataXWoo\Pricing;

use PromiDataXWoo\Core\Database;
use PromiDataXWoo\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Preview and apply pricing changes. Nothing changes until apply().
 *
 * One change is an associative array:
 *
 *   markup       { type, part, level, target?, percent|null }
 *                percent null removes the rule (not allowed for "default").
 *   fixed_price  { type, product_id, variation_id?, kind, ref_id?, qty?, price|null }
 *                price null removes the fixed price (qty omitted removes the
 *                whole ladder of that kind / ref).
 *   lock         { type, product_id, locked: bool }
 *   fixed_fee_rule { type, level, target?, fee_kind, price|null }
 *                level option | supplier | supplier_option; target is the
 *                option id, the SKU prefix or "A34:12". price null removes it.
 *                Print fees are calculated live, so nothing is repriced.
 *
 * preview() validates, measures the effect without writing anything and
 * returns a token. apply( token ) executes exactly what was previewed,
 * writes one history entry and schedules the reprice of affected products.
 *
 * Shared by the admin UI, REST and MCP.
 */
final class ChangeSet {

	private const TRANSIENT_PREFIX = 'pdxw_changeset_';
	private const TTL              = HOUR_IN_SECONDS;
	private const CANDIDATE_CAP    = 50000;
	private const SAMPLE_LIMIT     = 8;


	/*
	|--------------------------------------------------------------------------
	| Preview
	|--------------------------------------------------------------------------
	*/

	/**
	 * @param array<int,array<string,mixed>> $changes
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function preview( array $changes ) {

		$pricing = Plugin::instance()->pricing();

		if ( ! $pricing ) {
			return new \WP_Error( 'pdxw_unavailable', 'Pricing module is not initialised.', [ 'status' => 500 ] );
		}

		if ( empty( $changes ) ) {
			return new \WP_Error( 'pdxw_no_changes', 'No changes supplied.', [ 'status' => 400 ] );
		}

		if ( count( $changes ) > 500 ) {
			return new \WP_Error( 'pdxw_too_many', 'At most 500 changes per request.', [ 'status' => 400 ] );
		}

		$normalized = [];

		foreach ( array_values( $changes ) as $index => $change ) {

			$result = self::normalize( is_array( $change ) ? $change : [] );

			if ( is_wp_error( $result ) ) {
				return new \WP_Error(
					$result->get_error_code(),
					sprintf( 'Change #%d: %s', $index + 1, $result->get_error_message() ),
					[ 'status' => 400 ]
				);
			}

			$normalized[] = $result;
		}

		$locked = PriceLocks::ids();

		$report         = [];
		$markup_changes = array_values(
			array_filter( $normalized, static fn( $c ) => 'markup' === $c['type'] )
		);
		$union          = [];

		foreach ( $normalized as $index => $change ) {

			$entry = [
				'index'  => $index,
				'change' => $change,
			];

			if ( 'markup' === $change['type'] ) {
				$entry += self::preview_markup( $pricing, $change, $locked, $union );
			} elseif ( 'fixed_price' === $change['type'] ) {
				$entry += self::preview_fixed( $pricing, $change, $locked );
				$union[ $change['product_id'] ] = true;
			} elseif ( 'fixed_fee_rule' === $change['type'] ) {
				$entry += self::preview_fee_rule( $pricing, $change, $locked, $union );
			} else {
				$entry += [
					'affected_count' => 1,
					'locked_skipped' => 0,
					'samples'        => [],
					'description'    => $change['locked']
						? 'Lock product (freezes its current prices)'
						: 'Unlock product (prices follow the rules again)',
				];
				$union[ $change['product_id'] ] = true;
			}

			$report[] = $entry;
		}

		$token = wp_generate_password( 24, false );

		set_transient(
			self::TRANSIENT_PREFIX . $token,
			[
				'changes'        => $normalized,
				'affected_total' => count( $union ),
				'created_by'     => History::actor(),
			],
			self::TTL
		);

		return [
			'token'          => $token,
			'expires_in'     => self::TTL,
			'affected_total' => count( $union ),
			'changes'        => $report,
			'note'           => 'Nothing has been changed yet. Call apply with this token to save.',
		];
	}


	/**
	 * Preview, then optionally apply in the same call.
	 *
	 * With $apply false this is just preview() (nothing is saved).
	 *
	 * @param array<int,array<string,mixed>> $changes
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function run( array $changes, bool $apply ) {

		$preview = self::preview( $changes );

		if ( is_wp_error( $preview ) || ! $apply ) {
			return $preview;
		}

		$applied = self::apply( $preview['token'] );

		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		unset( $preview['token'], $preview['expires_in'], $preview['note'] );

		return [
			'applied' => true,
			'result'  => $applied,
			'preview' => $preview,
		];
	}


	/*
	|--------------------------------------------------------------------------
	| Apply
	|--------------------------------------------------------------------------
	*/

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function apply( string $token ) {

		$token = preg_replace( '/[^A-Za-z0-9]/', '', $token );

		$stored = '' !== $token
			? get_transient( self::TRANSIENT_PREFIX . $token )
			: false;

		if ( ! is_array( $stored ) || empty( $stored['changes'] ) ) {
			return new \WP_Error(
				'pdxw_invalid_token',
				'Unknown or expired preview token. Run the preview again.',
				[ 'status' => 404 ]
			);
		}

		$pricing = Plugin::instance()->pricing();

		if ( ! $pricing ) {
			return new \WP_Error( 'pdxw_unavailable', 'Pricing module is not initialised.', [ 'status' => 500 ] );
		}

		delete_transient( self::TRANSIENT_PREFIX . $token );

		// One history entry is written for the whole set; keep the
		// repositories from logging every rule on its own.
		History::suppress( true );

		try {
			return self::apply_changes( $pricing, $stored );
		} finally {
			History::suppress( false );
		}
	}

	/**
	 * @param array<string,mixed> $stored
	 * @return array<string,mixed>
	 */
	private static function apply_changes( Pricing $pricing, array $stored ): array {

		$applied        = [];
		$reprice_all    = false;
		$reprice_ids    = [];
		$reprice_terms  = [];
		$reprice_prefix = [];
		$summary        = [];

		foreach ( $stored['changes'] as $change ) {

			if ( 'markup' === $change['type'] ) {

				$old = self::current_markup( $pricing, $change );

				self::write_markup( $pricing, $change );

				$applied[] = $change + [ 'old_percent' => $old ];

				$summary[] = self::describe_markup( $change, $old );

				// Only the article part is stored in WooCommerce; print
				// prices are always calculated live.
				if ( ScopedRuleRepository::PART_ARTICLE === $change['part'] ) {

					switch ( $change['level'] ) {
						case MarkupRules::LEVEL_DEFAULT:
							$reprice_all = true;
							break;
						case MarkupRules::LEVEL_SUPPLIER:
							$reprice_prefix[] = $change['target'];
							break;
						case MarkupRules::LEVEL_CATEGORY:
							$reprice_terms[] = (int) $change['target'];
							break;
						case MarkupRules::LEVEL_PRODUCT:
							$reprice_ids[] = (int) $change['target'];
							break;
						case MarkupRules::LEVEL_SUPPLIER_CATEGORY:
							// Products matching both the prefix and the category.
							$reprice_ids = array_merge(
								$reprice_ids,
								self::candidates( $pricing, $change )[0]
							);
							break;
					}
				}

			} elseif ( 'fixed_price' === $change['type'] ) {

				self::write_fixed( $change );

				$applied[]     = $change;
				$summary[]     = self::describe_fixed( $change );
				$reprice_ids[] = (int) $change['product_id'];

			} elseif ( 'fixed_fee_rule' === $change['type'] ) {

				$old = ScopedFixedFees::get( $change['level'], $change['target'], $change['fee_kind'] );

				if ( null === $change['price'] ) {
					ScopedFixedFees::delete( $change['level'], $change['target'], $change['fee_kind'] );
				} else {
					ScopedFixedFees::save( $change['level'], $change['target'], $change['fee_kind'], (float) $change['price'], History::actor() );
				}

				$applied[] = $change + [ 'old_price' => $old ];
				$summary[] = self::describe_fee_rule( $change, $old );

			} else {

				if ( $change['locked'] ) {
					LockManager::lock( (int) $change['product_id'] );
				} else {
					LockManager::unlock( (int) $change['product_id'] );
				}

				$applied[] = $change;
				$summary[] = sprintf(
					'%s product #%d',
					$change['locked'] ? 'Locked' : 'Unlocked',
					$change['product_id']
				);
			}
		}

		$price_sync = $pricing->price_sync();
		$scheduled  = 0;

		if ( $reprice_all ) {
			$scheduled += $price_sync->reprice_all();
		} else {

			foreach ( array_unique( $reprice_prefix ) as $prefix ) {
				$scheduled += $price_sync->reprice_sku_prefix( $prefix );
			}

			if ( $reprice_terms ) {
				$scheduled += $price_sync->reprice_categories( array_unique( $reprice_terms ) );
			}

			if ( $reprice_ids ) {
				$scheduled += $price_sync->schedule( array_unique( $reprice_ids ) );
			}
		}

		$summary_text = implode( '; ', array_slice( $summary, 0, 5 ) );

		if ( count( $summary ) > 5 ) {
			$summary_text .= sprintf( ' (+%d more)', count( $summary ) - 5 );
		}

		$history_id = History::record(
			'apply',
			$summary_text,
			(int) $stored['affected_total'],
			[
				'changes'   => $applied,
				'reprice'   => $scheduled,
				'preview_by' => $stored['created_by'] ?? '',
			]
		);

		return [
			'applied'        => count( $applied ),
			'affected_total' => (int) $stored['affected_total'],
			'repricing'      => $scheduled,
			'history_id'     => $history_id,
		];
	}


	/*
	|--------------------------------------------------------------------------
	| Validation
	|--------------------------------------------------------------------------
	*/

	/**
	 * @param array<string,mixed> $change
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function normalize( array $change ) {

		$type = (string) ( $change['type'] ?? 'markup' );

		if ( 'markup' === $type ) {
			return self::normalize_markup( $change );
		}

		if ( 'fixed_price' === $type ) {
			return self::normalize_fixed( $change );
		}

		if ( 'fixed_fee_rule' === $type ) {
			return self::normalize_fee_rule( $change );
		}

		if ( 'lock' === $type ) {

			$product_id = PriceLocks::parent_id( (int) ( $change['product_id'] ?? 0 ) );

			if ( ! $product_id || 'product' !== get_post_type( $product_id ) ) {
				return new \WP_Error( 'pdxw_bad_product', 'Unknown product_id.' );
			}

			return [
				'type'       => 'lock',
				'product_id' => $product_id,
				'locked'     => ! empty( $change['locked'] ),
			];
		}

		return new \WP_Error( 'pdxw_bad_type', 'type must be markup, fixed_price, fixed_fee_rule or lock.' );
	}

	private static function normalize_fee_rule( array $change ) {

		$level = (string) ( $change['level'] ?? '' );

		if ( ! in_array( $level, ScopedFixedFees::levels(), true ) ) {
			return new \WP_Error( 'pdxw_bad_level', 'level must be one of: ' . implode( ', ', ScopedFixedFees::levels() ) . '.' );
		}

		$fee_kind = (string) ( $change['fee_kind'] ?? '' );

		if ( ! in_array( $fee_kind, ScopedFixedFees::kinds(), true ) ) {
			return new \WP_Error( 'pdxw_bad_fee_kind', 'fee_kind must be setup or ongoing.' );
		}

		$target = trim( (string) ( $change['target'] ?? '' ) );

		// Supplier + option may be given as separate fields.
		if (
			ScopedFixedFees::LEVEL_SUPPLIER_OPTION === $level
			&& '' === $target
			&& isset( $change['supplier'], $change['option_id'] )
		) {
			$target = trim( (string) $change['supplier'] ) . ':' . trim( (string) $change['option_id'] );
		}

		$target = ScopedFixedFees::normalize_key( $level, $target );

		if ( '' === $target ) {
			return new \WP_Error(
				'pdxw_bad_target',
				'target must be a print option id (option), a SKU prefix such as "A34" (supplier) or "A34:12" (supplier_option).'
			);
		}

		$price = $change['price'] ?? null;

		if ( null !== $price && '' !== $price ) {

			if ( ! is_numeric( $price ) || (float) $price < 0 ) {
				return new \WP_Error( 'pdxw_bad_price', 'price must be a number >= 0.' );
			}

			$price = round( (float) $price, 4 );

		} else {
			$price = null;
		}

		return [
			'type'     => 'fixed_fee_rule',
			'level'    => $level,
			'target'   => $target,
			'fee_kind' => $fee_kind,
			'price'    => $price,
		];
	}

	private static function normalize_markup( array $change ) {

		$part  = (string) ( $change['part'] ?? '' );
		$level = (string) ( $change['level'] ?? '' );

		if ( ! in_array( $part, ScopedRuleRepository::parts(), true ) ) {
			return new \WP_Error( 'pdxw_bad_part', 'part must be article, print_price or print_fee.' );
		}

		// Article: product level; print parts: print option level instead
		// (per-product print control is done with fixed prices or a lock).
		$levels = [
			MarkupRules::LEVEL_DEFAULT,
			MarkupRules::LEVEL_CATEGORY,
			MarkupRules::LEVEL_SUPPLIER,
			MarkupRules::LEVEL_SUPPLIER_CATEGORY,
			ScopedRuleRepository::PART_ARTICLE === $part
				? MarkupRules::LEVEL_PRODUCT
				: MarkupRules::LEVEL_PRINT_OPTION,
		];

		if ( ! in_array( $level, $levels, true ) ) {
			return new \WP_Error(
				'pdxw_bad_level',
				'level must be one of: ' . implode( ', ', $levels ) . '.'
			);
		}

		$target = trim( (string) ( $change['target'] ?? '' ) );

		// Supplier + category may be given as separate fields.
		if (
			MarkupRules::LEVEL_SUPPLIER_CATEGORY === $level
			&& '' === $target
			&& isset( $change['supplier'], $change['category'] )
		) {
			$target = trim( (string) $change['supplier'] ) . ':' . trim( (string) $change['category'] );
		}

		switch ( $level ) {

			case MarkupRules::LEVEL_DEFAULT:
				$target = '';
				break;

			case MarkupRules::LEVEL_SUPPLIER_CATEGORY:
				[ $prefix, $term_id ] = array_pad( explode( ':', $target, 2 ), 2, '' );
				$prefix  = strtoupper( trim( $prefix ) );
				$term_id = trim( $term_id );
				if ( ! preg_match( '/^[A-Z0-9_\-]{1,50}$/', $prefix ) ) {
					return new \WP_Error( 'pdxw_bad_target', 'Supplier + category target must look like "A34:12" (SKU prefix, colon, category id).' );
				}
				if ( ! ctype_digit( $term_id ) || ! term_exists( (int) $term_id, 'product_cat' ) ) {
					return new \WP_Error( 'pdxw_bad_target', 'The category in a supplier + category target must be an existing product_cat term id.' );
				}
				$target = $prefix . ':' . (int) $term_id;
				break;

			case MarkupRules::LEVEL_SUPPLIER:
				$target = strtoupper( $target );
				if ( ! preg_match( '/^[A-Z0-9_\-]{1,50}$/', $target ) ) {
					return new \WP_Error( 'pdxw_bad_target', 'Supplier target must be a SKU prefix such as "A34".' );
				}
				break;

			case MarkupRules::LEVEL_CATEGORY:
				if ( ! ctype_digit( $target ) || ! term_exists( (int) $target, 'product_cat' ) ) {
					return new \WP_Error( 'pdxw_bad_target', 'Category target must be an existing product_cat term id.' );
				}
				break;

			case MarkupRules::LEVEL_PRODUCT:
				$product_id = PriceLocks::parent_id( (int) $target );
				if ( ! $product_id || 'product' !== get_post_type( $product_id ) ) {
					return new \WP_Error( 'pdxw_bad_target', 'Product target must be an existing product id.' );
				}
				$target = (string) $product_id;
				break;

			case MarkupRules::LEVEL_PRINT_OPTION:
				if ( ! ctype_digit( $target ) || (int) $target < 1 ) {
					return new \WP_Error( 'pdxw_bad_target', 'Print option target must be a print option id.' );
				}
				break;
		}

		$percent = $change['percent'] ?? null;

		if ( null !== $percent && '' !== $percent ) {

			if ( ! is_numeric( $percent ) || (float) $percent < 0 || (float) $percent > 1000 ) {
				return new \WP_Error( 'pdxw_bad_percent', 'percent must be a number between 0 and 1000.' );
			}

			$percent = round( (float) $percent, 4 );

		} else {

			if ( MarkupRules::LEVEL_DEFAULT === $level ) {
				return new \WP_Error( 'pdxw_bad_percent', 'The default markup cannot be removed; set it to a value (0 = no markup).' );
			}

			$percent = null;
		}

		return [
			'type'    => 'markup',
			'part'    => $part,
			'level'   => $level,
			'target'  => $target,
			'percent' => $percent,
		];
	}

	private static function normalize_fixed( array $change ) {

		$product_id = PriceLocks::parent_id( (int) ( $change['product_id'] ?? 0 ) );

		if ( ! $product_id || 'product' !== get_post_type( $product_id ) ) {
			return new \WP_Error( 'pdxw_bad_product', 'Unknown product_id.' );
		}

		$kind = (string) ( $change['kind'] ?? '' );

		if ( ! in_array( $kind, FixedPrices::kinds(), true ) ) {
			return new \WP_Error( 'pdxw_bad_kind', 'kind must be article, print_price or print_fee.' );
		}

		$variation_id = absint( $change['variation_id'] ?? 0 );

		if (
			$variation_id
			&& (
				'product_variation' !== get_post_type( $variation_id )
				|| (int) wp_get_post_parent_id( $variation_id ) !== $product_id
			)
		) {
			return new \WP_Error( 'pdxw_bad_variation', 'variation_id does not belong to product_id.' );
		}

		$ref_id = absint( $change['ref_id'] ?? 0 );

		if ( FixedPrices::KIND_ARTICLE !== $kind && ! $ref_id ) {
			return new \WP_Error( 'pdxw_bad_ref', 'ref_id (print option id or fee id) is required for print prices and fees.' );
		}

		if ( FixedPrices::KIND_ARTICLE === $kind ) {
			$ref_id = 0;
		}

		$qty = array_key_exists( 'qty', $change ) && null !== $change['qty'] && '' !== $change['qty']
			? absint( $change['qty'] )
			: null;

		if ( FixedPrices::KIND_PRINT_FEE === $kind ) {
			$qty = 0;
		}

		$price = $change['price'] ?? null;

		if ( null !== $price && '' !== $price ) {

			if ( ! is_numeric( $price ) || (float) $price < 0 ) {
				return new \WP_Error( 'pdxw_bad_price', 'price must be a number >= 0.' );
			}

			if ( null === $qty || ( FixedPrices::KIND_PRINT_FEE !== $kind && $qty < 1 ) ) {
				return new \WP_Error( 'pdxw_bad_qty', 'qty (tier quantity, >= 1) is required when setting a fixed price.' );
			}

			$price = round( (float) $price, 4 );

		} else {
			$price = null;
		}

		return [
			'type'         => 'fixed_price',
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'kind'         => $kind,
			'ref_id'       => $ref_id,
			'qty'          => $qty,
			'price'        => $price,
		];
	}


	/*
	|--------------------------------------------------------------------------
	| Writes
	|--------------------------------------------------------------------------
	*/

	private static function write_markup( Pricing $pricing, array $c ): void {

		$rules   = $pricing->markup_rules();
		$percent = $c['percent'];
		$target  = $c['target'];

		if ( MarkupRules::LEVEL_DEFAULT === $c['level'] ) {

			$percent = (float) $percent;

			match ( $c['part'] ) {
				ScopedRuleRepository::PART_ARTICLE     => $rules->set_article_default( $percent ),
				ScopedRuleRepository::PART_PRINT_PRICE => $rules->set_print_price_default( $percent ),
				default                                => $rules->set_print_fee_default( $percent ),
			};

			return;
		}

		if (
			ScopedRuleRepository::PART_ARTICLE === $c['part']
			&& MarkupRules::LEVEL_CATEGORY === $c['level']
		) {
			$type = MarkupRepository::TYPE_CATEGORY;

		} elseif ( MarkupRules::LEVEL_PRINT_OPTION === $c['level'] ) {

			$type = ScopedRuleRepository::PART_PRINT_PRICE === $c['part']
				? MarkupRepository::TYPE_PRINT_OPTION_PRICE
				: MarkupRepository::TYPE_PRINT_OPTION_FEE;

		} else {

			if ( null === $percent ) {
				$rules->scoped()->delete( $c['part'], $c['level'], $target );
			} else {
				$rules->scoped()->save( $c['part'], $c['level'], $target, (float) $percent );
			}

			return;
		}

		if ( null === $percent ) {
			$rules->repository()->delete( $type, (int) $target );
		} else {
			$rules->repository()->save( $type, (int) $target, (float) $percent );
		}
	}

	private static function write_fixed( array $c ): void {

		if ( null === $c['price'] ) {

			FixedPrices::delete(
				$c['product_id'],
				$c['variation_id'],
				$c['kind'],
				$c['ref_id'],
				$c['qty']
			);

			return;
		}

		FixedPrices::set(
			$c['product_id'],
			$c['variation_id'],
			$c['kind'],
			$c['ref_id'],
			(int) $c['qty'],
			(float) $c['price'],
			History::actor()
		);
	}

	/**
	 * Value of the rule a markup change targets today (null = no rule).
	 */
	private static function current_markup( Pricing $pricing, array $c ): ?float {

		$rules = $pricing->markup_rules();

		if ( MarkupRules::LEVEL_DEFAULT === $c['level'] ) {

			return match ( $c['part'] ) {
				ScopedRuleRepository::PART_ARTICLE     => $rules->article_default(),
				ScopedRuleRepository::PART_PRINT_PRICE => $rules->print_price_default(),
				default                                => $rules->print_fee_default(),
			};
		}

		if (
			ScopedRuleRepository::PART_ARTICLE === $c['part']
			&& MarkupRules::LEVEL_CATEGORY === $c['level']
		) {
			return $rules->repository()->get( MarkupRepository::TYPE_CATEGORY, (int) $c['target'] );
		}

		if ( MarkupRules::LEVEL_PRINT_OPTION === $c['level'] ) {

			return $rules->repository()->get(
				ScopedRuleRepository::PART_PRINT_PRICE === $c['part']
					? MarkupRepository::TYPE_PRINT_OPTION_PRICE
					: MarkupRepository::TYPE_PRINT_OPTION_FEE,
				(int) $c['target']
			);
		}

		return $rules->scoped()->get( $c['part'], $c['level'], $c['target'] );
	}


	/*
	|--------------------------------------------------------------------------
	| Preview internals
	|--------------------------------------------------------------------------
	*/

	/**
	 * @param array<int,bool> $locked
	 * @param array<int,bool> $union  product ids affected by the whole set
	 * @return array<string,mixed>
	 */
	private static function preview_markup(
		Pricing $pricing,
		array $change,
		array $locked,
		array &$union
	): array {

		$old_rules = $pricing->markup_rules();
		$new_rules = $old_rules->with_changes( [ $change ] );

		$old_percent = self::current_markup( $pricing, $change );

		$part        = $change['part'];
		$is_article  = ScopedRuleRepository::PART_ARTICLE === $part;
		$new_costs   = new CostCalculator(
			$pricing->repository(),
			$new_rules,
			$pricing->manufacturer_discount(),
			$pricing->supplier_discount(),
			$pricing->selling_prices()
		);

		[ $candidates, $options_by_product, $truncated ] =
			self::candidates( $pricing, $change );

		$affected       = 0;
		$locked_skipped = 0;
		$fixed_skipped  = 0;
		$samples        = [];

		foreach ( $candidates as $product_id ) {

			$changed_option = 0;

			if ( $is_article ) {

				$differs =
					abs(
						$old_rules->resolve_article( $product_id )['percent']
						- $new_rules->resolve_article( $product_id )['percent']
					) > 0.00001;

			} else {

				$differs = false;

				foreach ( $options_by_product[ $product_id ] ?? [] as $option_id ) {

					$old = ScopedRuleRepository::PART_PRINT_PRICE === $part
						? $old_rules->resolve_print_price( $option_id, $product_id )
						: $old_rules->resolve_print_fee( $option_id, $product_id );

					$new = ScopedRuleRepository::PART_PRINT_PRICE === $part
						? $new_rules->resolve_print_price( $option_id, $product_id )
						: $new_rules->resolve_print_fee( $option_id, $product_id );

					if ( abs( $old['percent'] - $new['percent'] ) > 0.00001 ) {
						$differs        = true;
						$changed_option = $option_id;
						break;
					}
				}
			}

			if ( ! $differs ) {
				continue;
			}

			if ( isset( $locked[ $product_id ] ) ) {
				++$locked_skipped;
				continue;
			}

			if ( $is_article && FixedPrices::has_article( $product_id ) ) {
				++$fixed_skipped;
				continue;
			}

			++$affected;
			$union[ $product_id ] = true;

			if ( count( $samples ) < self::SAMPLE_LIMIT ) {

				$sample = $is_article
					? self::article_sample( $pricing, $new_costs, $product_id )
					: self::print_sample( $pricing, $old_rules, $new_rules, $part, $product_id, $changed_option );

				if ( $sample ) {
					$samples[] = $sample;
				}
			}
		}

		return [
			'description'    => self::describe_markup( $change, $old_percent ),
			'old_percent'    => $old_percent,
			'new_percent'    => $change['percent'],
			'affected_count' => $affected,
			'locked_skipped' => $locked_skipped,
			'fixed_skipped'  => $fixed_skipped,
			'truncated'      => $truncated,
			'samples'        => $samples,
		];
	}

	/**
	 * @return array{0:int[],1:array<int,int[]>,2:bool}
	 */
	private static function candidates( Pricing $pricing, array $change ): array {

		$is_print = ScopedRuleRepository::PART_ARTICLE !== $change['part'];
		$level    = $change['level'];
		$target   = $change['target'];
		$ids      = [];
		$options  = [];
		$truncated = false;

		global $wpdb;

		$relation = Database::table( 'print_relation' );

		switch ( $level ) {

			case MarkupRules::LEVEL_PRODUCT:
				$ids = [ (int) $target ];
				break;

			case MarkupRules::LEVEL_SUPPLIER:
				$ids = $pricing->markup_rules()->scoped()->products_for_prefix( $target );
				break;

			case MarkupRules::LEVEL_SUPPLIER_CATEGORY:
				[ $prefix, $term_id ] = array_pad( explode( ':', $target, 2 ), 2, '0' );
				$terms    = array_merge(
					[ (int) $term_id ],
					array_map( 'absint', (array) get_term_children( (int) $term_id, 'product_cat' ) )
				);
				$in_terms = get_objects_in_term( $terms, 'product_cat' );
				$ids      = array_values(
					array_intersect(
						$pricing->markup_rules()->scoped()->products_for_prefix( $prefix ),
						is_wp_error( $in_terms ) ? [] : array_map( 'absint', $in_terms )
					)
				);
				break;

			case MarkupRules::LEVEL_CATEGORY:
				$terms    = array_merge(
					[ (int) $target ],
					array_map( 'absint', (array) get_term_children( (int) $target, 'product_cat' ) )
				);
				$in_terms = get_objects_in_term( $terms, 'product_cat' );
				$ids      = is_wp_error( $in_terms ) ? [] : array_map( 'absint', $in_terms );
				break;

			case MarkupRules::LEVEL_PRINT_OPTION:
				$ids = array_map(
					'absint',
					$wpdb->get_col(
						$wpdb->prepare(
							"SELECT DISTINCT product_id FROM {$relation} WHERE print_option_id = %d",
							(int) $target
						)
					) ?: []
				);
				break;

			default: // default level: every product of the matching kind
				if ( $is_print ) {
					$ids = array_map(
						'absint',
						$wpdb->get_col( "SELECT DISTINCT product_id FROM {$relation}" ) ?: []
					);
				} else {
					$after = 0;
					do {
						$page = $pricing->repository()->get_product_ids( $after, 5000 );
						if ( empty( $page ) ) {
							break;
						}
						$after = (int) end( $page );
						$ids   = array_merge( $ids, $page );
					} while ( count( $ids ) < self::CANDIDATE_CAP );
				}
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( count( $ids ) > self::CANDIDATE_CAP ) {
			$ids       = array_slice( $ids, 0, self::CANDIDATE_CAP );
			$truncated = true;
		}

		if ( $is_print && $ids ) {

			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT product_id, print_option_id FROM {$relation} WHERE product_id IN ({$placeholders})",
					$ids
				),
				ARRAY_A
			);

			foreach ( is_array( $rows ) ? $rows : [] as $row ) {
				$options[ (int) $row['product_id'] ][] = (int) $row['print_option_id'];
			}
		}

		return [ $ids, $options, $truncated ];
	}

	private static function article_sample( Pricing $pricing, CostCalculator $new_costs, int $product_id ): ?array {

		$variation_ids = $pricing->repository()->get_variation_ids( $product_id );
		$variation_id  = $variation_ids ? (int) reset( $variation_ids ) : 0;

		$quantities = $pricing->repository()->get_target_quantities( $product_id, $variation_id );
		$qty        = $quantities ? (int) min( $quantities ) : 1;

		$old = $pricing->costs()->calculate( $product_id, $variation_id, $qty );
		$new = $new_costs->calculate( $product_id, $variation_id, $qty );

		if ( CostCalculator::STATUS_PRICED !== ( $new['status'] ?? '' ) ) {
			return null;
		}

		return [
			'product_id'   => $product_id,
			'sku'          => (string) get_post_meta( $product_id, '_sku', true ),
			'name'         => get_the_title( $product_id ),
			'part'         => 'article',
			'variation_id' => $variation_id,
			'qty'          => $qty,
			'old_price'    => $old['article_price'] ?? null,
			'new_price'    => $new['article_price'] ?? null,
			'old_markup'   => $old['article_markup'] ?? null,
			'new_markup'   => $new['article_markup'] ?? null,
			'old_level'    => $old['markup_level'] ?? '',
			'new_level'    => $new['markup_level'] ?? '',
		];
	}

	private static function print_sample(
		Pricing $pricing,
		MarkupRules $old_rules,
		MarkupRules $new_rules,
		string $part,
		int $product_id,
		int $option_id
	): ?array {

		$printing = Plugin::instance()->printing();

		if ( ! $printing || ! $option_id ) {
			return null;
		}

		$repository = $printing->repository();
		$calc       = $pricing->selling_prices();

		$base = [
			'product_id' => $product_id,
			'sku'        => (string) get_post_meta( $product_id, '_sku', true ),
			'name'       => get_the_title( $product_id ),
			'part'       => $part,
			'option_id'  => $option_id,
		];

		if ( ScopedRuleRepository::PART_PRINT_PRICE === $part ) {

			$tiers = $repository->get_selling_prices( $option_id );
			$qty   = $tiers ? max( 1, (int) $tiers[0]->min_qty ) : 1;
			$cost  = $repository->get_applicable_purchase_price( $option_id, $qty );

			if ( ! is_numeric( $cost ) || (float) $cost <= 0 ) {
				return null;
			}

			$old = $old_rules->resolve_print_price( $option_id, $product_id );
			$new = $new_rules->resolve_print_price( $option_id, $product_id );

			return $base + [
				'qty'        => $qty,
				'old_price'  => $calc->ongoing( (float) $cost, $old['percent'] ),
				'new_price'  => $calc->ongoing( (float) $cost, $new['percent'] ),
				'old_markup' => $old['percent'],
				'new_markup' => $new['percent'],
				'old_level'  => $old['level'],
				'new_level'  => $new['level'],
			];
		}

		$calculator = $printing->calculator();

		foreach ( $repository->get_fees( $option_id ) as $fee ) {

			$cost =
				isset( $fee->purchase_amount ) && is_numeric( $fee->purchase_amount )
					? (float) $fee->purchase_amount
					: (float) ( $fee->amount ?? 0 );

			if ( $cost <= 0 ) {
				continue;
			}

			$kind = $calculator->fee_kind( (string) $fee->fee_type, (string) $fee->fee_label );
			$old  = $old_rules->resolve_print_fee( $option_id, $product_id );
			$new  = $new_rules->resolve_print_fee( $option_id, $product_id );

			$apply = static fn( float $pct ) => 'setup' === $kind
				? $calc->setup( $cost, $pct )
				: $calc->ongoing( $cost, $pct );

			return $base + [
				'fee_label'  => (string) $fee->fee_label,
				'old_price'  => $apply( $old['percent'] ),
				'new_price'  => $apply( $new['percent'] ),
				'old_markup' => $old['percent'],
				'new_markup' => $new['percent'],
				'old_level'  => $old['level'],
				'new_level'  => $new['level'],
			];
		}

		return null;
	}

	/**
	 * Products whose print fee changes when a scoped fixed fee rule changes.
	 *
	 * @param array<int,bool> $locked
	 * @param array<int,bool> $union  product ids affected by the whole set
	 * @return array<string,mixed>
	 */
	private static function preview_fee_rule( Pricing $pricing, array $c, array $locked, array &$union ): array {

		global $wpdb;

		$relation = Database::table( 'print_relation' );
		$printing = Plugin::instance()->printing();
		$scoped   = $pricing->markup_rules()->scoped();
		$kind     = $c['fee_kind'];
		$old_rule = ScopedFixedFees::get( $c['level'], $c['target'], $kind );
		$overlay  = [ "{$c['level']}|{$c['target']}|{$kind}" => $c['price'] ];

		$option_filter = 0;
		$ids           = [];

		switch ( $c['level'] ) {

			case ScopedFixedFees::LEVEL_OPTION:
				$option_filter = (int) $c['target'];
				$ids           = $wpdb->get_col(
					$wpdb->prepare( "SELECT DISTINCT product_id FROM {$relation} WHERE print_option_id = %d", $option_filter )
				);
				break;

			case ScopedFixedFees::LEVEL_SUPPLIER_OPTION:
				[ $prefix, $option ] = explode( ':', $c['target'], 2 );
				$option_filter       = (int) $option;
				$ids                 = array_intersect(
					$scoped->products_for_prefix( $prefix ),
					array_map(
						'absint',
						$wpdb->get_col(
							$wpdb->prepare( "SELECT DISTINCT product_id FROM {$relation} WHERE print_option_id = %d", $option_filter )
						) ?: []
					)
				);
				break;

			default:
				$ids = $scoped->products_for_prefix( $c['target'] );
		}

		$ids       = array_values( array_unique( array_filter( array_map( 'absint', $ids ?: [] ) ) ) );
		$truncated = count( $ids ) > self::CANDIDATE_CAP;

		if ( $truncated ) {
			$ids = array_slice( $ids, 0, self::CANDIDATE_CAP );
		}

		$options = [];

		if ( $ids && ! $option_filter ) {

			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT product_id, print_option_id FROM {$relation} WHERE product_id IN ({$placeholders})",
					$ids
				),
				ARRAY_A
			);

			foreach ( is_array( $rows ) ? $rows : [] as $row ) {
				$options[ (int) $row['product_id'] ][] = (int) $row['print_option_id'];
			}
		}

		$affected       = 0;
		$locked_skipped = 0;
		$fixed_skipped  = 0;
		$samples        = [];
		$repository     = $printing ? $printing->repository() : null;
		$calculator     = $printing ? $printing->calculator() : null;
		$prices         = $pricing->selling_prices();
		$old_markup     = $pricing->markup_rules();

		foreach ( $ids as $product_id ) {

			$sku = (string) get_post_meta( $product_id, '_sku', true );

			foreach ( $option_filter ? [ $option_filter ] : ( $options[ $product_id ] ?? [] ) as $option_id ) {

				$old = ScopedFixedFees::match( $kind, $option_id, $sku );
				$new = ScopedFixedFees::match( $kind, $option_id, $sku, $overlay );

				if (
					( $old['price'] ?? null ) === ( $new['price'] ?? null )
					&& ( $old['level'] ?? '' ) === ( $new['level'] ?? '' )
				) {
					continue;
				}

				// Only fees of the rule's kind are touched.
				$fees = [];

				if ( $repository && $calculator ) {
					foreach ( $repository->get_fees( $option_id ) as $fee ) {
						if ( $calculator->fee_kind( (string) $fee->fee_type, (string) $fee->fee_label ) === $kind ) {
							$fees[] = $fee;
						}
					}
				}

				if ( ! $fees ) {
					continue;
				}

				if ( isset( $locked[ $product_id ] ) ) {
					++$locked_skipped;
					break;
				}

				$own = array_filter(
					$fees,
					static fn( $fee ) => null !== FixedPrices::print_fee( $product_id, 0, (int) ( $fee->id ?? 0 ) )
				);

				if ( count( $own ) === count( $fees ) ) {
					++$fixed_skipped;
					break;
				}

				++$affected;
				$union[ $product_id ] = true;

				if ( count( $samples ) < self::SAMPLE_LIMIT ) {

					$fee  = reset( $fees );
					$cost = isset( $fee->purchase_amount ) && is_numeric( $fee->purchase_amount )
						? (float) $fee->purchase_amount
						: (float) ( $fee->amount ?? 0 );

					$rule  = $old_markup->resolve_print_fee( $option_id, $product_id );
					$price = static function ( ?array $match ) use ( $prices, $kind, $cost, $rule ): float {
						if ( $match ) {
							return SellingPriceCalculator::round_money( (float) $match['price'] );
						}
						return 'setup' === $kind
							? $prices->setup( $cost, $rule['percent'] )
							: $prices->ongoing( $cost, $rule['percent'] );
					};

					$samples[] = [
						'product_id' => $product_id,
						'sku'        => $sku,
						'name'       => get_the_title( $product_id ),
						'part'       => 'print_fee',
						'option_id'  => $option_id,
						'fee_label'  => (string) $fee->fee_label,
						'old_price'  => $price( $old ),
						'new_price'  => $price( $new ),
						'old_level'  => $old ? 'fixed' : $rule['level'],
						'new_level'  => $new ? 'fixed' : $rule['level'],
					];
				}

				break; // one hit per product is enough
			}
		}

		return [
			'description'    => self::describe_fee_rule( $c, $old_rule ),
			'old_price'      => $old_rule,
			'new_price'      => $c['price'],
			'affected_count' => $affected,
			'locked_skipped' => $locked_skipped,
			'fixed_skipped'  => $fixed_skipped,
			'truncated'      => $truncated,
			'samples'        => $samples,
		];
	}

	/**
	 * Old -> new for the single product a fixed-price change targets.
	 *
	 * @param array<int,bool> $locked
	 * @return array<string,mixed>
	 */
	private static function preview_fixed( Pricing $pricing, array $c, array $locked ): array {

		$old_price = null;

		if ( FixedPrices::KIND_ARTICLE === $c['kind'] && null !== $c['qty'] ) {

			$old_price = $pricing->costs()
				->calculate( $c['product_id'], $c['variation_id'], max( 1, (int) $c['qty'] ) )['article_price'] ?? null;
		}

		return [
			'description'    => self::describe_fixed( $c ),
			'affected_count' => 1,
			'locked_skipped' => 0,
			'note'           => isset( $locked[ $c['product_id'] ] )
				? 'Product is locked; the fixed price is stored and applies right away.'
				: '',
			'samples'        => [
				[
					'product_id'   => $c['product_id'],
					'sku'          => (string) get_post_meta( $c['product_id'], '_sku', true ),
					'name'         => get_the_title( $c['product_id'] ),
					'part'         => $c['kind'],
					'variation_id' => $c['variation_id'],
					'qty'          => $c['qty'],
					'old_price'    => $old_price,
					'new_price'    => $c['price'],
				],
			],
		];
	}


	/*
	|--------------------------------------------------------------------------
	| Descriptions
	|--------------------------------------------------------------------------
	*/

	private static function describe_markup( array $c, ?float $old ): string {

		if ( MarkupRules::LEVEL_DEFAULT === $c['level'] ) {
			$where = 'default';
		} elseif ( MarkupRules::LEVEL_SUPPLIER_CATEGORY === $c['level'] ) {

			[ $prefix, $term_id ] = array_pad( explode( ':', $c['target'], 2 ), 2, '0' );

			$term  = get_term( (int) $term_id, 'product_cat' );
			$where = sprintf(
				'supplier %s + category %s',
				$prefix,
				$term && ! is_wp_error( $term ) ? $term->name : '#' . $term_id
			);

		} else {
			$where = $c['level'] . ' ' . $c['target'];
		}

		$old_text = null === $old ? 'none' : rtrim( rtrim( number_format( $old, 4, '.', '' ), '0' ), '.' ) . '%';

		if ( null === $c['percent'] ) {
			return sprintf( '%s markup, %s: removed (was %s)', $c['part'], $where, $old_text );
		}

		return sprintf(
			'%s markup, %s: %s -> %s%%',
			$c['part'],
			$where,
			$old_text,
			rtrim( rtrim( number_format( (float) $c['percent'], 4, '.', '' ), '0' ), '.' )
		);
	}

	private static function describe_fee_rule( array $c, ?float $old ): string {

		$where = match ( $c['level'] ) {
			ScopedFixedFees::LEVEL_OPTION   => 'print option #' . $c['target'],
			ScopedFixedFees::LEVEL_SUPPLIER => 'supplier ' . $c['target'],
			default                         => 'supplier ' . strtok( $c['target'], ':' ) . ' + print option #' . substr( strrchr( $c['target'], ':' ), 1 ),
		};

		$old_text = null === $old ? 'none' : rtrim( rtrim( number_format( $old, 4, '.', '' ), '0' ), '.' );

		if ( null === $c['price'] ) {
			return sprintf( 'Fixed %s fee, %s: removed (was %s)', $c['fee_kind'], $where, $old_text );
		}

		return sprintf(
			'Fixed %s fee, %s: %s -> %s',
			$c['fee_kind'],
			$where,
			$old_text,
			rtrim( rtrim( number_format( (float) $c['price'], 4, '.', '' ), '0' ), '.' )
		);
	}

	private static function describe_fixed( array $c ): string {

		$what = sprintf(
			'%s%s%s',
			$c['kind'],
			$c['ref_id'] ? ' #' . $c['ref_id'] : '',
			$c['variation_id'] ? ' (variation ' . $c['variation_id'] . ')' : ''
		);

		if ( null === $c['price'] ) {
			return sprintf(
				'Fixed price removed: product #%d %s%s',
				$c['product_id'],
				$what,
				null === $c['qty'] ? ' (all tiers)' : ' qty ' . $c['qty']
			);
		}

		return sprintf(
			'Fixed price: product #%d %s qty %d = %s',
			$c['product_id'],
			$what,
			(int) $c['qty'],
			$c['price']
		);
	}
}
