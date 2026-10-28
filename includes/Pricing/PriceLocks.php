<?php

namespace PromiDataXWoo\Pricing;

use PromiDataXWoo\Core\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Per-product price lock.
 *
 * A locked product keeps its stored tiers, print prices, fees and WooCommerce
 * price through every recalculation, Promi import and supplier sync. Fixed
 * prices live in their own table and are never touched by imports anyway.
 *
 * Callers may pass a variation id; it is resolved to its parent product.
 */
final class PriceLocks {

	/** @var array<int,bool> */
	private static array $cache = [];


	public static function is_locked( int $product_id ): bool {

		$product_id = self::parent_id( $product_id );

		if ( ! $product_id ) {
			return false;
		}

		if ( isset( self::$cache[ $product_id ] ) ) {
			return self::$cache[ $product_id ];
		}

		global $wpdb;

		$table = Database::table( 'price_locks' );

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT product_id FROM {$table} WHERE product_id = %d",
				$product_id
			)
		);

		return self::$cache[ $product_id ] = (bool) $found;
	}


	public static function lock( int $product_id, string $actor = '', string $note = '' ): bool {

		$product_id = self::parent_id( $product_id );

		if ( ! $product_id ) {
			return false;
		}

		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Database::table( 'price_locks' ) . '
					( product_id, locked_by, note, locked_at )
				VALUES ( %d, %s, %s, %s )
				ON DUPLICATE KEY UPDATE
					locked_by = VALUES(locked_by),
					note = VALUES(note)',
				$product_id,
				$actor,
				mb_substr( $note, 0, 255 ),
				current_time( 'mysql', true )
			)
		);

		unset( self::$cache[ $product_id ] );

		return false !== $result;
	}


	public static function unlock( int $product_id ): bool {

		$product_id = self::parent_id( $product_id );

		if ( ! $product_id ) {
			return false;
		}

		global $wpdb;

		$result = $wpdb->delete(
			Database::table( 'price_locks' ),
			[ 'product_id' => $product_id ],
			[ '%d' ]
		);

		unset( self::$cache[ $product_id ] );

		return false !== $result;
	}


	/**
	 * @return array<int,array{product_id:int,sku:string,locked_by:string,note:string,locked_at:string}>
	 */
	public static function all( int $limit = 200, int $offset = 0 ): array {

		global $wpdb;

		$table = Database::table( 'price_locks' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.product_id, l.locked_by, l.note, l.locked_at,
					( SELECT meta_value FROM {$wpdb->postmeta}
						WHERE post_id = l.product_id AND meta_key = '_sku' LIMIT 1 ) AS sku
				FROM {$table} l
				ORDER BY l.locked_at DESC
				LIMIT %d OFFSET %d",
				max( 1, $limit ),
				max( 0, $offset )
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		foreach ( $rows as &$row ) {
			$row['product_id'] = (int) $row['product_id'];
			$row['sku']        = (string) $row['sku'];
		}

		return $rows;
	}


	/**
	 * Every locked product id, as a lookup map (id => true).
	 *
	 * @return array<int,bool>
	 */
	public static function ids(): array {

		global $wpdb;

		$ids = $wpdb->get_col(
			'SELECT product_id FROM ' . Database::table( 'price_locks' )
		);

		return array_fill_keys( array_map( 'absint', $ids ?: [] ), true );
	}


	public static function count(): int {

		global $wpdb;

		return (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . Database::table( 'price_locks' )
		);
	}


	public static function parent_id( int $id ): int {

		$id = absint( $id );

		if ( ! $id ) {
			return 0;
		}

		if ( 'product_variation' === get_post_type( $id ) ) {
			return (int) wp_get_post_parent_id( $id );
		}

		return $id;
	}
}
