<?php

namespace PromiDataXWoo\Pricing;

use PromiDataXWoo\Core\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Storage for markup levels beyond the legacy rules table:
 * supplier (SKU prefix), print category and product, for each price part.
 *
 * SQL lives here. Which level wins is decided in MarkupRules.
 */
final class ScopedRuleRepository {

	public const PART_ARTICLE     = 'article';
	public const PART_PRINT_PRICE = 'print_price';
	public const PART_PRINT_FEE   = 'print_fee';

	public const LEVEL_SUPPLIER = 'supplier';
	public const LEVEL_CATEGORY = 'category';
	public const LEVEL_PRODUCT  = 'product';

	/**
	 * Per-request cache of supplier rules, longest prefix first.
	 *
	 * @var array<string,array<int,array{prefix:string,markup:float}>>
	 */
	private array $supplier_cache = [];

	/**
	 * In-memory what-if rules used by previews: "part|level|key" => percent,
	 * or null for a rule that is removed. Never written to the database.
	 *
	 * @var array<string,?float>
	 */
	private array $overlay = [];


	public function with_overlay( array $overlay ): self {

		$clone                 = clone $this;
		$clone->overlay        = $overlay + $this->overlay; // new entries win
		$clone->supplier_cache = [];

		return $clone;
	}


	public static function parts(): array {

		return [
			self::PART_ARTICLE,
			self::PART_PRINT_PRICE,
			self::PART_PRINT_FEE,
		];
	}

	public static function levels(): array {

		return [
			self::LEVEL_SUPPLIER,
			self::LEVEL_CATEGORY,
			self::LEVEL_PRODUCT,
		];
	}


