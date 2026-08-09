<?php

namespace PromiDataXWoo\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Promi-Data X Woo admin navigation.
 *
 * Creates one unified WordPress admin tree:
 *
 * Promi-Data X Woo
 * ├── Dashboard
 * ├── Promi Index
 * ├── Queue
 * ├── Ignore SKUs
 * ├── Ignore Rules
 * ├── Tier Pricing
 * └── Printing
 *
 * Page rendering remains inside:
 *
 * - PromiPages
 * - PricingPage
 * - PrintingPage
 */
final class Menu {

	/*
	|--------------------------------------------------------------------------
	| Menu Slugs
	|--------------------------------------------------------------------------
	*/

	public const ROOT_SLUG =
		'pdxw';

	public const DASHBOARD_SLUG =
		'pdxw';

	public const INDEX_SLUG =
		'pdxw-promi-index';

	public const QUEUE_SLUG =
		'pdxw-promi-queue';

	public const IGNORE_SKUS_SLUG =
		'pdxw-promi-ignore-skus';

	public const IGNORE_RULES_SLUG =
		'pdxw-promi-ignore-rules';

	public const PRICING_SLUG =
		'pdxw-tier-pricing';

	public const PRINTING_SLUG =
		'pdxw-printing';


	/*
	|--------------------------------------------------------------------------
	| Capability
	|--------------------------------------------------------------------------
	|
	| These pages can:
	|
	| - trigger imports
	| - mutate pricing
	| - mutate printing
	| - change ignore rules
	| - alter Promi synchronization state
	|
	| Therefore this is deliberately stricter than edit_products.
	|--------------------------------------------------------------------------
	*/

	public const CAPABILITY =
		'manage_woocommerce';


	private PromiPages $promi_pages;

	private PricingPage $pricing_page;

	private PrintingPage $printing_page;

	private bool $initialized = false;

	/**
	 * Registered WordPress hook suffixes.
	 *
	 * Useful for page detection and conditional asset loading.
	 *
	 * @var array<string,string>
	 */
	private array $page_hooks = [];


	public function __construct(
		PromiPages $promi_pages,
		PricingPage $pricing_page,
		PrintingPage $printing_page
	) {
		$this->promi_pages   = $promi_pages;
		$this->pricing_page  = $pricing_page;
		$this->printing_page = $printing_page;
	}


	/**
	 * Register WordPress admin menu hooks.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;


		add_action(
			'admin_menu',
			[
				$this,
				'register',
			],
			20
		);


		do_action(
			'pdxw_admin_menu_init',
			$this
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Menu Registration
	|--------------------------------------------------------------------------
	*/

