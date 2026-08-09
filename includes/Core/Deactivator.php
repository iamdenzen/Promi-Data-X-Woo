<?php

namespace PromiDataXWoo\Core;

use PromiDataXWoo\Promi\Cron;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin deactivation handler.
 *
 * Responsibilities:
 *
 * - Stop scheduled Promi background jobs.
 * - Flush rewrite rules.
 *
 * Deactivation intentionally preserves:
 *
 * - Imported WooCommerce products and variations.
 * - Promi index and queue data.
 * - Tier pricing.
 * - Printing data.
 * - Ignore rules.
 * - Plugin settings.
 * - Media attachments.
 *
 * Permanent data removal, if ever required, belongs in uninstall.php and
 * should be explicit rather than happening during normal deactivation.
 */
final class Deactivator {

	/**
	 * Run plugin deactivation.
	 */
	public static function deactivate(): void {

		/*
		|--------------------------------------------------------------------------
		| Promi Cron
		|--------------------------------------------------------------------------
		*/

		Cron::deactivate();


		/*
		|--------------------------------------------------------------------------
		| Rewrite Rules
		|--------------------------------------------------------------------------
		*/

		flush_rewrite_rules();


		do_action(
			'pdxw_deactivated'
		);
	}
}