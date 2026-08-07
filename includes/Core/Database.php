<?php

namespace PromiDataXWoo\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Central database manager.
 *
 * All custom tables used by Promi-Data X Woo are owned here.
 *
 * For the initial rebuild we intentionally preserve the existing physical
 * `cx_*` table names so current XSImpress data can be reused without migration.
 */
final class Database {

	public const VERSION        = '1.0.0';
	public const VERSION_OPTION = 'pdxw_db_version';

	/**
	 * Return a custom table name.
	 */
	public static function table( string $table ): string {

		global $wpdb;

		$tables = [
			// Promi.
			'promi_index'        => 'cx_promi_index',
			'promi_queue'        => 'cx_promi_queue',
			'promi_ignore_skus'  => 'cx_promi_ignore_skus',
			'promi_ignore_rules' => 'cx_promi_ignore_rules',

			// Product pricing.
			'tier_prices'        => 'cx_tier_prices',

			// Printing.
			'print_positions'    => 'cx_print_positions',
			'print_options'      => 'cx_print_options',
			'print_prices'       => 'cx_print_prices',
			'print_fees'         => 'cx_print_fees',
			'print_relation'     => 'cx_print_relation',
		];

		if ( ! isset( $tables[ $table ] ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					'Unknown Promi-Data X Woo database table: %s',
					$table
				)
			);
		}

