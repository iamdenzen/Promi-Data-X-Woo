<?php

namespace PromiDataXWoo\Core;

use PromiDataXWoo\Promi\Cron;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin deactivation.
 *
 * Deactivation must NEVER delete imported products, prices, print data,
 * configuration or custom database tables.
 */
final class Deactivator {

	public static function deactivate(): void {

		/**
		 * Stop our new Promi cron system.
		 */
		if ( class_exists( Cron::class ) ) {
			Cron::deactivate();
		}


		/**
		 * ------------------------------------------------------------------
		 * Legacy Cron Cleanup
		 * ------------------------------------------------------------------
		 *
		 * Your current CX Promi plugin uses these three hooks.
		 *
		 * Cleaning them here prevents orphaned events if the site is migrated
		 * from the old plugin architecture to Promi-Data X Woo.
		 */
		wp_clear_scheduled_hook( 'cx_promi_cron_index' );
		wp_clear_scheduled_hook( 'cx_promi_cron_worker' );
		wp_clear_scheduled_hook( 'cx_promi_cron_images' );


		do_action( 'pdxw_deactivated' );
	}
}