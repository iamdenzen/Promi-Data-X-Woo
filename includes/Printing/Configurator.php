<?php

namespace PromiDataXWoo\Printing;

defined( 'ABSPATH' ) || exit;

/**
 * Printing configurator service.
 *
 * Provides frontend-ready printing data for:
 *
 * - Available print positions
 * - Available options per position
 * - Tier prices
 * - Fees
 * - Minimum quantities
 * - Selection validation
 * - Price previews
 */
final class Configurator {

	private Printing $printing;

	private Repository $repository;

	private Calculator $calculator;


	public function __construct(
		Printing $printing,
		Repository $repository,
		Calculator $calculator
	) {
		$this->printing   = $printing;
		$this->repository = $repository;
		$this->calculator = $calculator;
	}


	/**
	 * Get full printing configuration for a product or variation.
	 *
	 * Variation-specific positions are preferred.
	 * If no variation-specific configuration exists, parent positions
	 * can be returned as a fallback.
	 */
	public function get_config(
		int $product_id,
		int $variation_id = 0
	): array {

		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );

		if ( ! $product_id ) {
			return [];
		}

		$positions = [];

		if ( $variation_id ) {
			$positions = $this->repository->get_positions(
				$product_id,
				$variation_id
			);
		}

		/*
		 * Some products may have parent-level print positions.
		 */
		if ( empty( $positions ) ) {
			$positions = $this->repository->get_positions(
				$product_id,
				0
			);
		}

		if ( empty( $positions ) ) {
			return [];
		}

		$config = [];

		foreach ( $positions as $position ) {

			$options = $this->repository
				->get_options_by_position(
					$product_id,
					$variation_id ?: 0,
					(int) $position->id
				);

			/*
			 * If we fell back to parent positions, relations may also live
			 * against variation_id = 0.
			 */
			if (
				empty( $options )
				&& $variation_id
			) {
				$options = $this->repository
					->get_options_by_position(
						$product_id,
						0,
						(int) $position->id
					);
			}

			if ( empty( $options ) ) {
				continue;
			}

			$config[] = $this->prepare_position(
				$position,
				$options
			);
		}

