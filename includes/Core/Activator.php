<?php

namespace PromiDataXWoo\Core;

use PromiDataXWoo\Promi\Cron;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin activation.
 */
final class Activator {

	public static function activate(): void {

		/**
		 * ------------------------------------------------------------------
		 * Database
		 * ------------------------------------------------------------------
		 */
		Database::install();


		/**
		 * ------------------------------------------------------------------
		 * Plugin Metadata
		 * ------------------------------------------------------------------
		 */
		if ( ! get_option( 'pdxw_installed_at' ) ) {
			update_option(
				'pdxw_installed_at',
				current_time( 'mysql', true ),
				false
			);
		}

		update_option(
			'pdxw_version',
			PDXW_VERSION,
			false
		);


		/**
		 * ------------------------------------------------------------------
		 * Cron
		 * ------------------------------------------------------------------
		 *
		 * Cron.php will be built when we work on the Promi module.
		 *
		 * class_exists() keeps activation safe until that module exists.
		 */
		if ( class_exists( Cron::class ) ) {
			wp_clear_scheduled_hook( 'cx_promi_cron_index' );
			wp_clear_scheduled_hook( 'cx_promi_cron_worker' );
			wp_clear_scheduled_hook( 'cx_promi_cron_images' );

			Cron::activate();
		}


		/**
		 * Allow future modules to perform activation work without bloating
		 * this class.
		 */
		do_action( 'pdxw_activated' );
	}
}