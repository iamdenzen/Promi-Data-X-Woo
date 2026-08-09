<?php

namespace PromiDataXWoo\Printing;

defined( 'ABSPATH' ) || exit;

/**
 * Print position service.
 *
 * Owns:
 * - Position synchronization
 * - Promi ImprintPositions mapping
 * - Position cleanup
 * - Position -> print option relationships
 *
 * Direct SQL remains inside Repository.
 */
final class Positions {

	private Repository $repository;


	public function __construct(
		Repository $repository
	) {
		$this->repository = $repository;
	}


	/**
	 * Synchronize Promi imprint positions for one product variation.
	 *
	 * Promi structure:
	 *
	 * ImprintReferences
	 *      ↓ referenced by hash
	 * ImprintPositions
	 *      ↓
	 * ImprintOptionsReferences
	 *
	 * Each resulting relation becomes:
	 *
	 * product
	 *   → variation
	 *      → position
	 *         → print option
	 */
	public function sync_promi(
		int $product_id,
		int $variation_id,
		array $references,
		array $positions
	): void {

		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );

		if (
			! $product_id
			|| empty( $references )
			|| empty( $positions )
		) {
			return;
		}

		/**
		 * Existing XSImpress behavior:
		 *
		 * Every Promi sync completely replaces positions for this
		 * product/variation combination.
		 */
		$this->repository
			->delete_positions_by_product_variation(
				$product_id,
				$variation_id
			);


		/**
		 * Collect every print-option SKU required by these positions.
		 *
		 * We resolve them in one database query rather than querying
		 * the print_options table inside each position loop.
		 */
		$option_skus = $this->collect_option_skus(
			$references,
			$positions
		);

		$option_ids_by_sku =
			$this->repository
				->get_option_ids_by_skus(
					$option_skus
				);


		/**
		 * Relations are collected and inserted in bulk after every
		 * position has been created.
		 */
		$relation_rows = [];

		/**
		 * Promi sometimes provides the same PositionCode more than once.
		 *
		 * The existing importer deliberately keeps the first occurrence.
		 */
		$processed_codes = [];


		foreach ( $positions as $position ) {

			if ( ! is_array( $position ) ) {
				continue;
			}

			$code = sanitize_key(
				$position['PositionCode']
					?? ''
			);

			if ( '' === $code ) {
				continue;
			}

			if (
				isset(
					$processed_codes[ $code ]
				)
			) {
				continue;
			}


			/*
			|--------------------------------------------------------------------------
			| Label
			|--------------------------------------------------------------------------
			*/

			$label = sanitize_text_field(
				$position
					['ImprintLocationTexts']
					['de']
					['Name']
				?? $code
			);

			if ( '' === $label ) {
				$label = $code;
			}


			/*
			|--------------------------------------------------------------------------
			| Printable Area
			|--------------------------------------------------------------------------
			*/

			$area = $this->extract_area(
				$position,
				$label
			);


			/*
			|--------------------------------------------------------------------------
			| Position Image
			|--------------------------------------------------------------------------
			|
			| The old Promi importer called:
			|
			| CX_Promi_Image::handle_other_image()
			|
			| directly from its product-sync class.
			|
			| Printing should not depend on the Promi image service, so we
			| expose this through a filter. Later our Promi ImageSync service
			| will handle this filter and return the attachment ID.
			|
			| Until then it safely resolves to 0.
			*/

			$image_url = esc_url_raw(
				$position
					['ImprintLocationTexts']
					['de']
					['Images']
					[0]
					['Url']
				?? ''
			);

			$image_text = (
				$code !== $label
			)
				? $code . ' - ' . $label
				: $code;

			$image_id = 0;

			if ( $image_url ) {

				$image_id = absint(
					apply_filters(
						'pdxw_print_position_image_id',
						0,
						$image_url,
						$image_text,
						$product_id
					)
				);
			}


			/*
			|--------------------------------------------------------------------------
			| Create Position
			|--------------------------------------------------------------------------
			*/

			$position_id =
				$this->repository
					->insert_position(
						$product_id,
						[
							'variation_id' =>
								$variation_id,

							'position_code' =>
								$code,

							'position_label' =>
								$label,

							'area' =>
								$area,

							'image' =>
								$image_id,
						]
					);

			if ( ! $position_id ) {
				continue;
			}


			/*
			|--------------------------------------------------------------------------
			| Position -> Print Option Relations
			|--------------------------------------------------------------------------
			*/

			$refs =
				$position[
					'ImprintOptionsReferences'
				] ?? [];

			if ( is_array( $refs ) ) {

				foreach (
					$refs as $hash => $reference_data
				) {

					$sku = $this->reference_sku(
						$references,
						(string) $hash
					);

					if ( '' === $sku ) {
						continue;
					}

					$option_id =
						$option_ids_by_sku[
							$sku
						] ?? 0;

					if ( ! $option_id ) {

						/**
						 * A relation referencing an unknown option is
						 * intentionally ignored.
						 *
						 * This matches the existing importer.
						 */
						continue;
					}

					$relation_rows[] = [
						'product_id' =>
							$product_id,

						'variation_id' =>
							$variation_id,

						'print_position_id' =>
							$position_id,

						'print_option_id' =>
							(int) $option_id,
					];
				}
			}

			$processed_codes[ $code ] = true;
		}


		/*
		|--------------------------------------------------------------------------
		| Bulk Relation Insert
		|--------------------------------------------------------------------------
		*/

		if ( $relation_rows ) {

			$this->repository
				->bulk_assign_options_to_positions(
					$relation_rows
				);
		}


