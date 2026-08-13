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
 * - Product / variation tier pricing.
 * - Article cost resolution.
 * - Category-based article markup.
 * - Manufacturer discounts.
 * - Finishing / decoration markup support.
 * - WooCommerce cart price application.
 * - Promi tier synchronization.
 *
 * Printing registers itself with the Engine separately at priority 20.
 *
 * Service dependency order:
 *
 *     PriceRepository
 *          ↓
 *     MarkupRepository
 *          ↓
 *     MarkupRules
 *
 *     ManufacturerDiscount
 *
 *     SellingPriceCalculator
 *          ↓
 *     CostCalculator
 *          ↓
 *     TieredPricing
 *          ↓
 *     Engine
 *          ↓
 *     CartPricing
 *          ↓
 *     RestController
 */
final class Pricing {

	private Plugin $plugin;

	private Catalog $catalog;

	private Engine $engine;

	private PriceRepository $repository;

	private MarkupRepository $markup_repository;

	private MarkupRules $markup_rules;

	private ManufacturerDiscount $manufacturer_discount;

	private SellingPriceCalculator $selling_prices;

	private CostCalculator $costs;

	private TieredPricing $tiers;

	private CartPricing $cart;

	private RestController $rest;

	private bool $initialized = false;


	/**
	 * Constructor.
	 */
	public function __construct(
		Plugin $plugin,
		Catalog $catalog
	) {

		$this->plugin =
			$plugin;

		$this->catalog =
			$catalog;

		$this->register_services();
	}


	/**
	 * Build the complete pricing service graph.
	 *
	 * Every service is constructed exactly once and only after all of its
	 * dependencies have been constructed.
	 */
	private function register_services(): void {

		/*
		|--------------------------------------------------------------------------
		| Raw Price Storage
		|--------------------------------------------------------------------------
		|
		| PriceRepository is responsible for Promi tier data and database
		| access. It does not calculate customer markups.
		*/

		$this->repository =
			new PriceRepository();


		/*
		|--------------------------------------------------------------------------
		| Markup Rules Storage
		|--------------------------------------------------------------------------
		*/

		$this->markup_repository =
			new MarkupRepository();


		/*
		|--------------------------------------------------------------------------
		| Markup Business Rules
		|--------------------------------------------------------------------------
		|
		| Handles:
		|
		| - article/category markup,
		| - finishing/print-option markup,
		| - default markup values.
		|
		| Manufacturer discounts intentionally live in their own service.
		*/

		$this->markup_rules =
			new MarkupRules(
				$this->markup_repository
			);


		/*
		|--------------------------------------------------------------------------
		| Manufacturer Discount
		|--------------------------------------------------------------------------
		|
		| Uses the existing WooCommerce product_brand taxonomy and the shared
		| manufacturer-discount meta key.
		*/

		$this->manufacturer_discount =
			new ManufacturerDiscount();


		/*
		|--------------------------------------------------------------------------
		| Selling Price Mathematics
		|--------------------------------------------------------------------------
		|
		| Contains only the mathematical markup/rounding rules.
		*/

		$this->selling_prices =
			new SellingPriceCalculator();


		/*
		|--------------------------------------------------------------------------
		| Cost Calculator
		|--------------------------------------------------------------------------
		|
		| Resolves:
		|
		| Case 1:
		|     purchase_price
		|
		| Case 2:
		|     RecommendedSellingPrice
		|     → manufacturer discount
		|
		| Case 3:
		|     price on request
		|
		| Then delegates the actual article markup mathematics to
		| SellingPriceCalculator.
		*/

		$this->costs =
			new CostCalculator(
				$this->repository,
				$this->markup_rules,
				$this->manufacturer_discount,
				$this->selling_prices
			);


		/*
		|--------------------------------------------------------------------------
		| Tier Pricing
		|--------------------------------------------------------------------------
		|
		| Connects quantity-tier resolution with the new cost/markup system.
		*/

		$this->tiers =
			new TieredPricing(
				$this->repository,
				$this->costs
			);


		/*
		|--------------------------------------------------------------------------
		| Unified Calculation Engine
		|--------------------------------------------------------------------------
		|
		| The Engine is the central orchestrator.
		|
		| TieredPricing registers at priority 10.
		| Printing registers separately at priority 20.
		*/

		$this->engine =
			new Engine();


		/*
		|--------------------------------------------------------------------------
		| WooCommerce Cart Pricing
		|--------------------------------------------------------------------------
		|
		| CartPricing depends on the already-built Engine and TieredPricing
		| service.
		*/

		$this->cart =
			new CartPricing(
				$this->engine,
				$this->tiers
			);


		/*
		|--------------------------------------------------------------------------
		| REST Controller
		|--------------------------------------------------------------------------
		|
		| REST endpoints can access the Pricing module through this object.
		*/

		$this->rest =
			new RestController(
				$this
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
		| Article/tier pricing executes first.
		|
		| Printing registers itself later at priority 20.
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
		| REST Controller
		|--------------------------------------------------------------------------
		*/

		$this->rest->init();


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
	 * ProductSync can pass the Promi payload here without needing to know
	 * how the pricing rows are stored or paired.
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
	 * Return the calculated customer selling price for a quantity.
	 *
	 * This is NOT the raw Promi RecommendedSellingPrice.
	 *
	 * Returns null when the product is Price on request.
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
	 * Return all raw selling tiers.
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
	 * Return the raw applicable purchase price for a quantity.
	 *
	 * This is the GeneralBuyingPrice / purchase_price source.
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
	 * Return all raw purchasing tiers.
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
	 * This preserves the existing CX Tiered Pricing behavior.
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

	/**
	 * Return the unified pricing engine.
	 */
	public function engine(): Engine {

		return $this->engine;
	}


	/**
	 * Return the raw price repository.
	 */
	public function repository(): PriceRepository {

		return $this->repository;
	}


	/**
	 * Return markup business rules.
	 */
	public function markup_rules(): MarkupRules {

		return $this->markup_rules;
	}


	/**
	 * Return markup repository.
	 */
	public function markup_repository(): MarkupRepository {

		return $this->markup_repository;
	}


	/**
	 * Return manufacturer discount service.
	 */
	public function manufacturer_discount(): ManufacturerDiscount {

		return $this->manufacturer_discount;
	}


	/**
	 * Return selling-price mathematics service.
	 */
	public function selling_prices(): SellingPriceCalculator {

		return $this->selling_prices;
	}


	/**
	 * Return article cost calculator.
	 */
	public function costs(): CostCalculator {

		return $this->costs;
	}


	/**
	 * Return tier-pricing service.
	 */
	public function tiers(): TieredPricing {

		return $this->tiers;
	}


	/**
	 * Return cart-pricing service.
	 */
	public function cart(): CartPricing {

		return $this->cart;
	}


	/*
	|--------------------------------------------------------------------------
	| Module Dependencies
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return Catalog module.
	 */
	public function catalog(): Catalog {

		return $this->catalog;
	}


	/**
	 * Return root Plugin container.
	 */
	public function plugin(): Plugin {

		return $this->plugin;
	}


	/*
	|--------------------------------------------------------------------------
	| State
	|--------------------------------------------------------------------------
	*/

	/**
	 * Determine whether Pricing has been initialized.
	 */
	public function is_initialized(): bool {

		return $this->initialized;
	}
}