	public function get(
		string $part,
		string $level,
		string $target_key
	): ?float {

		$target_key = $this->normalize_key( $level, $target_key );

		if ( ! $this->valid( $part, $level ) || '' === $target_key ) {
			return null;
		}

		$overlay_key = "{$part}|{$level}|{$target_key}";

		if ( array_key_exists( $overlay_key, $this->overlay ) ) {
			return null === $this->overlay[ $overlay_key ]
				? null
				: max( 0.0, (float) $this->overlay[ $overlay_key ] );
		}

		global $wpdb;

		$table = Database::table( 'pricing_scoped_rules' );

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT markup_percent
				FROM {$table}
				WHERE part = %s
					AND level = %s
					AND target_key = %s
					AND enabled = 1
				LIMIT 1",
				$part,
				$level,
				$target_key
			)
		);

		return is_numeric( $value )
			? max( 0.0, (float) $value )
			: null;
	}


	public function save(
		string $part,
		string $level,
		string $target_key,
		float $markup_percent
	): bool {

		$target_key = $this->normalize_key( $level, $target_key );

		if ( ! $this->valid( $part, $level ) || '' === $target_key ) {
			return false;
		}

		global $wpdb;

		$table = Database::table( 'pricing_scoped_rules' );
		$now   = current_time( 'mysql', true );

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
					( part, level, target_key, markup_percent, enabled, created_at, updated_at )
				VALUES ( %s, %s, %s, %f, 1, %s, %s )
				ON DUPLICATE KEY UPDATE
					markup_percent = VALUES(markup_percent),
					enabled = 1,
					updated_at = VALUES(updated_at)",
				$part,
				$level,
				$target_key,
				max( 0.0, $markup_percent ),
				$now,
				$now
			)
		);

		$this->supplier_cache = [];

		return false !== $result;
	}


	public function delete(
		string $part,
		string $level,
		string $target_key
	): bool {

		$target_key = $this->normalize_key( $level, $target_key );

		if ( ! $this->valid( $part, $level ) || '' === $target_key ) {
			return false;
		}

		global $wpdb;

		$result = $wpdb->delete(
			Database::table( 'pricing_scoped_rules' ),
			[
				'part'       => $part,
				'level'      => $level,
				'target_key' => $target_key,
			],
			[ '%s', '%s', '%s' ]
		);

		$this->supplier_cache = [];

		return false !== $result;
	}


	/**
	 * Every enabled rule, optionally filtered.
	 *
	 * @return array<int,array{part:string,level:string,target_key:string,markup_percent:float}>
	 */
	public function all(
		string $part = '',
		string $level = ''
	): array {

		global $wpdb;

		$table = Database::table( 'pricing_scoped_rules' );

		$where  = [ 'enabled = 1' ];
		$values = [];

		if ( '' !== $part ) {
			$where[]  = 'part = %s';
			$values[] = $part;
		}

		if ( '' !== $level ) {
			$where[]  = 'level = %s';
			$values[] = $level;
		}

		$sql = "SELECT part, level, target_key, markup_percent
			FROM {$table}
			WHERE " . implode( ' AND ', $where ) . '
			ORDER BY level ASC, part ASC, target_key ASC';

		if ( $values ) {
			$sql = $wpdb->prepare( $sql, $values );
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		foreach ( $rows as &$row ) {
			$row['markup_percent'] = (float) $row['markup_percent'];
		}

		return $rows;
	}


	/**
	 * Supplier rules of one part, longest prefix first.
	 *
	 * @return array<int,array{prefix:string,markup:float}>
	 */
	public function supplier_rules( string $part ): array {

		if ( isset( $this->supplier_cache[ $part ] ) ) {
			return $this->supplier_cache[ $part ];
		}

		$by_prefix = [];

		foreach ( $this->all( $part, self::LEVEL_SUPPLIER ) as $row ) {
			$by_prefix[ $row['target_key'] ] = $row['markup_percent'];
		}

		foreach ( $this->overlay as $key => $value ) {

			[ $o_part, $o_level, $o_key ] = array_pad( explode( '|', $key, 3 ), 3, '' );

			if ( $o_part !== $part || self::LEVEL_SUPPLIER !== $o_level ) {
				continue;
			}

			if ( null === $value ) {
				unset( $by_prefix[ $o_key ] );
			} else {
				$by_prefix[ $o_key ] = max( 0.0, (float) $value );
			}
		}

		$rules = [];

		foreach ( $by_prefix as $prefix => $markup ) {
			$rules[] = [
				'prefix' => (string) $prefix,
				'markup' => (float) $markup,
			];
		}

		usort(
			$rules,
			static fn( $a, $b ) => strlen( $b['prefix'] ) <=> strlen( $a['prefix'] )
		);

		return $this->supplier_cache[ $part ] = $rules;
	}


	/**
	 * Longest matching supplier rule for a SKU.
	 *
	 * @return array{prefix:string,markup:float}|null
	 */
	public function match_supplier( string $part, string $sku ): ?array {

		$sku = strtoupper( trim( $sku ) );

		if ( '' === $sku ) {
			return null;
		}

		foreach ( $this->supplier_rules( $part ) as $rule ) {
			if ( str_starts_with( $sku, $rule['prefix'] ) ) {
				return $rule;
			}
		}

		return null;
	}


	/**
	 * Product ids whose SKU starts with a prefix.
	 *
	 * @return int[]
	 */
	public function products_for_prefix( string $prefix ): array {

		global $wpdb;

		$prefix = $this->normalize_key( self::LEVEL_SUPPLIER, $prefix );

		if ( '' === $prefix ) {
			return [];
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m
					ON m.post_id = p.ID AND m.meta_key = '_sku'
				WHERE p.post_type = 'product'
					AND p.post_status <> 'trash'
					AND UPPER(m.meta_value) LIKE %s",
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		return array_map( 'absint', $ids ?: [] );
	}


	private function valid( string $part, string $level ): bool {

		return in_array( $part, self::parts(), true )
			&& in_array( $level, self::levels(), true );
	}

	private function normalize_key( string $level, string $key ): string {

		$key = trim( sanitize_text_field( $key ) );

		if ( self::LEVEL_SUPPLIER === $level ) {
			return strtoupper( $key );
		}

		// Category and product levels are numeric ids.
		return $key !== '' && ctype_digit( $key ) && (int) $key > 0
			? (string) (int) $key
			: '';
	}
}
