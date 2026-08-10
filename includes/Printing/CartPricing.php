<?php

namespace PromiDataXWoo\Printing;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce cart integration for printing.
 *
 * Existing XSImpress cart structure:
 *
 * [
 *     'cx_print' => [
 *         $position_id => $option_id,
 *         $position_id => $option_id,
 *     ],
 * ]
 *
 * Responsibilities:
 *
 * - Normalize print selections stored in cart items.
 * - Expose printing context to the unified pricing engine.
 * - Display selected printing in cart and checkout.
 * - Save selected printing onto WooCommerce order items.
 * - Validate selections before pricing.
 *
 * Adding products to the cart remains a Frontend/configurator concern.
 */
final class CartPricing {

	private Printing $printing;

	private Calculator $calculator;


	public function __construct(
		Printing $printing,
		Calculator $calculator
	) {
		$this->printing   = $printing;
		$this->calculator = $calculator;
	}


	/**
	 * Register WooCommerce hooks.
	 */
	public function init(): void {

		/*
		|--------------------------------------------------------------------------
		| Cart / Checkout Display
		|--------------------------------------------------------------------------
		*/

		add_filter(
			'woocommerce_get_item_data',
			[ $this, 'display_cart_data' ],
			10,
			2
		);


		/*
		|--------------------------------------------------------------------------
		| Order Item Metadata
		|--------------------------------------------------------------------------
		*/

		add_action(
			'woocommerce_checkout_create_order_line_item',
			[ $this, 'add_order_meta' ],
			10,
			4
		);


		/*
		|--------------------------------------------------------------------------
		| Pricing Context
		|--------------------------------------------------------------------------
		|
		| Pricing itself is NOT applied here.
		|
		| The existing CX Print plugin already stopped doing direct
		| woocommerce_before_calculate_totals pricing and moved that job to
		| CX_Pricing_Engine.
		|
		| We keep that architecture.
		*/

		add_filter(
			'pdxw_pricing_context',
			[ $this, 'add_pricing_context' ],
			20,
			2
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Pricing Context
	|--------------------------------------------------------------------------
	*/

	/**
	 * Add print selections to the unified Pricing Engine context.
	 *
	 * Expected base context:
	 *
	 * [
	 *     'product_id'   => 123,
	 *     'variation_id' => 456,
	 *     'quantity'     => 100,
	 *     'cart_item'    => [...],
	 * ]
	 */
	public function add_pricing_context(
		array $context,
		array $cart_item
	): array {

		$selections = $this->selections(
			$cart_item
		);

		if ( empty( $selections ) ) {
			return $context;
		}

		$product_id = absint(
			$cart_item['product_id']
			?? $context['product_id']
			?? 0
		);

		$variation_id = absint(
			$cart_item['variation_id']
			?? $context['variation_id']
			?? 0
		);

		/*
		 * CX_Print::validate_selection() historically validates against
		 * the variation when present, otherwise against the parent product.
		 */
		$entity_id = $variation_id
			?: $product_id;

		if (
			! $this->validate(
				$entity_id,
				$selections
			)
		) {
			return $context;
		}

		$context['printing'] =
			$this->to_calculator_selections(
				$selections
			);

		$calculator_selections =
			$this->to_calculator_selections(
				$selections
			);


		$quantity =
			max(
				1,
				absint(
					$cart_item['quantity']
					?? $context['quantity']
					?? 1
				)
			);


		$printing_breakdown =
			$this->calculator
				->calculate_breakdown(
					$calculator_selections,
					[
						'product_id' =>
							$product_id,

						'variation_id' =>
							$variation_id,

						'quantity' =>
							$quantity,
					]
				);


		$context['printing'] =
			$calculator_selections;


		/*
		|--------------------------------------------------------------------------
		| Authoritative Printing Breakdown
		|--------------------------------------------------------------------------
		*/

		$context['printing_breakdown'] =
			$printing_breakdown;


		return $context;
	}


	/*
	|--------------------------------------------------------------------------
	| Cart / Checkout Display
	|--------------------------------------------------------------------------
	*/

	/**
	 * Display selected print options underneath the cart item.
	 *
	 * This reproduces CX_Print_Cart::display_cart_data().
	 */
	public function display_cart_data(
		array $data,
		array $cart_item
	): array {

		$selections = $this->selections(
			$cart_item
		);

		if ( empty( $selections ) ) {
			return $data;
		}

		foreach (
			$selections as $position_id => $option_id
		) {

			$position =
				$this->printing
					->positions()
					->find(
						$position_id
					);

			$option =
				$this->printing
					->options()
					->find(
						$option_id
					);

			if (
				! $position
				|| ! $option
			) {
				continue;
			}

			$data[] = [
				'name' =>
					(string)
						$position
							->position_label,

				'value' =>
					(string)
						$option
							->name,
			];
		}

		return $data;
	}


	/*
	|--------------------------------------------------------------------------
	| Order Metadata
	|--------------------------------------------------------------------------
	*/

	/**
	 * Save printing selections to WooCommerce order items.
	 *
	 * The existing CX Print plugin saves:
	 *
	 *     Position Label => Print Option Name
	 *
	 * We preserve that visible order metadata exactly.
	 */
	public function add_order_meta(
		\WC_Order_Item_Product $item,
		string $cart_item_key,
		array $values,
		\WC_Order $order
	): void {

		$selections = $this->selections(
			$values
		);

		if ( empty( $selections ) ) {
			return;
		}

		foreach (
			$selections as $position_id => $option_id
		) {

			$position =
				$this->printing
					->positions()
					->find(
						$position_id
					);

			$option =
				$this->printing
					->options()
					->find(
						$option_id
					);

			if (
				! $position
				|| ! $option
			) {
				continue;
			}

			$item->add_meta_data(
				(string)
					$position
						->position_label,

				(string)
					$option
						->name
			);
		}


		/*
		|--------------------------------------------------------------------------
		| Internal Structured Metadata
		|--------------------------------------------------------------------------
		|
		| The old plugin only stored the human-readable position/option
		| metadata.
		|
		| For the rebuild I also recommend storing the IDs privately.
		|
		| This gives us reliable historical data if an option is renamed,
		| without changing anything visible in WooCommerce.
		*/

		$item->add_meta_data(
			'_pdxw_print',
			$selections,
			true
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Selection Handling
	|--------------------------------------------------------------------------
	*/

	/**
	 * Extract and normalize legacy cx_print cart data.
	 *
	 * Canonical cart format remains:
	 *
	 * [
	 *     position_id => option_id,
	 * ]
	 */
	public function selections(
		array $cart_item
	): array {

		if (
			empty(
				$cart_item['cx_print']
			)
			|| ! is_array(
				$cart_item['cx_print']
			)
		) {
			return [];
		}

		$selections = [];

		foreach (
			$cart_item['cx_print']
				as $position_id => $option_id
		) {

			$position_id = absint(
				$position_id
			);

			$option_id = absint(
				$option_id
			);

			if (
				! $position_id
				|| ! $option_id
			) {
				continue;
			}

			$selections[
				$position_id
			] = $option_id;
		}

		return $selections;
	}


	/**
	 * Normalize data before it is stored by the configurator.
	 *
	 * This will be called later by Frontend/Ajax.php.
	 */
	public function sanitize_selections(
		mixed $raw
	): array {

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$selections = [];

		foreach (
			$raw as $position_id => $option_id
		) {

			$position_id = absint(
				$position_id
			);

			$option_id = absint(
				$option_id
			);

			if (
				! $position_id
				|| ! $option_id
			) {
				continue;
			}

			$selections[
				$position_id
			] = $option_id;
		}

		return $selections;
	}


	/**
	 * Validate every print selection.
	 *
	 * This reproduces CX_Print::validate_selection().
	 */
	public function validate(
		int $product_or_variation_id,
		array $selections
	): bool {

		$product_or_variation_id =
			absint(
				$product_or_variation_id
			);

		if (
			! $product_or_variation_id
			|| empty( $selections )
		) {
			return false;
		}

		foreach (
			$selections
				as $position_id => $option_id
		) {

			if (
				! $this->printing
					->repository()
					->selection_is_valid(
						$product_or_variation_id,
						absint(
							$position_id
						),
						absint(
							$option_id
						)
					)
			) {
				return false;
			}
		}

		return true;
	}


	/**
	 * Remove print options that do not meet their minimum order quantity.
	 *
	 * The existing cx-product AJAX add-to-cart handler performs exactly
	 * this check before adding cx_print to the cart.
	 *
	 * We move the rule into Printing so the frontend does not need to know
	 * how print options work internally.
	 */
	public function enforce_minimum_quantity(
		array $selections,
		int $quantity
	): array {

		$quantity = max(
			1,
			absint(
				$quantity
			)
		);

		if ( empty( $selections ) ) {
			return [];
		}

		$options =
			$this->printing
				->options()
				->by_ids(
					array_values(
						$selections
					)
				);

		if ( empty( $options ) ) {
			return [];
		}

		$minimums = [];

		foreach ( $options as $option ) {

			$minimums[
				(int) $option->id
			] = max(
				1,
				(int)
					$option
						->min_order_qty
			);
		}

		foreach (
			$selections
				as $position_id => $option_id
		) {

			$option_id = absint(
				$option_id
			);

			/*
			 * Unknown options are removed as well.
			 */
			if (
				! isset(
					$minimums[
						$option_id
					]
				)
				|| $quantity
					< $minimums[
						$option_id
					]
			) {
				unset(
					$selections[
						$position_id
					]
				);
			}
		}

		return $selections;
	}


	/*
	|--------------------------------------------------------------------------
	| Calculator Conversion
	|--------------------------------------------------------------------------
	*/

	/**
	 * Convert legacy cx_print data into Calculator input.
	 *
	 * Legacy:
	 *
	 * [
	 *     15 => 8,
	 *     19 => 11,
	 * ]
	 *
	 * Calculator:
	 *
	 * [
	 *     [
	 *         'position_id' => 15,
	 *         'option_id'   => 8,
	 *     ],
	 * ]
	 */
	private function to_calculator_selections(
		array $selections
	): array {

		$result = [];

		foreach (
			$selections
				as $position_id => $option_id
		) {

			$result[] = [
				'position_id' =>
					absint(
						$position_id
					),

				'option_id' =>
					absint(
						$option_id
					),
			];
		}

		return $result;
	}
}