<?php

namespace PromiDataXWoo\Pricing;

use PromiDataXWoo\Catalog\Catalog;
use PromiDataXWoo\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Pricing module.
 *
 * Coordinates:
 *
 * - Unified price calculation pipeline.
 * - Product/variation tier pricing.
 * - Selling and purchase prices.
 * - WooCommerce cart price application.
 * - Promi tier synchronization.
 *
 * Printing registers itself with the Engine separately at priority 20.
 */
final class Pricing {

	private Plugin $plugin;

	private Catalog $catalog;

	private Engine $engine;

	private PriceRepository $repository;

	private MarkupRepository $markup_repository;

	private MarkupRules $markup_rules;

	private CostCalculator $costs;

	private TieredPricing $tiers;

	private CartPricing $cart;

	private bool $initialized = false;


	public function __construct(
		Plugin $plugin,
		Catalog $catalog
	) {
		$this->plugin  = $plugin;
		$this->catalog = $catalog;

		$this->register_services();
	}


	/**
	 * Build the pricing service graph.
	 */
	private function register_services(): void {

		/*
		|--------------------------------------------------------------------------
		| Calculation Engine
		|--------------------------------------------------------------------------
		*/

		$this->engine =
			new Engine();


		/*
		|--------------------------------------------------------------------------
		| WooCommerce Cart Integration
		|--------------------------------------------------------------------------
		*/

		$this->cart =
			new CartPricing(
				$this->engine,
				$this->tiers
			);

		/*
		|--------------------------------------------------------------------------
		| Storage
		|--------------------------------------------------------------------------
		*/

		$this->repository =
			new PriceRepository();

		/*
		|--------------------------------------------------------------------------
		| Pricing Engine
		|--------------------------------------------------------------------------
		*/

		$this->engine =
			new Engine();

		/*
		|--------------------------------------------------------------------------
		| Markup Repository and Rules
		|--------------------------------------------------------------------------
		*/

		$this->markup_repository =
			new MarkupRepository();

		$this->markup_rules =
			new MarkupRules(
				$this->markup_repository
			);

		/*
		|--------------------------------------------------------------------------
		| Cost Calculator
		|--------------------------------------------------------------------------
		*/

		$this->costs =
			new CostCalculator(
				$this->repository,
				$this->markup_rules
			);
		
		/*
		|--------------------------------------------------------------------------
		| Tier Pricing
		|--------------------------------------------------------------------------
		*/

		$this->tiers =
			new TieredPricing(
				$this->repository,
				$this->costs
			);

		/*
		|--------------------------------------------------------------------------
		| Cart Pricing
		|--------------------------------------------------------------------------
		*/
		$this->cart =
			new CartPricing(
				$this->engine,
				$this->tiers
			);

	}


	/**
	 * Initialize pricing.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;


		/*
		|--------------------------------------------------------------------------
		| Tier Pricing
		|--------------------------------------------------------------------------
		|
		| The existing CX pricing engine applies tier pricing first.
		|
		| Printing is registered later by Printing::init() at priority 20.
		*/

		$this->engine->register(
			'tier',
			[
				$this->tiers,
				'apply',
			],
			10
		);


		/*
		|--------------------------------------------------------------------------
		| WooCommerce Cart Pricing
		|--------------------------------------------------------------------------
		*/

		$this->cart->init();


		/*
		|--------------------------------------------------------------------------
		| Product Cleanup
		|--------------------------------------------------------------------------
		*/

		add_action(
			'before_delete_post',
			[
				$this,
				'handle_product_delete',
			]
		);


