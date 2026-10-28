<?php

namespace PromiDataXWoo\Pricing;

use PromiDataXWoo\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Locks and unlocks a product's prices.
 *
 * Locking freezes what the customer pays today: every article tier, print
 * unit tier and print fee that is currently calculated is stored as a fixed
 * price (origin "lock"). From then on no markup change, recalculation,
 * Promi import or supplier sync can move those prices, because fixed prices
 * bypass the cost -> markup calculation and live in their own table.
 *
 * Fixed prices an admin set by hand are never overwritten by the snapshot.
 * Unlocking removes the snapshot rows only and reprices the product.
 */
final class LockManager {

	/**
	 * @return array{locked:bool,frozen:array{article:int,print_price:int,print_fee:int}}|null
	 */
	public static function lock( int $product_id, string $note = '' ): ?array {

		$product_id = PriceLocks::parent_id( $product_id );

		$plugin   = Plugin::instance();
		$pricing  = $plugin->pricing();
		$printing = $plugin->printing();

		if ( ! $product_id || ! $pricing ) {
			return null;
		}

		$actor  = History::actor();
		$frozen = [
			'article'     => 0,
			'print_price' => 0,
			'print_fee'   => 0,
		];

		if ( ! PriceLocks::is_locked( $product_id ) ) {

			$variation_ids = $pricing->repository()->get_variation_ids( $product_id );

			if ( empty( $variation_ids ) ) {
				$variation_ids = [ 0 ];
			}

			foreach ( $variation_ids as $variation_id ) {

				$variation_id = (int) $variation_id;

				$frozen['article'] += self::freeze_article(
					$pricing,
					$product_id,
					$variation_id
				);

				if ( $printing ) {
					self::freeze_print(
						$printing,
						$product_id,
						$variation_id,
						$frozen
					);
				}
			}
		}

		PriceLocks::lock( $product_id, $actor, $note );

		History::record(
			'lock',
			sprintf( 'Locked product #%d (%s)', $product_id, get_post_meta( $product_id, '_sku', true ) ),
			1,
			[
				'product_id' => $product_id,
				'frozen'     => $frozen,
				'note'       => $note,
			]
		);

		return [
			'locked' => true,
			'frozen' => $frozen,
		];
	}


	public static function unlock( int $product_id ): bool {

		$product_id = PriceLocks::parent_id( $product_id );

		if ( ! $product_id ) {
			return false;
		}

		FixedPrices::delete_lock_snapshot( $product_id );
		PriceLocks::unlock( $product_id );

		$pricing = Plugin::instance()->pricing();

		if ( $pricing ) {
			$pricing->price_sync()->reprice_product( $product_id, true );
		}

		History::record(
			'unlock',
			sprintf( 'Unlocked product #%d (%s)', $product_id, get_post_meta( $product_id, '_sku', true ) ),
			1,
			[ 'product_id' => $product_id ]
		);

		return true;
	}


	private static function freeze_article(
		Pricing $pricing,
		int $product_id,
		int $variation_id
	): int {

		// A hand-set fixed ladder already pins this variation.
		if ( FixedPrices::has_article( $product_id, $variation_id ) ) {
			return 0;
		}

		$repository = $pricing->repository();

		$quantities = $repository->get_target_quantities( $product_id, $variation_id );

		if ( empty( $quantities ) && $variation_id ) {
			$quantities = $repository->get_target_quantities( $product_id, 0 );
		}

		$count = 0;

		foreach ( $quantities as $qty ) {

			$result = $pricing->costs()->calculate( $product_id, $variation_id, (int) $qty );

			if (
				CostCalculator::STATUS_PRICED !== ( $result['status'] ?? '' )
				|| ! is_numeric( $result['article_price'] ?? null )
			) {
				continue;
			}

			if (
				FixedPrices::set(
					$product_id,
					$variation_id,
					FixedPrices::KIND_ARTICLE,
					0,
					(int) $qty,
					(float) $result['article_price'],
					History::actor(),
					'lock'
				)
			) {
				++$count;
			}
		}

		return $count;
	}


	/**
	 * @param array<string,int> $frozen
	 */
	private static function freeze_print(
		\PromiDataXWoo\Printing\Printing $printing,
		int $product_id,
		int $variation_id,
		array &$frozen
	): void {

		$repository = $printing->repository();
		$calculator = $printing->calculator();

		$positions = $repository->get_positions( $product_id, $variation_id );

		if ( empty( $positions ) && $variation_id ) {
			$positions = $repository->get_positions( $product_id, 0 );
		}

		$option_ids = [];

		foreach ( $positions as $position ) {

			$options = $repository->get_options_by_position(
				$product_id,
				$variation_id,
				(int) $position->id
			);

			if ( empty( $options ) && $variation_id ) {
				$options = $repository->get_options_by_position(
					$product_id,
					0,
					(int) $position->id
				);
			}

			foreach ( $options as $option ) {
				$option_ids[ (int) $option->id ] = true;
			}
		}

		foreach ( array_keys( $option_ids ) as $option_id ) {

			foreach ( $repository->get_selling_prices( $option_id ) as $tier ) {

				$qty = max( 1, (int) $tier->min_qty );

				if (
					FixedPrices::exists( $product_id, $variation_id, FixedPrices::KIND_PRINT_PRICE, $option_id, $qty )
				) {
					continue;
				}

				$unit = $calculator->selling_unit_price( $product_id, $variation_id, $option_id, $qty );

				if (
					$unit['price'] > 0
					&& FixedPrices::set(
						$product_id,
						$variation_id,
						FixedPrices::KIND_PRINT_PRICE,
						$option_id,
						$qty,
						$unit['price'],
						History::actor(),
						'lock'
					)
				) {
					++$frozen['print_price'];
				}
			}

			foreach ( $repository->get_fees( $option_id ) as $fee ) {

				$fee_id = (int) ( $fee->id ?? 0 );

				if (
					! $fee_id
					|| FixedPrices::exists( $product_id, $variation_id, FixedPrices::KIND_PRINT_FEE, $fee_id, 0 )
				) {
					continue;
				}

				$base =
					isset( $fee->purchase_amount )
					&& is_numeric( $fee->purchase_amount )
						? (float) $fee->purchase_amount
						: (float) ( $fee->amount ?? 0 );

				if ( $base <= 0 ) {
					continue;
				}

				$kind = $calculator->fee_kind(
					(string) $fee->fee_type,
					(string) $fee->fee_label
				);

				$selling = $calculator->selling_fee(
					$kind,
					$base,
					0, // no fixed lookup: the snapshot is the calculated price
					$product_id,
					$variation_id,
					$option_id,
					$base
				);

				if (
					$selling['amount'] > 0
					&& FixedPrices::set(
						$product_id,
						$variation_id,
						FixedPrices::KIND_PRINT_FEE,
						$fee_id,
						0,
						$selling['amount'],
						History::actor(),
						'lock'
					)
				) {
					++$frozen['print_fee'];
				}
			}
		}
	}
}
