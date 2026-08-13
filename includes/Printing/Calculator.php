<?php

namespace PromiDataXWoo\Printing;

use PromiDataXWoo\Pricing\Pricing;
use PromiDataXWoo\Pricing\SellingPriceCalculator;

defined( 'ABSPATH' ) || exit;

/**
 * Printing price calculator.
 *
 * Responsibilities:
 *
 * - Resolve selected print options.
 * - Resolve applicable purchase print tiers.
 * - Apply finishing / decoration markup.
 * - Calculate purchase-side fees.
 * - Apply finishing markup to fees.
 * - Round setup fees to the nearest whole euro.
 * - Leave ongoing printing / decoration unrounded.
 * - Produce the final selling-side printing amount used by the Pricing Engine.
 *
 * Important:
 *
 * Promi's raw selling price is NOT used as the storefront selling price.
 *
 * The new calculation is cost-based:
 *
 *     purchase print price
 *             ↓
 *     finishing markup
 *             ↓
 *     customer print price
 *
 * Setup:
 *
 *     purchase setup cost
 *             ↓
 *     finishing markup
 *             ↓
 *     nearest whole euro
 *
 * Ongoing:
 *
 *     purchase ongoing cost
 *             ↓
 *     finishing markup
 *             ↓
 *     no rounding
 *
 * Cart/session data is not read directly.
 * CartPricing prepares the context and passes it here.
 */
final class Calculator {

	private Repository $repository;

	private Pricing $pricing;

	private Fees $fees;

	private SellingPriceCalculator $selling_prices;


	public function __construct(
		Repository $repository,
		Pricing $pricing
	) {

		$this->repository =
			$repository;

		$this->pricing =
			$pricing;

		$this->fees =
			new Fees(
				$repository
			);

		$this->selling_prices =
			$pricing->selling_prices();
	}


