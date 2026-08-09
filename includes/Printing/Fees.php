<?php

namespace PromiDataXWoo\Printing;

defined( 'ABSPATH' ) || exit;

/**
 * Print fee service.
 *
 * Responsible for:
 * - Retrieving fees for a print option
 * - Evaluating fee requirements
 * - Calculating selling fees
 * - Calculating purchase fees
 *
 * Storage remains inside Repository.
 */
final class Fees {

	private Repository $repository;


	public function __construct(
		Repository $repository
	) {
		$this->repository = $repository;
	}


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


	/**
	 * Calculate total selling-side fees for one print option.
	 *
	 * Context may contain:
	 *
	 * [
	 *     'quantity'        => 100,
	 *     'colors'          => 2,
	 *     'positions'       => 1,
	 *     'product_id'      => 123,
	 *     'variation_id'    => 456,
	 *     'print_option_id' => 12,
	 * ]
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


	/**
	 * Calculate total purchase-side fees.
	 *
	 * If a fee has no purchase_amount, it contributes zero to the purchase
	 * calculation rather than falling back to its selling amount.
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

		$fees = $this->get(
			$option_id
		);

		if ( empty( $fees ) ) {
			return 0.0;
		}

		$context = $this->normalize_context(
			$option_id,
			$context
		);

		$total = 0.0;

		foreach ( $fees as $fee ) {

			if (
				! $this->requirements_met(
					$fee,
					$context
				)
			) {
				continue;
			}

			$value = $this->fee_value(
				$fee,
				$column
			);

			if ( null === $value ) {
				continue;
			}

			$total += $this->apply_calculation(
				$value,
				$fee,
				$context
			);
		}

		return $total;
	}


	/**
	 * Normalize calculation context.
	 */
	private function normalize_context(
		int $option_id,
		array $context
	): array {

		return [
			'quantity' => max(
				1,
				absint(
					$context['quantity']
					?? 1
				)
			),

			'colors' => max(
				0,
				absint(
					$context['colors']
					?? 0
				)
			),

			'positions' => max(
				1,
				absint(
					$context['positions']
					?? 1
				)
			),

			'product_id' => absint(
				$context['product_id']
				?? 0
			),

			'variation_id' => absint(
				$context['variation_id']
				?? 0
			),

			'print_option_id' => absint(
				$context['print_option_id']
				?? $option_id
			),
		];
	}


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

		if ( ! is_numeric( $fee->{$column} ) ) {
			return null;
		}

		return (float) $fee->{$column};
	}


	/**
	 * Apply the stored fee calculation rule.
	 *
	 * Existing data primarily distinguishes:
	 *
	 * unique
	 * multiplied
	 *
	 * "multiplied" uses calculation_amount where available.
	 */
	private function apply_calculation(
		float $amount,
		object $fee,
		array $context
	): float {

		$calculation = strtolower(
			trim(
				(string) (
					$fee->calculation
					?? 'unique'
				)
			)
		);

		if ( 'multiplied' !== $calculation ) {
			return $amount;
		}

		$multiplier = $this->multiplier(
			$fee,
			$context
		);

		return $amount * $multiplier;
	}


	/**
	 * Resolve the multiplier used by a multiplied fee.
	 */
	private function multiplier(
		object $fee,
		array $context
	): float {

		$type = strtolower(
			trim(
				(string) (
					$fee->calculation_type
					?? ''
				)
			)
		);

		$stored_amount = isset(
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
				(float) $context['colors']
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
				(float) $context['positions']
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
				(float) $context['quantity']
			);
		}


		/*
		|--------------------------------------------------------------------------
		| Stored multiplier
		|--------------------------------------------------------------------------
		*/

		if ( $stored_amount > 0 ) {
			return $stored_amount;
		}

		return 1.0;
	}


	/**
	 * Check whether a fee applies to the current configuration.
	 */
	private function requirements_met(
		object $fee,
		array $context
	): bool {

		$raw = $fee->requirement
			?? null;

		if (
			null === $raw
			|| '' === $raw
		) {
			return true;
		}

		if ( is_string( $raw ) ) {

			$requirement = json_decode(
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

		} elseif ( is_array( $raw ) ) {

			$requirement = $raw;

		} else {

			return true;
		}

		if ( empty( $requirement ) ) {
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
	 * deliberately supports both associative structures and lists of rules.
	 */
	private function evaluate_requirement(
		array $requirement,
		array $context
	): bool {

		/*
		 * A list of rules means every rule must pass.
		 */
		if ( array_is_list( $requirement ) ) {

			foreach ( $requirement as $rule ) {

				if (
					! is_array( $rule )
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
		| Generic field/operator/value structure
		|--------------------------------------------------------------------------
		*/

		$field = sanitize_key(
			$requirement['field']
			?? $requirement['Field']
			?? ''
		);

		$operator = strtolower(
			trim(
				(string) (
					$requirement['operator']
					?? $requirement['Operator']
					?? ''
				)
			)
		);

		$value = $requirement['value']
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

		$matched = false;

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

			$matched = true;

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

		$operator = match ( $operator ) {

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
				=== (string) $expected,

			'!=' =>
				(string) $actual
				!== (string) $expected,

			'>' =>
				(float) $actual
				> (float) $expected,

			'>=' =>
				(float) $actual
				>= (float) $expected,

			'<' =>
				(float) $actual
				< (float) $expected,

			'<=' =>
				(float) $actual
				<= (float) $expected,

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
}