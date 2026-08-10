<?php

namespace PromiDataXWoo\Frontend;

use PromiDataXWoo\Catalog\Catalog;
use PromiDataXWoo\Pricing\Pricing;
use PromiDataXWoo\Printing\Printing;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;

defined( 'ABSPATH' ) || exit;

/**
 * Frontend-facing WooCommerce product data.
 *
 * Replaces the reusable data/helper responsibilities previously contained
 * inside CX_Product.
 *
 * Responsibilities:
 *
 * - Resolve WooCommerce variations.
 * - Return normalized variation data.
 * - Return product/variation tier prices.
 * - Return printing configuration.
 * - Return minimum order quantity / quantity increments.
 * - Return variation galleries.
 * - Order variations by attribute term menu order.
 * - Prepare variation attribute data for frontend rendering.
 *
 * This class returns data only.
 *
 * HTML belongs in Shortcodes.
 * AJAX belongs in Ajax.
 * Scripts belong in Assets.
 */
final class ProductData {

	private Catalog $catalog;

	private Pricing $pricing;

	private Printing $printing;


	public function __construct(
		Catalog $catalog,
		Pricing $pricing,
		Printing $printing
	) {
		$this->catalog  = $catalog;
		$this->pricing  = $pricing;
		$this->printing = $printing;
	}


	/*
	|--------------------------------------------------------------------------
	| Complete Frontend State
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return the same logical product state currently returned by cx_get_data.
	 *
	 * A variation may be resolved either:
	 *
	 * - directly using $variation_id
	 * - from submitted variation attributes
	 *
	 * Returned structure:
	 *
	 * [
	 *     'product_id'     => 123,
	 *     'variation_id'   => 456,
	 *     'attributes'     => [...],
	 *     'sku'            => 'ABC-RED',
	 *     'tiers'          => [...],
	 *     'positions'      => [...],
	 *     'min_order_qty'  => 10,
	 *     'qty_increments' => 5,
	 * ]
	 */
	public function state(
		int $product_id,
		int $variation_id = 0,
		array $attributes = []
	): array {

		$product_id =
			absint(
				$product_id
			);

		$variation_id =
			absint(
				$variation_id
			);

		if ( ! $product_id ) {
			return [];
		}

		$product =
			$this->catalog
				->product(
					$product_id
				);

		if ( ! $product ) {
			return [];
		}


		/*
		|--------------------------------------------------------------------------
		| Resolve Variation
		|--------------------------------------------------------------------------
		*/

		if (
			$product instanceof WC_Product_Variable
		) {

			$variation_id =
				$this->resolve_variation(
					$product,
					$variation_id,
					$attributes
				);

		} else {

			$variation_id = 0;
		}


		/*
		|--------------------------------------------------------------------------
		| Target
		|--------------------------------------------------------------------------
		*/

		$target =
			$variation_id
				? wc_get_product(
					$variation_id
				)
				: $product;

		if ( ! $target ) {
			$target = $product;
			$variation_id = 0;
		}


		/*
		|--------------------------------------------------------------------------
		| Final Attributes
		|--------------------------------------------------------------------------
		|
		| Existing cx_get_data always returns the attributes stored on the
		| resolved variation rather than echoing the submitted request.
		*/

		$final_attributes = [];

		if (
			$target instanceof WC_Product_Variation
		) {
			$final_attributes =
				$target->get_attributes();
		}


		return [
			'product_id' =>
				$product_id,

			'variation_id' =>
				$variation_id,

			'attributes' =>
				$final_attributes,

			'sku' =>
				(string)
					$target->get_sku(),

			'tiers' =>
				$this->tiers(
					$product_id,
					$variation_id
				),

			'positions' =>
				$this->printing_config(
					$product_id,
					$variation_id
				),

			'min_order_qty' =>
				$this->minimum_order_quantity(
					$target
				),

			'qty_increments' =>
				$this->quantity_increment(
					$target
				),
		];
	}


	/*
	|--------------------------------------------------------------------------
	| Variation Resolution
	|--------------------------------------------------------------------------
	*/

