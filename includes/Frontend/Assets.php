<?php

namespace PromiDataXWoo\Frontend;

use PromiDataXWoo\Pricing\Pricing;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Frontend asset manager.
 *
 * Migrates the existing cx-product JavaScript application into the unified
 * Promi-Data X Woo plugin.
 *
 * Existing frontend architecture:
 *
 * assets/frontend/js/
 *
 * ├── main.js
 * ├── utils.js
 * ├── pricing.js
 * ├── api.js
 * ├── state.js
 * │
 * ├── events/
 * │   ├── attributes.js
 * │   ├── variation.js
 * │   ├── cart.js
 * │   ├── table.js
 * │   ├── sample.js
 * │   ├── qty.js
 * │   ├── printers.js
 * │   └── navigation.js
 * │
 * └── renderer/
 *     ├── gallery.js
 *     ├── table.js
 *     ├── qty.js
 *     ├── printer.js
 *     └── summary.js
 *
 * These files currently communicate through:
 *
 *     window.CX
 *
 * and localized configuration:
 *
 *     window.cxatc_vars
 *
 * We intentionally preserve those JavaScript contracts during the rebuild.
 * They are frontend application state, not PHP legacy compatibility.
 */
final class Assets {

	private const HANDLE = 'pdxw-product';

	private ProductData $product_data;

	private Pricing $pricing;

	private bool $initialized = false;


	public function __construct(
		ProductData $product_data,
		Pricing $pricing
	) {
		$this->product_data = $product_data;
		$this->pricing      = $pricing;
	}


	/**
	 * Register frontend asset hooks.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		add_action(
			'wp_enqueue_scripts',
			[
				$this,
				'enqueue',
			]
		);

		do_action(
			'pdxw_frontend_assets_init',
			$this
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Product Assets
	|--------------------------------------------------------------------------
	*/

	/**
	 * Enqueue the product configurator application.
	 *
	 * Existing cx-product behavior loads these assets only on WooCommerce
	 * single-product pages. Preserve that because none of this JS is needed
	 * across the rest of the site.
	 */
	public function enqueue(): void {

		if ( ! is_product() ) {
			return;
		}

		$product =
			$this->current_product();

		if ( ! $product ) {
			return;
		}

		$this->enqueue_scripts(
			$product
		);

		$this->localize(
			$product
		);

		do_action(
			'pdxw_frontend_product_assets_enqueued',
			$product
		);
	}


	/**
	 * Resolve the current WooCommerce product.
	 */
	private function current_product(): ?WC_Product {

		global $post;

		$product_id = 0;

		if (
			$post instanceof \WP_Post
		) {
			$product_id =
				absint(
					$post->ID
				);
		}

		if ( ! $product_id ) {

			$product_id =
				absint(
					get_queried_object_id()
				);
		}

		if ( ! $product_id ) {
			return null;
		}

		$product =
			wc_get_product(
				$product_id
			);

		return $product instanceof WC_Product
			? $product
			: null;
	}


	/*
	|--------------------------------------------------------------------------
	| Scripts
	|--------------------------------------------------------------------------
	*/