		do_action(
			'pdxw_pricing_init',
			$this
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Promi Synchronization
	|--------------------------------------------------------------------------
	*/

	/**
	 * Replace all selling/purchase tiers for a product or variation.
	 *
	 * Expected normalized structure:
	 *
	 * [
	 *     [
	 *         'qty'            => 1,
	 *         'price'          => 10.00,
	 *         'purchase_price' => 5.00,
	 *     ],
	 *     [
	 *         'qty'            => 100,
	 *         'price'          => 8.50,
	 *         'purchase_price' => 4.20,
	 *     ],
	 * ]
	 */
	public function sync_tiers(
		int $product_id,
		int $variation_id,
		array $tiers,
		bool $update_wc_price = true
	): bool {

		return $this->tiers
			->replace_all(
				$product_id,
				$variation_id,
				$tiers,
				$update_wc_price
			);
	}


	/**
	 * Synchronize tier prices directly from Promi country-price data.
	 *
	 * This lets ProductSync pass the Promi price payload into Pricing rather
	 * than knowing how tier rows are stored or paired.
	 */
	public function sync_promi(
		int $product_id,
		int $variation_id,
		array $price_data,
		bool $update_wc_price = true
	): bool {

		return $this->tiers
			->sync_promi(
				$product_id,
				$variation_id,
				$price_data,
				$update_wc_price
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Selling Price API
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return the applicable selling price for a quantity.
	 */
	public function selling_price(
		int $product_id,
		int $variation_id,
		int $quantity
	): ?float {

		return $this->tiers
			->selling_price(
				$product_id,
				$variation_id,
				$quantity
			);
	}


	/**
	 * Return all selling tiers.
	 */
	public function selling_tiers(
		int $product_id,
		int $variation_id = 0
	): array {

		return $this->tiers
			->selling_tiers(
				$product_id,
				$variation_id
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Purchase Price API
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return the applicable purchase price for a quantity.
	 */
	public function purchase_price(
		int $product_id,
		int $variation_id,
		int $quantity
	): ?float {

		return $this->tiers
			->purchase_price(
				$product_id,
				$variation_id,
				$quantity
			);
	}


	/**
	 * Return all purchasing tiers.
	 */
	public function purchase_tiers(
		int $product_id,
		int $variation_id = 0
	): array {

		return $this->tiers
			->purchase_tiers(
				$product_id,
				$variation_id
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Tier Quantities
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return all unique tier quantities.
	 */
	public function quantities(
		int $product_id,
		?int $variation_id = null
	): array {

		return $this->tiers
			->quantities(
				$product_id,
				$variation_id
			);
	}


	/**
	 * Return the first tier quantity greater than one.
	 *
	 * This reproduces the existing CX Tiered Pricing concept of
	 * "lowest tier quantity".
	 */
	public function lowest_tier_quantity(
		int $product_id,
		?int $variation_id = null
	): ?int {

		return $this->tiers
			->lowest_tier_quantity(
				$product_id,
				$variation_id
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Product Cleanup
	|--------------------------------------------------------------------------
	*/

	/**
	 * Delete tier data when WooCommerce products or variations are
	 * permanently deleted.
	 */
	public function handle_product_delete(
		int $post_id
	): void {

		$post_type =
			get_post_type(
				$post_id
			);

		if ( 'product' === $post_type ) {

			$this->repository
				->delete_by_product(
					$post_id
				);

			return;
		}

		if (
			'product_variation'
			=== $post_type
		) {

			$this->repository
				->delete_by_variation(
					$post_id
				);
		}
	}


	/*
	|--------------------------------------------------------------------------
	| Service Accessors
	|--------------------------------------------------------------------------
	*/

	public function engine(): Engine {
		return $this->engine;
	}


	public function repository(): PriceRepository {
		return $this->repository;
	}


	public function markup_rules(): MarkupRules {
		return $this->markup_rules;
	}


	public function markup_repository(): MarkupRepository {
		return $this->markup_repository;
	}


	public function costs(): CostCalculator {
		return $this->costs;
	}


	public function tiers(): TieredPricing {
		return $this->tiers;
	}


	public function cart(): CartPricing {
		return $this->cart;
	}


	/*
	|--------------------------------------------------------------------------
	| Module Dependencies
	|--------------------------------------------------------------------------
	*/

	public function catalog(): Catalog {
		return $this->catalog;
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