	/**
	 * Resolve a variation.
	 *
	 * Direct variation ID takes priority when it belongs to the supplied
	 * parent product.
	 *
	 * Otherwise submitted attributes are used.
	 */
	public function resolve_variation(
		WC_Product_Variable|int $product,
		int $variation_id = 0,
		array $attributes = []
	): int {

		if ( is_int( $product ) ) {

			$product =
				wc_get_product(
					$product
				);
		}

		if (
			! $product instanceof WC_Product_Variable
		) {
			return 0;
		}

		$product_id =
			$product->get_id();

		$variation_id =
			absint(
				$variation_id
			);


		/*
		|--------------------------------------------------------------------------
		| Explicit Variation
		|--------------------------------------------------------------------------
		*/

		if ( $variation_id ) {

			$variation =
				wc_get_product(
					$variation_id
				);

			if (
				$variation instanceof WC_Product_Variation
				&& $variation->get_parent_id()
					=== $product_id
			) {
				return $variation_id;
			}
		}


		/*
		|--------------------------------------------------------------------------
		| Attributes
		|--------------------------------------------------------------------------
		*/

		$attributes =
			$this->normalize_matching_attributes(
				$attributes
			);

		error_log(
			'resolve_variation: '
			. print_r(
				[
					'product_id' => $product_id,
					'variation_id' => $variation_id,
					'attributes' => $attributes,
				],
				true
			)
		);

		if ( empty( $attributes ) ) {
			return 0;
		}

		$data_store =
			$product->get_data_store();

		if (
			method_exists(
				$data_store,
				'find_matching_product_variation'
			)
		) {

			return absint(
				$data_store
					->find_matching_product_variation(
						$product,
						$attributes
					)
			);
		}

		return 0;
	}


	/**
	 * Normalize frontend attribute input for WooCommerce variation matching.
	 *
	 * Accepted forms:
	 *
	 * [
	 *     'attribute_pa_farbe' => 'rot'
	 * ]
	 *
	 * [
	 *     'pa_farbe' => 'rot'
	 * ]
	 */
	private function normalize_matching_attributes(
		array $attributes
	): array {

		$normalized = [];

		foreach ( $attributes as $key => $value ) {

			$key = (string) $key;

			if ( str_starts_with( $key, 'attribute_' ) ) {
				$key = substr( $key, 10 );
			}

			$key = wc_variation_attribute_name( $key );
			$value = sanitize_title( (string) $value );

			if ( '' === $value ) {
				continue;
			}

			$normalized[ $key ] = $value;
		}

		return $normalized;
	}


	/*
	|--------------------------------------------------------------------------
	| Variation Access
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return product variation IDs ordered by attribute term menu_order.
	 *
	 * The old CX_Product helper sorted variations using pa_color/pa_size.
	 *
	 * Promi currently creates German attributes such as pa_farbe and
	 * pa_groesse, so those are checked first while retaining the original
	 * attribute names as fallback.
	 */
	public function variation_ids(
		WC_Product_Variable|int $product,
		array $attribute_priority = [
			'pa_farbe',
			'pa_groesse',
			'pa_color',
			'pa_size',
		]
	): array {

		if ( is_int( $product ) ) {

			$product =
				wc_get_product(
					$product
				);
		}

		if (
			! $product instanceof WC_Product_Variable
		) {
			return [];
		}

		$variation_ids =
			array_map(
				'absint',
				$product->get_children()
			);

		if (
			empty( $variation_ids )
			|| empty( $attribute_priority )
		) {
			return $variation_ids;
		}


		/*
		|--------------------------------------------------------------------------
		| Attribute Term Order
		|--------------------------------------------------------------------------
		*/

		$term_orders = [];

		foreach (
			$attribute_priority as $taxonomy
		) {

			if (
				! taxonomy_exists(
					$taxonomy
				)
			) {
				$term_orders[
					$taxonomy
				] = [];

				continue;
			}

			$terms =
				get_terms(
					[
						'taxonomy' =>
							$taxonomy,

						'hide_empty' =>
							false,

						'orderby' =>
							'menu_order',

						'order' =>
							'ASC',
					]
				);

			if ( is_wp_error( $terms ) ) {

				$term_orders[
					$taxonomy
				] = [];

				continue;
			}

			$term_orders[
				$taxonomy
			] = array_values(
				wp_list_pluck(
					$terms,
					'slug'
				)
			);
		}


		/*
		|--------------------------------------------------------------------------
		| Variation Attribute Map
		|--------------------------------------------------------------------------
		*/

		$variation_attributes = [];

		foreach (
			$variation_ids as $variation_id
		) {

			$variation =
				wc_get_product(
					$variation_id
				);

			if (
				! $variation instanceof WC_Product_Variation
			) {
				continue;
			}

			$attributes =
				$variation->get_attributes();

			foreach (
				$attribute_priority as $taxonomy
			) {

				$variation_attributes[
					$variation_id
				][
					$taxonomy
				] =
					(string) (
						$attributes[
							$taxonomy
						] ?? ''
					);
			}
		}


		/*
		|--------------------------------------------------------------------------
		| Sort
		|--------------------------------------------------------------------------
		*/

		usort(
			$variation_ids,
			static function (
				int $a,
				int $b
			) use (
				$attribute_priority,
				$variation_attributes,
				$term_orders
			): int {

				foreach (
					$attribute_priority as $taxonomy
				) {

					$order =
						$term_orders[
							$taxonomy
						] ?? [];

					if ( empty( $order ) ) {
						continue;
					}

					$value_a =
						$variation_attributes[
							$a
						][
							$taxonomy
						] ?? '';

					$value_b =
						$variation_attributes[
							$b
						][
							$taxonomy
						] ?? '';

					$position_a =
						array_search(
							$value_a,
							$order,
							true
						);

					$position_b =
						array_search(
							$value_b,
							$order,
							true
						);

					/*
					 * Unknown values should sort after known taxonomy terms.
					 */
					$position_a =
						false === $position_a
							? PHP_INT_MAX
							: $position_a;

					$position_b =
						false === $position_b
							? PHP_INT_MAX
							: $position_b;

					if (
						$position_a
						!== $position_b
					) {
						return $position_a
							<=> $position_b;
					}
				}

				return $a <=> $b;
			}
		);

		return $variation_ids;
	}