	/**
	 * Register and enqueue every existing frontend JS module.
	 *
	 * We use explicit dependencies here rather than relying only on enqueue
	 * order like the old plugin did.
	 *
	 * This gives the application a deterministic dependency graph while
	 * allowing us to keep every existing JS file unchanged initially.
	 */
	private function enqueue_scripts(
		WC_Product $product
	): void {

		/*
		|--------------------------------------------------------------------------
		| Foundation
		|--------------------------------------------------------------------------
		*/

		$this->enqueue_script(
			'utils',
			'utils.js',
			[
				'jquery',
			]
		);

		$this->enqueue_script(
			'pricing',
			'pricing.js',
			[
				'jquery',
				$this->handle(
					'utils'
				),
			]
		);

		$this->enqueue_script(
			'api',
			'api.js',
			[
				'jquery',
			]
		);

		$this->enqueue_script(
			'state',
			'state.js',
			[
				'jquery',
			]
		);


		/*
		|--------------------------------------------------------------------------
		| Renderers
		|--------------------------------------------------------------------------
		|
		| Events invoke these functions, so renderers should exist before the
		| event files execute.
		*/

		$this->enqueue_script(
			'renderer-gallery',
			'renderer/gallery.js',
			[
				'jquery',
				$this->handle(
					'state'
				),
			]
		);

		$this->enqueue_script(
			'renderer-table',
			'renderer/table.js',
			[
				'jquery',
				$this->handle(
					'state'
				),
				$this->handle(
					'pricing'
				),
			]
		);

		$this->enqueue_script(
			'renderer-qty',
			'renderer/qty.js',
			[
				'jquery',
				$this->handle(
					'state'
				),
				$this->handle(
					'pricing'
				),
			]
		);

		$this->enqueue_script(
			'renderer-printer',
			'renderer/printer.js',
			[
				'jquery',
				$this->handle(
					'state'
				),
				$this->handle(
					'pricing'
				),
			]
		);

		$this->enqueue_script(
			'renderer-summary',
			'renderer/summary.js',
			[
				'jquery',
				$this->handle(
					'state'
				),
				$this->handle(
					'pricing'
				),
			]
		);


		/*
		|--------------------------------------------------------------------------
		| Events
		|--------------------------------------------------------------------------
		*/

		$this->enqueue_script(
			'events-attributes',
			'events/attributes.js',
			[
				'jquery',
				$this->handle(
					'api'
				),
				$this->handle(
					'state'
				),
				$this->handle(
					'renderer-gallery'
				),
				$this->handle(
					'renderer-table'
				),
				$this->handle(
					'renderer-qty'
				),
				$this->handle(
					'renderer-printer'
				),
				$this->handle(
					'renderer-summary'
				),
			]
		);

		$this->enqueue_script(
			'events-variation',
			'events/variation.js',
			[
				'jquery',
				$this->handle(
					'api'
				),
				$this->handle(
					'state'
				),
				$this->handle(
					'renderer-gallery'
				),
				$this->handle(
					'renderer-table'
				),
				$this->handle(
					'renderer-qty'
				),
				$this->handle(
					'renderer-printer'
				),
				$this->handle(
					'renderer-summary'
				),
			]
		);

		$this->enqueue_script(
			'events-cart',
			'events/cart.js',
			[
				'jquery',
				$this->handle(
					'api'
				),
				$this->handle(
					'state'
				),
			]
		);

		$this->enqueue_script(
			'events-table',
			'events/table.js',
			[
				'jquery',
				$this->handle(
					'state'
				),
			]
		);

		$this->enqueue_script(
			'events-sample',
			'events/sample.js',
			[
				'jquery',
				$this->handle(
					'api'
				),
				$this->handle(
					'state'
				),
			]
		);

		$this->enqueue_script(
			'events-qty',
			'events/qty.js',
			[
				'jquery',
				$this->handle(
					'state'
				),
				$this->handle(
					'renderer-table'
				),
				$this->handle(
					'renderer-summary'
				),
			]
		);

		$this->enqueue_script(
			'events-printers',
			'events/printers.js',
			[
				'jquery',
				$this->handle(
					'state'
				),
				$this->handle(
					'pricing'
				),
				$this->handle(
					'renderer-printer'
				),
				$this->handle(
					'renderer-table'
				),
				$this->handle(
					'renderer-summary'
				),
			]
		);

		$this->enqueue_script(
			'events-navigation',
			'events/navigation.js',
			[
				'jquery',
			]
		);


		/*
		|--------------------------------------------------------------------------
		| Main
		|--------------------------------------------------------------------------
		|
		| main.js performs the initial render on DOM ready.
		|
		| Therefore it should execute only after every renderer/event module
		| has been loaded.
		*/

		$this->enqueue_script(
			'main',
			'main.js',
			[
				'jquery',

				$this->handle(
					'utils'
				),

				$this->handle(
					'pricing'
				),

				$this->handle(
					'api'
				),

				$this->handle(
					'state'
				),

				$this->handle(
					'events-attributes'
				),

				$this->handle(
					'events-variation'
				),

				$this->handle(
					'events-cart'
				),

				$this->handle(
					'events-table'
				),

				$this->handle(
					'events-sample'
				),

				$this->handle(
					'events-qty'
				),

				$this->handle(
					'events-printers'
				),

				$this->handle(
					'events-navigation'
				),

				$this->handle(
					'renderer-gallery'
				),

				$this->handle(
					'renderer-table'
				),

				$this->handle(
					'renderer-qty'
				),

				$this->handle(
					'renderer-printer'
				),

				$this->handle(
					'renderer-summary'
				),
			]
		);
	}


