<?php

namespace PromiDataXWoo\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce product helpers.
 *
 * Responsibilities:
 *
 * - Product / variation lookup by SKU.
 * - Efficient bulk SKU lookup.
 * - Promi dimensions and weight mapping.
 * - Promi carton / packaging metadata.
 *
 * This service does not own:
 *
 * - Attributes.
 * - Brands.
 * - Categories.
 * - Pricing.
 * - Printing.
 * - Promi synchronization orchestration.
 */
final class Products {

	private bool $initialized = false;


	/**
	 * Initialize product functionality.
	 *
	 * Products currently has no global hooks to register, but keeping an
	 * init() method makes it consistent with the other Catalog services and
	 * gives product-domain hooks a clear home later.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;


		do_action(
			'pdxw_catalog_products_init',
			$this
		);
	}


	/*
	|--------------------------------------------------------------------------
	| SKU Lookup
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return a WooCommerce product or variation ID by SKU.
	 */
	public function id_by_sku(
		string $sku
	): int {

		$sku = trim(
			$sku
		);

		if ( '' === $sku ) {
			return 0;
		}

		return absint(
			wc_get_product_id_by_sku(
				$sku
			)
		);
	}


	/**
	 * Return a WooCommerce product by SKU.
	 */
	public function by_sku(
		string $sku
	): ?\WC_Product {

		$product_id =
			$this->id_by_sku(
				$sku
			);

		if ( ! $product_id ) {
			return null;
		}

		$product =
			wc_get_product(
				$product_id
			);

		return $product instanceof \WC_Product
			? $product
			: null;
	}