	/**
	 * Return ordered WooCommerce variation objects.
	 */
	public function variations(
		WC_Product_Variable|int $product,
		array $attribute_priority = [
			'pa_farbe',
			'pa_groesse',
			'pa_color',
			'pa_size',
		]
	): array {

		$variations = [];

		foreach (
			$this->variation_ids(
				$product,
				$attribute_priority
			) as $variation_id
		) {

			$variation =
				wc_get_product(
					$variation_id
				);

			if (
				$variation instanceof WC_Product_Variation
			) {
				$variations[] =
					$variation;
			}
		}

		return $variations;
	}


	/*
	|--------------------------------------------------------------------------
	| Variation Attributes
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return frontend-ready variation attributes.
	 *
	 * This provides the data Shortcodes will later use for dropdowns/radios.
	 */
	public function variation_attributes(
		int $product_id
	): array {

		$product =
			wc_get_product(
				absint(
					$product_id
				)
			);

		if (
			! $product instanceof WC_Product_Variable
		) {
			return [];
		}

		$attributes =
			$product
				->get_variation_attributes();

		$available_variations =
			$product
				->get_available_variations();

		$image_map = [];


		/*
		|--------------------------------------------------------------------------
		| Representative Variation Images
		|--------------------------------------------------------------------------
		|
		| Existing CX Product uses the first variation image found for each
		| attribute option.
		*/

		foreach (
			$available_variations as $variation
		) {

			if (
				empty(
					$variation[
						'attributes'
					]
				)
			) {
				continue;
			}

			foreach (
				$variation[
					'attributes'
				] as $attribute => $value
			) {

				if (
					'' === $value
					|| isset(
						$image_map[
							$attribute
						][
							$value
						]
					)
				) {
					continue;
				}

				$image_map[
					$attribute
				][
					$value
				] =
					absint(
						$variation[
							'image_id'
						] ?? 0
					);
			}
		}


		$result = [];

		foreach (
			$attributes as $taxonomy => $options
		) {

			$ordered_options =
				$this->ordered_attribute_options(
					$taxonomy,
					$options
				);

			$prepared = [];

			foreach (
				$ordered_options as $slug
			) {

				$term =
					taxonomy_exists(
						$taxonomy
					)
						? get_term_by(
							'slug',
							$slug,
							$taxonomy
						)
						: null;

				$label =
					$term instanceof \WP_Term
						? $term->name
						: $slug;

				if (
					str_contains(
						$taxonomy,
						'color'
					)
					|| str_contains(
						$taxonomy,
						'farbe'
					)
				) {

					$label =
						$this->normalize_color_label(
							$label
						)
						?? $label;
				}

				$image_id =
					absint(
						$image_map[
							'attribute_'
							. sanitize_title(
								$taxonomy
							)
						][
							$slug
						]
						?? 0
					);

				$prepared[] = [
					'value' =>
						$slug,

					'label' =>
						$label,

					'term_id' =>
						$term instanceof \WP_Term
							? (int) $term->term_id
							: 0,

					'hex' =>
						$term instanceof \WP_Term
							? (
								(string)
									get_term_meta(
										$term->term_id,
										'hex',
										true
									)
								?: '#000000'
							)
							: '#000000',

					'image_id' =>
						$image_id,

					'image' =>
						$image_id
							? (
								wp_get_attachment_image_url(
									$image_id,
									'thumbnail'
								)
								?: null
							)
							: null,
				];
			}

			$result[
				$taxonomy
			] = [
				'name' =>
					$taxonomy,

				'label' =>
					wc_attribute_label(
						$taxonomy,
						$product
					),

				'options' =>
					$prepared,
			];
		}

		return $result;
	}


