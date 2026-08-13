<?php

namespace PromiDataXWoo\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce cart pricing integration.
 *
 * Responsibilities:
 *
 * - Build pricing context for each cart item.
 * - Resolve the article pricing state.
 * - Run the unified Pricing Engine.
 * - Apply the resulting per-unit price to WooCommerce.
 * - Store an authoritative pricing breakdown on the cart item.
 *
 * Pricing business logic does not live here.
 *
 * Pipeline:
 *
 *     WooCommerce cart item
 *              ↓
 *     Article pricing state
 *              ↓
 *     Pricing Engine
 *              │
 *              ├── priority 10: TieredPricing
 *              │
 *              └── priority 20: Printing
 *              ↓
 *     Final WooCommerce unit price
 *
 * The cart template must never independently recalculate pricing.
 */
final class CartPricing {

	/**
	 * Cart-item meta key containing the authoritative pricing breakdown.
	 */
	public const BREAKDOWN_KEY =
		'_pdxw_pricing';


	/**
	 * Unified pricing engine.
	 */
	private Engine $engine;


	/**
	 * Tier pricing service.
	 */
	private TieredPricing $tiers;


	/**
	 * Whether WooCommerce hooks have been registered.
	 */
	private bool $initialized = false;


	/**
	 * Recursion guard.
	 */
	private bool $calculating = false;


	/**
	 * Constructor.
	 */
	public function __construct(
		Engine $engine,
		TieredPricing $tiers
	) {

		$this->engine =
			$engine;

		$this->tiers =
			$tiers;
	}


	/**
	 * Register WooCommerce hooks.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}


		$this->initialized = true;


		add_action(
			'woocommerce_before_calculate_totals',
			[
				$this,
				'calculate_cart',
			],
			99999
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Cart Pricing
	|--------------------------------------------------------------------------
	*/

	/**
	 * Apply the unified pricing engine to every WooCommerce cart item.
	 */
	public function calculate_cart(
		\WC_Cart $cart
	): void {

		if (
			is_admin()
			&& ! wp_doing_ajax()
		) {
			return;
		}


		if ( $this->calculating ) {
			return;
		}


		$this->calculating = true;


		try {

			$cart_contents =
				$cart->get_cart();


			foreach (
				$cart_contents as $cart_item_key => &$cart_item
			) {

				$this->calculate_item(
					(string) $cart_item_key,
					$cart_item
				);
			}


			unset(
				$cart_item
			);


			$cart->set_cart_contents(
				$cart_contents
			);

		} finally {

			$this->calculating = false;
		}
	}


