<?php

namespace PromiDataXWoo\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Promi-Data X Woo admin asset manager.
 *
 * Admin assets are loaded only on PDXW administration pages.
 *
 * Asset structure:
 *
 * assets/admin/
 * ├── css/
 * │   └── admin.css
 * │
 * └── js/
 *     └── admin.js
 *
 * The JavaScript handles the interactive Promi administration features:
 *
 * - Run indexer.
 * - Recheck queue.
 * - Run worker.
 * - Queue progress/status.
 * - Pause/resume cron.
 * - Process individual SKUs.
 * - Save Promi configuration.
 * - Manage ignored SKUs.
 * - Manage ignore rules.
 *
 * PricingPage and PrintingPage may also use this shared admin application.
 */
final class Assets {

	private const SCRIPT_HANDLE =
		'pdxw-admin';

	private const STYLE_HANDLE =
		'pdxw-admin';

	private const NONCE_ACTION =
		'pdxw_admin';

	private Menu $menu;

	private bool $initialized = false;


	public function __construct(Menu $menu) {
		$this->menu = $menu;
	}


	/**
	 * Register admin asset hooks.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;


		add_action(
			'admin_enqueue_scripts',
			[
				$this,
				'enqueue',
			]
		);


		do_action(
			'pdxw_admin_assets_init',
			$this
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Enqueue
	|--------------------------------------------------------------------------
	*/

