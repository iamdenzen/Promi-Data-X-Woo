<?php

namespace PromiDataXWoo\Printing;

defined( 'ABSPATH' ) || exit;

/**
 * Global print-option service.
 *
 * Print options are reusable across products and variations.
 *
 * Promi calls these ImprintReferences.
 */
final class Options {

	private Repository $repository;


	public function __construct(
		Repository $repository
	) {
		$this->repository = $repository;
	}


	/**
	 * Synchronize Promi ImprintReferences into global print options.
	 */
	public function sync_promi(
		array $imprints
	): void {

		if ( empty( $imprints ) ) {
			return;
		}

		/*
		|--------------------------------------------------------------------------
		| Resolve Existing Options
		|--------------------------------------------------------------------------
		*/

		$skus = [];

		foreach ( $imprints as $imprint ) {

			if (
				! is_array( $imprint )
				|| empty( $imprint['Sku'] )
			) {
				continue;
			}

			$skus[] = sanitize_text_field(
				$imprint['Sku']
			);
		}

		$existing_options =
			$this->repository
				->get_option_ids_by_skus(
					$skus
				);


		/*
		|--------------------------------------------------------------------------
		| Synchronize Options
		|--------------------------------------------------------------------------
		*/

		foreach ( $imprints as $imprint ) {

			if (
				! is_array( $imprint )
				|| empty( $imprint['Sku'] )
			) {
				continue;
			}

			$sku = sanitize_text_field(
				$imprint['Sku']
			);

			if ( '' === $sku ) {
				continue;
			}


			/*
			|--------------------------------------------------------------------------
			| Basic Option Data
			|--------------------------------------------------------------------------
			*/

			$name = sanitize_text_field(
				$imprint
					['ImprintTexts']
					['de']
					['Name']
				?? $sku
			);

			if ( '' === $name ) {
				$name = $sku;
			}

			$price_data = $this->country_price_data(
				$imprint
			);

			$min_order_qty = max(
				1,
				absint(
					$price_data[
						'MinimumOrderQuantity'
					] ?? 1
				)
			);


			/*
			|--------------------------------------------------------------------------
			| Create / Update
			|--------------------------------------------------------------------------
			*/

			if (
				isset(
					$existing_options[ $sku ]
				)
			) {

				$option_id = absint(
					$existing_options[ $sku ]
				);

				$this->repository
					->update_option(
						$option_id,
						[
							'name' =>
								$name,

							'min_order_qty' =>
								$min_order_qty,

							/**
							 * The current Promi importer does not map a
							 * max-color field from ImprintReferences.
							 *
							 * Preserve whatever is already stored instead
							 * of overwriting it with zero.
							 */
							'max_colors' =>
								$this->existing_max_colors(
									$option_id
								),
						]
					);

			} else {

				$option_id =
					$this->repository
						->insert_option(
							[
								'sku' =>
									$sku,

								'name' =>
									$name,

								'min_order_qty' =>
									$min_order_qty,

								'max_colors' =>
									0,
							]
						);

				if ( $option_id ) {
					$existing_options[
						$sku
					] = $option_id;
				}
			}

			if ( ! $option_id ) {
				continue;
			}


			/*
			|--------------------------------------------------------------------------
			| Tier Prices
			|--------------------------------------------------------------------------
			*/

			$this->sync_prices(
				$option_id,
				$price_data
			);


			/*
			|--------------------------------------------------------------------------
			| Fees
			|--------------------------------------------------------------------------
			*/

			$this->sync_fees(
				$option_id,
				$imprint[
					'ImprintCosts'
				] ?? []
			);


			do_action(
				'pdxw_print_option_synced',
				$option_id,
				$sku,
				$imprint
			);
		}
	}


	/**
	 * Create a print option manually.
	 */
	public function create(
		array $data
	): int {

		return $this->repository
			->insert_option(
				[
					'sku' =>
						sanitize_text_field(
							$data['sku'] ?? ''
						),

					'name' =>
						sanitize_text_field(
							$data['name'] ?? ''
						),

					'min_order_qty' =>
						max(
							1,
							absint(
								$data[
									'min_order_qty'
								] ?? 1
							)
						),

					'max_colors' =>
						absint(
							$data[
								'max_colors'
							] ?? 0
						),
				]
			);
	}


	/**
	 * Update a print option manually.
	 */
	public function update(
		int $option_id,
		array $data
	): bool {

		$current =
			$this->repository
				->get_option(
					$option_id
				);

		if ( ! $current ) {
			return false;
		}

		return $this->repository
			->update_option(
				$option_id,
				[
					'name' =>
						isset( $data['name'] )
							? sanitize_text_field(
								$data['name']
							)
							: $current->name,

					'min_order_qty' =>
						isset(
							$data[
								'min_order_qty'
							]
						)
							? max(
								1,
								absint(
									$data[
										'min_order_qty'
									]
								)
							)
							: (int)
								$current
									->min_order_qty,

					'max_colors' =>
						isset(
							$data[
								'max_colors'
							]
						)
							? absint(
								$data[
									'max_colors'
								]
							)
							: (int)
								$current
									->max_colors,
				]
			);
	}