		do_action(
			'pdxw_print_positions_synced',
			$product_id,
			$variation_id,
			array_keys(
				$processed_codes
			)
		);
	}


	/**
	 * Delete one print position.
	 */
	public function delete(
		int $position_id
	): bool {

		return $this->repository
			->delete_position(
				$position_id
			);
	}


	/**
	 * Delete every print position belonging to a product.
	 */
	public function delete_by_product(
		int $product_id
	): int {

		return $this->repository
			->delete_positions_by_product(
				$product_id
			);
	}


	/**
	 * Delete every print position belonging to a variation.
	 */
	public function delete_by_variation(
		int $variation_id
	): int {

		return $this->repository
			->delete_positions_by_variation(
				$variation_id
			);
	}


	/**
	 * Delete positions for one exact product + variation pair.
	 */
	public function delete_by_product_variation(
		int $product_id,
		int $variation_id
	): int {

		return $this->repository
			->delete_positions_by_product_variation(
				$product_id,
				$variation_id
			);
	}


	/**
	 * Get positions for an exact product/variation combination.
	 */
	public function get(
		int $product_id,
		int $variation_id = 0
	): array {

		return $this->repository
			->get_positions(
				$product_id,
				$variation_id
			);
	}


	/**
	 * Get all positions belonging to a product.
	 */
	public function get_by_product(
		int $product_id
	): array {

		return $this->repository
			->get_positions_by_product(
				$product_id
			);
	}


	/**
	 * Return unique print positions across all variations.
	 *
	 * This is used by the frontend configurator when building the
	 * overall list of available positions for a variable product.
	 */
	public function get_unique(
		int $product_id
	): array {

		return $this->repository
			->get_unique_positions(
				$product_id
			);
	}


	/**
	 * Get one position.
	 */
	public function find(
		int $position_id
	): ?object {

		return $this->repository
			->get_position(
				$position_id
			);
	}


	/**
	 * Get print options attached to one position.
	 */
	public function options(
		int $product_id,
		int $variation_id,
		int $position_id
	): array {

		return $this->repository
			->get_options_by_position(
				$product_id,
				$variation_id,
				$position_id
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Promi Mapping Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Collect all option SKUs referenced by the supplied positions.
	 */
	private function collect_option_skus(
		array $references,
		array $positions
	): array {

		$skus = [];

		foreach ( $positions as $position ) {

			if ( ! is_array( $position ) ) {
				continue;
			}

			$refs =
				$position[
					'ImprintOptionsReferences'
				] ?? [];

			if ( ! is_array( $refs ) ) {
				continue;
			}

			foreach (
				$refs as $hash => $reference_data
			) {

				$sku = $this->reference_sku(
					$references,
					(string) $hash
				);

				if ( $sku ) {
					$skus[] = $sku;
				}
			}
		}

		return array_values(
			array_unique(
				$skus
			)
		);
	}


	/**
	 * Convert a Promi imprint-reference hash into its print-option SKU.
	 */
	private function reference_sku(
		array $references,
		string $hash
	): string {

		if (
			! isset(
				$references[ $hash ]
			)
			|| ! is_array(
				$references[ $hash ]
			)
		) {
			return '';
		}

		return sanitize_text_field(
			$references[
				$hash
			]['Sku']
			?? ''
		);
	}


	/**
	 * Resolve the printable area using the same priority as the
	 * existing Promi importer.
	 *
	 * Priority:
	 *
	 * 1. PositionInformation.MaxWidth × MaxHeigth/MaxHeight
	 * 2. UnstructuredInformation.MaxPrintArea
	 * 3. Extract dimensions from the German position label
	 */
	private function extract_area(
		array $position,
		string $label
	): string {

		$area = '';


		/*
		|--------------------------------------------------------------------------
		| Structured Position Information
		|--------------------------------------------------------------------------
		*/

		if (
			! empty(
				$position[
					'PositionInformation'
				]
			)
			&& is_array(
				$position[
					'PositionInformation'
				]
			)
		) {

			$information =
				$position[
					'PositionInformation'
				];

			$width =
				$information[
					'MaxWidth'
				] ?? '';

			/**
			 * Promi contains the misspelled "MaxHeigth" in existing data.
			 *
			 * Keep supporting both spellings.
			 */
			$height =
				$information[
					'MaxHeigth'
				]
				?? $information[
					'MaxHeight'
				]
				?? '';

			if (
				'' !== (string) $width
				&& '' !== (string) $height
			) {
				$area = trim(
					(string) $width
					. ' x '
					. (string) $height
				);
			}

		} else {

			/*
			|--------------------------------------------------------------------------
			| Unstructured Information
			|--------------------------------------------------------------------------
			*/

			$area = (string) (
				$position[
					'UnstructuredInformation'
				]['MaxPrintArea']
				?? ''
			);
		}


		/*
		|--------------------------------------------------------------------------
		| Label Fallback
		|--------------------------------------------------------------------------
		|
		| Existing importer extracts strings such as:
		|
		| 100 x 50 mm
		| 10x5cm
		| 100 × 40
		*/

		if (
			'' === trim( $area )
			&& preg_match(
				'/(\d+(?:[.,]\d+)?\s*(?:mm|cm)?\s*[x×]\s*\d+(?:[.,]\d+)?\s*(?:mm|cm)?)/i',
				$label,
				$matches
			)
		) {
			$area = $matches[1];
		}

		return sanitize_text_field(
			trim( $area )
		);
	}
}