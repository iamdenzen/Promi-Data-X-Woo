<?php

namespace PromiDataXWoo\Core;

use PromiDataXWoo\Admin\Admin;
use PromiDataXWoo\Catalog\Catalog;
use PromiDataXWoo\Frontend\Frontend;
use PromiDataXWoo\Mcp\Mcp;
use PromiDataXWoo\Pricing\Pricing;
use PromiDataXWoo\Printing\Printing;
use PromiDataXWoo\Promi\Promi;

defined( 'ABSPATH' ) || exit;

/**
 * Main Promi-Data X Woo application.
 *
 * Acts as the plugin's application container.
 *
 * Responsibilities:
 *
 * - Initialize plugin modules in dependency order.
 * - Hold shared module instances.
 * - Perform lightweight runtime maintenance.
 * - Expose controlled access to each subsystem.
 *
 * Business logic does not belong in this class.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 */
	private static ?self $instance = null;

	/**
	 * Whether the application has booted.
	 */
	private bool $booted = false;

	private ?Catalog $catalog = null;

	private ?Pricing $pricing = null;

	private ?Printing $printing = null;

	private ?Mcp $mcp = null;

	private ?Promi $promi = null;

	private ?Frontend $frontend = null;

	private ?Admin $admin = null;


	/**
	 * Prevent direct construction.
	 */
	private function __construct() {}


	/**
	 * Prevent cloning.
	 */
	private function __clone() {}


	/**
	 * Return the application instance.
	 */
	public static function instance(): self {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}


	/**
	 * Boot Promi-Data X Woo.
	 */
	public function boot(): void {

		if ( $this->booted ) {
			return;
		}

		$this->booted = true;


		/*
		|--------------------------------------------------------------------------
		| Translations
		|--------------------------------------------------------------------------
		*/

		$this->load_textdomain();


		/*
		|--------------------------------------------------------------------------
		| Database Upgrades
		|--------------------------------------------------------------------------
		|
		| Activation normally installs the schema, but maybe_upgrade() also
		| protects deployments where plugin files are replaced without
		| explicitly deactivating/reactivating the plugin.
		*/

		Database::maybe_upgrade();


		do_action(
			'pdxw_before_boot',
			$this
		);


		/*
		|--------------------------------------------------------------------------
		| Modules
		|--------------------------------------------------------------------------
		*/

		$this->register_modules();

		$this->init_modules();


		do_action(
			'pdxw_loaded',
			$this
		);
	}


	/**
	 * Construct all application modules.
	 *
	 * Construction establishes dependencies only.
	 * Hooks and runtime behavior belong in each module's init() method.
	 */
	private function register_modules(): void {

		/*
		|--------------------------------------------------------------------------
		| Catalog
		|--------------------------------------------------------------------------
		|
		| WooCommerce product structures are the lowest-level business domain.
		*/

		$this->catalog = new Catalog(
			$this
		);


		/*
		|--------------------------------------------------------------------------
		| Pricing
		|--------------------------------------------------------------------------
		*/

		$this->pricing = new Pricing(
			$this,
			$this->catalog
		);


		/*
		|--------------------------------------------------------------------------
		| Printing
		|--------------------------------------------------------------------------
		|
		| Printing depends on Pricing because print costs participate in the
		| unified price calculation pipeline.
		*/

		$this->printing = new Printing(
			$this,
			$this->catalog,
			$this->pricing
		);


		/*
		|--------------------------------------------------------------------------
		| MCP Integration
		|--------------------------------------------------------------------------
		|
		| Optional: exposes tiered pricing / print config data as MCP tools
		| when a WP MCP Server framework plugin is active. Only depends on
		| Pricing and Printing.
		*/

		$this->mcp = new Mcp(
			$this,
			$this->pricing,
			$this->printing
		);


		/*
		|--------------------------------------------------------------------------
		| Promi
		|--------------------------------------------------------------------------
		|
		| Promi imports data into Catalog, Pricing and Printing.
		|
		| Those modules deliberately do not depend on Promi.
		*/

		$this->promi = new Promi(
			$this,
			$this->catalog,
			$this->pricing,
			$this->printing
		);


		/*
		|--------------------------------------------------------------------------
		| Frontend
		|--------------------------------------------------------------------------
		|
		| Frontend consumes the business-domain modules but does not own them.
		*/

		$this->frontend = new Frontend(
			$this,
			$this->catalog,
			$this->pricing,
			$this->printing
		);


		/*
		|--------------------------------------------------------------------------
		| Admin
		|--------------------------------------------------------------------------
		*/

		if ( is_admin() ) {

			$this->admin = new Admin(
				$this,
				$this->catalog,
				$this->pricing,
				$this->printing,
				$this->promi
			);
		}
	}


	/**
	 * Initialize modules in dependency order.
	 */
	private function init_modules(): void {

		/*
		|--------------------------------------------------------------------------
		| Catalog
		|--------------------------------------------------------------------------
		*/

		$this->catalog?->init();


		/*
		|--------------------------------------------------------------------------
		| Pricing
		|--------------------------------------------------------------------------
		|
		| Pricing must initialize before Printing so the engine exists before
		| Printing registers its calculation callback.
		*/

		$this->pricing?->init();


		/*
		|--------------------------------------------------------------------------
		| Printing
		|--------------------------------------------------------------------------
		*/

		$this->printing?->init();


		/*
		|--------------------------------------------------------------------------
		| MCP Integration
		|--------------------------------------------------------------------------
		*/

		$this->mcp?->init();


		/*
		|--------------------------------------------------------------------------
		| Promi
		|--------------------------------------------------------------------------
		|
		| Promi can now safely synchronize data into the initialized domains.
		*/

		$this->promi?->init();


		/*
		|--------------------------------------------------------------------------
		| Frontend
		|--------------------------------------------------------------------------
		*/

		$this->frontend?->init();


		/*
		|--------------------------------------------------------------------------
		| Admin
		|--------------------------------------------------------------------------
		*/

		$this->admin?->init();
	}


	/**
	 * Load translations.
	 */
	private function load_textdomain(): void {

		load_plugin_textdomain(
			'promi-data-x-woo',
			false,
			dirname(
				PDXW_BASENAME
			)
			. '/languages'
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Module Accessors
	|--------------------------------------------------------------------------
	*/

	public function catalog(): ?Catalog {
		return $this->catalog;
	}


	public function pricing(): ?Pricing {
		return $this->pricing;
	}


	public function printing(): ?Printing {
		return $this->printing;
	}


	public function mcp(): ?Mcp {
		return $this->mcp;
	}


	public function promi(): ?Promi {
		return $this->promi;
	}


	public function frontend(): ?Frontend {
		return $this->frontend;
	}


	public function admin(): ?Admin {
		return $this->admin;
	}


	/*
	|--------------------------------------------------------------------------
	| State
	|--------------------------------------------------------------------------
	*/

	public function is_booted(): bool {
		return $this->booted;
	}
}