<?php

namespace PromiDataXWoo\Pricing;

use PromiDataXWoo\Core\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed print fees for a whole scope instead of one product.
 *
 *   supplier_option  SKU prefix + print option   (most specific)
 *   option           print option
 *   supplier         SKU prefix                  (longest prefix wins)
 *
 * A rule is stored per fee kind (setup / ongoing). Like a product fixed
 * price it is the final selling price per fee unit: no markup or rounding is
 * applied, the colors / quantity / positions multiplier still applies.
 * A fixed price on the product itself (FixedPrices) wins over these.
 */
final class ScopedFixedFees {

	public const LEVEL_OPTION          = 'option';
	public const LEVEL_SUPPLIER        = 'supplier';
	public const LEVEL_SUPPLIER_OPTION = 'supplier_option';

	public const KIND_SETUP   = 'setup';
	public const KIND_ONGOING = 'ongoing';

	/** @var array<int,array{level:string,target_key:string,fee_kind:string,price:float}>|null */
	private static ?array $cache = null;


	public static function levels(): array {

		return [
			self::LEVEL_SUPPLIER_OPTION,
			self::LEVEL_OPTION,
			self::LEVEL_SUPPLIER,
		];
	}

	public static function kinds(): array {

		return [ self::KIND_SETUP, self::KIND_ONGOING ];
	}


	/**
	 * Key a target the way it is stored, or '' when it is not valid.
	 */
	public static function normalize_key( string $level, string $key ): string {

		$key = strtoupper( trim( sanitize_text_field( $key ) ) );

		switch ( $level ) {

			case self::LEVEL_OPTION:
				return ctype_digit( $key ) && (int) $key > 0 ? (string) (int) $key : '';

			case self::LEVEL_SUPPLIER:
				return preg_match( '/^[A-Z0-9_\-]{1,50}$/', $key ) ? $key : '';

			case self::LEVEL_SUPPLIER_OPTION:
				[ $prefix, $option ] = array_pad( explode( ':', $key, 2 ), 2, '' );
				$prefix = trim( $prefix );
				return preg_match( '/^[A-Z0-9_\-]{1,50}$/', $prefix ) && ctype_digit( $option ) && (int) $option > 0
					? $prefix . ':' . (int) $option
					: '';
		}

		return '';
	}


	/**
	 * Winning rule for a fee kind on one option of one product.
	 *
	 * $overlay is a what-if used by previews: "level|target|kind" => price,
	 * or null for a rule that is removed. Nothing is written.
	 *
	 * @param array<string,?float>|null $overlay
	 * @return array{price:float,level:string,label:string,target:string}|null
	 */
	public static function match(
		string $kind,
		int $option_id,
		string $sku,
		?array $overlay = null
	): ?array {

		$sku = strtoupper( trim( $sku ) );
		$map = self::map( $kind, $overlay );

		if ( ! $map ) {
			return null;
		}

		$best = null;

		// Supplier + option: the SKU must start with the prefix.
		foreach ( $map[ self::LEVEL_SUPPLIER_OPTION ] ?? [] as $key => $price ) {

			[ $prefix, $option ] = explode( ':', (string) $key, 2 );

			if (
				(int) $option === $option_id
				&& '' !== $sku
				&& str_starts_with( $sku, $prefix )
				&& ( null === $best || strlen( $prefix ) > strlen( $best['prefix'] ) )
			) {
				$best = [ 'prefix' => $prefix, 'key' => $key, 'price' => $price ];
			}
		}

		if ( $best ) {
			return self::result( self::LEVEL_SUPPLIER_OPTION, $best['key'], $best['price'], 'supplier ' . $best['prefix'] . ' + option #' . (int) explode( ':', $best['key'], 2 )[1] );
		}

		if ( $option_id && isset( $map[ self::LEVEL_OPTION ][ (string) $option_id ] ) ) {
			return self::result( self::LEVEL_OPTION, (string) $option_id, $map[ self::LEVEL_OPTION ][ (string) $option_id ], 'option #' . $option_id );
		}

		foreach ( $map[ self::LEVEL_SUPPLIER ] ?? [] as $prefix => $price ) {

			$prefix = (string) $prefix;

			if (
				'' !== $sku
				&& str_starts_with( $sku, $prefix )
				&& ( null === $best || strlen( $prefix ) > strlen( $best['prefix'] ) )
			) {
				$best = [ 'prefix' => $prefix, 'key' => $prefix, 'price' => $price ];
			}
		}

		return $best
			? self::result( self::LEVEL_SUPPLIER, $best['key'], $best['price'], 'supplier ' . $best['prefix'] )
			: null;
	}