	/**
	 * Calculate one cart item's final unit price.
	 */
	private function calculate_item(
		string $cart_item_key,
		array &$cart_item
	): void {

		$product =
			$cart_item['data']
				?? null;


		if (
			! $product instanceof \WC_Product
		) {
			return;
		}


		$quantity =
			max(
				1,
				absint(
					$cart_item['quantity']
						?? 1
				)
			);


		$product_id =
			absint(
				$cart_item['product_id']
					?? 0
			);


		$variation_id =
			absint(
				$cart_item['variation_id']
					?? 0
			);


		if ( ! $product_id ) {
			return;
		}


		/*
		|--------------------------------------------------------------------------
		| Raw WooCommerce Starting Price
		|--------------------------------------------------------------------------
		|
		| Engine receives the current WooCommerce price as its starting point.
		|
		| TieredPricing priority 10 replaces it with our calculated article
		| price whenever a usable Promi cost exists.
		|
		| The raw WooCommerce price is therefore only a fallback.
		*/

		$base_price =
			$this->base_price(
				$product
			);


		if ( null === $base_price ) {
			$base_price = 0.0;
		}


		/*
		|--------------------------------------------------------------------------
		| Article Pricing State
		|--------------------------------------------------------------------------
		|
		| Resolve the article result once before running Engine.
		|
		| This tells us whether the product is:
		|
		|     priced
		|
		| or:
		|
		|     price_on_request
		|
		| Engine itself intentionally remains numeric and generic.
		*/

		$article_result =
			$this->tiers
				->article_price(
					$product_id,
					$variation_id,
					$quantity
				);


		$price_on_request =
			CostCalculator::STATUS_PRICE_ON_REQUEST
			=== (
				$article_result['status']
					?? ''
			);


		/*
		|--------------------------------------------------------------------------
		| Unified Pricing Context
		|--------------------------------------------------------------------------
		*/

		$context = [

			'cart_item_key' =>
				$cart_item_key,

			'product_id' =>
				$product_id,

			'variation_id' =>
				$variation_id,

			'quantity' =>
				$quantity,

			'cart_item' =>
				$cart_item,

			'product' =>
				$product,

			/*
			 * This flag is important for Case 3.
			 *
			 * Printing must not accidentally turn a Price-on-request article
			 * into a normal purchasable product merely because a printing
			 * amount exists.
			 */
			'price_on_request' =>
				$price_on_request,

			/*
			 * Expose the resolved article result to later pricing modules.
			 *
			 * This avoids forcing Printing or future modules to repeat the
			 * article cost lookup.
			 */
			'article_pricing' =>
				$article_result,
		];


		/*
		|--------------------------------------------------------------------------
		| Allow other modules to enrich pricing context
		|--------------------------------------------------------------------------
		*/

		$context =
			apply_filters(
				'pdxw_pricing_context',
				$context,
				$cart_item
			);


		if ( ! is_array( $context ) ) {
			return;
		}


		/*
		|--------------------------------------------------------------------------
		| Final Engine Calculation
		|--------------------------------------------------------------------------
		|
		| The Engine executes:
		|
		|     priority 10 → TieredPricing
		|     priority 20 → Printing
		|
		| The Engine remains responsible for orchestration.
		*/

		$final_unit =
			$this->engine
				->calculate(
					$base_price,
					$context
				);


		$final_unit =
			max(
				0.0,
				(float) $final_unit
			);


		/*
		|--------------------------------------------------------------------------
		| Price on Request
		|--------------------------------------------------------------------------
		|
		| A product without a usable article cost must never become
		| purchasable merely because the Engine returns a numeric value.
		|
		| The current Engine represents this state numerically as 0.00.
		| The presentation layer can use the stored flag to show the inquiry
		| form instead of Add to Cart.
		*/

		if ( $price_on_request ) {

			$final_unit = 0.0;
		}


		/*
		|--------------------------------------------------------------------------
		| Apply Final Price to WooCommerce
		|--------------------------------------------------------------------------
		*/

		$product->set_price(
			$final_unit
		);


		$cart_item['data']
			->set_price(
				$final_unit
			);


		/*
		|--------------------------------------------------------------------------
		| Authoritative Breakdown
		|--------------------------------------------------------------------------
		|
		| Printing CartPricing / Printing Calculator can enrich the context
		| with its authoritative printing breakdown.
		|
		| We do not recalculate printing here.
		*/

		$printing =
			isset(
				$context['printing_breakdown']
			)
			&& is_array(
				$context['printing_breakdown']
			)
				? $context['printing_breakdown']
				: [];


		$printing_total =
			$this->numeric_value(
				$printing['total']
					?? 0
			);


		$printing_tier_total =
			$this->numeric_value(
				$printing['print_total']
					?? 0
			);


		$printing_fees_total =
			$this->numeric_value(
				$printing['fees']
					?? 0
			);


		/*
		|--------------------------------------------------------------------------
		| Article Breakdown
		|--------------------------------------------------------------------------
		*/

		$article_unit =
			$this->numeric_nullable(
				$article_result['article_price']
					?? null
			);


		/*
		 * If Case 3 is active, article_unit is intentionally null.
		 */
		$article_total =
			null !== $article_unit
				? $article_unit * $quantity
				: 0.0;


		/*
		|--------------------------------------------------------------------------
		| Final Breakdown
		|--------------------------------------------------------------------------
		*/

		$cart_item[
			self::BREAKDOWN_KEY
		] = [

			'status' =>
				$price_on_request
					? CostCalculator::STATUS_PRICE_ON_REQUEST
					: CostCalculator::STATUS_PRICED,

			'price_on_request' =>
				$price_on_request,

			'cost' =>
				$this->nullable_money(
					$article_result['cost']
						?? null
				),

			'article_markup' =>
				$this->money(
					$article_result['article_markup']
						?? 0
				),

			'article_source' =>
				$article_result['source']
					?? null,

			'manufacturer_discount' =>
				$this->money(
					$article_result[
						'manufacturer_discount'
					]
						?? 0
				),

			'article_unit' =>
				$this->nullable_money(
					$article_unit
				),

			'article_total' =>
				$this->money(
					$article_total
				),

			'printing_tier_total' =>
				$this->money(
					$printing_tier_total
				),

			'printing_fees_total' =>
				$this->money(
					$printing_fees_total
				),

			'printing_total' =>
				$this->money(
					$printing_total
				),

			'printing_unit' =>
				$this->money(
					$printing_total / $quantity
				),

			'final_unit' =>
				$this->money(
					$final_unit
				),

			'line_total' =>
				$this->money(
					$final_unit * $quantity
				),

			'quantity' =>
				$quantity,

			'product_id' =>
				$product_id,

			'variation_id' =>
				$variation_id,
		];
	}


