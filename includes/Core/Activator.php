<?php

namespace PromiDataXWoo\Core;

use PromiDataXWoo\Promi\Cron;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin activation handler.
 *
 * Responsibilities:
 *
 * - Install/update the custom database schema.
 * - Record plugin installation/version information.
 * - Schedule Promi background processing.
 *
 * Activation must never import products or perform expensive Promi network
 * requests directly.
 */
final class Activator {

	/**
	 * Run plugin activation.
	 */
	public static function activate(): void {

		self::validate_environment();

		/*
		|--------------------------------------------------------------------------
		| Database
		|--------------------------------------------------------------------------
		*/

		Database::install();


		/*
		|--------------------------------------------------------------------------
		| Installation Metadata
		|--------------------------------------------------------------------------
		*/

		if (
			false === get_option(
				'pdxw_installed_at',
				false
			)
		) {

			add_option(
				'pdxw_installed_at',
				current_time(
					'mysql',
					true
				),
				'',
				false
			);
		}

		update_option(
			'pdxw_version',
			PDXW_VERSION,
			false
		);


		/*
		|--------------------------------------------------------------------------
		| Promi Cron
		|--------------------------------------------------------------------------
		|
		| Scheduling only.
		|
		| We deliberately do not run the feed index, queue worker, or image
		| worker during activation. Those jobs can involve substantial remote
		| and WooCommerce processing and belong in background execution.
		*/

		Cron::activate();


		/*
		|--------------------------------------------------------------------------
		| Rewrite Rules
		|--------------------------------------------------------------------------
		|
		| Currently the plugin does not register its own rewrite endpoints,
		| but flushing once on activation keeps this class ready for frontend
		| routes without performing the expensive operation on every request.
		*/

		flush_rewrite_rules();


		do_action(
			'pdxw_activated'
		);
	}


	/**
	 * Validate minimum runtime requirements.
	 *
	 * The root plugin bootstrap already checks these conditions, but keeping
	 * activation safe here makes the activator usable independently as well.
	 */
	private static function validate_environment(): void {

		if (
			version_compare(
				PHP_VERSION,
				'8.0',
				'<'
			)
		) {

			wp_die(
				esc_html__(
					'Promi-Data X Woo requires PHP 8.0 or newer.',
					'promi-data-x-woo'
				)
			);
		}


		if (
			! class_exists(
				'WooCommerce'
			)
		) {

			wp_die(
				esc_html__(
					'Promi-Data X Woo requires WooCommerce to be installed and active.',
					'promi-data-x-woo'
				)
			);
		}
	}
}