		return $wpdb->prefix . $tables[ $table ];
	}


	/**
	 * Install or update all custom tables.
	 *
	 * dbDelta makes this safe to run repeatedly.
	 */
	public static function install(): void {

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		/*
		 * ------------------------------------------------------------------
		 * Promi Index
		 * ------------------------------------------------------------------
		 *
		 * Local representation of the Promi feed index.
		 *
		 * sku      = Promi product identifier
		 * hash     = remote version/hash
		 * json_url = URL to full product JSON
		 */
		$table = self::table( 'promi_index' );

		$sql = "CREATE TABLE {$table} (
			sku varchar(100) NOT NULL,
			hash char(40) DEFAULT NULL,
			json_url text DEFAULT NULL,
			last_seen datetime DEFAULT NULL,

			PRIMARY KEY  (sku),
			KEY hash_idx (hash),
			KEY last_seen_idx (last_seen)
		) {$charset};";

		dbDelta( $sql );


		/*
		 * ------------------------------------------------------------------
		 * Promi Queue
		 * ------------------------------------------------------------------
		 */
		$table = self::table( 'promi_queue' );

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
			KEY claim_token_idx (claim_token)
		) {$charset};";

		dbDelta( $sql );


		/*
		 * ------------------------------------------------------------------
		 * Explicitly Ignored SKUs
		 * ------------------------------------------------------------------
		 */
		$table = self::table( 'promi_ignore_skus' );

		$sql = "CREATE TABLE {$table} (
			sku varchar(100) NOT NULL,
			reason text DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,

			PRIMARY KEY  (sku)
		) {$charset};";

		dbDelta( $sql );


		/*
		 * ------------------------------------------------------------------
		 * Promi Ignore Rules
		 * ------------------------------------------------------------------
		 *
		 * Used for selectively ignoring pieces of incoming Promi data.
		 */
		$table = self::table( 'promi_ignore_rules' );

		$sql = "CREATE TABLE {$table} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			sku varchar(100) DEFAULT NULL,
			type varchar(20) NOT NULL,
			field_key varchar(100) NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,

			PRIMARY KEY  (id),
			UNIQUE KEY unique_rule (sku, type, field_key),
			KEY sku_idx (sku),
			KEY type_key_idx (type, field_key)
		) {$charset};";

		dbDelta( $sql );


		/*
		 * ------------------------------------------------------------------
		 * Product / Variation Tier Pricing
		 * ------------------------------------------------------------------
		 */
		$table = self::table( 'tier_prices' );

		$sql = "CREATE TABLE {$table} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint unsigned NOT NULL,
			variation_id bigint unsigned NOT NULL DEFAULT 0,
			qty int unsigned NOT NULL,
			price decimal(12,4) NOT NULL,
			purchase_price decimal(12,4) DEFAULT NULL,

			PRIMARY KEY  (id),
			UNIQUE KEY unique_tier (product_id, variation_id, qty),
			KEY product_variation_idx (product_id, variation_id),
			KEY variation_idx (variation_id)
		) {$charset};";

		dbDelta( $sql );


		/*
		 * ------------------------------------------------------------------
		 * Print Positions
		 * ------------------------------------------------------------------
		 */
		$table = self::table( 'print_positions' );

		$sql = "CREATE TABLE {$table} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint unsigned NOT NULL,
			variation_id bigint unsigned NOT NULL DEFAULT 0,
			position_code varchar(50) NOT NULL,
			position_label varchar(255) NOT NULL,
			area varchar(50) DEFAULT '',
			image bigint unsigned NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,

			PRIMARY KEY  (id),
			UNIQUE KEY product_position_unique (
				product_id,
				variation_id,
				position_code
			),
			KEY product_idx (product_id),
			KEY variation_idx (variation_id),
			KEY code_idx (position_code)
		) {$charset};";

		dbDelta( $sql );


		/*
		 * ------------------------------------------------------------------
		 * Print Options
		 * ------------------------------------------------------------------
		 *
		 * Unlike positions these are globally reusable.
		 */
		$table = self::table( 'print_options' );

		$sql = "CREATE TABLE {$table} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			sku varchar(100) DEFAULT '',
			name varchar(255) NOT NULL,
			max_colors int unsigned NOT NULL DEFAULT 0,
			min_order_qty int unsigned NOT NULL DEFAULT 1,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,

			PRIMARY KEY  (id),
			KEY sku_idx (sku)
		) {$charset};";

		dbDelta( $sql );


		/*
		 * ------------------------------------------------------------------
		 * Print Tier Pricing
		 * ------------------------------------------------------------------
		 */
		$table = self::table( 'print_prices' );

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
		 * ------------------------------------------------------------------
		 * Print Fees
		 * ------------------------------------------------------------------
		 *
		 * Examples:
		 * - setup fees
		 * - handling fees
		 * - multiplied fees
		 * - conditional fees
		 */
		$table = self::table( 'print_fees' );

		$sql = "CREATE TABLE {$table} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			print_option_id bigint unsigned NOT NULL,
			fee_label varchar(100) DEFAULT NULL,
			fee_type varchar(20) NOT NULL,
			calculation varchar(20) NOT NULL,
			calculation_type varchar(100) DEFAULT NULL,
			calculation_amount decimal(12,4) DEFAULT NULL,
			requirement longtext DEFAULT NULL,
			amount decimal(12,4) NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,

			PRIMARY KEY  (id),
			KEY option_idx (print_option_id)
		) {$charset};";

		dbDelta( $sql );


		/*
		 * ------------------------------------------------------------------
		 * Product / Position / Print Option Relations
		 * ------------------------------------------------------------------
		 */
		$table = self::table( 'print_relation' );

		$sql = "CREATE TABLE {$table} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint unsigned NOT NULL,
			variation_id bigint unsigned NOT NULL DEFAULT 0,
			print_option_id bigint unsigned NOT NULL,
			print_position_id bigint unsigned NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,

			PRIMARY KEY  (id),
			UNIQUE KEY relation_unique (
				product_id,
				variation_id,
				print_option_id,
				print_position_id
			),
			KEY product_idx (product_id),
			KEY variation_idx (variation_id),
			KEY option_idx (print_option_id),
			KEY position_idx (print_position_id)
		) {$charset};";

		dbDelta( $sql );


		update_option( self::VERSION_OPTION, self::VERSION );
	}


	/**
	 * Run database upgrades when required.
	 */
	public static function maybe_upgrade(): void {

		$installed = get_option( self::VERSION_OPTION );

		if ( self::VERSION !== $installed ) {
			self::install();
		}
	}


	/**
	 * Check whether all required tables exist.
	 */
	public static function tables_exist(): bool {

		global $wpdb;

		$tables = [
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

		foreach ( $tables as $table ) {

			$name = self::table( $table );

			$exists = $wpdb->get_var(
				$wpdb->prepare(
					'SHOW TABLES LIKE %s',
					$name
				)
			);

			if ( $exists !== $name ) {
				return false;
			}
		}

		return true;
	}
}