	/**
	 * Enqueue one frontend JS module.
	 */
	private function enqueue_script(
		string $key,
		string $relative_path,
		array $dependencies = []
	): void {

		$relative_path =
			'frontend/js/'
			. ltrim(
				$relative_path,
				'/'
			);

		$file =
			PDXW_ASSETS_PATH
			. $relative_path;

		$url =
			PDXW_ASSETS_URL
			. $relative_path;


		/*
		 * Do not create a broken script tag when an asset has not yet been
		 * copied into the rebuilt plugin.
		 */
		if (
			! is_readable(
				$file
			)
		) {
			return;
		}

		wp_enqueue_script(
			$this->handle(
				$key
			),
			$url,
			$dependencies,
			$this->asset_version(
				$file
			),
			true
		);
	}


	/**
	 * Generate a script handle.
	 */
	private function handle(
		string $key
	): string {

		return self::HANDLE
			. '-'
			. sanitize_key(
				$key
			);
	}


	/**
	 * Use file modification time during development and plugin version as a
	 * safe fallback.
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
	| Localized Application Data
	|--------------------------------------------------------------------------
	*/

	/**
	 * Provide the frontend application bootstrap data.
	 *
	 * Preserve the existing cxatc_vars name because all migrated JS modules
	 * currently consume that object.
	 */
	private function localize(
		WC_Product $product
	): void {

		$main_handle =
			$this->handle(
				'main'
			);

		if (
			! wp_script_is(
				$main_handle,
				'enqueued'
			)
		) {
			return;
		}

		$product_id =
			$product->get_id();


		/*
		|--------------------------------------------------------------------------
		| WooCommerce Price Formatting
		|--------------------------------------------------------------------------
		|
		| Used by CX.utils.currency().
		*/

		$settings = [
			'currency_symbol' =>
				get_woocommerce_currency_symbol(),

			'currency_position' =>
				get_option(
					'woocommerce_currency_pos'
				),

			'decimal_separator' =>
				wc_get_price_decimal_separator(),

			'thousand_separator' =>
				wc_get_price_thousand_separator(),

			'num_decimals' =>
				wc_get_price_decimals(),
		];


		/*
		|--------------------------------------------------------------------------
		| Quantity Tiers
		|--------------------------------------------------------------------------
		|
		| Preserve this localized value even though the current JS does not
		| actively read cxatc_vars.qty_tiers.
		|
		| It is still useful bootstrap data and was part of the existing
		| cx-product contract.
		*/

		$qty_tiers =
			$this->pricing
				->quantities(
					$product_id,
					null
				);


		/*
		|--------------------------------------------------------------------------
		| Lightweight Variation Data
		|--------------------------------------------------------------------------
		|
		| Do not preload:
		|
		| - print positions
		| - print options
		| - tier prices per variation
		| - quantity metadata
		|
		| Those are fetched only once the shopper selects a variation.
		|
		| This is important for Promi products with many variations.
		*/

		$variations =
			$this->product_data
				->localized_variations(
					$product_id
				);


		/*
		|--------------------------------------------------------------------------
		| JavaScript Bootstrap
		|--------------------------------------------------------------------------
		*/

		wp_localize_script(
			$main_handle,
			'cxatc_vars',
			[
				'ajax_url' =>
					admin_url(
						'admin-ajax.php'
					),

				'nonce' =>
					wp_create_nonce(
						'cxatc_nonce'
					),

				'product_id' =>
					$product_id,

				'sku' =>
					(string)
						$product
							->get_sku(),

				'settings' =>
					$settings,

				'qty_tiers' =>
					$qty_tiers,

				'variations' =>
					$variations,
			]
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
