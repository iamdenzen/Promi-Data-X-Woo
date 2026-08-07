<?php

namespace PromiDataXWoo\Core;

use PromiDataXWoo\Promi\Promi;
use PromiDataXWoo\Catalog\Catalog;
use PromiDataXWoo\Pricing\Pricing;
use PromiDataXWoo\Printing\Printing;
use PromiDataXWoo\Frontend\Frontend;
use PromiDataXWoo\Admin\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin application.
 *
 * Acts as the central container for Promi-Data X Woo.
 *
 * Responsibilities:
 * - Boot the plugin.
 * - Initialize modules in the correct order.
 * - Hold module instances.
 * - Provide controlled access to shared modules.
 *
 * Business logic should not live here.
 */
final class Plugin {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether the plugin has already booted.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Promi integration.
	 *
	 * @var Promi|null
	 */
	private ?Promi $promi = null;

	/**
	 * WooCommerce catalog integration.
	 *
	 * @var Catalog|null
	 */
	private ?Catalog $catalog = null;

	/**
	 * Pricing system.
	 *
	 * @var Pricing|null
	 */
	private ?Pricing $pricing = null;

	/**
	 * Print configuration and pricing.
	 *
	 * @var Printing|null
	 */
	private ?Printing $printing = null;

	/**
	 * Frontend functionality.
	 *
	 * @var Frontend|null
	 */
	private ?Frontend $frontend = null;

	/**
	 * Admin functionality.
	 *
	 * @var Admin|null
	 */
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
	 * Get plugin instance.
	 */
	public static function instance(): Plugin {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}


	/**
	 * Boot the application.
	 */
	public function boot(): void {

		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		/**
		 * Load translations.
		 */
		$this->load_textdomain();

        /*
         * Ensure the database is up to date.
         *
         * This is a bit of a hack, but it works. We don't want to run this
         * on every page load, but we also don't want to require a separate
         * upgrade routine. So we check the version and run the upgrade if
         * needed.
         *
         * This is not ideal, but it is a common pattern in WordPress plugins.
         * It is also safe because the upgrade routine is idempotent.
         *
         * @see Database::maybe_upgrade()
         */
        Database::maybe_upgrade();

		/**
		 * Fire before any modules initialize.
		 *
		 * Useful later if we need internal extensions without modifying
		 * the plugin bootstrap.
		 */
		do_action( 'pdxw_before_boot', $this );

		/**
		 * Register the core modules.
		 *
		 * Order matters.
		 *
		 * Catalog:
		 * WooCommerce product structures.
		 *
		 * Pricing:
		 * Product/variation tiered pricing.
		 *
		 * Printing:
		 * Positions, options, print prices and fees.
		 *
		 * Promi:
		 * Imports data into Catalog/Pricing/Printing.
		 *
		 * Frontend:
		 * Consumes Catalog/Pricing/Printing.
		 *
		 * Admin:
		 * Management interface for everything above.
		 */
		$this->register_modules();

		/**
		 * Initialize modules.
		 */
		$this->init_modules();

		do_action( 'pdxw_loaded', $this );
	}


	/**
	 * Register the plugin's modules.
	 *
	 * Registration creates the objects.
	 * It should not yet attach expensive hooks or execute business logic.
	 */
	private function register_modules(): void {

		$this->catalog = new Catalog( $this );

		$this->pricing = new Pricing(
			$this,
			$this->catalog
		);

		$this->printing = new Printing(
			$this,
			$this->catalog,
			$this->pricing
		);

		$this->promi = new Promi(
			$this,
			$this->catalog,
			$this->pricing,
			$this->printing
		);

		$this->frontend = new Frontend(
			$this,
			$this->catalog,
			$this->pricing,
			$this->printing
		);

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
	 * Initialize registered modules.
	 */
	private function init_modules(): void {

		/**
		 * Catalog must initialize first because most other systems operate
		 * against WooCommerce products and variations.
		 */
		$this->catalog?->init();

		/**
		 * Pricing establishes the pricing pipeline before anything
		 * attempts to calculate prices.
		 */
		$this->pricing?->init();

		/**
		 * Printing can then attach itself to the pricing pipeline.
		 */
		$this->printing?->init();

		/**
		 * Promi imports/synchronizes data into the systems above.
		 */
		$this->promi?->init();

		/**
		 * Frontend consumes those systems.
		 */
		$this->frontend?->init();

		/**
		 * Admin is loaded last.
		 */
		$this->admin?->init();
	}


	/**
	 * Load plugin translations.
	 */
	private function load_textdomain(): void {

		load_plugin_textdomain(
			'promi-data-x-woo',
			false,
			dirname( PDXW_BASENAME ) . '/languages'
		);
	}


	/**
	 * Get Promi module.
	 */
	public function promi(): ?Promi {
		return $this->promi;
	}


	/**
	 * Get Catalog module.
	 */
	public function catalog(): ?Catalog {
		return $this->catalog;
	}


	/**
	 * Get Pricing module.
	 */
	public function pricing(): ?Pricing {
		return $this->pricing;
	}


	/**
	 * Get Printing module.
	 */
	public function printing(): ?Printing {
		return $this->printing;
	}


	/**
	 * Get Frontend module.
	 */
	public function frontend(): ?Frontend {
		return $this->frontend;
	}


	/**
	 * Get Admin module.
	 */
	public function admin(): ?Admin {
		return $this->admin;
	}


	/**
	 * Has the plugin finished booting?
	 */
	public function is_booted(): bool {
		return $this->booted;
	}
}