	/**
	 * Preserve WooCommerce attribute term menu order.
	 */
	private function ordered_attribute_options(
		string $taxonomy,
		array $options
	): array {

		if (
			! taxonomy_exists(
				$taxonomy
			)
		) {
			return array_values(
				$options
			);
		}

		$terms =
			get_terms(
				[
					'taxonomy' =>
						$taxonomy,

					'hide_empty' =>
						false,

					'orderby' =>
						'menu_order',

					'order' =>
						'ASC',
				]
			);

		if ( is_wp_error( $terms ) ) {
			return array_values(
				$options
			);
		}

		$ordered = [];

		foreach ( $terms as $term ) {

			if (
				in_array(
					$term->slug,
					$options,
					true
				)
			) {
				$ordered[] =
					$term->slug;
			}
		}

		return $ordered;
	}


	/*
	|--------------------------------------------------------------------------
	| Pricing
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return applicable selling price.
	 */
	public function price(
		int $product_id,
		int $variation_id = 0,
		int $quantity = 1
	): ?float {

		return $this->pricing
			->selling_price(
				$product_id,
				$variation_id,
				max(
					1,
					$quantity
				)
			);
	}


	/**
	 * Return selling tiers.
	 *
	 * Keep qty/price keys because the existing frontend pricing JS accepts
	 * this structure directly.
	 */
	public function tiers(
		int $product_id,
		int $variation_id = 0
	): array {

		return $this->pricing
			->selling_tiers(
				$product_id,
				$variation_id
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Printing
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return printing configuration using the structure expected by the
	 * existing XSImpress frontend:
	 *
	 * [
	 *     position_id => [
	 *         'label'   => ...,
	 *         'code'    => ...,
	 *         'area'    => ...,
	 *         'image'   => ...,
	 *         'options' => [
	 *             option_id => [...]
	 *         ]
	 *     ]
	 * ]
	 *
	 * Printing\Configurator internally uses a cleaner indexed structure, so
	 * this method acts as the frontend adapter.
	 */
	public function printing_config(
		int $product_id,
		int $variation_id = 0
	): array {

		$config =
			$this->printing
				->configurator()
				->get_config(
					$product_id,
					$variation_id
				);

		if ( empty( $config ) ) {
			return [];
		}

		$result = [];

		foreach ( $config as $position ) {

			$position_id =
				absint(
					$position[
						'id'
					] ?? 0
				);

			if ( ! $position_id ) {
				continue;
			}

			$options = [];

			foreach (
				$position[
					'options'
				] ?? [] as $option
			) {

				$option_id =
					absint(
						$option[
							'id'
						] ?? 0
					);

				if ( ! $option_id ) {
					continue;
				}

				$options[
					$option_id
				] = [
					'name' =>
						(string) (
							$option[
								'name'
							] ?? ''
						),

					'sku' =>
						(string) (
							$option[
								'sku'
							] ?? ''
						),

					'min_order_qty' =>
						max(
							1,
							absint(
								$option[
									'min_order_qty'
								] ?? 1
							)
						),

					'max_colors' =>
						absint(
							$option[
								'max_colors'
							] ?? 0
						),

					'prices' =>
						$option[
							'prices'
						] ?? [],

					'fees' =>
						$option[
							'fees'
						] ?? [],
				];
			}

			$result[
				$position_id
			] = [
				'label' =>
					(string) (
						$position[
							'label'
						] ?? ''
					),

				'code' =>
					(string) (
						$position[
							'code'
						] ?? ''
					),

				'area' =>
					(string) (
						$position[
							'area'
						] ?? ''
					),

				'image' =>
					$position[
						'image'
					] ?? null,

				'options' =>
					$options,
			];
		}

		return $result;
	}


	/*
	|--------------------------------------------------------------------------
	| Quantity Rules
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return minimum order quantity.
	 */
	public function minimum_order_quantity(
		WC_Product|int $product
	): int {

		if ( is_int( $product ) ) {

			$product =
				wc_get_product(
					$product
				);
		}

		if ( ! $product ) {
			return 1;
		}

		return max(
			1,
			absint(
				$product->get_meta(
					'min_order_qty',
					true
				)
			)
		);
	}


	/**
	 * Return quantity increment.
	 */
	public function quantity_increment(
		WC_Product|int $product
	): int {

		if ( is_int( $product ) ) {

			$product =
				wc_get_product(
					$product
				);
		}

		if ( ! $product ) {
			return 1;
		}

		return max(
			1,
			absint(
				$product->get_meta(
					'qty_increments',
					true
				)
			)
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Galleries
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return variation gallery URLs.
	 *
	 * Existing cx-product reads _product_image_gallery directly from the
	 * variation and returns full attachment URLs.
	 */
	public function variation_gallery_urls(
		int $variation_id
	): array {

		$variation_id =
			absint(
				$variation_id
			);

		if ( ! $variation_id ) {
			return [];
		}

		$gallery =
			(string)
				get_post_meta(
					$variation_id,
					'_product_image_gallery',
					true
				);

		if ( '' === $gallery ) {
			return [];
		}

		$image_ids =
			array_filter(
				array_map(
					'absint',
					explode(
						',',
						$gallery
					)
				)
			);

		$urls = [];

		foreach ( $image_ids as $image_id ) {

			$url =
				wp_get_attachment_url(
					$image_id
				);

			if ( $url ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}


	/**
	 * Return lightweight variation data used when localizing frontend JS.
	 *
	 * The existing frontend only preloads galleries here. Tier prices,
	 * printing options and quantity rules are requested when a variation is
	 * actually selected.
	 */
	public function localized_variations(
		int $product_id
	): array {

		$product =
			wc_get_product(
				absint(
					$product_id
				)
			);

		if (
			! $product instanceof WC_Product_Variable
		) {
			return [];
		}

		$result = [];

		foreach (
			$this->variation_ids(
				$product
			) as $variation_id
		) {

			$result[
				$variation_id
			] = [
				'gallery' =>
					$this->variation_gallery_urls(
						$variation_id
					),
			];
		}

		return $result;
	}


	/*
	|--------------------------------------------------------------------------
	| Color Labels
	|--------------------------------------------------------------------------
	*/

	/**
	 * Preserve the existing CX Product English -> German frontend label map.
	 *
	 * This is presentation normalization only.
	 *
	 * Promi import color normalization remains in Catalog\Attributes.
	 */
	public function normalize_color_label(
		string $color
	): ?string {

		$key =
			strtolower(
				trim(
					$color
				)
			);

		if ( '' === $key ) {
			return null;
		}

		$colors = [
			'black' =>
				'Schwarz',

			'white' =>
				'Weiß',

			'red' =>
				'Rot',

			'green' =>
				'Grün',

			'blue' =>
				'Blau',

			'yellow' =>
				'Gelb',

			'orange' =>
				'Orange',

			'purple' =>
				'Lila',

			'pink' =>
				'Rosa',

			'brown' =>
				'Braun',

			'gray' =>
				'Grau',

			'grey' =>
				'Grau',

			'silver' =>
				'Silber',

			'gold' =>
				'Gold',

			'beige' =>
				'Beige',

			'cyan' =>
				'Cyan',

			'magenta' =>
				'Magenta',

			'violet' =>
				'Violett',

			'indigo' =>
				'Indigo',

			'turquoise' =>
				'Türkis',

			'navy' =>
				'Marineblau',

			'teal' =>
				'Blaugrün',

			'maroon' =>
				'Kastanienbraun',

			'olive' =>
				'Olivgrün',

			'lime' =>
				'Limettengrün',

			'coral' =>
				'Koralle',

			'salmon' =>
				'Lachs',

			'chocolate' =>
				'Schokolade',

			'crimson' =>
				'Karmesinrot',

			'tan' =>
				'Hellbraun',

			'khaki' =>
				'Khaki',

			'lavender' =>
				'Lavendel',

			'azure' =>
				'Azurblau',

			'ivory' =>
				'Elfenbein',

			'mint' =>
				'Minze',

			'peach' =>
				'Pfirsich',

			'plum' =>
				'Pflaume',

			'sapphire' =>
				'Saphirblau',

			'amber' =>
				'Bernstein',

			'mustard' =>
				'Senfgelb',

			'burgundy' =>
				'Burgunderrot',

			'charcoal' =>
				'Kohle',

			'rose' =>
				'Rosé',
		];

		return $colors[
			$key
		] ?? null;
	}
}