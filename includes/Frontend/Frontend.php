<?php

namespace PromiDataXWoo\Frontend;

use PromiDataXWoo\Catalog\Catalog;
use PromiDataXWoo\Core\Plugin;
use PromiDataXWoo\Pricing\Pricing;
use PromiDataXWoo\Printing\Printing;

defined( 'ABSPATH' ) || exit;

/**
 * Frontend module.
 *
 * Replaces the frontend responsibilities previously contained in
 * cx-product.
 *
 * Coordinates:
 *
 * - Product-page assets and localized application data.
 * - Product/configurator shortcodes.
 * - Frontend AJAX endpoints.
 * - Variable-product presentation helpers.
 * - Sample-product cart behavior.
 *
 * Catalog, Pricing and Printing remain the authoritative business domains.
 * Frontend only consumes those modules.
 */
final class Frontend {

	private Plugin $plugin;

	private Catalog $catalog;

	private Pricing $pricing;

	private Printing $printing;

	private ProductData $product_data;

	private Assets $assets;

	private Shortcodes $shortcodes;

	private Ajax $ajax;

	private Samples $samples;

	private bool $initialized = false;


	public function __construct(
		Plugin $plugin,
		Catalog $catalog,
		Pricing $pricing,
		Printing $printing
	) {
		$this->plugin   = $plugin;
		$this->catalog  = $catalog;
		$this->pricing  = $pricing;
		$this->printing = $printing;

		$this->register_services();
	}


	/**
	 * Build the frontend service graph.
	 */
	private function register_services(): void {

		/*
		|--------------------------------------------------------------------------
		| Product Data
		|--------------------------------------------------------------------------
		|
		| Reusable frontend-facing product data:
		|
		| - Variation matching
		| - Variation attributes
		| - Variation ordering
		| - Variation galleries
		| - Tier data
		| - Printing configuration
		| - Minimum order quantities
		| - Quantity increments
		|
		| The old CX_Product class performed all of this directly.
		*/

		$this->product_data =
			new ProductData(
				$this->catalog,
				$this->pricing,
				$this->printing
			);


		/*
		|--------------------------------------------------------------------------
		| Assets
		|--------------------------------------------------------------------------
		|
		| Owns the frontend JavaScript application previously registered from
		| CX_Product::__construct().
		|
		| This includes the existing logical modules:
		|
		| - main
		| - utils
		| - pricing
		| - api
		| - state
		| - events/*
		| - renderer/*
		*/

		$this->assets =
			new Assets(
				$this->product_data,
				$this->pricing
			);


		/*
		|--------------------------------------------------------------------------
		| Shortcodes / Templates
		|--------------------------------------------------------------------------
		|
		| Replaces:
		|
		| cx_product_varattr_dropdown
		| cx_product_price_table
		| cx_product_printers
		| cx_addtocart_form
		| cx_product_configurator
		*/

		$this->shortcodes =
			new Shortcodes(
				$this->product_data,
				$this->pricing,
				$this->printing
			);


		/*
		|--------------------------------------------------------------------------
		| AJAX
		|--------------------------------------------------------------------------
		|
		| Replaces:
		|
		| cx_get_data
		| cx_add_to_cart
		|
		| The AJAX layer will validate requests and delegate actual business
		| decisions to Pricing, Printing and ProductData.
		*/

		$this->ajax =
			new Ajax(
				$this->product_data,
				$this->printing
			);


		/*
		|--------------------------------------------------------------------------
		| Sample Products
		|--------------------------------------------------------------------------
		|
		| Existing CX Product behavior treats a quantity-one configurator
		| order as a sample:
		|
		| - cart item receives is_sample
		| - cart label receives "(muster)"
		| - quantity is forced to one
		|
		| Keep this isolated from normal pricing/cart code.
		*/

		$this->samples =
			new Samples();
	}


	/**
	 * Initialize frontend behavior.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;


		/*
		|--------------------------------------------------------------------------
		| Assets
		|--------------------------------------------------------------------------
		*/

		$this->assets->init();


		/*
		|--------------------------------------------------------------------------
		| Product Shortcodes
		|--------------------------------------------------------------------------
		*/

		$this->shortcodes->init();


		/*
		|--------------------------------------------------------------------------
		| AJAX
		|--------------------------------------------------------------------------
		*/

		$this->ajax->init();


		/*
		|--------------------------------------------------------------------------
		| Samples
		|--------------------------------------------------------------------------
		*/

		$this->samples->init();


		do_action(
			'pdxw_frontend_init',
			$this
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Services
	|--------------------------------------------------------------------------
	*/

	public function product_data(): ProductData {
		return $this->product_data;
	}


	public function assets(): Assets {
		return $this->assets;
	}


	public function shortcodes(): Shortcodes {
		return $this->shortcodes;
	}


	public function ajax(): Ajax {
		return $this->ajax;
	}


	public function samples(): Samples {
		return $this->samples;
	}


	/*
	|--------------------------------------------------------------------------
	| Modules
	|--------------------------------------------------------------------------
	*/

	public function catalog(): Catalog {
		return $this->catalog;
	}


	public function pricing(): Pricing {
		return $this->pricing;
	}


	public function printing(): Printing {
		return $this->printing;
	}


	public function plugin(): Plugin {
		return $this->plugin;
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
