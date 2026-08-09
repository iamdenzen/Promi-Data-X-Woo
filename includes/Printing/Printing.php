<?php

namespace PromiDataXWoo\Printing;

use PromiDataXWoo\Catalog\Catalog;
use PromiDataXWoo\Core\Plugin;
use PromiDataXWoo\Pricing\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Printing module.
 *
 * Coordinates all XSImpress printing functionality:
 *
 * - Print positions
 * - Global print options
 * - Tiered print prices
 * - Print fees
 * - Product/variation relationships
 * - Print-price calculation
 * - Cart/order integration
 * - Configurator data/validation
 * - Promi print synchronization
 *
 * Business logic remains inside the individual services.
 */
final class Printing {

	private Plugin $plugin;

	private Catalog $catalog;

	private Pricing $pricing;

	private Repository $repository;

	private Positions $positions;

	private Options $options;

	private Fees $fees;

	private Calculator $calculator;

	private CartPricing $cart;

	private Configurator $configurator;

	private bool $initialized = false;


	public function __construct(
		Plugin $plugin,
		Catalog $catalog,
		Pricing $pricing
	) {
		$this->plugin  = $plugin;
		$this->catalog = $catalog;
		$this->pricing = $pricing;

		$this->register_services();
	}


	/**
	 * Build the printing service graph.
	 */
	private function register_services(): void {

		/*
		|--------------------------------------------------------------------------
		| Repository
		|--------------------------------------------------------------------------
		*/

		$this->repository =
			new Repository();


		/*
		|--------------------------------------------------------------------------
		| Domain Services
		|--------------------------------------------------------------------------
		*/

		$this->positions =
			new Positions(
				$this->repository
			);

		$this->options =
			new Options(
				$this->repository
			);

		$this->fees =
			new Fees(
				$this->repository
			);


		/*
		|--------------------------------------------------------------------------
		| Pricing
		|--------------------------------------------------------------------------
		*/

		$this->calculator =
			new Calculator(
				$this->repository,
				$this->pricing
			);


		/*
		|--------------------------------------------------------------------------
		| WooCommerce Cart Integration
		|--------------------------------------------------------------------------
		*/

		$this->cart =
			new CartPricing(
				$this,
				$this->calculator
			);


		/*
		|--------------------------------------------------------------------------
		| Product Configurator API
		|--------------------------------------------------------------------------
		*/

		$this->configurator =
			new Configurator(
				$this,
				$this->repository,
				$this->calculator
			);
	}


	/**
	 * Initialize printing functionality.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;


		/*
		|--------------------------------------------------------------------------
		| Product Cleanup
		|--------------------------------------------------------------------------
		*/

		add_action(
			'before_delete_post',
			[ $this, 'handle_product_delete' ]
		);


		/*
		|--------------------------------------------------------------------------
		| Unified Pricing Pipeline
		|--------------------------------------------------------------------------
		|
		| Tier pricing is expected to run first.
		|
		| Printing then adds:
		|
		| - per-unit print tier price
		| - applicable setup/handling fees
		|
		| Priority 20 preserves that ordering.
		*/

		$this->pricing
			->engine()
			->register(
				'printing',
				[
					$this->calculator,
					'apply',
				],
				20
			);


		/*
		|--------------------------------------------------------------------------
		| WooCommerce Cart / Order Hooks
		|--------------------------------------------------------------------------
		*/

		$this->cart->init();


		/*
		|--------------------------------------------------------------------------
		| Module Ready
		|--------------------------------------------------------------------------
		*/

		do_action(
			'pdxw_printing_init',
			$this
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Product Cleanup
	|--------------------------------------------------------------------------
	*/

	/**
	 * Remove printing records when a WooCommerce product or variation
	 * is permanently deleted.
	 */
	public function handle_product_delete(
		int $post_id
	): void {

		$post_type =
			get_post_type(
				$post_id
			);

		if ( 'product' === $post_type ) {

			$this->positions
				->delete_by_product(
					$post_id
				);

			return;
		}

		if (
			'product_variation'
			=== $post_type
		) {

			$this->positions
				->delete_by_variation(
					$post_id
				);
		}
	}


	/*
	|--------------------------------------------------------------------------
	| Promi Synchronization API
	|--------------------------------------------------------------------------
	*/

	/**
	 * Synchronize global Promi print options.
	 *
	 * Promi calls these ImprintReferences.
	 */
	public function sync_global_options(
		array $references
	): void {

		if ( empty( $references ) ) {
			return;
		}

		$this->options
			->sync_promi(
				$references
			);
	}


	/**
	 * Synchronize print positions for one variation.
	 */
	public function sync_positions(
		int $product_id,
		int $variation_id,
		array $references,
		array $positions
	): void {

		$product_id =
			absint(
				$product_id
			);

		$variation_id =
			absint(
				$variation_id
			);

		if (
			! $product_id
			|| empty( $positions )
		) {
			return;
		}

		$this->positions
			->sync_promi(
				$product_id,
				$variation_id,
				$references,
				$positions
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Service Accessors
	|--------------------------------------------------------------------------
	*/

	public function repository(): Repository {
		return $this->repository;
	}


	public function positions(): Positions {
		return $this->positions;
	}


	public function options(): Options {
		return $this->options;
	}


	public function fees(): Fees {
		return $this->fees;
	}


	public function calculator(): Calculator {
		return $this->calculator;
	}


	public function cart(): CartPricing {
		return $this->cart;
	}


	public function configurator(): Configurator {
		return $this->configurator;
	}


	/*
	|--------------------------------------------------------------------------
	| Module Dependencies
	|--------------------------------------------------------------------------
	*/

	public function catalog(): Catalog {
		return $this->catalog;
	}


	public function pricing(): Pricing {
		return $this->pricing;
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