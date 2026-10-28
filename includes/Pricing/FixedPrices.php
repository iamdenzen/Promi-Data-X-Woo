<?php

namespace PromiDataXWoo\Pricing;

use PromiDataXWoo\Core\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Static customer prices set on top of what Promi delivers.
 *
 * A fixed price is the final price: no discount, markup or rounding is
 * applied to it. Rows are keyed by (product, variation, kind, ref, qty).
 *
 *   article      ref 0,          qty = tier quantity
 *   print_price  ref = option id, qty = tier quantity
 *   print_fee    ref = fee id,    qty 0
 *
 * variation_id 0 means "every variation of the product"; a variation-specific
 * set wins over the product-wide set.
 */
final class FixedPrices {

	public const KIND_ARTICLE     = 'article';
	public const KIND_PRINT_PRICE = 'print_price';
	public const KIND_PRINT_FEE   = 'print_fee';

	/** @var array<string,array> */
	private static array $cache = [];


	public static function kinds(): array {

		return [
			self::KIND_ARTICLE,
			self::KIND_PRINT_PRICE,
			self::KIND_PRINT_FEE,
		];
	}


	/**
	 * Fixed article price for a quantity, or null when none is set.
	 */
	public static function article( int $product_id, int $variation_id, int $quantity ): ?float {

		return self::tier_price(
			self::KIND_ARTICLE,
			$product_id,
			$variation_id,
			0,
			$quantity
		);
	}

	public static function print_price( int $product_id, int $variation_id, int $option_id, int $quantity ): ?float {

		return self::tier_price(
			self::KIND_PRINT_PRICE,
			$product_id,
			$variation_id,
			$option_id,
			$quantity
		);
	}

	public static function print_fee( int $product_id, int $variation_id, int $fee_id ): ?float {

		return self::tier_price(
			self::KIND_PRINT_FEE,
			$product_id,
			$variation_id,
			$fee_id,
			0
		);
	}


	/**
	 * Does this product have any fixed article tier?
	 */
	public static function has_article( int $product_id, int $variation_id = 0 ): bool {

		return ! empty(
			self::rows( self::KIND_ARTICLE, $product_id, $variation_id, 0 )
		);
	}


	/**
	 * Every fixed article tier price that applies to a variation, ascending.
	 *
	 * @return array<int,float> qty => price
	 */
	public static function article_tiers( int $product_id, int $variation_id ): array {

		$tiers = [];

		foreach ( self::rows( self::KIND_ARTICLE, $product_id, $variation_id, 0 ) as $row ) {
			$tiers[ (int) $row['qty'] ] = (float) $row['price'];
		}

		ksort( $tiers );

		return $tiers;
	}


	/**
	 * Tier quantities of a kind that apply to a variation, ascending.
	 *
	 * @return int[]
	 */
	public static function tier_quantities(
		string $kind,
		int $product_id,
		int $variation_id,
		int $ref_id
	): array {

		$qtys = [];

		foreach ( self::rows( $kind, $product_id, $variation_id, $ref_id ) as $row ) {
			$qtys[] = (int) $row['qty'];
		}

		sort( $qtys );

		return array_values( array_unique( $qtys ) );
	}


