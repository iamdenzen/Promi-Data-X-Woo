<?php

namespace PromiDataXWoo\Printing;

use PromiDataXWoo\Pricing\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Printing price calculator.
 *
 * Responsibilities:
 * - Resolve selected print options.
 * - Resolve applicable print tier price by quantity.
 * - Add applicable print fees.
 * - Support selling and purchase calculations.
 *
 * It does not read cart/session data directly.
 * CartPricing prepares the context and passes it here.
 */
final class Calculator {

	private Repository $repository;

	private Pricing $pricing;

	private Fees $fees;


	public function __construct(
		Repository $repository,
		Pricing $pricing
	) {
		$this->repository = $repository;
		$this->pricing    = $pricing;
		$this->fees       = new Fees(
			$repository
		);
	}


	/**
	 * Pricing-engine callback.
	 *
	 * Expected context:
	 *
	 * [
	 *     'product_id'   => 123,
	 *     'variation_id' => 456,
	 *     'quantity'     => 100,
	 *     'printing'     => [
	 *         [
	 *             'position_id' => 1,
	 *             'option_id'   => 12,
	 *             'colors'      => 2,
	 *         ],
	 *     ],
	 * ]
	 *
	 * $base_price is the already tier-adjusted product unit price.
	 */
	public function apply(
		float $base_price,
		array $context
	): float {

		$printing = $context['printing']
			?? [];

		if (
			empty( $printing )
			|| ! is_array( $printing )
		) {
			return $base_price;
		}

		$quantity = max(
			1,
			absint(
				$context['quantity']
				?? 1
			)
		);

		$product_id = absint(
			$context['product_id']
				?? 0
		);

		$variation_id = absint(
			$context['variation_id']
				?? 0
		);

		$print_total = $this->calculate(
			$printing,
			[
				'quantity'     => $quantity,
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
			]
		);

		/*
		 * The pricing engine works in UNIT prices.
		 *
		 * Print tier prices are per item, but setup/handling fees may be
		 * fixed order-level amounts.
		 *
		 * calculate() returns the total printing amount for the configured
		 * quantity, so divide it back into a per-unit amount before adding
		 * it to WooCommerce's product price.
		 */
		$print_unit_price =
			$print_total / $quantity;

		return $base_price
			+ $print_unit_price;
	}


	/**
	 * Calculate total selling print cost for a configured line item.
	 */
	public function calculate(
		array $selections,
		array $context = []
	): float {

		return $this->calculate_side(
			$selections,
			$context,
			false
		);
	}


	/**
	 * Calculate total purchase print cost.
	 */
	public function calculate_purchase(
		array $selections,
		array $context = []
	): float {

		return $this->calculate_side(
			$selections,
			$context,
			true
		);
	}


	/**
	 * Calculate printing cost.
	 */
	private function calculate_side(
		array $selections,
		array $context,
		bool $purchase
	): float {

		$quantity = max(
			1,
			absint(
				$context['quantity']
				?? 1
			)
		);

		$product_id = absint(
			$context['product_id']
				?? 0
		);

		$variation_id = absint(
			$context['variation_id']
				?? 0
		);

		$total = 0.0;

		$normalized =
			$this->normalize_selections(
				$selections
			);

		$position_count = count(
			$normalized
		);

		foreach (
			$normalized as $selection
		) {

			$position_id = $selection[
				'position_id'
			];

			$option_id = $selection[
				'option_id'
			];

			$colors = $selection[
				'colors'
			];


			/*
			|--------------------------------------------------------------------------
			| Validate Selection
			|--------------------------------------------------------------------------
			|
			| A frontend request must not be able to submit arbitrary
			| option IDs and obtain pricing for an option that isn't actually
			| available for this product/variation/position.
			*/

			$entity_id = $variation_id
				?: $product_id;

			if (
				$entity_id
				&& ! $this->repository
					->selection_is_valid(
						$entity_id,
						$position_id,
						$option_id
					)
			) {
				continue;
			}


			/*
			|--------------------------------------------------------------------------
			| Print Tier Price
			|--------------------------------------------------------------------------
			*/

			if ( $purchase ) {

				$unit_print_price =
					$this->repository
						->get_applicable_purchase_price(
							$option_id,
							$quantity
						);

			} else {

				$unit_print_price =
					$this->repository
						->get_applicable_selling_price(
							$option_id,
							$quantity
						);
			}


			/*
			 * If no applicable price exists, the print option contributes
			 * no tier price.
			 *
			 * Fees may still exist and are calculated separately.
			 */
			if (
				null !== $unit_print_price
			) {
				$total +=
					$unit_print_price
					* $quantity;
			}


			/*
			|--------------------------------------------------------------------------
			| Fees
			|--------------------------------------------------------------------------
			*/

			$fee_context = [
				'quantity' =>
					$quantity,

				'colors' =>
					$colors,

				'positions' =>
					$position_count,

				'product_id' =>
					$product_id,

				'variation_id' =>
					$variation_id,

				'print_option_id' =>
					$option_id,
			];

			if ( $purchase ) {

				$total +=
					$this->fees
						->calculate_purchase(
							$option_id,
							$fee_context
						);

			} else {

				$total +=
					$this->fees
						->calculate(
							$option_id,
							$fee_context
						);
			}
		}

		return max(
			0.0,
			$total
		);
	}


