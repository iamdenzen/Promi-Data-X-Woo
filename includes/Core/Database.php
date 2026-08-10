<?php

namespace PromiDataXWoo\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Central database manager.
 *
 * Owns every custom table used by Promi-Data X Woo.
 *
 * Existing physical cx_* table names are intentionally preserved so the
 * rebuilt plugin can continue using the current XSImpress production data
 * without requiring an unnecessary table-name migration.
 */
final class Database {

	public const VERSION = '1.1.0';

	public const VERSION_OPTION = 'pdxw_db_version';


	/**
	 * Resolve one logical table name into its WordPress-prefixed table.
	 */
	public static function table(
		string $table
	): string {

		global $wpdb;

		$tables = [

			/*
			|--------------------------------------------------------------------------
			| Promi
			|--------------------------------------------------------------------------
			*/

			'promi_index' =>
				'cx_promi_index',

			'promi_queue' =>
				'cx_promi_queue',

			'promi_ignore_skus' =>
				'cx_promi_ignore_skus',

			'promi_ignore_rules' =>
				'cx_promi_ignore_rules',


			/*
			|--------------------------------------------------------------------------
			| Product Pricing
			|--------------------------------------------------------------------------
			*/

			'tier_prices' =>
				'cx_tier_prices',


			/*
			|--------------------------------------------------------------------------
			| Printing
			|--------------------------------------------------------------------------
			*/

			'print_positions' =>
				'cx_print_positions',

			'print_options' =>
				'cx_print_options',

			'print_prices' =>
				'cx_print_prices',

			'print_fees' =>
				'cx_print_fees',

			'print_relation' =>
				'cx_print_relation',
		];

		if ( ! isset( $tables[ $table ] ) ) {

			throw new \InvalidArgumentException(
				sprintf(
					'Unknown Promi-Data X Woo database table: %s',
					$table
				)
			);
		}

		return $wpdb->prefix
			. $tables[ $table ];
	}


	/**
	 * Install or upgrade every custom table.
	 *
	 * WordPress dbDelta() allows this method to safely handle both a new
	 * installation and upgrades from the existing XSImpress schemas.
	 */
	public static function install(): void {

		global $wpdb;

		require_once ABSPATH
			. 'wp-admin/includes/upgrade.php';

		$charset =
			$wpdb->get_charset_collate();


		/*
		|--------------------------------------------------------------------------
		| Promi Index
		|--------------------------------------------------------------------------
		|
		| Stores the lightweight Promi feed index.
		|
		| sku
		|     Parent Promi SKU.
		|
		| hash
		|     Remote product version/hash.
		|
		| json_url
		|     URL to the complete Promi JSON product document.
		|
		| last_seen
		|     Last successful feed index where this SKU was present.
		*/

		$table = self::table(
			'promi_index'
		);

		$sql = "CREATE TABLE {$table} (
			sku varchar(100) NOT NULL,
			hash varchar(100) DEFAULT NULL,
			json_url text DEFAULT NULL,
			last_seen datetime DEFAULT NULL,
			PRIMARY KEY  (sku),
			KEY hash_idx (hash),
			KEY last_seen_idx (last_seen)
		) {$charset};";

		dbDelta( $sql );


		/*
		|--------------------------------------------------------------------------
		| Promi Queue
		|--------------------------------------------------------------------------
		|
		| Queue jobs are persistent and retryable.
		|
		| available_at
		|     Allows exponential retry delay.
		|
		| claimed_at / claim_token
		|     Allows a worker to claim jobs without another worker processing
		|     the same rows.
		|
		| attempts
		|     Number of worker attempts.
		|
		| last_error
		|     Most recent processing error for admin/debugging purposes.
		*/

		$table = self::table(
			'promi_queue'
		);