	/**
	 * Resolve many SKUs in one query.
	 *
	 * Return structure:
	 *
	 * [
	 *     'ABC-001' => 123,
	 *     'ABC-002' => 124,
	 * ]
	 *
	 * Both products and product variations are included.
	 *
	 * This replaces the bulk _sku query that previously lived directly in
	 * CX_Promi_Product_Sync.
	 */
	public function ids_by_skus(
		array $skus
	): array {

		global $wpdb;

		$skus = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( mixed $sku ): string {

							return trim(
								(string) $sku
							);
						},
						$skus
					),
					static fn( string $sku ): bool =>
						'' !== $sku
				)
			)
		);

		if ( empty( $skus ) ) {
			return [];
		}


		/*
		|--------------------------------------------------------------------------
		| Chunking
		|--------------------------------------------------------------------------
		|
		| Promi products can contain many child variations. Chunking keeps
		| unusually large SKU lists from producing excessively large SQL
		| statements.
		*/

		$map = [];

		foreach (
			array_chunk(
				$skus,
				500
			) as $chunk
		) {

			$placeholders =
				implode(
					',',
					array_fill(
						0,
						count( $chunk ),
						'%s'
					)
				);

			$sql =
				"SELECT
					pm.meta_value AS sku,
					pm.post_id AS product_id
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p
					ON p.ID = pm.post_id
				WHERE pm.meta_key = '_sku'
					AND pm.meta_value IN ({$placeholders})
					AND p.post_type IN (
						'product',
						'product_variation'
					)";

			$rows =
				$wpdb->get_results(
					$wpdb->prepare(
						$sql,
						...$chunk
					)
				);

			foreach ( $rows as $row ) {

				$sku =
					(string)
						$row->sku;

				$product_id =
					absint(
						$row->product_id
					);

				if (
					'' === $sku
					|| ! $product_id
				) {
					continue;
				}

				$map[
					$sku
				] = $product_id;
			}
		}

		return $map;
	}


	/*
	|--------------------------------------------------------------------------
	| Promi Physical Product Data
	|--------------------------------------------------------------------------
	*/

	/**
	 * Apply Promi dimensions, weight and carton metadata.
	 *
	 * $primary_data is normally the entity's own
	 * NonLanguageDependedProductDetails.
	 *
	 * $fallback_data is used for parent products where the existing importer
	 * falls back to the first child when parent-level physical data is
	 * unavailable.
	 *
	 * Promi fields used:
	 *
	 * Product:
	 *
	 * - Weight
	 * - DimensionsLength
	 * - DimensionsWidth
	 * - DimensionsHeight
	 * - DimensionsDepth
	 * - DimensionsDiameter
	 *
	 * Carton:
	 *
	 * - OuterCartonLengthCM
	 * - OuterCartonWidthCM
	 * - OuterCartonHeightCM
	 * - OuterCartonWeightGrossKG
	 * - OuterCartonWeightNetKG
	 * - OuterCartonQuantityPerCarton
	 */
	public function apply_dimensions(
		\WC_Product $product,
		array $primary_data = [],
		array $fallback_data = []
	): void {

		/*
		|--------------------------------------------------------------------------
		| Product Weight
		|--------------------------------------------------------------------------
		|
		| Existing Promi data supplies product Weight in grams.
		|
		| WooCommerce's current XSImpress setup stores product weight in kg,
		| therefore preserve the existing /1000 conversion.
		*/

		$weight =
			$this->resolve_value(
				$primary_data[
					'Weight'
				] ?? null,
				$fallback_data[
					'Weight'
				] ?? null
			);

		if ( $weight > 0 ) {
			$weight /= 1000;
		}


		/*
		|--------------------------------------------------------------------------
		| Product Dimensions
		|--------------------------------------------------------------------------
		*/

		$length =
			$this->resolve_value(
				$primary_data[
					'DimensionsLength'
				] ?? null,
				$fallback_data[
					'DimensionsLength'
				] ?? null
			);

		$width =
			$this->resolve_value(
				$primary_data[
					'DimensionsWidth'
				] ?? null,
				$fallback_data[
					'DimensionsWidth'
				] ?? null
			);

		$height =
			$this->resolve_value(
				$primary_data[
					'DimensionsHeight'
				] ?? null,
				$fallback_data[
					'DimensionsHeight'
				] ?? null
			);

		$depth =
			$this->resolve_value(
				$primary_data[
					'DimensionsDepth'
				] ?? null,
				$fallback_data[
					'DimensionsDepth'
				] ?? null
			);

		$diameter =
			$this->resolve_value(
				$primary_data[
					'DimensionsDiameter'
				] ?? null,
				$fallback_data[
					'DimensionsDiameter'
				] ?? null
			);


		/*
		 * Existing XSImpress behavior:
		 *
		 * If no explicit height exists but Promi provides depth, depth
		 * becomes WooCommerce height.
		 */
		if (
			$height <= 0
			&& $depth > 0
		) {
			$height = $depth;
		}


		/*
		 * Circular products may provide diameter instead of normal length
		 * and width.
		 */
		if ( $diameter > 0 ) {

			if ( $length <= 0 ) {
				$length = $diameter;
			}

			if ( $width <= 0 ) {
				$width = $diameter;
			}
		}


		/*
		|--------------------------------------------------------------------------
		| WooCommerce Physical Fields
		|--------------------------------------------------------------------------
		|
		| Preserve the existing importer behavior of explicitly setting these
		| values even when they resolve to zero.
		*/

		$product->set_weight(
			$weight > 0
				? (string) $weight
				: ''
		);

		$product->set_length(
			$length > 0
				? (string) $length
				: ''
		);

		$product->set_width(
			$width > 0
				? (string) $width
				: ''
		);

		$product->set_height(
			$height > 0
				? (string) $height
				: ''
		);


		/*
		|--------------------------------------------------------------------------
		| Carton Data
		|--------------------------------------------------------------------------
		*/

		$carton_length =
			$this->resolve_value(
				$primary_data[
					'OuterCartonLengthCM'
				] ?? null,
				$fallback_data[
					'OuterCartonLengthCM'
				] ?? null
			);

		$carton_width =
			$this->resolve_value(
				$primary_data[
					'OuterCartonWidthCM'
				] ?? null,
				$fallback_data[
					'OuterCartonWidthCM'
				] ?? null
			);

		$carton_height =
			$this->resolve_value(
				$primary_data[
					'OuterCartonHeightCM'
				] ?? null,
				$fallback_data[
					'OuterCartonHeightCM'
				] ?? null
			);

		$carton_weight_gross =
			$this->resolve_value(
				$primary_data[
					'OuterCartonWeightGrossKG'
				] ?? null,
				$fallback_data[
					'OuterCartonWeightGrossKG'
				] ?? null
			);

		$carton_weight_net =
			$this->resolve_value(
				$primary_data[
					'OuterCartonWeightNetKG'
				] ?? null,
				$fallback_data[
					'OuterCartonWeightNetKG'
				] ?? null
			);

		$carton_quantity =
			$this->resolve_value(
				$primary_data[
					'OuterCartonQuantityPerCarton'
				] ?? null,
				$fallback_data[
					'OuterCartonQuantityPerCarton'
				] ?? null
			);


		/*
		|--------------------------------------------------------------------------
		| Carton Meta
		|--------------------------------------------------------------------------
		|
		| Keep the current XSImpress meta keys because they contain real
		| business data and may already be consumed by shipping/admin logic.
		*/

		$this->update_positive_meta(
			$product,
			'_outer_length',
			$carton_length
		);

		$this->update_positive_meta(
			$product,
			'_outer_width',
			$carton_width
		);

		$this->update_positive_meta(
			$product,
			'_outer_height',
			$carton_height
		);

		$this->update_positive_meta(
			$product,
			'_outer_weight_gross',
			$carton_weight_gross
		);

		$this->update_positive_meta(
			$product,
			'_outer_weight_net',
			$carton_weight_net
		);

		$this->update_positive_meta(
			$product,
			'_outer_qty',
			$carton_quantity
		);


		/*
		|--------------------------------------------------------------------------
		| Diameter
		|--------------------------------------------------------------------------
		*/

		$this->update_positive_meta(
			$product,
			'_diameter',
			$diameter
		);
	}


	/**
	 * Apply dimensions and immediately save the WooCommerce object.
	 *
	 * ProductSync normally calls apply_dimensions() before its own save(),
	 * but this is useful for admin/manual operations.
	 */
	public function apply_dimensions_and_save(
		\WC_Product $product,
		array $primary_data = [],
		array $fallback_data = []
	): int {

		$this->apply_dimensions(
			$product,
			$primary_data,
			$fallback_data
		);

		return absint(
			$product->save()
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Physical Data Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Resolve Promi's preferred/fallback numeric value.
	 *
	 * The primary value wins only when it is numeric and greater than zero.
	 * Otherwise the fallback value is considered.
	 */
	private function resolve_value(
		mixed $primary,
		mixed $fallback,
		float $default = 0.0
	): float {

		if (
			is_numeric(
				$primary
			)
			&& (float) $primary > 0
		) {
			return (float) $primary;
		}

		if (
			is_numeric(
				$fallback
			)
			&& (float) $fallback > 0
		) {
			return (float) $fallback;
		}

		return $default;
	}


	/**
	 * Update Promi physical metadata only when a positive value exists.
	 *
	 * This deliberately preserves the current importer behavior. Missing
	 * carton values do not delete previously stored carton metadata.
	 */
	private function update_positive_meta(
		\WC_Product $product,
		string $key,
		float $value
	): void {

		if ( $value <= 0 ) {
			return;
		}

		$product->update_meta_data(
			$key,
			$value
		);
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