	/**
	 * Calculate the print cost for one selection only.
	 *
	 * Useful for AJAX/frontend previews.
	 */
	public function calculate_selection(
		int $option_id,
		int $quantity,
		array $context = []
	): array {

		$option_id = absint(
			$option_id
		);

		$quantity = max(
			1,
			absint(
				$quantity
			)
		);

		if ( ! $option_id ) {
			return [
				'unit_price' => 0.0,
				'print_total' => 0.0,
				'fees' => 0.0,
				'total' => 0.0,
			];
		}

		$unit_price =
			$this->repository
				->get_applicable_selling_price(
					$option_id,
					$quantity
				)
			?? 0.0;

		$print_total =
			$unit_price
			* $quantity;

		$fee_context = [
			'quantity' =>
				$quantity,

			'colors' =>
				max(
					0,
					absint(
						$context['colors']
						?? 0
					)
				),

			'positions' =>
				max(
					1,
					absint(
						$context['positions']
						?? 1
					)
				),

			'product_id' =>
				absint(
					$context['product_id']
						?? 0
				),

			'variation_id' =>
				absint(
					$context['variation_id']
						?? 0
				),

			'print_option_id' =>
				$option_id,
		];

		$fees =
			$this->fees
				->calculate(
					$option_id,
					$fee_context
				);

		return [
			'unit_price' =>
				(float) $unit_price,

			'print_total' =>
				(float) $print_total,

			'fees' =>
				(float) $fees,

			'total' =>
				(float) (
					$print_total
					+ $fees
				),
		];
	}


	/**
	 * Calculate both selling and purchase values.
	 *
	 * This will later be useful for admin/order profitability reporting.
	 */
	public function breakdown(
		array $selections,
		array $context = []
	): array {

		$selling =
			$this->calculate(
				$selections,
				$context
			);

		$purchase =
			$this->calculate_purchase(
				$selections,
				$context
			);

		return [
			'selling' =>
				$selling,

			'purchase' =>
				$purchase,

			'margin' =>
				$selling
				- $purchase,
		];
	}


	/*
	|--------------------------------------------------------------------------
	| Selection Normalization
	|--------------------------------------------------------------------------
	*/

	/**
	 * Normalize incoming printing selections.
	 *
	 * Supports both the new structure:
	 *
	 * [
	 *     [
	 *         'position_id' => 1,
	 *         'option_id'   => 10,
	 *         'colors'      => 2,
	 *     ]
	 * ]
	 *
	 * and the old cx_print-style keys where possible.
	 */
	private function normalize_selections(
		array $selections
	): array {

		$normalized = [];

		foreach (
			$selections as $selection
		) {

			if ( ! is_array( $selection ) ) {
				continue;
			}

			$position_id = absint(
				$selection[
					'position_id'
				]
				?? $selection[
					'print_position_id'
				]
				?? $selection[
					'position'
				]
				?? 0
			);

			$option_id = absint(
				$selection[
					'option_id'
				]
				?? $selection[
					'print_option_id'
				]
				?? $selection[
					'option'
				]
				?? 0
			);

			if (
				! $position_id
				|| ! $option_id
			) {
				continue;
			}

			$colors = max(
				0,
				absint(
					$selection[
						'colors'
					]
					?? $selection[
						'color_count'
					]
					?? 0
				)
			);

			/*
			 * One option per position.
			 *
			 * Keying by position also protects the pricing calculation from
			 * duplicate frontend/cart payload entries.
			 */
			$normalized[
				$position_id
			] = [
				'position_id' =>
					$position_id,

				'option_id' =>
					$option_id,

				'colors' =>
					$colors,
			];
		}

		return array_values(
			$normalized
		);
	}
}