	/*
	|--------------------------------------------------------------------------
	| Pricing Engine
	|--------------------------------------------------------------------------
	*/

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
	 * $base_price is the article price already calculated by the preceding
	 * Pricing Engine stage.
	 *
	 * Printing adds its marked-up per-unit amount to that article price.
	 */
	public function apply(
		float $base_price,
		array $context
	): float {

		$printing =
			$context['printing']
				?? [];


		if (
			empty( $printing )
			|| ! is_array( $printing )
		) {

			return $base_price;
		}


		$quantity =
			max(
				1,
				absint(
					$context['quantity']
						?? 1
				)
			);


		/*
		|--------------------------------------------------------------------------
		| Reuse an already calculated breakdown
		|--------------------------------------------------------------------------
		|
		| Printing CartPricing may calculate this before the Engine runs.
		|
		| If it exists, use it.
		|
		| Otherwise calculate it here.
		*/

		$printing_breakdown =
			isset(
				$context['printing_breakdown']
			)
			&& is_array(
				$context['printing_breakdown']
			)
				? $context['printing_breakdown']
				: null;


		if ( null === $printing_breakdown ) {

			$printing_breakdown =
				$this->calculate_breakdown(
					$printing,
					[
						'quantity' =>
							$quantity,

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
					]
				);
		}


		$print_unit_price =
			(float) (
				$printing_breakdown['per_unit']
					?? 0
			);


		return max(
			0.0,
			$base_price
			+ $print_unit_price
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Selling Calculation
	|--------------------------------------------------------------------------
	*/

	/**
	 * Calculate total selling-side printing cost.
	 *
	 * This method now calculates customer prices from purchase costs plus
	 * finishing markup.
	 *
	 * It does NOT use Promi's raw selling price.
	 */
	public function calculate(
		array $selections,
		array $context = []
	): float {

		$breakdown =
			$this->calculate_breakdown(
				$selections,
				$context
			);


		return max(
			0.0,
			(float) (
				$breakdown['total']
					?? 0
			)
		);
	}


	/**
	 * Calculate total purchase-side printing cost.
	 *
	 * This is the raw cost side and does not apply finishing markup.
	 *
	 * It is useful for profitability calculations and order reporting.
	 */
	public function calculate_purchase(
		array $selections,
		array $context = []
	): float {

		return $this->calculate_purchase_breakdown(
			$selections,
			$context
		)['total'];
	}


	/**
	 * Calculate the raw purchase-side printing cost.
	 *
	 * No markup or customer-facing rounding is applied here.
	 */
	private function calculate_purchase_side(
		array $selections,
		array $context
	): array {

		$quantity =
			max(
				1,
				absint(
					$context['quantity']
						?? 1
				)
			);


		$product_id =
			absint(
				$context['product_id']
					?? 0
			);


		$variation_id =
			absint(
				$context['variation_id']
					?? 0
			);


		$normalized =
			$this->normalize_selections(
				$selections
			);


		$position_count =
			count(
				$normalized
			);


		$print_total =
			0.0;


		$fees_total =
			0.0;


		$fee_breakdown = [];


		$valid_selections = [];


		foreach (
			$normalized as $selection
		) {

			$position_id =
				absint(
					$selection['position_id']
				);


			$option_id =
				absint(
					$selection['option_id']
				);


			$colors =
				max(
					0,
					absint(
						$selection['colors']
							?? 0
					)
				);


			if (
				! $position_id
				|| ! $option_id
			) {
				continue;
			}


			/*
			|--------------------------------------------------------------------------
			| Validate Selection
			|--------------------------------------------------------------------------
			*/

			$entity_id =
				$variation_id
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
			| Purchase Print Tier
			|--------------------------------------------------------------------------
			|
			| This is the authoritative cost source.
			|
			| We deliberately do NOT use:
			|
			|     get_applicable_selling_price()
			|
			| because that is Promi's selling-side value.
			*/

			$purchase_price =
				$this->repository
					->get_applicable_purchase_price(
						$option_id,
						$quantity
					);


			if (
				null !== $purchase_price
				&& is_numeric(
					$purchase_price
				)
				&& (float) $purchase_price > 0
			) {

				$purchase_price =
					(float) $purchase_price;


				$print_total +=
					$purchase_price
					* $quantity;
			}


			/*
			|--------------------------------------------------------------------------
			| Purchase Fees
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


			$fees =
				$this->purchase_fee_breakdown(
					$option_id,
					$fee_context
				);


			foreach (
				$fees as $fee
			) {

				$amount =
					max(
						0.0,
						(float) (
							$fee['raw']
								?? 0
						)
					);


				$fees_total +=
					$amount;


				$fee_breakdown[] =
					[
						'type' =>
							$fee['type']
								?? '',

						'label' =>
							$fee['label']
								?? '',

						'raw' =>
							$amount,

						'option_id' =>
							$option_id,
					];
			}


			$valid_selections[
				$position_id
			] =
				$option_id;
		}


		return [

			'print_total' =>
				max(
					0.0,
					$print_total
				),

			'fees' =>
				max(
					0.0,
					$fees_total
				),

			'total' =>
				max(
					0.0,
					$print_total
					+ $fees_total
				),

			'fee_breakdown' =>
				$fee_breakdown,

			'quantity' =>
				$quantity,

			'selections' =>
				$valid_selections,
		];
	}


	/**
	 * Calculate detailed purchase-side printing costs.
	 *
	 * Public because order/profitability code may need the raw cost
	 * breakdown.
	 */
	public function calculate_purchase_breakdown(
		array $selections,
		array $context = []
	): array {

		return $this->calculate_purchase_side(
			$selections,
			$context
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Customer Selling Breakdown
	|--------------------------------------------------------------------------
	*/

	/**
	 * Calculate the authoritative customer-facing printing breakdown.
	 *
	 * Print tiers:
	 *
	 *     purchase price
	 *         ×
	 *     finishing markup
	 *         =
	 *     ongoing print selling price
	 *
	 * Fees:
	 *
	 *     setup:
	 *         purchase fee
	 *         ×
	 *         finishing markup
	 *         →
	 *         nearest euro
	 *
	 *     ongoing:
	 *         purchase fee
	 *         ×
	 *         finishing markup
	 *         →
	 *         no rounding
	 */
	public function calculate_breakdown(
		array $selections,
		array $context = []
	): array {

		$quantity =
			max(
				1,
				absint(
					$context['quantity']
						?? 1
				)
			);


		$product_id =
			absint(
				$context['product_id']
					?? 0
			);


		$variation_id =
			absint(
				$context['variation_id']
					?? 0
			);


		$normalized =
			$this->normalize_selections(
				$selections
			);


		$position_count =
			count(
				$normalized
			);


		$unit_print_price =
			0.0;


		$print_total =
			0.0;


		$fees_total =
			0.0;


		$setup_total =
			0.0;


		$ongoing_fee_total =
			0.0;


		$fee_breakdown = [];


		$valid_selections = [];


		foreach (
			$normalized as $selection
		) {

			$position_id =
				absint(
					$selection['position_id']
				);


			$option_id =
				absint(
					$selection['option_id']
				);


			$colors =
				max(
					0,
					absint(
						$selection['colors']
							?? 0
					)
				);


			if (
				! $position_id
				|| ! $option_id
			) {
				continue;
			}


			/*
			|--------------------------------------------------------------------------
			| Validate
			|--------------------------------------------------------------------------
			*/

			$entity_id =
				$variation_id
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
			| Finishing Markup
			|--------------------------------------------------------------------------
			*/

			$markup =
				$this->pricing
					->markup_rules()
					->finishing_markup(
						$option_id
					);


			/*
			|--------------------------------------------------------------------------
			| Raw Purchase Print Tier
			|--------------------------------------------------------------------------
			*/

			$purchase_price =
				$this->repository
					->get_applicable_purchase_price(
						$option_id,
						$quantity
					);


			/*
			 * Do NOT fall back to the Promi selling price.
			 *
			 * If there is no purchase print price, there is no cost basis
			 * from which we can calculate our customer print price.
			 */
			if (
				null !== $purchase_price
				&& is_numeric(
					$purchase_price
				)
				&& (float) $purchase_price > 0
			) {

				$purchase_price =
					(float) $purchase_price;


				/*
				 * Ongoing decoration / printing:
				 *
				 *     purchase cost
				 *         ×
				 *     finishing markup
				 *
				 * NO rounding.
				 */
				$customer_unit_price =
					$this->selling_prices
						->ongoing(
							$purchase_price,
							$markup
						);


				$customer_unit_price =
					max(
						0.0,
						(float)
							$customer_unit_price
					);


				$unit_print_price +=
					$customer_unit_price;


				$print_total +=
					$customer_unit_price
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


			$fees =
				$this->purchase_fee_breakdown(
					$option_id,
					$fee_context
				);


			foreach (
				$fees as $fee
			) {

				$raw =
					max(
						0.0,
						(float) (
							$fee['raw']
								?? 0
						)
					);


				if ( $raw <= 0 ) {
					continue;
				}


				$type =
					$this->fee_type(
						$fee
					);


				$label =
					(string) (
						$fee['label']
							?? ''
					);


				/*
				|--------------------------------------------------------------------------
				| Setup Fee
				|--------------------------------------------------------------------------
				|
				| Apply finishing markup, then round to nearest whole euro.
				*/

				if (
					'setup'
					=== $type
				) {

					$selling_fee =
						$this->selling_prices
							->setup(
								$raw,
								$markup
							);


					$selling_fee =
						max(
							0.0,
							(float)
								$selling_fee
						);


					$setup_total +=
						$selling_fee;

				} else {

					/*
					|--------------------------------------------------------------------------
					| Ongoing / other fee
					|--------------------------------------------------------------------------
					|
					| Apply finishing markup.
					|
					| No rounding.
					*/

					$selling_fee =
						$this->selling_prices
							->ongoing(
								$raw,
								$markup
							);


					$selling_fee =
						max(
							0.0,
							(float)
								$selling_fee
						);


					$ongoing_fee_total +=
						$selling_fee;
				}


				$fee_breakdown[] = [

					'type' =>
						$type,

					'label' =>
						$label,

					'raw' =>
						(float) $raw,

					'markup' =>
						(float) $markup,

					'selling' =>
						(float) $selling_fee,

					'option_id' =>
						$option_id,
				];
			}


			$valid_selections[
				$position_id
			] =
				$option_id;
		}


		$fees_total =
			$setup_total
			+ $ongoing_fee_total;


		$total =
			$print_total
			+ $fees_total;


		return [

			/*
			 * Total per-unit raw marked-up print-tier amount.
			 *
			 * This does not include fixed setup fees.
			 */
			'unit_price' =>
				(float) $unit_print_price,

			'print_total' =>
				(float) $print_total,

			'setup_total' =>
				(float) $setup_total,

			'ongoing_fee_total' =>
				(float) $ongoing_fee_total,

			'fees' =>
				(float) $fees_total,

			'total' =>
				(float) $total,

			/*
			 * WooCommerce needs a per-unit amount because its cart price
			 * is multiplied by quantity.
			 *
			 * Fixed/setup amounts therefore have to be apportioned across
			 * the line quantity.
			 */
			'per_unit' =>
				(float) (
					$total / $quantity
				),

			'quantity' =>
				$quantity,

			'selections' =>
				$valid_selections,

			'fee_breakdown' =>
				$fee_breakdown,
		];
	}


	/*
	|--------------------------------------------------------------------------
	| Single Selection
	|--------------------------------------------------------------------------
	*/

	/**
	 * Calculate one print option's customer-facing selling price.
	 *
	 * This method follows the same cost-based rules as calculate_breakdown().
	 */
	public function calculate_selection(
		int $option_id,
		int $quantity,
		array $context = []
	): array {

		$option_id =
			absint(
				$option_id
			);


		$quantity =
			max(
				1,
				absint(
					$quantity
				)
			);


		if ( ! $option_id ) {

			return [

				'unit_price' =>
					0.0,

				'print_total' =>
					0.0,

				'fees' =>
					0.0,

				'total' =>
					0.0,
			];
		}


		$markup =
			$this->pricing
				->markup_rules()
				->finishing_markup(
					$option_id
				);


		/*
		|--------------------------------------------------------------------------
		| Purchase Print Cost
		|--------------------------------------------------------------------------
		*/

		$purchase_price =
			$this->repository
				->get_applicable_purchase_price(
					$option_id,
					$quantity
				);


		$unit_price =
			0.0;


		if (
			null !== $purchase_price
			&& is_numeric(
				$purchase_price
			)
			&& (float) $purchase_price > 0
		) {

			$unit_price =
				$this->selling_prices
					->ongoing(
						(float)
							$purchase_price,
						$markup
					);
		}


		$print_total =
			$unit_price
			* $quantity;


		/*
		|--------------------------------------------------------------------------
		| Fees
		|--------------------------------------------------------------------------
		*/

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
			$this->purchase_fee_breakdown(
				$option_id,
				$fee_context
			);


		$fee_total =
			0.0;


		foreach (
			$fees as $fee
		) {

			$raw =
				max(
					0.0,
					(float) (
						$fee['raw']
							?? 0
					)
				);


			if ( $raw <= 0 ) {
				continue;
			}


			$type =
				$this->fee_type(
					$fee
				);


			if (
				'setup'
				=== $type
			) {

				$fee_total +=
					$this->selling_prices
						->setup(
							$raw,
							$markup
						);

			} else {

				$fee_total +=
					$this->selling_prices
						->ongoing(
							$raw,
							$markup
						);
			}
		}


		return [

			'unit_price' =>
				(float) $unit_price,

			'print_total' =>
				(float) $print_total,

			'fees' =>
				(float) $fee_total,

			'total' =>
				(float) (
					$print_total
					+ $fee_total
				),
		];
	}


	/*
	|--------------------------------------------------------------------------
	| Purchase Fee Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return purchase-side fee breakdown.
	 *
	 * Fees.php already owns:
	 *
	 * - fee retrieval,
	 * - requirement evaluation,
	 * - calculation rules,
	 * - purchase_amount selection.
	 *
	 * We only interpret the resulting fee type here so that we can apply
	 * the correct markup/rounding policy.
	 */
	private function purchase_fee_breakdown(
		int $option_id,
		array $context
	): array {

		if (
			method_exists(
				$this->fees,
				'calculate_purchase_breakdown'
			)
		) {

			return $this->fees
				->calculate_purchase_breakdown(
					$option_id,
					$context
				);
		}


		/*
		 * Compatibility fallback for an older Fees class.
		 *
		 * This keeps the calculator from fatally failing while the fee
		 * service is being migrated.
		 *
		 * The normal/current path should use
		 * calculate_purchase_breakdown().
		 */
		$total =
			$this->fees
				->calculate_purchase(
					$option_id,
					$context
				);


		if ( $total <= 0 ) {
			return [];
		}


		return [
			[
				'type' =>
					'setup',

				'label' =>
					'Setup',

				'raw' =>
					(float) $total,
			],
		];
	}


	/**
	 * Determine whether a fee is a setup fee or an ongoing fee.
	 *
	 * Existing Promi data already commonly exposes:
	 *
	 *     fee_type
	 *     fee_label
	 *
	 * We use those existing fields rather than introducing a new database
	 * taxonomy.
	 *
	 * Known setup terminology:
	 *
	 *     setup
	 *     einrichtung
	 *     setup fee
	 *     setup cost
	 *
	 * Everything else is treated as ongoing.
	 */
	private function fee_type(
		array $fee
	): string {

		$type =
			strtolower(
				trim(
					(string) (
						$fee['type']
							?? $fee['fee_type']
							?? ''
					)
				)
			);


		$label =
			strtolower(
				trim(
					(string) (
						$fee['label']
							?? $fee['fee_label']
							?? ''
					)
				)
			);


		if (
			$this->is_setup_term(
				$type
			)
			|| $this->is_setup_term(
				$label
			)
		) {

			return 'setup';
		}


		return 'ongoing';
	}


	/**
	 * Determine whether a string identifies a setup fee.
	 */
	private function is_setup_term(
		string $value
	): bool {

		if ( '' === $value ) {
			return false;
		}


		return false !== strpos(
			$value,
			'setup'
		)
		|| false !== strpos(
			$value,
			'einrichtung'
		)
		|| false !== strpos(
			$value,
			'setupkosten'
		)
		|| false !== strpos(
			$value,
			'setup cost'
		)
		|| false !== strpos(
			$value,
			'setup fee'
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Selection Normalization
	|--------------------------------------------------------------------------
	*/

	/**
	 * Normalize incoming printing selections.
	 *
	 * Supported structure:
	 *
	 * [
	 *     [
	 *         'position_id' => 1,
	 *         'option_id'   => 10,
	 *         'colors'      => 2,
	 *     ],
	 * ]
	 *
	 * Legacy frontend aliases remain accepted:
	 *
	 *     print_position_id
	 *     position
	 *     print_option_id
	 *     option
	 *     color_count
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


			$position_id =
				absint(
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


			$option_id =
				absint(
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


			$colors =
				max(
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
			 * This also prevents duplicate frontend entries from charging
			 * the same position twice.
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


	/*
	|--------------------------------------------------------------------------
	| Accessors
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return the Printing repository.
	 */
	public function repository(): Repository {

		return $this->repository;
	}


	/**
	 * Return the Printing fee service.
	 */
	public function fees(): Fees {

		return $this->fees;
	}


	/**
	 * Return the Pricing module.
	 */
	public function pricing(): Pricing {

		return $this->pricing;
	}
}
