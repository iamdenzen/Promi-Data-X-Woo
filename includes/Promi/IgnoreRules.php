<?php

namespace PromiDataXWoo\Promi;

use PromiDataXWoo\Core\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Promi ignore rules.
 *
 * Supports:
 *
 * 1. Complete SKU exclusions.
 *
 * 2. Field-level exclusions.
 *    - Global rule: SKU is NULL.
 *    - SKU-specific rule: SKU contains a product SKU.
 *
 * Rules are cached in memory for the duration of the request because they
 * may be checked repeatedly during large Promi import batches.
 */
final class IgnoreRules {

	/**
	 * Ignored SKUs keyed by SKU.
	 *
	 * @var array<string,bool>|null
	 */
	private static ?array $ignored_skus = null;

	/**
	 * Loaded field rules.
	 *
	 * Structure:
	 *
	 * [
	 *     'global' => [
	 *         'meta' => [
	 *             '_price' => true,
	 *         ],
	 *     ],
	 *
	 *     'sku' => [
	 *         'ABC123' => [
	 *             'taxonomy' => [
	 *                 'product_cat' => true,
	 *             ],
	 *         ],
	 *     ],
	 * ]
	 *
	 * @var array|null
	 */
	private static ?array $rules = null;


	/*
	|--------------------------------------------------------------------------
	| SKU Rules
	|--------------------------------------------------------------------------
	*/

	/**
	 * Check whether an entire SKU should be ignored.
	 */
	public static function is_sku_ignored(
		string $sku
	): bool {

		$sku = trim(
			$sku
		);

		if ( '' === $sku ) {
			return false;
		}

		self::load_skus();

		return isset(
			self::$ignored_skus[
				$sku
			]
		);
	}


	/**
	 * Add or update an ignored SKU.
	 */
	public static function add_sku(
		string $sku,
		string $reason = ''
	): bool {

		global $wpdb;

		$sku = sanitize_text_field(
			$sku
		);

		$reason = sanitize_text_field(
			$reason
		);

		if ( '' === $sku ) {
			return false;
		}

		$result = $wpdb->replace(
			Database::table(
				'promi_ignore_skus'
			),
			[
				'sku' =>
					$sku,

				'reason' =>
					$reason,

				'created_at' =>
					current_time(
						'mysql'
					),
			],
			[
				'%s',
				'%s',
				'%s',
			]
		);

		if ( false === $result ) {
			return false;
		}

		self::reset_cache();

		return true;
	}


	/**
	 * Remove an ignored SKU.
	 */
	public static function remove_sku(
		string $sku
	): bool {

		global $wpdb;

		$sku = sanitize_text_field(
			$sku
		);

		if ( '' === $sku ) {
			return false;
		}

		$result = $wpdb->delete(
			Database::table(
				'promi_ignore_skus'
			),
			[
				'sku' =>
					$sku,
			],
			[
				'%s',
			]
		);

		if ( false === $result ) {
			return false;
		}

		self::reset_cache();

		return true;
	}


