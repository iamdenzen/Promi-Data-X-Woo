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
 * - Expose printing context to the unified Pricing Engine.
 * - Calculate the authoritative printing breakdown.
 * - Display selected printing in cart and checkout.
 * - Save selected printing onto WooCommerce order items.
 * - Validate selections before pricing.
 *
 * This class does NOT set WooCommerce prices directly.
 *
 * Price calculation belongs to:
 *
 *     Pricing\CartPricing
 *          ↓
 *     Pricing\Engine
 *          ↓
 *     Printing\Calculator
 *
 * This keeps printing as one stage of the unified pricing pipeline.
 */
final class CartPricing {

	private Printing $printing;

	private Calculator $calculator;


	public function __construct(
		Printing $printing,
		Calculator $calculator
	) {

		$this->printing =
			$printing;

		$this->calculator =
			$calculator;
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
			[
				$this,
				'display_cart_data',
			],
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
			[
				$this,
				'add_order_meta',
			],
			10,
			4
		);


		/*
		|--------------------------------------------------------------------------
		| Unified Pricing Context
		|--------------------------------------------------------------------------
		|
		| Printing does not calculate the WooCommerce price here.
		|
		| It enriches the context consumed by Pricing\Engine.
		*/

		add_filter(
			'pdxw_pricing_context',
			[
				$this,
				'add_pricing_context',
			],
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
	 * Add printing selections and the authoritative printing breakdown to
	 * the unified Pricing Engine context.
	 *
	 * Expected base context:
	 *
	 * [
	 *     'product_id'   => 123,
	 *     'variation_id' => 456,
	 *     'quantity'     => 100,
	 *     'cart_item'    => [...],
	 * ]
	 *
	 * The resulting context contains:
	 *
	 * [
	 *     'printing' => [...],
	 *     'printing_breakdown' => [
	 *         'unit_price' => ...,
	 *         'print_total' => ...,
	 *         'setup_total' => ...,
	 *         'ongoing_fee_total' => ...,
	 *         'fees' => ...,
	 *         'total' => ...,
	 *         'per_unit' => ...,
	 *     ],
	 * ]
	 */
	public function add_pricing_context(
		array $context,
		array $cart_item
	): array {

		$selections =
			$this->selections(
				$cart_item
			);


		if ( empty( $selections ) ) {
			return $context;
		}


		$product_id =
			absint(
				$cart_item['product_id']
					?? $context['product_id']
					?? 0
			);


		$variation_id =
			absint(
				$cart_item['variation_id']
					?? $context['variation_id']
					?? 0
			);


		/*
		|--------------------------------------------------------------------------
		| Validate Against Variation / Product
		|--------------------------------------------------------------------------
		|
		| Variations take precedence because the available print options can
		| differ by variation.
		*/

		$entity_id =
			$variation_id
				?: $product_id;


		if (
			! $entity_id
			|| ! $this->validate(
				$entity_id,
				$selections
			)
		) {
			return $context;
		}


		/*
		|--------------------------------------------------------------------------
		| Quantity
		|--------------------------------------------------------------------------
		*/

		$quantity =
			max(
				1,
				absint(
					$cart_item['quantity']
						?? $context['quantity']
						?? 1
				)
			);


		/*
		|--------------------------------------------------------------------------
		| Minimum Quantity
		|--------------------------------------------------------------------------
		|
		| Keep this defensive. The configurator should normally have already
		| enforced minimum quantities before the item enters the cart.
		|
		| We do not silently remove selections here because changing cart
		| contents while the pricing filter is running is unsafe.
		*/

		$calculator_selections =
			$this->to_calculator_selections(
				$selections
			);


		if (
			empty(
				$calculator_selections
			)
		) {
			return $context;
		}


		/*
		|--------------------------------------------------------------------------
		| Authoritative Printing Calculation
		|--------------------------------------------------------------------------
		|
		| Calculator is now the only class responsible for:
		|
		| - print purchase costs,
		| - finishing markup,
		| - setup rounding,
		| - ongoing/no-rounding rules.
		|
		| We calculate this once here.
		|
		| Printing\Calculator::apply() will reuse this breakdown when the
		| Pricing Engine executes priority 20.
		*/

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


		/*
		|--------------------------------------------------------------------------
		| Add Printing Context
		|--------------------------------------------------------------------------
		*/

		$context['printing'] =
			$calculator_selections;


		$context['printing_breakdown'] =
			$printing_breakdown;


		/*
		|--------------------------------------------------------------------------
		| Allow Later Pricing Modules to Enrich Context
		|--------------------------------------------------------------------------
		|
		| Keep the context extensible without introducing another pricing
		| calculation path.
		*/

		$context =
			apply_filters(
				'pdxw_printing_pricing_context',
				$context,
				$cart_item,
				$printing_breakdown
			);


		return is_array( $context )
			? $context
			: [];
	}


	/*
	|--------------------------------------------------------------------------
	| Cart / Checkout Display
	|--------------------------------------------------------------------------
	*/

	/**
	 * Display selected print options underneath the cart item.
	 *
	 * This preserves the existing CX Print visible cart structure:
	 *
	 *     Position Label → Print Option Name
	 */
	public function display_cart_data(
		array $data,
		array $cart_item
	): array {

		$selections =
			$this->selections(
				$cart_item
			);


		if ( empty( $selections ) ) {
			return $data;
		}


		foreach (
			$selections as $position_id => $selection
		) {

			$option_id =
				absint(
					$selection['option_id']
						?? 0
				);


			if (
				! $position_id
				|| ! $option_id
			) {
				continue;
			}


			$position =
				$this->printing
					->positions()
					->find(
						absint(
							$position_id
						)
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


		return $this->append_fee_data(
			$data,
			$cart_item,
			$selections
		);
	}


	/**
	 * Surface the print fee amounts the Calculator actually charges.
	 *
	 * Without this, setup/ongoing fees were silently folded into the
	 * WooCommerce line price with no visible breakdown, making the cart
	 * look like fees were not being charged at all.
	 */
	private function append_fee_data(
		array $data,
		array $cart_item,
		array $selections
	): array {

		$calculator_selections =
			$this->to_calculator_selections(
				$selections
			);

		if ( empty( $calculator_selections ) ) {
			return $data;
		}

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

		$quantity =
			max(
				1,
				absint(
					$cart_item['quantity']
						?? 1
				)
			);

		$breakdown =
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

		$fees =
			$breakdown['fee_breakdown']
				?? [];

		if ( empty( $fees ) ) {
			return $data;
		}

		$totals = [];

		foreach ( $fees as $fee ) {

			$selling =
				(float) (
					$fee['selling']
						?? 0.0
				);

			if ( $selling <= 0 ) {
				continue;
			}

			$label =
				(string) (
					$fee['label']
						?? ''
				);

			if ( '' === $label ) {

				$label =
					'setup' === ( $fee['type'] ?? '' )
						? __( 'Setup Fee', 'promi-data-x-woo' )
						: __( 'Print Fee', 'promi-data-x-woo' );
			}

			$totals[ $label ] =
				( $totals[ $label ] ?? 0.0 )
				+ $selling;
		}

		foreach ( $totals as $label => $amount ) {

			$data[] = [

				'name' =>
					$label,

				'value' =>
					wc_price( $amount ),
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
	 * Visible metadata remains:
	 *
	 *     Position Label => Print Option Name
	 *
	 * A private structured representation is also stored so historical
	 * orders retain the IDs even if an option is renamed later.
	 */
	public function add_order_meta(
		\WC_Order_Item_Product $item,
		string $cart_item_key,
		array $values,
		\WC_Order $order
	): void {

		$selections =
			$this->selections(
				$values
			);


		if ( empty( $selections ) ) {
			return;
		}


		/*
		|--------------------------------------------------------------------------
		| Human-readable metadata
		|--------------------------------------------------------------------------
		*/

		foreach (
			$selections as $position_id => $selection
		) {

			$option_id =
				absint(
					$selection['option_id']
						?? 0
				);


			if (
				! $position_id
				|| ! $option_id
			) {
				continue;
			}


			$position =
				$this->printing
					->positions()
					->find(
						absint(
							$position_id
						)
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
		| Private Structured Metadata
		|--------------------------------------------------------------------------
		|
		| This is intentionally private so it does not appear as normal
		| customer-facing order metadata.
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
	 * Extract and normalize cx_print cart data.
	 *
	 * Canonical legacy structure:
	 *
	 * [
	 *     position_id => option_id,
	 * ]
	 *
	 * Internally we normalize this into:
	 *
	 * [
	 *     position_id => [
	 *         'option_id' => option_id,
	 *         'colors'    => optional color count,
	 *     ],
	 * ]
	 *
	 * The old format remains fully supported.
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
				as $position_id => $raw_option
		) {

			$position_id =
				absint(
					$position_id
				);


			if ( ! $position_id ) {
				continue;
			}


			/*
			|--------------------------------------------------------------------------
			| Existing Structure
			|--------------------------------------------------------------------------
			|
			|     position_id => option_id
			|
			*/

			if (
				is_scalar(
					$raw_option
				)
			) {

				$option_id =
					absint(
						$raw_option
					);


				if ( ! $option_id ) {
					continue;
				}


				$selections[
					$position_id
				] = [

					'option_id' =>
						$option_id,

					'colors' =>
						0,
				];


				continue;
			}


			/*
			|--------------------------------------------------------------------------
			| Extended Structure
			|--------------------------------------------------------------------------
			|
			| This allows future configurator code to provide:
			|
			| [
			|     'option_id' => 123,
			|     'colors'    => 2,
			| ]
			|
			| without breaking the old cart format.
			*/

			if (
				! is_array(
					$raw_option
				)
			) {
				continue;
			}


			$option_id =
				absint(
					$raw_option['option_id']
						?? $raw_option['print_option_id']
						?? $raw_option['option']
						?? 0
				);


			if ( ! $option_id ) {
				continue;
			}


			$colors =
				max(
					0,
					absint(
						$raw_option['colors']
							?? $raw_option['color_count']
							?? 0
					)
				);


			$selections[
				$position_id
			] = [

				'option_id' =>
					$option_id,

				'colors' =>
					$colors,
			];
		}


		return $selections;
	}


	/**
	 * Normalize data before it is stored by the configurator.
	 *
	 * This remains compatible with the existing:
	 *
	 *     position_id => option_id
	 *
	 * structure.
	 *
	 * Extended selection data is also accepted.
	 */
	public function sanitize_selections(
		mixed $raw
	): array {

		if (
			! is_array(
				$raw
			)
		) {
			return [];
		}


		$selections = [];


		foreach (
			$raw as $position_id => $raw_option
		) {

			$position_id =
				absint(
					$position_id
				);


			if ( ! $position_id ) {
				continue;
			}


			/*
			|--------------------------------------------------------------------------
			| Legacy scalar option ID
			|--------------------------------------------------------------------------
			*/

			if (
				is_scalar(
					$raw_option
				)
			) {

				$option_id =
					absint(
						$raw_option
					);


				if ( ! $option_id ) {
					continue;
				}


				$selections[
					$position_id
				] =
					$option_id;


				continue;
			}


			/*
			|--------------------------------------------------------------------------
			| Extended selection
			|--------------------------------------------------------------------------
			*/

			if (
				! is_array(
					$raw_option
				)
			) {
				continue;
			}


			$option_id =
				absint(
					$raw_option['option_id']
						?? $raw_option['print_option_id']
						?? $raw_option['option']
						?? 0
				);


			if ( ! $option_id ) {
				continue;
			}


			$colors =
				max(
					0,
					absint(
						$raw_option['colors']
							?? $raw_option['color_count']
							?? 0
					)
				);


			/*
			 * Preserve the simple legacy representation whenever no
			 * additional information is supplied.
			 */
			if ( ! $colors ) {

				$selections[
					$position_id
				] =
					$option_id;

				continue;
			}


			$selections[
				$position_id
			] = [

				'option_id' =>
					$option_id,

				'colors' =>
					$colors,
			];
		}


		return $selections;
	}


	/**
	 * Validate every print selection.
	 *
	 * This preserves the existing validation architecture and repository
	 * method.
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
			|| empty(
				$selections
			)
		) {
			return false;
		}


		foreach (
			$selections as $position_id => $selection
		) {

			$option_id =
				absint(
					$selection['option_id']
						?? $selection
				);


			if (
				! $option_id
				|| ! $this->printing
					->repository()
					->selection_is_valid(
						$product_or_variation_id,
						absint(
							$position_id
						),
						$option_id
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
	 * The configurator can call this before storing cx_print.
	 *
	 * We retain the existing behavior rather than mutating cart contents
	 * during WooCommerce price calculation.
	 */
	public function enforce_minimum_quantity(
		array $selections,
		int $quantity
	): array {

		$quantity =
			max(
				1,
				absint(
					$quantity
				)
			);


		if ( empty( $selections ) ) {
			return [];
		}


		$option_ids = [];


		foreach (
			$selections as $selection
		) {

			$option_id =
				absint(
					$selection['option_id']
						?? $selection
				);


			if ( $option_id ) {
				$option_ids[] =
					$option_id;
			}
		}


		$option_ids =
			array_values(
				array_unique(
					$option_ids
				)
			);


		if ( empty( $option_ids ) ) {
			return [];
		}


		$options =
			$this->printing
				->options()
				->by_ids(
					$option_ids
				);


		if ( empty( $options ) ) {
			return [];
		}


		$minimums = [];


		foreach (
			$options as $option
		) {

			$minimums[
				(int) $option->id
			] =
				max(
					1,
					(int)
						$option
							->min_order_qty
				);
		}


		foreach (
			$selections as $position_id => $selection
		) {

			$option_id =
				absint(
					$selection['option_id']
						?? $selection
				);


			/*
			 * Unknown options are removed.
			 */
			if (
				! isset(
					$minimums[
						$option_id
					]
				)
			) {

				unset(
					$selections[
						$position_id
					]
				);

				continue;
			}


			if (
				$quantity
				<
				$minimums[
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
	 * Convert normalized cart selections into Calculator input.
	 *
	 * Legacy:
	 *
	 * [
	 *     15 => [
	 *         'option_id' => 8,
	 *         'colors'    => 0,
	 *     ],
	 * ]
	 *
	 * Calculator:
	 *
	 * [
	 *     [
	 *         'position_id' => 15,
	 *         'option_id'   => 8,
	 *         'colors'      => 0,
	 *     ],
	 * ]
	 */
	private function to_calculator_selections(
		array $selections
	): array {

		$result = [];


		foreach (
			$selections as $position_id => $selection
		) {

			$option_id =
				absint(
					$selection['option_id']
						?? $selection
				);


			if (
				! $position_id
				|| ! $option_id
			) {
				continue;
			}


			$colors =
				max(
					0,
					absint(
						is_array(
							$selection
						)
							? (
								$selection['colors']
									?? 0
							)
							: 0
					)
				);


			$result[] = [

				'position_id' =>
					absint(
						$position_id
					),

				'option_id' =>
					$option_id,

				'colors' =>
					$colors,
			];
		}


		return $result;
	}
}