	/**
	 * Delete a global print option and its dependent records.
	 */
	public function delete(
		int $option_id
	): bool {

		return $this->repository
			->delete_option(
				$option_id
			);
	}


	/**
	 * Retrieve one option.
	 */
	public function find(
		int $option_id
	): ?object {

		return $this->repository
			->get_option(
				$option_id
			);
	}


	/**
	 * Find one option by Promi SKU.
	 */
	public function find_by_sku(
		string $sku
	): ?object {

		return $this->repository
			->find_option_by_sku(
				$sku
			);
	}


	/**
	 * Retrieve all global print options.
	 */
	public function all(
		bool $use_cache = true
	): array {

		return $this->repository
			->get_options(
				$use_cache
			);
	}


	/**
	 * Return distinct option names.
	 *
	 * Existing CX Print uses this for admin dropdowns.
	 */
	public function dropdown(
		bool $use_cache = true
	): array {

		return $this->repository
			->get_dropdown_options(
				$use_cache
			);
	}


	/**
	 * Retrieve several options in one query.
	 */
	public function by_ids(
		array $option_ids
	): array {

		return $this->repository
			->get_options_by_ids(
				$option_ids
			);
	}


	/**
	 * Return option IDs keyed by SKU.
	 */
	public function ids_by_skus(
		array $skus
	): array {

		return $this->repository
			->get_option_ids_by_skus(
				$skus
			);
	}


	/**
	 * Paginated options for the admin interface.
	 */
	public function paginated(
		int $page = 1,
		int $per_page = 50,
		string $sku = ''
	): array {

		$items =
			$this->repository
				->get_options_paginated(
					$page,
					$per_page,
					$sku
				);

		$total =
			$this->repository
				->count_options(
					$sku
				);

		$per_page = max(
			1,
			$per_page
		);

		return [
			'items' => $items,

			'total' => $total,

			'page' => max(
				1,
				$page
			),

			'per_page' => $per_page,

			'total_pages' => max(
				1,
				(int) ceil(
					$total / $per_page
				)
			),
		];
	}


	/**
	 * Return all data needed by the configurator for one option.
	 */
	public function config(
		int $option_id
	): ?array {

		$option =
			$this->repository
				->get_option(
					$option_id
				);

		if ( ! $option ) {
			return null;
		}

		return [
			'id' =>
				(int) $option->id,

			'sku' =>
				(string) $option->sku,

			'name' =>
				(string) $option->name,

			'min_order_qty' =>
				(int)
					$option
						->min_order_qty,

			'max_colors' =>
				(int)
					$option
						->max_colors,

			'prices' =>
				$this->repository
					->get_prices(
						$option_id
					),

			'fees' =>
				$this->repository
					->get_fees(
						$option_id
					),
		];
	}


	/*
	|--------------------------------------------------------------------------
	| Promi Price Synchronization
	|--------------------------------------------------------------------------
	*/

