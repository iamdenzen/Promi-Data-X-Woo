<?php

namespace PromiDataXWoo\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce sample-product behavior.
 *
 * Existing XSImpress sample model:
 *
 *     cart_item['is_sample'] = 1
 *
 * A sample:
 *
 * - Is identified by the is_sample cart-item flag.
 * - Displays "(muster)" beside the product name.
 * - Is permanently limited to quantity 1.
 *
 * Determining whether an add-to-cart request is a sample belongs in Ajax.
 * This class only manages the resulting WooCommerce cart behavior.
 */
final class Samples {

	public const CART_KEY = 'is_sample';

	private bool $initialized = false;


	/**
	 * Register WooCommerce sample hooks.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;


		/*
		|--------------------------------------------------------------------------
		| Cart Label
		|--------------------------------------------------------------------------
		*/

		add_filter(
			'woocommerce_cart_item_name',
			[
				$this,
				'add_sample_label',
			],
			10,
			3
		);


		/*
		|--------------------------------------------------------------------------
		| Quantity Enforcement
		|--------------------------------------------------------------------------
		*/

		add_action(
			'woocommerce_before_calculate_totals',
			[
				$this,
				'enforce_quantity',
			],
			10
		);


		do_action(
			'pdxw_frontend_samples_init',
			$this
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Identification
	|--------------------------------------------------------------------------
	*/

	/**
	 * Determine whether a WooCommerce cart item is a sample.
	 */
	public function is_sample(
		array $cart_item
	): bool {

		return ! empty(
			$cart_item[
				self::CART_KEY
			]
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Cart Label
	|--------------------------------------------------------------------------
	*/

	/**
	 * Append "(muster)" to sample product names.
	 *
	 * This preserves the existing XSImpress cart presentation:
	 *
	 *     Product Name (muster)
	 */
	public function add_sample_label(
		string $product_name,
		array $cart_item,
		string $cart_item_key
	): string {

		if (
			! $this->is_sample(
				$cart_item
			)
		) {
			return $product_name;
		}


		return $product_name
			. ' <span class="cx-sample-label">'
			. esc_html__(
				'(muster)',
				'promi-data-x-woo'
			)
			. '</span>';
	}


	/*
	|--------------------------------------------------------------------------
	| Quantity Enforcement
	|--------------------------------------------------------------------------
	*/

	/**
	 * Force every sample cart item to quantity 1.
	 *
	 * This protects the sample rule even if quantity is changed through:
	 *
	 * - WooCommerce cart controls.
	 * - Direct requests.
	 * - Third-party cart code.
	 * - Session restoration.
	 *
	 * The old CX Product implementation only corrected quantities greater
	 * than one. Preserve that behavior.
	 */
	public function enforce_quantity(
		\WC_Cart $cart
	): void {

		if (
			is_admin()
			&& ! wp_doing_ajax()
		) {
			return;
		}


		foreach (
			$cart->get_cart()
				as $cart_item_key => $cart_item
		) {

			if (
				! $this->is_sample(
					$cart_item
				)
			) {
				continue;
			}


			$quantity =
				absint(
					$cart_item[
						'quantity'
					] ?? 0
				);

			if ( $quantity <= 1 ) {
				continue;
			}


			/*
			 * Do not refresh totals from inside
			 * woocommerce_before_calculate_totals.
			 *
			 * WooCommerce is already calculating them.
			 */
			$cart->set_quantity(
				(string) $cart_item_key,
				1,
				false
			);


			do_action(
				'pdxw_sample_quantity_corrected',
				$cart_item_key,
				$quantity
			);
		}
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
