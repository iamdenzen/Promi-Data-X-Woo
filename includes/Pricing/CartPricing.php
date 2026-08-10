<?php

namespace PromiDataXWoo\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce cart pricing integration.
 *
 * Responsibilities:
 *
 * - Build pricing context for each cart item.
 * - Start from the raw WooCommerce regular price.
 * - Allow other modules to enrich the pricing context.
 * - Run the unified pricing Engine.
 * - Apply the resulting per-unit price to WooCommerce.
 * - Store an authoritative pricing breakdown on the cart item.
 *
 * Pricing business logic does not live here.
 *
 * Current pipeline:
 *
 * WooCommerce regular price
 *          ↓
 * TieredPricing        priority 10
 *          ↓
 * Printing Calculator  priority 20
 *          ↓
 * Final WooCommerce unit price
 *
 * The pricing breakdown is derived from that same calculation.
 * The cart template must never independently recalculate pricing.
 */
final class CartPricing {

	/**
	 * Cart-item meta key containing the authoritative pricing breakdown.
	 */
	public const BREAKDOWN_KEY =
		'_pdxw_pricing';


	private Engine $engine;
	
	private TieredPricing $tiers;

	private bool $initialized = false;

	private bool $calculating = false;

	public function __construct(
		Engine $engine,
		TieredPricing $tiers
	) {
		$this->engine = $engine;
		$this->tiers  = $tiers;
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
			20
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

		/*
		 * Avoid running on unrelated wp-admin requests.
		 *
		 * WooCommerce checkout/cart AJAX requests still need pricing.
		 */
		if (
			is_admin()
			&& ! wp_doing_ajax()
		) {
			return;
		}


		/*
		 * Protect against accidental recursion if another pricing callback
		 * invokes cart-total calculation.
		 */
		if ( $this->calculating ) {
			return;
		}

		$this->calculating = true;

		try {

			foreach (
				$cart->get_cart()
				as $cart_item_key => &$cart_item
			) {

				$this->calculate_item(
					(string) $cart_item_key,
					$cart_item
				);
			}

			unset( $cart_item );

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
			! $product
			|| ! $product instanceof \WC_Product
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


		/*
		|--------------------------------------------------------------------------
		| Raw WooCommerce Starting Price
		|--------------------------------------------------------------------------
		*/

		$base_price =
			$this->base_price(
				$product
			);


		if ( null === $base_price ) {
			return;
		}


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
		];


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
		| Article / Tier Price
		|--------------------------------------------------------------------------
		|
		| This is exactly the first stage of Engine:
		|
		| TieredPricing priority 10.
		*/

		$article_unit =
			$this->tiers
				->selling_price(
					$product_id,
					$variation_id,
					$quantity
				);


		if ( null === $article_unit ) {

			$article_unit =
				$base_price;
		}


		$article_unit =
			max(
				0.0,
				(float) $article_unit
		);


		/*
		|--------------------------------------------------------------------------
		| Final Engine Price
		|--------------------------------------------------------------------------
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
		| Apply to WooCommerce
		|--------------------------------------------------------------------------
		*/

		$product->set_price(
			wc_format_decimal(
				$final_unit,
				wc_get_price_decimals()
			)
		);


		/*
		|--------------------------------------------------------------------------
		| Printing Breakdown
		|--------------------------------------------------------------------------
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
			max(
				0.0,
				(float) (
					$printing['total']
					?? 0
				)
			);


		$printing_tier_total =
			max(
				0.0,
				(float) (
					$printing['print_total']
					?? 0
				)
			);


		$printing_fees_total =
			max(
				0.0,
				(float) (
					$printing['fees']
					?? 0
				)
			);


		/*
		|--------------------------------------------------------------------------
		| Final Breakdown
		|--------------------------------------------------------------------------
		|
		| Every displayed value comes from the same calculation pipeline.
		*/

		$cart_item[
			'_pdxw_pricing'
		] = [

			'article_unit' =>
				$this->money(
					$article_unit
				),

			'article_total' =>
				$this->money(
					$article_unit
					* $quantity
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
					$printing_total
					/ $quantity
				),

			'final_unit' =>
				$this->money(
					$final_unit
				),

			'line_total' =>
				$this->money(
					$final_unit
					* $quantity
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
	| Breakdown
	|--------------------------------------------------------------------------
	*/

	/**
	 * Build the authoritative cart pricing breakdown.
	 *
	 * The only potentially complex component is printing. That value is
	 * obtained from the Printing Calculator already used by the pricing
	 * engine rather than being reconstructed from repository rows.
	 */
	private function build_breakdown(
		float $base_price,
		float $final_price,
		int $quantity,
		array $context,
		int $product_id,
		int $variation_id
	): array {

		$quantity =
			max(
				1,
				$quantity
			);


		/*
		|--------------------------------------------------------------------------
		| Article / Tier Price
		|--------------------------------------------------------------------------
		|
		| The tier callback replaces the incoming price when a tier exists.
		|
		| Therefore we can resolve the article-side price directly from the
		| same TieredPricing service used by Engine priority 10.
		*/

		$article_unit =
			$this->article_unit_price(
				$base_price,
				$product_id,
				$variation_id,
				$quantity
			);


		$article_total =
			$article_unit
			* $quantity;


		/*
		|--------------------------------------------------------------------------
		| Printing
		|--------------------------------------------------------------------------
		*/

		$printing =
			isset(
				$context['printing']
			)
			&& is_array(
				$context['printing']
			)
				? $context['printing']
				: [];


		$printing_total =
			0.0;


		if ( ! empty( $printing ) ) {

			$printing_total =
				$this->printing_total(
					$printing,
					$product_id,
					$variation_id,
					$quantity
				);
		}


		$printing_total =
			max(
				0.0,
				(float) $printing_total
			);


		$printing_unit =
			$printing_total
			/ $quantity;


		/*
		|--------------------------------------------------------------------------
		| Printing Tier / Fee Breakdown
		|--------------------------------------------------------------------------
		|
		| These values are informational only.
		|
		| The complete printing_total above remains authoritative.
		|
		| We derive the fee amount from:
		|
		|     total printing
		|     -
		|     print tier total
		|
		| rather than recalculating the fee rules a second time.
		*/

		$printing_tier_total =
			$this->printing_tier_total(
				$printing,
				$quantity
			);


		$printing_tier_total =
			max(
				0.0,
				(float) $printing_tier_total
			);


		$printing_fees_total =
			max(
				0.0,
				$printing_total
				- $printing_tier_total
			);


		/*
		|--------------------------------------------------------------------------
		| Final Line
		|--------------------------------------------------------------------------
		*/

		$line_total =
			$final_price
			* $quantity;


		/*
		|--------------------------------------------------------------------------
		| Rounding
		|--------------------------------------------------------------------------
		|
		| Keep every displayed component at WooCommerce's configured currency
		| precision.
		*/

		return [

			'article_unit' =>
				$this->money(
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
					$printing_unit
				),

			'final_unit' =>
				$this->money(
					$final_price
				),

			'line_total' =>
				$this->money(
					$line_total
				),

			'quantity' =>
				$quantity,

			'product_id' =>
				$product_id,

			'variation_id' =>
				$variation_id,
		];
	}


	/**
	 * Resolve the article-side unit price.
	 *
	 * This mirrors Engine priority 10 without running the engine again.
	 */
	private function article_unit_price(
		float $base_price,
		int $product_id,
		int $variation_id,
		int $quantity
	): float {

		$tier_price =
			$this->engine
				->tiers()
				->selling_price(
					$product_id,
					$variation_id,
					$quantity
				);


		if ( null !== $tier_price ) {

			return max(
				0.0,
				(float) $tier_price
			);
		}


		return max(
			0.0,
			(float) $base_price
		);
	}


	/**
	 * Calculate the authoritative total printing cost.
	 */
	private function printing_total(
		array $printing,
		int $product_id,
		int $variation_id,
		int $quantity
	): float {

		$calculator =
			$this->printing_calculator();


		if ( ! $calculator ) {
			return 0.0;
		}


		return $calculator->calculate(
			$printing,
			[
				'product_id' =>
					$product_id,

				'variation_id' =>
					$variation_id,

				'quantity' =>
					$quantity,
			]
		);
	}


	/**
	 * Resolve the Printing Calculator without coupling Pricing to the
	 * Printing module at construction time.
	 *
	 * The calculator is already the callback registered by Printing at
	 * priority 20.
	 */
	private function printing_calculator(): ?object {

		/*
		 * Printing stores its calculator inside the pricing context only
		 * indirectly, so use the module accessor when the application is
		 * available.
		 */

		$plugin =
			function_exists( 'pdxw' )
				? pdxw()
				: null;


		if ( ! $plugin ) {
			return null;
		}


		$printing =
			$plugin->printing();


		if ( ! $printing ) {
			return null;
		}


		return $printing->calculator();
	}


	/**
	 * Calculate print tier prices only.
	 *
	 * This is used solely to provide the cart's "Veredelung" / "Einrichtung"
	 * display breakdown.
	 *
	 * It does NOT affect the actual WooCommerce price.
	 */
	private function printing_tier_total(
		array $printing,
		int $quantity
	): float {

		$total = 0.0;


		foreach (
			$printing as $selection
		) {

			if ( ! is_array( $selection ) ) {
				continue;
			}


			$option_id =
				absint(
					$selection['option_id']
						?? 0
				);


			if ( ! $option_id ) {
				continue;
			}


			$price =
				$this->printing_option_price(
					$option_id,
					$quantity
				);


			if ( null !== $price ) {

				$total +=
					max(
						0.0,
						(float) $price
					)
					* $quantity;
			}
		}


		return $total;
	}


	/**
	 * Resolve one print option's applicable selling price.
	 */
	private function printing_option_price(
		int $option_id,
		int $quantity
	): ?float {

		$plugin =
			function_exists( 'pdxw' )
				? pdxw()
				: null;


		if ( ! $plugin ) {
			return null;
		}


		$printing =
			$plugin->printing();


		if ( ! $printing ) {
			return null;
		}


		return $printing
			->repository()
			->get_applicable_selling_price(
				$option_id,
				$quantity
			);
	}


	/**
	 * Normalize one monetary value to WooCommerce precision.
	 */
	private function money(
		float $value
	): float {

		return (float)
			wc_format_decimal(
				$value,
				wc_get_price_decimals()
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Base Price
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return the raw price used to start our pricing pipeline.
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
			&& is_numeric( $regular_price )
		) {

			return max(
				0.0,
				(float) $regular_price
			);
		}


		/*
		 * Some manually-created/imported products may not have a separate
		 * regular price.
		 */
		$price =
			$product->get_price(
				'edit'
			);


		if (
			'' === $price
			|| null === $price
			|| ! is_numeric( $price )
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
	| Public Breakdown API
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return the authoritative pricing breakdown stored on a cart item.
	 *
	 * This is intended for cart templates and other presentation layers.
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


		return isset(
			$breakdown[
				$key
			]
		)
			&& is_numeric(
				$breakdown[
					$key
				]
			)
				? (float)
					$breakdown[
						$key
					]
				: $default;
	}


	/**
	 * Whether this cart item has a calculated pricing breakdown.
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
	| State
	|--------------------------------------------------------------------------
	*/

	public function is_initialized(): bool {
		return $this->initialized;
	}
}