	/**
	 * Register the complete PDXW admin navigation.
	 */
	public function register(): void {

		/*
		|--------------------------------------------------------------------------
		| Root
		|--------------------------------------------------------------------------
		*/

		$this->page_hooks[
			self::DASHBOARD_SLUG
		] =
			(string)
			add_menu_page(
				__(
					'Promi-Data X Woo',
					'promi-data-x-woo'
				),
				__(
					'Promi-Data X Woo',
					'promi-data-x-woo'
				),
				self::CAPABILITY,
				self::ROOT_SLUG,
				[
					$this->promi_pages,
					'render_dashboard',
				],
				'dashicons-database-import',
				56
			);


		/*
		|--------------------------------------------------------------------------
		| Dashboard
		|--------------------------------------------------------------------------
		|
		| WordPress automatically creates the first submenu entry for a
		| top-level menu.
		|
		| Registering it explicitly lets us control its label.
		*/

		$this->page_hooks[
			self::DASHBOARD_SLUG
		] =
			(string)
			add_submenu_page(
				self::ROOT_SLUG,
				__(
					'Promi Dashboard',
					'promi-data-x-woo'
				),
				__(
					'Dashboard',
					'promi-data-x-woo'
				),
				self::CAPABILITY,
				self::DASHBOARD_SLUG,
				[
					$this->promi_pages,
					'render_dashboard',
				]
			);


		/*
		|--------------------------------------------------------------------------
		| Promi Index
		|--------------------------------------------------------------------------
		*/

		$this->page_hooks[
			self::INDEX_SLUG
		] =
			(string)
			add_submenu_page(
				self::ROOT_SLUG,
				__(
					'Promi Index',
					'promi-data-x-woo'
				),
				__(
					'Promi Index',
					'promi-data-x-woo'
				),
				self::CAPABILITY,
				self::INDEX_SLUG,
				[
					$this->promi_pages,
					'render_index',
				]
			);


		/*
		|--------------------------------------------------------------------------
		| Queue
		|--------------------------------------------------------------------------
		*/

		$this->page_hooks[
			self::QUEUE_SLUG
		] =
			(string)
			add_submenu_page(
				self::ROOT_SLUG,
				__(
					'Promi Queue',
					'promi-data-x-woo'
				),
				__(
					'Queue',
					'promi-data-x-woo'
				),
				self::CAPABILITY,
				self::QUEUE_SLUG,
				[
					$this->promi_pages,
					'render_queue',
				]
			);


		/*
		|--------------------------------------------------------------------------
		| Ignore SKUs
		|--------------------------------------------------------------------------
		*/

		$this->page_hooks[
			self::IGNORE_SKUS_SLUG
		] =
			(string)
			add_submenu_page(
				self::ROOT_SLUG,
				__(
					'Ignored Promi SKUs',
					'promi-data-x-woo'
				),
				__(
					'Ignore SKUs',
					'promi-data-x-woo'
				),
				self::CAPABILITY,
				self::IGNORE_SKUS_SLUG,
				[
					$this->promi_pages,
					'render_ignore_skus',
				]
			);


		/*
		|--------------------------------------------------------------------------
		| Ignore Rules
		|--------------------------------------------------------------------------
		*/

		$this->page_hooks[
			self::IGNORE_RULES_SLUG
		] =
			(string)
			add_submenu_page(
				self::ROOT_SLUG,
				__(
					'Promi Ignore Rules',
					'promi-data-x-woo'
				),
				__(
					'Ignore Rules',
					'promi-data-x-woo'
				),
				self::CAPABILITY,
				self::IGNORE_RULES_SLUG,
				[
					$this->promi_pages,
					'render_ignore_rules',
				]
			);


		/*
		|--------------------------------------------------------------------------
		| Tier Pricing
		|--------------------------------------------------------------------------
		*/

		$this->page_hooks[
			self::PRICING_SLUG
		] =
			(string)
			add_submenu_page(
				self::ROOT_SLUG,
				__(
					'Tier Pricing',
					'promi-data-x-woo'
				),
				__(
					'Tier Pricing',
					'promi-data-x-woo'
				),
				self::CAPABILITY,
				self::PRICING_SLUG,
				[
					$this->pricing_page,
					'render',
				]
			);


		/*
		|--------------------------------------------------------------------------
		| Printing
		|--------------------------------------------------------------------------
		*/

		$this->page_hooks[
			self::PRINTING_SLUG
		] =
			(string)
			add_submenu_page(
				self::ROOT_SLUG,
				__(
					'Printing',
					'promi-data-x-woo'
				),
				__(
					'Printing',
					'promi-data-x-woo'
				),
				self::CAPABILITY,
				self::PRINTING_SLUG,
				[
					$this->printing_page,
					'render',
				]
			);


		do_action(
			'pdxw_admin_menu_registered',
			$this,
			$this->page_hooks
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Current Page Detection
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return the current PDXW page slug.
	 */
	public function current_page(): string {

		if ( ! is_admin() ) {
			return '';
		}


		$page =
			isset(
				$_GET[
					'page'
				]
			)
				? sanitize_key(
					wp_unslash(
						$_GET[
							'page'
						]
					)
				)
				: '';


		return $this->is_plugin_page(
			$page
		)
			? $page
			: '';
	}


	/**
	 * Determine whether the current admin request belongs to PDXW.
	 */
	public function is_current_page(): bool {

		return ''
			!== $this->current_page();
	}


	/**
	 * Determine whether a slug belongs to this plugin.
	 */
	public function is_plugin_page(
		string $slug
	): bool {

		return in_array(
			$slug,
			$this->page_slugs(),
			true
		);
	}


	/**
	 * Return every registered PDXW page slug.
	 */
	public function page_slugs(): array {

		return [
			self::DASHBOARD_SLUG,
			self::INDEX_SLUG,
			self::QUEUE_SLUG,
			self::IGNORE_SKUS_SLUG,
			self::IGNORE_RULES_SLUG,
			self::PRICING_SLUG,
			self::PRINTING_SLUG,
		];
	}


	/**
	 * Return the WordPress hook suffix for one plugin page.
	 */
	public function hook(
		string $slug
	): string {

		return $this->page_hooks[
			$slug
		] ?? '';
	}


	/**
	 * Return all page hook suffixes.
	 */
	public function hooks(): array {

		return $this->page_hooks;
	}


	/*
	|--------------------------------------------------------------------------
	| URLs
	|--------------------------------------------------------------------------
	*/

	/**
	 * Build an admin URL to one PDXW page.
	 */
	public function url(
		string $slug = self::DASHBOARD_SLUG,
		array $args = []
	): string {

		if (
			! $this->is_plugin_page(
				$slug
			)
		) {
			$slug =
				self::DASHBOARD_SLUG;
		}


		$url =
			add_query_arg(
				[
					'page' =>
						$slug,
				],
				admin_url(
					'admin.php'
				)
			);


		if ( ! empty( $args ) ) {

			$url =
				add_query_arg(
					$args,
					$url
				);
		}


		return $url;
	}


	/*
	|--------------------------------------------------------------------------
	| Capability
	|--------------------------------------------------------------------------
	*/

	/**
	 * Whether the current user can manage Promi-Data X Woo.
	 */
	public function can_manage(): bool {

		return current_user_can(
			self::CAPABILITY
		);
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