	private function sync_prices(
		int $option_id,
		array $price_data
	): void {

		$selling_prices =
			$price_data[
				'RecommendedSellingPrice'
			] ?? [];

		$purchasing_prices =
			$price_data[
				'GeneralBuyingPrice'
			] ?? [];

		/*
		 * Purchase tiers are keyed by their exact quantity.
		 *
		 * This is how the current importer pairs selling and buying
		 * prices. We intentionally do not approximate or carry forward
		 * purchase tiers here.
		 */
		$purchase_by_qty = [];

		if ( is_array( $purchasing_prices ) ) {

			foreach (
				$purchasing_prices
				as $price
			) {

				if (
					! is_array( $price )
					|| ! empty(
						$price[
							'OnRequest'
						]
					)
				) {
					continue;
				}

				$qty = absint(
					$price[
						'Quantity'
					] ?? 0
				);

				$value =
					$this->promi_price(
						$price
					);

				if (
					$qty <= 0
					|| null === $value
				) {
					continue;
				}

				$purchase_by_qty[
					$qty
				] = $value;
			}
		}


		$tiers = [];

		if ( is_array( $selling_prices ) ) {

			foreach (
				$selling_prices
				as $price
			) {

				if (
					! is_array( $price )
					|| ! empty(
						$price[
							'OnRequest'
						]
					)
				) {
					continue;
				}

				$qty = absint(
					$price[
						'Quantity'
					] ?? 0
				);

				$value =
					$this->promi_price(
						$price
					);

				if (
					$qty <= 0
					|| null === $value
				) {
					continue;
				}

				$tiers[] = [
					'min_qty' =>
						$qty,

					'price' =>
						$value,

					'purchase_price' =>
						$purchase_by_qty[
							$qty
						] ?? null,
				];
			}
		}

		$this->repository
			->replace_prices(
				$option_id,
				$tiers
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Promi Fee Synchronization
	|--------------------------------------------------------------------------
	*/

	private function sync_fees(
		int $option_id,
		array $costs
	): void {

		$fees = [];

		foreach ( $costs as $cost ) {

			if ( ! is_array( $cost ) ) {
				continue;
			}

			$label = sanitize_text_field(
				$cost
					['Texts']
					['de']
					['Name']
				?? ''
			);

			if ( '' === $label ) {
				continue;
			}

			/*
			 * This classification is copied from the current importer.
			 *
			 * Any other Promi cost type is intentionally ignored.
			 */
			$type =
				$this->fee_type_from_label(
					$label
				);

			if ( null === $type ) {
				continue;
			}


			$calculation_type =
				sanitize_text_field(
					$cost[
						'CalculationType'
					] ?? ''
				);

			$calculation =
				'multiplied'
				=== strtolower(
					$calculation_type
				)
					? 'multiplied'
					: 'unique';


			$price_data =
				$this->country_price_data(
					$cost
				);

			$selling_fee =
				$this->first_available_price(
					$price_data[
						'RecommendedSellingPrice'
					] ?? []
				);

			$purchase_fee =
				$this->first_available_price(
					$price_data[
						'GeneralBuyingPrice'
					] ?? []
				);


			/*
			 * The existing importer requires a selling value because
			 * `amount` remains the canonical fee amount.
			 */
			if ( null === $selling_fee ) {
				continue;
			}


			$fees[] = [
				'label' =>
					$label,

				'type' =>
					$type,

				'calculation' =>
					$calculation,

				'calculation_type' =>
					$calculation_type,

				'calculation_amount' =>
					(float) (
						$cost[
							'CalculationAmount'
						] ?? 0
					),

				'requirement' =>
					$cost[
						'Requirement'
					] ?? null,

				'amount' =>
					$selling_fee,

				'purchase_amount' =>
					$purchase_fee,
			];
		}

		$this->repository
			->replace_fees(
				$option_id,
				$fees
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Mapping Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Promi price data used by the existing importer.
	 *
	 * DEU is preferred, EURO is the fallback.
	 */
	private function country_price_data(
		array $data
	): array {

		$prices =
			$data[
				'ProductPriceCountryBased'
			] ?? [];

		if ( ! is_array( $prices ) ) {
			return [];
		}

		return $prices['DEU']
			?? $prices['EURO']
			?? [];
	}


	/**
	 * Extract one Promi price value.
	 */
	private function promi_price(
		array $row
	): ?float {

		if (
			! empty(
				$row['OnRequest']
			)
		) {
			return null;
		}

		if (
			! array_key_exists(
				'Price',
				$row
			)
			|| '' === (string)
				$row['Price']
			|| ! is_numeric(
				$row['Price']
			)
		) {
			return null;
		}

		return (float)
			$row['Price'];
	}


	/**
	 * Return the first usable Promi price.
	 *
	 * This is intentionally different from product base pricing, where
	 * the existing importer uses another tier-selection rule.
	 *
	 * Existing print fee synchronization takes the first available tier.
	 */
	private function first_available_price(
		mixed $prices
	): ?float {

		if (
			empty( $prices )
			|| ! is_array( $prices )
		) {
			return null;
		}

		foreach ( $prices as $price ) {

			if ( ! is_array( $price ) ) {
				continue;
			}

			$value =
				$this->promi_price(
					$price
				);

			if ( null !== $value ) {
				return $value;
			}
		}

		return null;
	}


	/**
	 * Map existing German fee labels into XSImpress fee types.
	 */
	private function fee_type_from_label(
		string $label
	): ?string {

		$normalized =
			strtolower(
				$label
			);

		if (
			false !== strpos(
				$normalized,
				'einrichtung'
			)
		) {
			return 'setup';
		}

		if (
			false !== strpos(
				$normalized,
				'handling'
			)
		) {
			return 'handling';
		}

		return null;
	}


	/**
	 * Preserve existing max_colors when Promi updates the option.
	 *
	 * The current Promi synchronization does not populate max_colors,
	 * even though cx_print_options supports the field and other parts of
	 * the frontend may consume it.
	 */
	private function existing_max_colors(
		int $option_id
	): int {

		$option =
			$this->repository
				->get_option(
					$option_id
				);

		return $option
			? absint(
				$option->max_colors
			)
			: 0;
	}
}