	/**
	 * Enqueue assets for Promi-Data X Woo admin pages.
	 */
	public function enqueue(
		string $hook_suffix = ''
	): void {

		if (
			! $this->menu
				->is_current_page()
		) {
			return;
		}


		$this->enqueue_style();

		$this->enqueue_script();


		do_action(
			'pdxw_admin_assets_enqueued',
			$this->menu->current_page(),
			$hook_suffix
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Styles
	|--------------------------------------------------------------------------
	*/

	/**
	 * Enqueue shared PDXW admin CSS.
	 */
	private function enqueue_style(): void {

		$relative_path =
			'admin/css/admin.css';

		$file =
			PDXW_ASSETS_PATH
			. $relative_path;

		if (
			! is_readable(
				$file
			)
		) {
			return;
		}


		wp_enqueue_style(
			self::STYLE_HANDLE,
			PDXW_ASSETS_URL
				. $relative_path,
			[],
			$this->asset_version(
				$file
			)
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Scripts
	|--------------------------------------------------------------------------
	*/

	/**
	 * Enqueue shared PDXW admin JavaScript.
	 */
	private function enqueue_script(): void {

		$relative_path =
			'admin/js/admin.js';

		$file =
			PDXW_ASSETS_PATH
			. $relative_path;

		if (
			! is_readable(
				$file
			)
		) {
			return;
		}


		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			PDXW_ASSETS_URL
				. $relative_path,
			[
				'jquery',
			],
			$this->asset_version(
				$file
			),
			true
		);


		$this->localize();
	}


	/*
	|--------------------------------------------------------------------------
	| JavaScript Configuration
	|--------------------------------------------------------------------------
	*/

	/**
	 * Localize admin application configuration.
	 *
	 * The old cx-promi JavaScript relied directly on WordPress's global:
	 *
	 *     ajaxurl
	 *
	 * That remains available in wp-admin, but the rebuilt application gets
	 * an explicit configuration object as well.
	 */
	private function localize(): void {

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'pdxw_admin',
			[
				/*
				|--------------------------------------------------------------------------
				| AJAX
				|--------------------------------------------------------------------------
				*/

				'ajax_url' =>
					admin_url(
						'admin-ajax.php'
					),

				'nonce' =>
					wp_create_nonce(
						self::NONCE_ACTION
					),


				/*
				|--------------------------------------------------------------------------
				| Current Page
				|--------------------------------------------------------------------------
				*/

				'page' =>
					$this->menu
						->current_page(),


				/*
				|--------------------------------------------------------------------------
				| Admin URLs
				|--------------------------------------------------------------------------
				*/

				'urls' => [
					'dashboard' =>
						$this->menu->url(
							Menu::DASHBOARD_SLUG
						),

					'index' =>
						$this->menu->url(
							Menu::INDEX_SLUG
						),

					'queue' =>
						$this->menu->url(
							Menu::QUEUE_SLUG
						),

					'ignore_skus' =>
						$this->menu->url(
							Menu::IGNORE_SKUS_SLUG
						),

					'ignore_rules' =>
						$this->menu->url(
							Menu::IGNORE_RULES_SLUG
						),

					'pricing' =>
						$this->menu->url(
							Menu::PRICING_SLUG
						),

					'printing' =>
						$this->menu->url(
							Menu::PRINTING_SLUG
						),

					'inquiries' =>
						$this->menu->url(
							Menu::INQUIRIES_SLUG
						),
				],


				/*
				|--------------------------------------------------------------------------
				| AJAX Actions
				|--------------------------------------------------------------------------
				|
				| These are the action names we will register in Admin\Ajax.
				|
				| Keeping them localized means admin.js does not need action
				| names scattered throughout the source.
				*/

				'actions' => [
					'save_config' =>
						'pdxw_promi_config',

					'run_index' =>
						'pdxw_promi_index',

					'recheck_queue' =>
						'pdxw_promi_recheck_queue',

					'run_worker' =>
						'pdxw_promi_run_worker',

					'queue_stats' =>
						'pdxw_promi_queue_stats',

					'pause_cron' =>
						'pdxw_promi_pause_cron',

					'resume_cron' =>
						'pdxw_promi_resume_cron',

					'process_sku' =>
						'pdxw_promi_process_sku',

					'process_sku_now' =>
						'pdxw_promi_process_sku_now',

					'add_ignore_sku' =>
						'pdxw_promi_add_ignore_sku',

					'remove_ignore_sku' =>
						'pdxw_promi_remove_ignore_sku',

					'add_ignore_rule' =>
						'pdxw_promi_add_ignore_rule',

					'remove_ignore_rule' =>
						'pdxw_promi_remove_ignore_rule',

					'update_inquiry_status' =>
						'pdxw_update_inquiry_status',

					'delete_inquiry' =>
						'pdxw_delete_inquiry',
				],


				/*
				|--------------------------------------------------------------------------
				| UI Text
				|--------------------------------------------------------------------------
				*/

				'i18n' => [
					'saving' =>
						__(
							'Saving…',
							'promi-data-x-woo'
						),

					'saved' =>
						__(
							'Saved.',
							'promi-data-x-woo'
						),

					'processing' =>
						__(
							'Processing…',
							'promi-data-x-woo'
						),

					'done' =>
						__(
							'Done.',
							'promi-data-x-woo'
						),

					'failed' =>
						__(
							'Failed.',
							'promi-data-x-woo'
						),

					'error' =>
						__(
							'An unexpected error occurred.',
							'promi-data-x-woo'
						),

					'not_scheduled' =>
						__(
							'Not scheduled',
							'promi-data-x-woo'
						),

					'now' =>
						__(
							'Now',
							'promi-data-x-woo'
						),
				],
			]
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Versioning
	|--------------------------------------------------------------------------
	*/

	/**
	 * Use filemtime while developing so changed assets bypass browser cache.
	 *
	 * Plugin version remains the fallback.
	 */
	private function asset_version(
		string $file
	): string {

		$modified =
			@filemtime(
				$file
			);

		if ( $modified ) {
			return (string) $modified;
		}

		return PDXW_VERSION;
	}


	/*
	|--------------------------------------------------------------------------
	| Accessors
	|--------------------------------------------------------------------------
	*/

	public function nonce_action(): string {
		return self::NONCE_ACTION;
	}


	public function menu(): Menu {
		return $this->menu;
	}


	/*
	|--------------------------------------------------------------------------
	| State
	|--------------------------------------------------------------------------
	*/

	public function is_initialized(): bool {
		return $this->initialized;
	}
}
