<?php

namespace PromiDataXWoo\Admin;

use PromiDataXWoo\Catalog\Catalog;
use PromiDataXWoo\Core\Plugin;
use PromiDataXWoo\Pricing\Pricing;
use PromiDataXWoo\Printing\Printing;
use PromiDataXWoo\Promi\Promi;

defined( 'ABSPATH' ) || exit;

/**
 * Promi-Data X Woo admin module.
 *
 * Coordinates the WordPress / WooCommerce administration interface for:
 *
 * - Promi synchronization.
 * - Promi queue and index monitoring.
 * - Ignore SKUs and ignore rules.
 * - Product synchronization diagnostics.
 * - Tiered pricing management.
 * - Print-option inspection.
 * - Product print-option filtering.
 * - Variation printing information.
 * - Admin AJAX operations.
 * - Admin-only assets.
 *
 * Business logic remains in the underlying domain modules.
 *
 * This class only constructs and initializes the admin services.
 */
final class Admin {

	private Plugin $plugin;

	private Catalog $catalog;

	private Pricing $pricing;

	private Printing $printing;

	private Promi $promi;


	/*
	|--------------------------------------------------------------------------
	| Admin Services
	|--------------------------------------------------------------------------
	*/

	private Menu $menu;

	private Assets $assets;

	private Ajax $ajax;

	private ProductAdmin $products;

	private ProductMetaBox $product_meta_box;

	private PromiPages $promi_pages;

	private PricingPage $pricing_page;

	private PrintingPage $printing_page;

	private MarkupPage $markup_page;


	private bool $initialized = false;


	public function __construct(
		Plugin $plugin,
		Catalog $catalog,
		Pricing $pricing,
		Printing $printing,
		Promi $promi
	) {
		$this->plugin   = $plugin;
		$this->catalog  = $catalog;
		$this->pricing  = $pricing;
		$this->printing = $printing;
		$this->promi    = $promi;

		$this->register_services();
	}


	/**
	 * Construct the admin service graph.
	 *
	 * Construction must remain side-effect free.
	 * WordPress hooks are registered from init().
	 */
	private function register_services(): void {

		/*
		|--------------------------------------------------------------------------
		| Promi Pages
		|--------------------------------------------------------------------------
		|
		| Replaces the existing:
		|
		| - Dashboard
		| - Index
		| - Queue
		| - Ignore SKUs
		| - Ignore Rules
		|
		| pages currently spread across CX_Promi_Admin_Menu and
		| CX_Promi_Admin_Pages.
		*/

		$this->promi_pages =
			new PromiPages(
				$this->promi,
				$this->catalog
			);


		/*
		|--------------------------------------------------------------------------
		| Tiered Pricing Admin
		|--------------------------------------------------------------------------
		|
		| Replaces the standalone CX Tiered Pricing product submenu.
		|
		| PricingPage owns only admin presentation and form handling.
		| Tier storage/calculation remains inside Pricing.
		*/

		$this->pricing_page =
			new PricingPage(
				$this->catalog,
				$this->pricing
			);


		/*
		|--------------------------------------------------------------------------
		| Printing Admin
		|--------------------------------------------------------------------------
		|
		| Replaces CX_Print_Admin responsibilities:
		|
		| - Global print-option list.
		| - SKU search.
		| - Price/fee inspection.
		| - Variation printing information.
		| - Product filtering by print option.
		*/

		$this->printing_page =
			new PrintingPage(
				$this->catalog,
				$this->printing
			);


		/*
		|--------------------------------------------------------------------------
		| Markup Admin
		|--------------------------------------------------------------------------
		*/

		$this->markup_page =
			new MarkupPage(
				$this->pricing
			);


		/*
		|--------------------------------------------------------------------------
		| Admin Navigation
		|--------------------------------------------------------------------------
		|
		| The old architecture exposes several separate top-level menus.
		|
		| The rebuilt plugin will expose one:
		|
		|     Promi-Data X Woo
		|
		| administration tree.
		*/

		$this->menu =
			new Menu(
				$this->promi_pages,
				$this->pricing_page,
				$this->printing_page,
				$this->markup_page
			);


		/*
		|--------------------------------------------------------------------------
		| Admin AJAX
		|--------------------------------------------------------------------------
		|
		| Existing Promi admin AJAX operations include:
		|
		| - save configuration
		| - refresh index
		| - recheck queue
		| - run worker
		| - fetch queue statistics
		| - pause/resume cron
		| - process a specific SKU
		| - add ignore SKU
		| - add ignore rule
		|
		| Those operations should delegate into Promi services instead of
		| invoking static legacy classes.
		*/

		$this->ajax =
			new Ajax(
				$this->promi
			);


		/*
		|--------------------------------------------------------------------------
		| Product Admin Integration
		|--------------------------------------------------------------------------
		|
		| Replaces existing product-list enhancements such as:
		|
		| - Promi Status column.
		| - Promi Disabled view.
		| - Need Images view.
		| - Variable products without variations.
		| - Print-option filter.
		|
		| These are product-management features rather than Promi page
		| rendering, so they get their own service.
		*/

		$this->products =
			new ProductAdmin(
				$this->catalog,
				$this->printing,
				$this->promi
			);


		/*
		|--------------------------------------------------------------------------
		| Product Meta Box
		|--------------------------------------------------------------------------
		|
		| The existing Promi plugin exposes a "Promi Index Data" meta box
		| on WooCommerce products.
		|
		| Keep that useful diagnostic feature isolated from product-list
		| behavior.
		*/

		$this->product_meta_box =
			new ProductMetaBox(
				$this->promi
			);


		/*
		|--------------------------------------------------------------------------
		| Assets
		|--------------------------------------------------------------------------
		|
		| Existing cx-promi admin.js/admin.css are only loaded on Promi admin
		| pages.
		|
		| The rebuilt Assets service will extend that rule to all PDXW admin
		| pages while avoiding site-wide admin asset loading.
		*/

		$this->assets =
			new Assets(
				$this->menu
			);
	}


