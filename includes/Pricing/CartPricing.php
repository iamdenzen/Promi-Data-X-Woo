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
 *
 * Pricing business logic does not live here.
 *
 * Current pipeline:
 *
 * WooCommerce regular price
 *          ↓
 * TieredPricing        priority 10
 *          ↓
 * Printing Calculator priority 20
 *          ↓
 * Final WooCommerce unit price
 */
final class CartPricing {

	private Engine $engine;

	private bool $initialized = false;

	private bool $calculating = false;


	public function __construct(
		Engine $engine
	) {
		$this->engine = $engine;
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

		add_action(
			'woocommerce_cart_item_subtotal',
			function($subtotal, $cart_item, $cart_item_key) {
				return $this->calculate_item(
					(string) $cart_item_key,
					$cart_item
				);
			},
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


		/*
		|--------------------------------------------------------------------------
		| Quantity
		|--------------------------------------------------------------------------
		*/

		$quantity = max(
			1,
			absint(
				$cart_item['quantity']
					?? 1
			)
		);


		/*
		|--------------------------------------------------------------------------
		| Product Identity
		|--------------------------------------------------------------------------
		*/

		$product_id = absint(
			$cart_item['product_id']
				?? 0
		);

		$variation_id = absint(
			$cart_item['variation_id']
				?? 0
		);

		if ( ! $product_id ) {

			$product_id = $variation_id
				? absint(
					wp_get_post_parent_id(
						$variation_id
					)
				)
				: $product->get_id();
		}

		if ( ! $product_id ) {
			return;
		}


		/*
		|--------------------------------------------------------------------------
		| Base Price
		|--------------------------------------------------------------------------
		|
		| This must NOT use the already-calculated cart price.
		|
		| WooCommerce can call calculate_totals() several times during one
		| request. If we used $product->get_price() after a previous
		| set_price(), tier/printing costs could be applied repeatedly.
		|
		| The existing XSImpress pricing architecture uses the regular product
		| price as the engine's starting value, so we preserve that behavior.
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
		| Context
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


		/*
		 * Other business domains can append their pricing information here.
		 *
		 * Printing currently uses this filter to add:
		 *
		 *     $context['printing']
		 *
		 * The Pricing module therefore does not need to know anything about
		 * print positions, options, fees or cart-storage structure.
		 */
		$context = apply_filters(
			'pdxw_pricing_context',
			$context,
			$cart_item
		);

		if ( ! is_array( $context ) ) {
			return;
		}


		/*
		|--------------------------------------------------------------------------
		| Unified Calculation
		|--------------------------------------------------------------------------
		*/

		$final_price =
			$this->engine
				->calculate(
					$base_price,
					$context
				);

		error_log( "Base price: $base_price, Final price: $final_price" );
		/*
		|--------------------------------------------------------------------------
		| WooCommerce
		|--------------------------------------------------------------------------
		|
		| Engine::calculate() always returns a UNIT price.
		|
		| WooCommerce multiplies this by the cart quantity when calculating
		| line totals.
		*/

		$product->set_price(
			wc_format_decimal(
				$final_price,
				wc_get_price_decimals()
			)
		);


		do_action(
			'pdxw_cart_item_priced',
			$cart_item_key,
			$final_price,
			$base_price,
			$context,
			$cart_item
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Base Price
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return the raw price used to start our pricing pipeline.
	 *
	 * Regular price is intentionally preferred because set_price() mutates
	 * the cart product object's active price during totals calculation.
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
		 * Some manually-created/imported products may not have a separate
		 * regular price. Fall back to the raw stored active price.
		 *
		 * This fallback is secondary; normal Promi products should have
		 * their regular price synchronized by TieredPricing.
		 */
		$price =
			$product->get_price(
				'edit'
			);

		if (
			'' === $price
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
	| Manual Calculation API
	|--------------------------------------------------------------------------
	*/

	/**
	 * Calculate a unit price outside WooCommerce cart processing.
	 *
	 * Useful later for:
	 *
	 * - AJAX configurator previews.
	 * - REST endpoints.
	 * - Admin previews.
	 * - Tests.
	 */
	public function calculate(
		float $base_price,
		int $product_id,
		int $variation_id,
		int $quantity,
		array $context = []
	): float {

		$product_id =
			absint(
				$product_id
			);

		$variation_id =
			absint(
				$variation_id
			);

		$quantity = max(
			1,
			absint(
				$quantity
			)
		);

		$context = array_merge(
			$context,
			[
				'product_id' =>
					$product_id,

				'variation_id' =>
					$variation_id,

				'quantity' =>
					$quantity,
			]
		);

		return $this->engine
			->calculate(
				max(
					0.0,
					$base_price
				),
				$context
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