	/*
	|--------------------------------------------------------------------------
	| Breakdown API
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return the authoritative pricing breakdown stored on a cart item.
	 *
	 * Cart templates should use this instead of recalculating prices.
	 */
	public function breakdown(
		array $cart_item
	): array {

		$breakdown =
			$cart_item[
				self::BREAKDOWN_KEY
			]
				?? [];


		if ( ! is_array( $breakdown ) ) {
			return [];
		}


		return $breakdown;
	}


	/**
	 * Return one breakdown value safely.
	 */
	public function breakdown_value(
		array $cart_item,
		string $key,
		float $default = 0.0
	): float {

		$breakdown =
			$this->breakdown(
				$cart_item
			);


		if (
			isset(
				$breakdown[$key]
			)
			&& is_numeric(
				$breakdown[$key]
			)
		) {

			return (float)
				$breakdown[$key];
		}


		return $default;
	}


	/**
	 * Determine whether this cart item is Price on request.
	 */
	public function is_price_on_request(
		array $cart_item
	): bool {

		$breakdown =
			$this->breakdown(
				$cart_item
			);


		return ! empty(
			$breakdown[
				'price_on_request'
			]
		);
	}


	/**
	 * Determine whether this cart item has a calculated breakdown.
	 */
	public function has_breakdown(
		array $cart_item
	): bool {

		return ! empty(
			$cart_item[
				self::BREAKDOWN_KEY
			]
		)
		&& is_array(
			$cart_item[
				self::BREAKDOWN_KEY
			]
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Base Price
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return the raw WooCommerce starting price.
	 *
	 * This is only the Engine starting point.
	 *
	 * It is NOT treated as the authoritative Promi selling price.
	 */
	private function base_price(
		\WC_Product $product
	): ?float {

		$regular_price =
			$product->get_regular_price(
				'edit'
			);


		if (
			'' !== $regular_price
			&& null !== $regular_price
			&& is_numeric(
				$regular_price
			)
		) {

			return max(
				0.0,
				(float) $regular_price
			);
		}


		/*
		 * Some imported/manual products may not have a regular price.
		 */
		$price =
			$product->get_price(
				'edit'
			);


		if (
			''
			=== $price
			|| null === $price
			|| ! is_numeric(
				$price
			)
		) {

			return null;
		}


		return max(
			0.0,
			(float) $price
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Numeric Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return a non-negative numeric value.
	 */
	private function numeric_value(
		mixed $value
	): float {

		if (
			null === $value
			|| ''
				=== (string) $value
			|| ! is_numeric(
				$value
			)
		) {

			return 0.0;
		}


		return max(
			0.0,
			(float) $value
		);
	}


	/**
	 * Return a nullable numeric amount.
	 */
	private function numeric_nullable(
		mixed $value
	): ?float {

		if (
			null === $value
			|| ''
				=== (string) $value
			|| ! is_numeric(
				$value
			)
		) {

			return null;
		}


		return max(
			0.0,
			(float) $value
		);
	}


	/**
	 * Format a monetary value using WooCommerce's configured precision.
	 */
	private function money(
		mixed $value
	): float {

		return (float)
			wc_format_decimal(
				$this->numeric_value(
					$value
				),
				wc_get_price_decimals()
			);
	}


	/**
	 * Format a nullable monetary value.
	 */
	private function nullable_money(
		mixed $value
	): ?float {

		$value =
			$this->numeric_nullable(
				$value
			);


		if ( null === $value ) {
			return null;
		}


		return (float)
			wc_format_decimal(
				$value,
				wc_get_price_decimals()
			);
	}


	/*
	|--------------------------------------------------------------------------
	| State
	|--------------------------------------------------------------------------
	*/

	/**
	 * Determine whether the WooCommerce hooks have been registered.
	 */
	public function is_initialized(): bool {

		return $this->initialized;
	}
}