		return $config;
	}


	/**
	 * Return only positions available for a product/variation.
	 */
	public function positions(
		int $product_id,
		int $variation_id = 0
	): array {

		$config = $this->get_config(
			$product_id,
			$variation_id
		);

		return array_map(
			static function ( array $position ): array {

				return [
					'id'    => $position['id'],
					'code'  => $position['code'],
					'label' => $position['label'],
					'area'  => $position['area'],
					'image' => $position['image'],
				];
			},
			$config
		);
	}


	/**
	 * Return options available for one position.
	 */
	public function options(
		int $product_id,
		int $variation_id,
		int $position_id
	): array {

		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );
		$position_id  = absint( $position_id );

		if (
			! $product_id
			|| ! $position_id
		) {
			return [];
		}

		$options = $this->repository
			->get_options_by_position(
				$product_id,
				$variation_id,
				$position_id
			);

		if (
			empty( $options )
			&& $variation_id
		) {
			$options = $this->repository
				->get_options_by_position(
					$product_id,
					0,
					$position_id
				);
		}

		$result = [];

		foreach ( $options as $option ) {
			$result[] = $this->prepare_option(
				$option
			);
		}

		return $result;
	}


	/**
	 * Validate submitted selections.
	 *
	 * Expected:
	 *
	 * [
	 *     position_id => option_id,
	 * ]
	 */
	public function validate_selections(
		int $product_id,
		int $variation_id,
		array $selections,
		int $quantity = 1
	): array {

		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );
		$quantity     = max(
			1,
			absint( $quantity )
		);

		if (
			! $product_id
			|| empty( $selections )
		) {
			return [];
		}

		$valid = [];

		foreach (
			$selections
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

			if (
				! $this->selection_exists(
					$product_id,
					$variation_id,
					$position_id,
					$option_id
				)
			) {
				continue;
			}

			$option = $this->repository
				->get_option(
					$option_id
				);

			if ( ! $option ) {
				continue;
			}

			$minimum = max(
				1,
				absint(
					$option->min_order_qty
				)
			);

			if ( $quantity < $minimum ) {
				continue;
			}

			$valid[ $position_id ] =
				$option_id;
		}

		return $valid;
	}


	/**
	 * Return a selling-price preview for submitted selections.
	 */
	public function price_preview(
		int $product_id,
		int $variation_id,
		int $quantity,
		array $selections
	): array {

		$quantity = max(
			1,
			absint( $quantity )
		);

		$valid = $this->validate_selections(
			$product_id,
			$variation_id,
			$selections,
			$quantity
		);

		if ( empty( $valid ) ) {
			return [
				'selections'        => [],
				'printing_total'    => 0.0,
				'printing_per_unit' => 0.0,
			];
		}

		$calculator_selections = [];

		foreach (
			$valid as $position_id => $option_id
		) {
			$calculator_selections[] = [
				'position_id' =>
					$position_id,

				'option_id' =>
					$option_id,
			];
		}

		$total = $this->calculator
			->calculate(
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

		return [
			'selections' =>
				$valid,

			'printing_total' =>
				$total,

			'printing_per_unit' =>
				$total / $quantity,
		];
	}


	/**
	 * Return a detailed preview for one print option.
	 */
	public function option_price_preview(
		int $option_id,
		int $quantity,
		array $context = []
	): array {

		return $this->calculator
			->calculate_selection(
				$option_id,
				$quantity,
				$context
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Position Formatting
	|--------------------------------------------------------------------------
	*/

	private function prepare_position(
		object $position,
		array $options
	): array {

		$prepared_options = [];

		foreach ( $options as $option ) {
			$prepared_options[] =
				$this->prepare_option(
					$option
				);
		}

		return [
			'id' =>
				(int) $position->id,

			'code' =>
				(string)
					$position
						->position_code,

			'label' =>
				(string)
					$position
						->position_label,

			'area' =>
				(string)
					$position
						->area,

			'image' =>
				$position->image
					? wp_get_attachment_image_url(
						(int) $position->image,
						'medium'
					)
					: null,

			'options' =>
				$prepared_options,
		];
	}


	/*
	|--------------------------------------------------------------------------
	| Option Formatting
	|--------------------------------------------------------------------------
	*/

	private function prepare_option(
		object $option
	): array {

		$option_id = (int)
			$option->id;

		$prices = $this->repository
			->get_selling_prices(
				$option_id
			);

		$fees = $this->repository
			->get_fees(
				$option_id
			);

		return [
			'id' =>
				$option_id,

			'sku' =>
				(string)
					$option->sku,

			'name' =>
				(string)
					$option->name,

			'min_order_qty' =>
				max(
					1,
					(int)
						$option
							->min_order_qty
				),

			'max_colors' =>
				max(
					0,
					(int)
						$option
							->max_colors
				),

			'prices' =>
				$this->prepare_prices(
					$prices
				),

			'fees' =>
				$this->prepare_fees(
					$fees
				),
		];
	}


	private function prepare_prices(
		array $prices
	): array {

		$result = [];

		foreach ( $prices as $price ) {

			$quantity =
				max(
					1,
					(int)
						$price
							->min_qty
				);

			$processed =
				$this->calculator
					->calculate_selection(
						(int) (
							$price
								->print_option_id
								?? 0
						),
						$quantity
					);

			$result[] = [
				'quantity' =>
					$quantity,

				'price' =>
					(float) (
						$processed['unit_price']
							?? 0.0
					),
			];
		}

		return $result;
	}


	private function prepare_fees(
		array $fees
	): array {

		$result = [];

		foreach ( $fees as $fee ) {

			$requirement = null;

			if (
				isset(
					$fee->requirement
				)
				&& '' !==
					(string)
						$fee
							->requirement
			) {

				$decoded = json_decode(
					(string)
						$fee
							->requirement,
					true
				);

				if (
					JSON_ERROR_NONE
					=== json_last_error()
				) {
					$requirement =
						$decoded;
				}
			}

			$result[] = [
				'label' =>
					(string)
						$fee
							->fee_label,

				'type' =>
					(string)
						$fee
							->fee_type,

				'calculation' =>
					(string)
						$fee
							->calculation,

				'calculation_type' =>
					(string)
						$fee
							->calculation_type,

				'calculation_amount' =>
					(float)
						$fee
							->calculation_amount,

				'amount' =>
					(float)
						$fee
							->amount,

				'requirement' =>
					$requirement,
			];
		}

		return $result;
	}


	/*
	|--------------------------------------------------------------------------
	| Validation
	|--------------------------------------------------------------------------
	*/

	private function selection_exists(
		int $product_id,
		int $variation_id,
		int $position_id,
		int $option_id
	): bool {

		/*
		 * Prefer variation-level relationship.
		 */
		if (
			$variation_id
			&& $this->repository
				->selection_is_valid(
					$variation_id,
					$position_id,
					$option_id
				)
		) {
			return true;
		}

		/*
		 * Parent-level fallback.
		 */
		return $this->repository
			->selection_is_valid(
				$product_id,
				$position_id,
				$option_id
			);
	}
}