	public static function set(
		int $product_id,
		int $variation_id,
		string $kind,
		int $ref_id,
		int $qty,
		float $price,
		string $actor = '',
		string $origin = 'manual'
	): bool {

		$product_id = PriceLocks::parent_id( $product_id );

		if (
			! $product_id
			|| ! in_array( $kind, self::kinds(), true )
			|| $price < 0
		) {
			return false;
		}

		global $wpdb;

		$now    = current_time( 'mysql', true );
		$origin = 'lock' === $origin ? 'lock' : 'manual';

		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Database::table( 'fixed_prices' ) . '
					( product_id, variation_id, kind, ref_id, qty, price, origin, updated_by, created_at, updated_at )
				VALUES ( %d, %d, %s, %d, %d, %f, %s, %s, %s, %s )
				ON DUPLICATE KEY UPDATE
					price = VALUES(price),
					origin = VALUES(origin),
					updated_by = VALUES(updated_by),
					updated_at = VALUES(updated_at)',
				$product_id,
				absint( $variation_id ),
				$kind,
				absint( $ref_id ),
				absint( $qty ),
				$price,
				$origin,
				$actor,
				$now,
				$now
			)
		);

		self::$cache = [];

		return false !== $result;
	}


	/**
	 * Remove every lock-snapshot row of a product (manual rows stay).
	 */
	public static function delete_lock_snapshot( int $product_id ): void {

		global $wpdb;

		$wpdb->delete(
			Database::table( 'fixed_prices' ),
			[
				'product_id' => absint( $product_id ),
				'origin'     => 'lock',
			],
			[ '%d', '%s' ]
		);

		self::$cache = [];
	}


	/**
	 * Does this exact key already have a row (any origin)?
	 */
	public static function exists(
		int $product_id,
		int $variation_id,
		string $kind,
		int $ref_id,
		int $qty
	): bool {

		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . Database::table( 'fixed_prices' ) . '
				WHERE product_id = %d AND variation_id = %d AND kind = %s
					AND ref_id = %d AND qty = %d',
				$product_id,
				$variation_id,
				$kind,
				$ref_id,
				$qty
			)
		);
	}


	/**
	 * Delete one tier, or every tier of a kind (qty null).
	 */
	public static function delete(
		int $product_id,
		int $variation_id,
		string $kind,
		int $ref_id,
		?int $qty = null
	): bool {

		$product_id = PriceLocks::parent_id( $product_id );

		if ( ! $product_id || ! in_array( $kind, self::kinds(), true ) ) {
			return false;
		}

		global $wpdb;

		$where = [
			'product_id'   => $product_id,
			'variation_id' => absint( $variation_id ),
			'kind'         => $kind,
			'ref_id'       => absint( $ref_id ),
		];

		$format = [ '%d', '%d', '%s', '%d' ];

		if ( null !== $qty ) {
			$where['qty'] = absint( $qty );
			$format[]     = '%d';
		}

		$result = $wpdb->delete(
			Database::table( 'fixed_prices' ),
			$where,
			$format
		);

		self::$cache = [];

		return false !== $result;
	}


	/**
	 * Every fixed price row of a product (or all products, paged).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function list( int $product_id = 0, int $limit = 500, int $offset = 0 ): array {

		global $wpdb;

		$table = Database::table( 'fixed_prices' );

		$sql    = "SELECT product_id, variation_id, kind, ref_id, qty, price, origin, updated_by, updated_at FROM {$table}";
		$values = [];

		if ( $product_id ) {
			$sql     .= ' WHERE product_id = %d';
			$values[] = $product_id;
		}

		$sql     .= ' ORDER BY product_id ASC, variation_id ASC, kind ASC, ref_id ASC, qty ASC LIMIT %d OFFSET %d';
		$values[] = max( 1, $limit );
		$values[] = max( 0, $offset );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		foreach ( $rows as &$row ) {
			$row['product_id']   = (int) $row['product_id'];
			$row['variation_id'] = (int) $row['variation_id'];
			$row['ref_id']       = (int) $row['ref_id'];
			$row['qty']          = (int) $row['qty'];
			$row['price']        = (float) $row['price'];
		}

		return $rows;
	}


	/*
	|--------------------------------------------------------------------------
	| Internals
	|--------------------------------------------------------------------------
	*/

	/**
	 * Applicable tier price: the highest tier quantity not above the
	 * requested quantity, or the lowest tier when the quantity is below all.
	 */
	private static function tier_price(
		string $kind,
		int $product_id,
		int $variation_id,
		int $ref_id,
		int $quantity
	): ?float {

		$rows = self::rows( $kind, $product_id, $variation_id, $ref_id );

		if ( empty( $rows ) ) {
			return null;
		}

		usort(
			$rows,
			static fn( $a, $b ) => (int) $a['qty'] <=> (int) $b['qty']
		);

		$chosen = $rows[0];

		foreach ( $rows as $row ) {
			if ( (int) $row['qty'] <= $quantity ) {
				$chosen = $row;
			}
		}

		return (float) $chosen['price'];
	}


	/**
	 * Rows for the narrowest scope that has any: the variation set when it
	 * exists, otherwise the product-wide (variation 0) set.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function rows(
		string $kind,
		int $product_id,
		int $variation_id,
		int $ref_id
	): array {

		$product_id = absint( $product_id );

		if ( ! $product_id ) {
			return [];
		}

		$key = "{$kind}:{$product_id}:{$ref_id}";

		if ( ! isset( self::$cache[ $key ] ) ) {

			global $wpdb;

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT variation_id, qty, price FROM ' . Database::table( 'fixed_prices' ) . '
					WHERE product_id = %d AND kind = %s AND ref_id = %d',
					$product_id,
					$kind,
					$ref_id
				),
				ARRAY_A
			);

			self::$cache[ $key ] = is_array( $rows ) ? $rows : [];
		}

		$variation_id = absint( $variation_id );

		if ( $variation_id ) {

			$own = array_values(
				array_filter(
					self::$cache[ $key ],
					static fn( $r ) => (int) $r['variation_id'] === $variation_id
				)
			);

			if ( $own ) {
				return $own;
			}
		}

		return array_values(
			array_filter(
				self::$cache[ $key ],
				static fn( $r ) => 0 === (int) $r['variation_id']
			)
		);
	}
}
