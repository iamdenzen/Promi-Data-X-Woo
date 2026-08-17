<?php

namespace PromiDataXWoo\Printing;

defined( 'ABSPATH' ) || exit;

/**
 * Print fee service.
 *
 * Responsible for:
 *
 * - Retrieving fees for a print option.
 * - Evaluating fee requirements.
 * - Calculating selling fees.
 * - Calculating purchase fees.
 * - Returning detailed purchase-fee breakdowns for the pricing engine.
 *
 * Storage remains inside Repository.
 *
 * Important:
 *
 * This class does NOT apply customer markups.
 *
 * It returns the raw Promi-side fee amounts. The Printing Calculator then
 * applies the configured finishing markup and the appropriate rounding rule:
 *
 *     setup fee
 *         → markup
 *         → nearest whole euro
 *
 *     ongoing fee
 *         → markup
 *         → no rounding
 */
final class Fees {

	private Repository $repository;


	public function __construct(
		Repository $repository
	) {

		$this->repository =
			$repository;
	}


	/*
	|--------------------------------------------------------------------------
	| Retrieval
	|--------------------------------------------------------------------------
	*/

	/**
	 * Get all fees for a print option.
	 */
	public function get(
		int $option_id
	): array {

		return $this->repository
			->get_fees(
				$option_id
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Selling Fees
	|--------------------------------------------------------------------------
	*/

	/**
	 * Calculate total Promi selling-side fees for one print option.
	 *
	 * This method intentionally returns the stored selling-side amount.
	 *
	 * The new customer pricing engine does not use this amount to calculate
	 * the storefront price. It is retained for compatibility, previews,
	 * reporting, and any existing consumers that still need Promi's raw
	 * selling-side value.
	 */
	public function calculate(
		int $option_id,
		array $context = []
	): float {

		return $this->calculate_column(
			$option_id,
			'amount',
			$context
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Purchase Fees
	|--------------------------------------------------------------------------
	*/

	/**
	 * Calculate total purchase-side fees for one print option.
	 *
	 * Unlike calculate_purchase_breakdown(), this aggregate has no
	 * fallback to the selling amount when purchase_amount is missing.
	 * Only used by Calculator's legacy compatibility fallback; the
	 * normal path is calculate_purchase_breakdown().
	 */
	public function calculate_purchase(
		int $option_id,
		array $context = []
	): float {

		return $this->calculate_column(
			$option_id,
			'purchase_amount',
			$context
		);
	}


	/**
	 * Return the detailed purchase-side fee breakdown.
	 *
	 * Each returned row contains:
	 *
	 * [
	 *     'type'  => 'setup',
	 *     'label' => 'Setup',
	 *     'raw'   => 20.00,
	 * ]
	 *
	 * The type and label come directly from the existing fee data.
	 *
	 * No markup or rounding is applied here.
	 */
	public function calculate_purchase_breakdown(
		int $option_id,
		array $context = []
	): array {

		$fees =
			$this->get(
				$option_id
			);


		if ( empty( $fees ) ) {
			return [];
		}


		$context =
			$this->normalize_context(
				$option_id,
				$context
			);


		$result = [];


		foreach (
			$fees as $fee
		) {

			/*
			|--------------------------------------------------------------------------
			| Requirements
			|--------------------------------------------------------------------------
			*/

			if (
				! $this->requirements_met(
					$fee,
					$context
				)
			) {

				$this->debug_log(
					fn () => sprintf(
						'fee "%s" skipped for option #%d: requirement not met (requirement=%s, context=%s)',
						(string) ( $fee->fee_label ?? '' ),
						$option_id,
						(string) ( $fee->requirement ?? '' ),
						wp_json_encode( $context )
					)
				);

				continue;
			}


			/*
			|--------------------------------------------------------------------------
			| Purchase Amount
			|--------------------------------------------------------------------------
			|
			| Some historical print_option data (imported from an older CX
			| Print installer that failed to create the purchase_amount
			| column, see Core\Database) never had purchase_amount
			| populated.
			|
			| Mirroring get_applicable_purchase_price()'s behaviour for
			| print tier prices, fall back to the Promi selling amount as
			| the cost basis rather than silently dropping the fee.
			*/

			$value =
				$this->fee_value(
					$fee,
					'purchase_amount'
				);


			if ( null === $value ) {

				$value =
					$this->fee_value(
						$fee,
						'amount'
					);
			}


			if ( null === $value ) {

				$this->debug_log(
					fn () => sprintf(
						'fee "%s" skipped for option #%d: no usable purchase_amount or amount',
						(string) ( $fee->fee_label ?? '' ),
						$option_id
					)
				);

				continue;
			}


			/*
			|--------------------------------------------------------------------------
			| Apply Existing Fee Calculation Rule
			|--------------------------------------------------------------------------
			|
			| For example:
			|
			| unique
			| multiplied by colors
			| multiplied by quantity
			| multiplied by positions
			|
			| This produces the raw purchase-side fee amount.
			*/

			$raw =
				$this->apply_calculation(
					$value,
					$fee,
					$context
				);


			$raw =
				max(
					0.0,
					(float) $raw
				);


			/*
			|--------------------------------------------------------------------------
			| Preserve Existing Fee Metadata
			|--------------------------------------------------------------------------
			|
			| The database already has fee_type and fee_label. We don't invent
			| a new taxonomy here.
			*/

			$type =
				trim(
					(string) (
						$fee->fee_type
							?? ''
					)
				);


			$label =
				trim(
					(string) (
						$fee->fee_label
							?? ''
					)
				);


			$result[] = [

				'type' =>
					$type,

				'label' =>
					$label,

				'raw' =>
					$raw,
			];
		}


		return $result;
	}


	/*
	|--------------------------------------------------------------------------
	| Generic Fee Calculation
	|--------------------------------------------------------------------------
	*/

	/**
	 * Calculate one side of the fee structure.
	 */
	private function calculate_column(
		int $option_id,
		string $column,
		array $context
	): float {

		if (
			! in_array(
				$column,
				[
					'amount',
					'purchase_amount',
				],
				true
			)
		) {

			return 0.0;
		}


		$fees =
			$this->get(
				$option_id
			);


		if ( empty( $fees ) ) {
			return 0.0;
		}


		$context =
			$this->normalize_context(
				$option_id,
				$context
			);


		$total =
			0.0;


		foreach (
			$fees as $fee
		) {

			if (
				! $this->requirements_met(
					$fee,
					$context
				)
			) {
				continue;
			}


			$value =
				$this->fee_value(
					$fee,
					$column
				);


			if ( null === $value ) {
				continue;
			}


			$total +=
				$this->apply_calculation(
					$value,
					$fee,
					$context
				);
		}


		return max(
			0.0,
			$total
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Context
	|--------------------------------------------------------------------------
	*/

	/**
	 * Normalize calculation context.
	 */
	private function normalize_context(
		int $option_id,
		array $context
	): array {

		return [

			'quantity' =>
				max(
					1,
					absint(
						$context['quantity']
							?? 1
					)
				),

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
				absint(
					$context['print_option_id']
						?? $option_id
				),
		];
	}


	/*
	|--------------------------------------------------------------------------
	| Fee Values
	|--------------------------------------------------------------------------
	*/

	/**
	 * Extract the requested fee value.
	 */
	private function fee_value(
		object $fee,
		string $column
	): ?float {

		if (
			! isset( $fee->{$column} )
			|| null === $fee->{$column}
			|| '' === (string) $fee->{$column}
		) {

			return null;
		}


		if (
			! is_numeric(
				$fee->{$column}
			)
		) {

			return null;
		}


		return (float)
			$fee->{$column};
	}


	/*
	|--------------------------------------------------------------------------
	| Fee Calculation Rules
	|--------------------------------------------------------------------------
	*/

	/**
	 * Apply the stored fee calculation rule.
	 *
	 * Existing data primarily distinguishes:
	 *
	 *     unique
	 *     multiplied
	 *
	 * "multiplied" uses calculation_amount where appropriate.
	 */
	private function apply_calculation(
		float $amount,
		object $fee,
		array $context
	): float {

		$calculation =
			strtolower(
				trim(
					(string) (
						$fee->calculation
							?? 'unique'
					)
				)
			);


		if (
			'multiplied'
			!== $calculation
		) {

			return $amount;
		}


		$multiplier =
			$this->multiplier(
				$fee,
				$context
			);


		return $amount
			* $multiplier;
	}


	/**
	 * Resolve the multiplier used by a multiplied fee.
	 */
	private function multiplier(
		object $fee,
		array $context
	): float {

		$type =
			strtolower(
				trim(
					(string) (
						$fee->calculation_type
							?? ''
					)
				)
			);


		$stored_amount =
			isset(
				$fee->calculation_amount
			)
			&& is_numeric(
				$fee->calculation_amount
			)
				? (float)
					$fee->calculation_amount
				: 0.0;


		/*
		|--------------------------------------------------------------------------
		| Context-driven calculations
		|--------------------------------------------------------------------------
		*/

		if (
			str_contains(
				$type,
				'color'
			)
		) {

			return max(
				1,
				(float)
					$context['colors']
			);
		}


		if (
			str_contains(
				$type,
				'position'
			)
		) {

			return max(
				1,
				(float)
					$context['positions']
			);
		}


		if (
			str_contains(
				$type,
				'quantity'
			)
			|| str_contains(
				$type,
				'qty'
			)
		) {

			return max(
				1,
				(float)
					$context['quantity']
			);
		}


		/*
		|--------------------------------------------------------------------------
		| Stored Multiplier
		|--------------------------------------------------------------------------
		*/

		if (
			$stored_amount > 0
		) {

			return $stored_amount;
		}


		return 1.0;
	}


	/*
	|--------------------------------------------------------------------------
	| Requirements
	|--------------------------------------------------------------------------
	*/

	/**
	 * Check whether a fee applies to the current configuration.
	 */
	private function requirements_met(
		object $fee,
		array $context
	): bool {

		$raw =
			$fee->requirement
				?? null;


		if (
			null === $raw
			|| '' === $raw
		) {

			return true;
		}


		if (
			is_string(
				$raw
			)
		) {

			$requirement =
				json_decode(
					$raw,
					true
				);


			if (
				JSON_ERROR_NONE
				!== json_last_error()
			) {

				/*
				 * Invalid historical requirement data should not make the
				 * entire pricing calculation fail.
				 */
				return true;
			}

		} elseif (
			is_array(
				$raw
			)
		) {

			$requirement =
				$raw;

		} else {

			return true;
		}


		if (
			empty(
				$requirement
			)
		) {

			return true;
		}


		return $this->evaluate_requirement(
			$requirement,
			$context
		);
	}


	/**
	 * Evaluate requirement data.
	 *
	 * Promi requirement payloads are not consistently shaped, so this
	 * supports both associative structures and lists of rules.
	 */
	private function evaluate_requirement(
		array $requirement,
		array $context
	): bool {

		/*
		|--------------------------------------------------------------------------
		| List of rules
		|--------------------------------------------------------------------------
		|
		| A list means every rule must pass.
		*/

		if (
			array_is_list(
				$requirement
			)
		) {

			foreach (
				$requirement as $rule
			) {

				if (
					! is_array(
						$rule
					)
					|| ! $this->evaluate_requirement(
						$rule,
						$context
					)
				) {

					return false;
				}
			}


			return true;
		}


		/*
		|--------------------------------------------------------------------------
		| Generic field / operator / value
		|--------------------------------------------------------------------------
		*/

		$field =
			sanitize_key(
				$requirement['field']
					?? $requirement['Field']
					?? ''
			);


		$operator =
			strtolower(
				trim(
					(string) (
						$requirement['operator']
							?? $requirement['Operator']
							?? ''
					)
				)
			);


		$value =
			$requirement['value']
				?? $requirement['Value']
				?? null;


		if (
			$field
			&& array_key_exists(
				$field,
				$context
			)
		) {

			return $this->compare(
				$context[ $field ],
				$operator,
				$value
			);
		}


		/*
		|--------------------------------------------------------------------------
		| Known shorthand keys
		|--------------------------------------------------------------------------
		*/

		$checks = [

			'min_quantity' => [
				'quantity',
				'>=',
			],

			'max_quantity' => [
				'quantity',
				'<=',
			],

			'min_colors' => [
				'colors',
				'>=',
			],

			'max_colors' => [
				'colors',
				'<=',
			],

			'min_positions' => [
				'positions',
				'>=',
			],

			'max_positions' => [
				'positions',
				'<=',
			],
		];


		foreach (
			$checks as $key => [
				$context_key,
				$comparison,
			]
		) {

			if (
				! array_key_exists(
					$key,
					$requirement
				)
			) {
				continue;
			}


			if (
				! $this->compare(
					$context[ $context_key ],
					$comparison,
					$requirement[ $key ]
				)
			) {

				return false;
			}
		}


		/*
		 * Unknown requirement structures are treated as applicable rather
		 * than accidentally suppressing a fee.
		 */
		return true;
	}


	/**
	 * Compare two requirement values.
	 */
	private function compare(
		mixed $actual,
		string $operator,
		mixed $expected
	): bool {

		$operator =
			match ( $operator ) {

				'=', '==', 'eq' =>
					'==',

				'!=', '<>', 'neq' =>
					'!=',

				'>', 'gt' =>
					'>',

				'>=', 'gte' =>
					'>=',

				'<', 'lt' =>
					'<',

				'<=', 'lte' =>
					'<=',

				'in' =>
					'in',

				'not_in', 'not-in' =>
					'not_in',

				default =>
					'==',
			};


		return match ( $operator ) {

			'==' =>
				(string) $actual
				===
				(string) $expected,

			'!=' =>
				(string) $actual
				!==
				(string) $expected,

			'>' =>
				(float) $actual
				>
				(float) $expected,

			'>=' =>
				(float) $actual
				>=
				(float) $expected,

			'<' =>
				(float) $actual
				<
				(float) $expected,

			'<=' =>
				(float) $actual
				<=
				(float) $expected,

			'in' =>
				in_array(
					$actual,
					(array) $expected,
					true
				),

			'not_in' =>
				! in_array(
					$actual,
					(array) $expected,
					true
				),

			default =>
				true,
		};
	}


	/*
	|--------------------------------------------------------------------------
	| Debug Logging
	|--------------------------------------------------------------------------
	*/

	/**
	 * Log why a fee was excluded from a purchase breakdown.
	 *
	 * Only active when WP_DEBUG is enabled, so this never runs on a
	 * production site unless the site owner has already turned on
	 * debug logging.
	 *
	 * Takes a closure rather than a pre-built string: this runs inside
	 * the cart/checkout/product-page pricing hot path (once per fee row,
	 * per print position, per pricing calculation), so the sprintf()/
	 * wp_json_encode() cost of building the message must only be paid
	 * when WP_DEBUG is actually on, not discarded on every request.
	 */
	private function debug_log(
		callable $message
	): void {

		if (
			! defined( 'WP_DEBUG' )
			|| ! WP_DEBUG
		) {
			return;
		}

		error_log(
			'[PromiDataXWoo\\Printing\\Fees] ' . $message()
		);
	}
}