	/**
	 * Initialize all admin services.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;


		/*
		|--------------------------------------------------------------------------
		| Navigation
		|--------------------------------------------------------------------------
		*/

		$this->menu->init();


		/*
		|--------------------------------------------------------------------------
		| Assets
		|--------------------------------------------------------------------------
		*/

		$this->assets->init();


		/*
		|--------------------------------------------------------------------------
		| Admin Actions
		|--------------------------------------------------------------------------
		*/

		$this->ajax->init();


		/*
		|--------------------------------------------------------------------------
		| WooCommerce Product Admin
		|--------------------------------------------------------------------------
		*/

		$this->products->init();

		$this->product_meta_box->init();


		/*
		|--------------------------------------------------------------------------
		| Domain Admin Pages
		|--------------------------------------------------------------------------
		|
		| Menu registration is handled by Menu.
		|
		| These services register their own form handlers and any
		| page-specific WooCommerce hooks.
		*/

		$this->promi_pages->init();

		$this->pricing_page->init();

		$this->printing_page->init();

		$this->markup_page->init();


		do_action(
			'pdxw_admin_init',
			$this
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Service Accessors
	|--------------------------------------------------------------------------
	*/

	public function menu(): Menu {
		return $this->menu;
	}


	public function assets(): Assets {
		return $this->assets;
	}


	public function ajax(): Ajax {
		return $this->ajax;
	}


	public function products(): ProductAdmin {
		return $this->products;
	}


	public function product_meta_box(): ProductMetaBox {
		return $this->product_meta_box;
	}


	public function promi_pages(): PromiPages {
		return $this->promi_pages;
	}


	public function pricing_page(): PricingPage {
		return $this->pricing_page;
	}


	public function printing_page(): PrintingPage {
		return $this->printing_page;
	}


	/*
	|--------------------------------------------------------------------------
	| Domain Accessors
	|--------------------------------------------------------------------------
	*/

	public function plugin(): Plugin {
		return $this->plugin;
	}


	public function catalog(): Catalog {
		return $this->catalog;
	}


	public function pricing(): Pricing {
		return $this->pricing;
	}


	public function printing(): Printing {
		return $this->printing;
	}


	public function promi(): Promi {
		return $this->promi;
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