		$sql = "CREATE TABLE {$table} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			sku varchar(100) NOT NULL,
			action varchar(20) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts int unsigned NOT NULL DEFAULT 0,
			available_at datetime DEFAULT NULL,
			claimed_at datetime DEFAULT NULL,
			claim_token varchar(64) DEFAULT NULL,
			last_attempt datetime DEFAULT NULL,
			last_error text DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY sku_status_idx (sku, status),
			KEY status_available_idx (status, available_at),
			KEY claim_token_idx (claim_token),
			KEY claimed_at_idx (claimed_at)
		) {$charset};";

		dbDelta( $sql );


		/*
		|--------------------------------------------------------------------------
		| Ignored Promi SKUs
		|--------------------------------------------------------------------------
		|
		| Products in this table are excluded from Promi queue processing.
		*/

		$table = self::table(
			'promi_ignore_skus'
		);

		$sql = "CREATE TABLE {$table} (
			sku varchar(100) NOT NULL,
			reason text DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (sku)
		) {$charset};";

		dbDelta( $sql );


		/*
		|--------------------------------------------------------------------------
		| Promi Field Ignore Rules
		|--------------------------------------------------------------------------
		|
		| sku = NULL:
		|     Global rule.
		|
		| sku populated:
		|     Rule applies only to that product.
		|
		| These rules existed in the previous Promi plugin, although the old
		| ProductSync did not consistently consume them.
		*/

		$table = self::table(
			'promi_ignore_rules'
		);

		$sql = "CREATE TABLE {$table} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			sku varchar(100) DEFAULT NULL,
			type varchar(50) NOT NULL,
			field_key varchar(191) NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY sku_idx (sku),
			KEY type_field_idx (type, field_key),
			KEY sku_type_field_idx (sku, type, field_key)
		) {$charset};";

		dbDelta( $sql );


		/*
		|--------------------------------------------------------------------------
		| WooCommerce Product / Variation Tier Pricing
		|--------------------------------------------------------------------------
		|
		| variation_id = 0 can represent parent-level prices.
		|
		| purchase_price is intentionally nullable because Promi does not
		| provide buying prices for every product/tier.
		*/

		$table = self::table(
			'tier_prices'
		);

		$sql = "CREATE TABLE {$table} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint unsigned NOT NULL,
			variation_id bigint unsigned NOT NULL DEFAULT 0,
			qty int unsigned NOT NULL,
			price decimal(12,4) NOT NULL,
			purchase_price decimal(12,4) DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY product_variation_qty_unique (product_id, variation_id, qty),
			KEY product_variation_idx (product_id, variation_id),
			KEY variation_idx (variation_id),
			KEY qty_idx (qty)
		) {$charset};";

		dbDelta( $sql );


		/*
		|--------------------------------------------------------------------------
		| Print Positions
		|--------------------------------------------------------------------------
		|
		| Print positions belong to one product and optionally one variation.
		|
		| position_code is the Promi position identifier.
		*/

		$table = self::table(
			'print_positions'
		);

		$sql = "CREATE TABLE {$table} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint unsigned NOT NULL,
			variation_id bigint unsigned NOT NULL DEFAULT 0,
			position_code varchar(100) NOT NULL,
			position_label varchar(255) NOT NULL,
			area varchar(255) DEFAULT '',
			image bigint unsigned NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY product_variation_position_unique (product_id, variation_id, position_code),
			KEY product_idx (product_id),
			KEY variation_idx (variation_id),
			KEY position_code_idx (position_code)
		) {$charset};";

		dbDelta( $sql );


		/*
		|--------------------------------------------------------------------------
		| Global Print Options
		|--------------------------------------------------------------------------
		|
		| These are reusable print methods imported from Promi
		| ImprintReferences.
		*/

		$table = self::table(
			'print_options'
		);

		$sql = "CREATE TABLE {$table} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			sku varchar(100) DEFAULT '',
			name varchar(255) NOT NULL,
			max_colors int unsigned NOT NULL DEFAULT 0,
			min_order_qty int unsigned NOT NULL DEFAULT 1,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY sku_idx (sku),
			KEY name_idx (name)
		) {$charset};";

		dbDelta( $sql );


		/*
		|--------------------------------------------------------------------------
		| Print Tier Prices
		|--------------------------------------------------------------------------
		|
		| Each global print option can have selling and optional buying prices
		| at multiple quantity tiers.
		*/

		$table = self::table(
			'print_prices'
		);

		$sql = "CREATE TABLE {$table} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			print_option_id bigint unsigned NOT NULL,
			min_qty int unsigned NOT NULL,
			price decimal(12,4) NOT NULL,
			purchase_price decimal(12,4) DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY option_qty_unique (print_option_id, min_qty),
			KEY option_idx (print_option_id),
			KEY qty_idx (min_qty)
		) {$charset};";

		dbDelta( $sql );


		/*
		|--------------------------------------------------------------------------
		| Print Fees
		|--------------------------------------------------------------------------
		|
		| Important:
		|
		| The previous CX Print runtime reads/writes purchase_amount even
		| though one version of its installer failed to create that column.
		|
		| The rebuilt plugin explicitly includes it.
		*/

		$table = self::table(
			'print_fees'
		);

		$sql = "CREATE TABLE {$table} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			print_option_id bigint unsigned NOT NULL,
			fee_label varchar(255) DEFAULT NULL,
			fee_type varchar(50) NOT NULL,
			calculation varchar(50) NOT NULL,
			calculation_type varchar(191) DEFAULT NULL,
			calculation_amount decimal(12,4) DEFAULT NULL,
			requirement longtext DEFAULT NULL,
			amount decimal(12,4) NOT NULL,
			purchase_amount decimal(12,4) DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY option_idx (print_option_id),
			KEY fee_type_idx (fee_type)
		) {$charset};";

		dbDelta( $sql );


		/*
		|--------------------------------------------------------------------------
		| Print Relations
		|--------------------------------------------------------------------------
		|
		| Connects:
		|
		| product
		|     ↓
		| variation
		|     ↓
		| position
		|     ↓
		| global print option
		*/

		$table = self::table(
			'print_relation'
		);

		$sql = "CREATE TABLE {$table} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint unsigned NOT NULL,
			variation_id bigint unsigned NOT NULL DEFAULT 0,
			print_option_id bigint unsigned NOT NULL,
			print_position_id bigint unsigned NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY relation_unique (product_id, variation_id, print_option_id, print_position_id),
			KEY product_idx ( product_id ),
			KEY variation_idx ( variation_id ),
			KEY option_idx ( print_option_id ),
			KEY position_idx ( print_position_id ),
			KEY product_variation_idx ( product_id, variation_id )
		) {$charset};";

		dbDelta( $sql );


		/*
		|--------------------------------------------------------------------------
		| Database Version
		|--------------------------------------------------------------------------
		*/

		update_option(
			self::VERSION_OPTION,
			self::VERSION,
			false
		);
	}


	/**
	 * Run schema upgrades when the installed database version differs from
	 * the current plugin schema version.
	 */
	public static function maybe_upgrade(): void {

		$installed = get_option(
			self::VERSION_OPTION,
			''
		);

		if (
			self::VERSION
			=== $installed
		) {
			return;
		}

		self::install();
	}


	/**
	 * Verify that all required custom tables exist.
	 */
	public static function tables_exist(): bool {

		global $wpdb;

		foreach (
			self::logical_tables()
				as $logical_table
		) {

			$table =
				self::table(
					$logical_table
				);

			$exists =
				$wpdb->get_var(
					$wpdb->prepare(
						'SHOW TABLES LIKE %s',
						$table
					)
				);

			if ( $table !== $exists ) {
				return false;
			}
		}

		return true;
	}


	/**
	 * Return missing logical tables.
	 *
	 * Useful for future diagnostics/admin health checks.
	 */
	public static function missing_tables(): array {

		global $wpdb;

		$missing = [];

		foreach (
			self::logical_tables()
				as $logical_table
		) {

			$table =
				self::table(
					$logical_table
				);

			$exists =
				$wpdb->get_var(
					$wpdb->prepare(
						'SHOW TABLES LIKE %s',
						$table
					)
				);

			if ( $table !== $exists ) {
				$missing[] =
					$logical_table;
			}
		}

		return $missing;
	}


	/**
	 * Return the logical names of every table owned by the plugin.
	 */
	public static function logical_tables(): array {

		return [
			'promi_index',
			'promi_queue',
			'promi_ignore_skus',
			'promi_ignore_rules',
			'tier_prices',
			'print_positions',
			'print_options',
			'print_prices',
			'print_fees',
			'print_relation',
		];
	}
}