	/**
	 * Retrieve ignored SKUs.
	 */
	public static function get_ignored_skus(): array {

		global $wpdb;

		return $wpdb->get_results(
			'SELECT
				sku,
				reason,
				created_at
			FROM '
			. Database::table(
				'promi_ignore_skus'
			)
			. '
			ORDER BY sku ASC',
			ARRAY_A
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Field Rules
	|--------------------------------------------------------------------------
	*/

	/**
	 * Check whether a field should be ignored.
	 *
	 * SKU-specific rules take precedence, followed by global rules.
	 *
	 * Examples:
	 *
	 * Ignore category for every product:
	 *
	 * is_field_ignored(
	 *     'ABC123',
	 *     'taxonomy',
	 *     'product_cat'
	 * );
	 *
	 * Ignore price only for one SKU:
	 *
	 * is_field_ignored(
	 *     'ABC123',
	 *     'meta',
	 *     '_price'
	 * );
	 */
	public static function is_field_ignored(
		string $sku,
		string $type,
		string $field_key
	): bool {

		$sku = trim(
			$sku
		);

		$type = sanitize_key(
			$type
		);

		$field_key = sanitize_text_field(
			$field_key
		);

		if (
			'' === $type
			|| '' === $field_key
		) {
			return false;
		}

		self::load_rules();


		/*
		|--------------------------------------------------------------------------
		| SKU-specific rule
		|--------------------------------------------------------------------------
		*/

		if (
			'' !== $sku
			&& isset(
				self::$rules
					['sku']
					[$sku]
					[$type]
					[$field_key]
			)
		) {
			return true;
		}


		/*
		|--------------------------------------------------------------------------
		| Global rule
		|--------------------------------------------------------------------------
		*/

		return isset(
			self::$rules
				['global']
				[$type]
				[$field_key]
		);
	}


	/**
	 * Add an ignore rule.
	 *
	 * Empty SKU means the rule applies globally.
	 */
	public static function add_rule(
		string $type,
		string $field_key,
		string $sku = ''
	): bool {

		global $wpdb;

		$sku = sanitize_text_field(
			$sku
		);

		$type = sanitize_key(
			$type
		);

		$field_key = sanitize_text_field(
			$field_key
		);

		if (
			'' === $type
			|| '' === $field_key
		) {
			return false;
		}

		$table = Database::table(
			'promi_ignore_rules'
		);


		/*
		 * MySQL UNIQUE indexes allow multiple NULL values, so we explicitly
		 * check for an existing global/SKU rule before inserting.
		 */
		if (
			self::rule_exists(
				$type,
				$field_key,
				$sku
			)
		) {
			return true;
		}

		$result = $wpdb->insert(
			$table,
			[
				'sku' =>
					'' !== $sku
						? $sku
						: null,

				'type' =>
					$type,

				'field_key' =>
					$field_key,

				'created_at' =>
					current_time(
						'mysql'
					),
			],
			[
				'%s',
				'%s',
				'%s',
				'%s',
			]
		);

		if ( false === $result ) {
			return false;
		}

		self::reset_cache();

		return true;
	}


	/**
	 * Remove a field rule.
	 */
	public static function remove_rule(
		int $rule_id
	): bool {

		global $wpdb;

		$rule_id = absint(
			$rule_id
		);

		if ( ! $rule_id ) {
			return false;
		}

		$result = $wpdb->delete(
			Database::table(
				'promi_ignore_rules'
			),
			[
				'id' =>
					$rule_id,
			],
			[
				'%d',
			]
		);

		if ( false === $result ) {
			return false;
		}

		self::reset_cache();

		return true;
	}


	/**
	 * Retrieve all field rules.
	 */
	public static function get_rules(): array {

		global $wpdb;

		return $wpdb->get_results(
			'SELECT
				id,
				sku,
				type,
				field_key,
				created_at
			FROM '
			. Database::table(
				'promi_ignore_rules'
			)
			. '
			ORDER BY
				type ASC,
				field_key ASC,
				sku ASC',
			ARRAY_A
		);
	}


	/**
	 * Determine whether an exact rule exists.
	 */
	private static function rule_exists(
		string $type,
		string $field_key,
		string $sku
	): bool {

		global $wpdb;

		$table = Database::table(
			'promi_ignore_rules'
		);

		if ( '' === $sku ) {

			$id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id
					FROM {$table}
					WHERE sku IS NULL
						AND type = %s
						AND field_key = %s
					LIMIT 1",
					$type,
					$field_key
				)
			);

		} else {

			$id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id
					FROM {$table}
					WHERE sku = %s
						AND type = %s
						AND field_key = %s
					LIMIT 1",
					$sku,
					$type,
					$field_key
				)
			);
		}

		return null !== $id;
	}


	/*
	|--------------------------------------------------------------------------
	| Cache
	|--------------------------------------------------------------------------
	*/

	/**
	 * Load ignored SKUs into memory.
	 */
	private static function load_skus(): void {

		if ( null !== self::$ignored_skus ) {
			return;
		}

		global $wpdb;

		$rows = $wpdb->get_col(
			'SELECT sku
			FROM '
			. Database::table(
				'promi_ignore_skus'
			)
		);

		self::$ignored_skus = [];

		foreach ( $rows as $sku ) {

			$sku = (string) $sku;

			if ( '' !== $sku ) {
				self::$ignored_skus[
					$sku
				] = true;
			}
		}
	}


	/**
	 * Load field-level rules into memory.
	 */
	private static function load_rules(): void {

		if ( null !== self::$rules ) {
			return;
		}

		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT
				sku,
				type,
				field_key
			FROM '
			. Database::table(
				'promi_ignore_rules'
			),
			ARRAY_A
		);

		$rules = [
			'global' =>
				[],

			'sku' =>
				[],
		];

		foreach ( $rows as $row ) {

			$sku = trim(
				(string) (
					$row['sku']
					?? ''
				)
			);

			$type = sanitize_key(
				$row['type']
				?? ''
			);

			$field_key =
				(string) (
					$row['field_key']
					?? ''
				);

			if (
				'' === $type
				|| '' === $field_key
			) {
				continue;
			}

			if ( '' === $sku ) {

				$rules
					['global']
					[$type]
					[$field_key] = true;

				continue;
			}

			$rules
				['sku']
				[$sku]
				[$type]
				[$field_key] = true;
		}

		self::$rules = $rules;
	}


	/**
	 * Clear request-level rule caches.
	 */
	public static function reset_cache(): void {

		self::$ignored_skus = null;
		self::$rules        = null;
	}
}