	/**
	 * Current value of one rule (null = none).
	 */
	public static function get( string $level, string $target_key, string $kind ): ?float {

		$target_key = self::normalize_key( $level, $target_key );

		foreach ( self::rows() as $row ) {
			if ( $row['level'] === $level && $row['target_key'] === $target_key && $row['fee_kind'] === $kind ) {
				return $row['price'];
			}
		}

		return null;
	}


	public static function save( string $level, string $target_key, string $kind, float $price, string $actor = '' ): bool {

		$target_key = self::normalize_key( $level, $target_key );

		if (
			'' === $target_key
			|| ! in_array( $level, self::levels(), true )
			|| ! in_array( $kind, self::kinds(), true )
			|| $price < 0
		) {
			return false;
		}

		global $wpdb;

		$now = current_time( 'mysql', true );

		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Database::table( 'scoped_fixed_fees' ) . '
					( level, target_key, fee_kind, price, updated_by, created_at, updated_at )
				VALUES ( %s, %s, %s, %f, %s, %s, %s )
				ON DUPLICATE KEY UPDATE
					price = VALUES(price),
					updated_by = VALUES(updated_by),
					updated_at = VALUES(updated_at)',
				$level,
				$target_key,
				$kind,
				$price,
				$actor,
				$now,
				$now
			)
		);

		self::$cache = null;

		return false !== $result;
	}


	public static function delete( string $level, string $target_key, string $kind ): bool {

		$target_key = self::normalize_key( $level, $target_key );

		if ( '' === $target_key ) {
			return false;
		}

		global $wpdb;

		$result = $wpdb->delete(
			Database::table( 'scoped_fixed_fees' ),
			[
				'level'      => $level,
				'target_key' => $target_key,
				'fee_kind'   => $kind,
			],
			[ '%s', '%s', '%s' ]
		);

		self::$cache = null;

		return false !== $result;
	}


	/**
	 * @return array<int,array{level:string,target_key:string,fee_kind:string,price:float}>
	 */
	public static function all(): array {

		return self::rows();
	}


	/*
	|--------------------------------------------------------------------------
	| Internals
	|--------------------------------------------------------------------------
	*/

	/**
	 * level => target_key => price for one fee kind, overlay applied.
	 *
	 * @param array<string,?float>|null $overlay
	 * @return array<string,array<string,float>>
	 */
	private static function map( string $kind, ?array $overlay ): array {

		$map = [];

		foreach ( self::rows() as $row ) {
			if ( $row['fee_kind'] === $kind ) {
				$map[ $row['level'] ][ $row['target_key'] ] = $row['price'];
			}
		}

		foreach ( $overlay ?? [] as $key => $value ) {

			[ $level, $target, $o_kind ] = array_pad( explode( '|', (string) $key, 3 ), 3, '' );

			if ( $o_kind !== $kind ) {
				continue;
			}

			if ( null === $value ) {
				unset( $map[ $level ][ $target ] );
			} else {
				$map[ $level ][ $target ] = max( 0.0, (float) $value );
			}
		}

		return $map;
	}

	/**
	 * @return array<int,array{level:string,target_key:string,fee_kind:string,price:float}>
	 */
	private static function rows(): array {

		if ( null !== self::$cache ) {
			return self::$cache;
		}

		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT level, target_key, fee_kind, price FROM ' . Database::table( 'scoped_fixed_fees' ) . '
			ORDER BY level ASC, target_key ASC, fee_kind ASC',
			ARRAY_A
		);

		self::$cache = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			self::$cache[] = [
				'level'      => (string) $row['level'],
				'target_key' => (string) $row['target_key'],
				'fee_kind'   => (string) $row['fee_kind'],
				'price'      => (float) $row['price'],
			];
		}

		return self::$cache;
	}

	/**
	 * @return array{price:float,level:string,label:string,target:string}
	 */
	private static function result( string $level, string $target, float $price, string $where ): array {

		return [
			'price'  => $price,
			'level'  => $level,
			'label'  => 'Fixed fee · ' . $where,
			'target' => $target,
		];
	}
}
