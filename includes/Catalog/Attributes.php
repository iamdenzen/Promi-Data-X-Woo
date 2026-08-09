<?php

namespace PromiDataXWoo\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce global attribute service.
 *
 * Responsibilities:
 *
 * - Create global WooCommerce attributes.
 * - Register newly-created attribute taxonomies during the current request.
 * - Create/retrieve attribute terms.
 * - Build WC_Product_Attribute objects.
 * - Normalize Promi color values.
 * - Maintain XSImpress color hex metadata.
 *
 * Promi-specific traversal remains inside ProductSync.
 */
final class Attributes {

	private bool $initialized = false;

	/**
	 * Attributes created/resolved during this request.
	 *
	 * [
	 *     'farbe' => [
	 *         'id'       => 12,
	 *         'taxonomy' => 'pa_farbe',
	 *     ],
	 * ]
	 *
	 * @var array<string,array{id:int,taxonomy:string}>
	 */
	private array $attribute_cache = [];


	/**
	 * Initialize attribute functionality.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		do_action(
			'pdxw_catalog_attributes_init',
			$this
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Product Attributes
	|--------------------------------------------------------------------------
	*/

	/**
	 * Prepare one global WooCommerce product attribute.
	 *
	 * The returned object can be passed directly to:
	 *
	 *     $product->set_attributes()
	 *
	 * Every supplied value is created as a global taxonomy term when needed.
	 *
	 * Existing XSImpress behavior:
	 *
	 * - attribute is visible
	 * - attribute is used for variations
	 * - type is select
	 * - ordering is menu_order
	 * - archives are disabled
	 */
	public function prepare_product_attribute(
		int $product_id,
		string $label,
		array $values
	): ?\WC_Product_Attribute {

		$product_id = absint(
			$product_id
		);

		$label = sanitize_text_field(
			$label
		);

		if (
			! $product_id
			|| '' === $label
		) {
			return null;
		}

		$values = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( mixed $value ): string {

							return trim(
								sanitize_text_field(
									(string) $value
								)
							);
						},
						$values
					),
					static fn( string $value ): bool =>
						'' !== $value
				)
			)
		);

		if ( empty( $values ) ) {
			return null;
		}


		/*
		|--------------------------------------------------------------------------
		| Global Attribute
		|--------------------------------------------------------------------------
		*/

		$attribute =
			$this->ensure_attribute(
				$label
			);

		if ( ! $attribute ) {
			return null;
		}

		$taxonomy =
			$attribute['taxonomy'];

		$term_ids = [];


		/*
		|--------------------------------------------------------------------------
		| Terms
		|--------------------------------------------------------------------------
		*/

		foreach ( $values as $value ) {

			$term =
				$this->term(
					$label,
					$value,
					true
				);

			if ( ! $term ) {
				continue;
			}

			$term_ids[] =
				(int) $term->term_id;
		}

		$term_ids = array_values(
			array_unique(
				array_filter(
					$term_ids
				)
			)
		);

		if ( empty( $term_ids ) ) {
			return null;
		}


		/*
		|--------------------------------------------------------------------------
		| Product Terms
		|--------------------------------------------------------------------------
		|
		| The old importer used wp_set_object_terms() with the term names.
		| We resolve the terms first and assign their IDs here, which preserves
		| the same data model while avoiding another round of term resolution.
		*/

		$result = wp_set_object_terms(
			$product_id,
			$term_ids,
			$taxonomy,
			false
		);

		if ( is_wp_error( $result ) ) {
			return null;
		}


		/*
		|--------------------------------------------------------------------------
		| WC_Product_Attribute
		|--------------------------------------------------------------------------
		*/

		$product_attribute =
			new \WC_Product_Attribute();

		$product_attribute->set_id(
			$attribute['id']
		);

		$product_attribute->set_name(
			$taxonomy
		);

		$product_attribute->set_options(
			$term_ids
		);

		$product_attribute->set_position(
			0
		);

		$product_attribute->set_visible(
			true
		);

		$product_attribute->set_variation(
			true
		);

		return $product_attribute;
	}


	/*
	|--------------------------------------------------------------------------
	| Attribute Taxonomies
	|--------------------------------------------------------------------------
	*/

	/**
	 * Ensure a global WooCommerce attribute exists.
	 *
	 * Returns:
	 *
	 * [
	 *     'id'       => 12,
	 *     'slug'     => 'farbe',
	 *     'taxonomy' => 'pa_farbe',
	 * ]
	 */
	public function ensure_attribute(
		string $label
	): ?array {

		$label = sanitize_text_field(
			$label
		);

		if ( '' === $label ) {
			return null;
		}

		$slug = wc_sanitize_taxonomy_name(
			$label
		);

		if ( '' === $slug ) {
			return null;
		}

		if (
			isset(
				$this->attribute_cache[
					$slug
				]
			)
		) {
			return $this->attribute_cache[
				$slug
			];
		}

		$taxonomy =
			wc_attribute_taxonomy_name(
				$slug
			);


		/*
		|--------------------------------------------------------------------------
		| Existing Attribute
		|--------------------------------------------------------------------------
		*/

		$attribute_id =
			wc_attribute_taxonomy_id_by_name(
				$taxonomy
			);


		/*
		|--------------------------------------------------------------------------
		| Create Attribute
		|--------------------------------------------------------------------------
		|
		| This reproduces the old maybe_create_attribute():
		|
		| type         = select
		| order_by     = menu_order
		| has_archives = false
		*/

		if ( ! $attribute_id ) {

			$created =
				wc_create_attribute(
					[
						'name' =>
							$label,

						'slug' =>
							$slug,

						'type' =>
							'select',

						'order_by' =>
							'menu_order',

						'has_archives' =>
							false,
					]
				);

			if ( is_wp_error( $created ) ) {
				return null;
			}

			$attribute_id =
				absint(
					$created
				);
		}

		if ( ! $attribute_id ) {
			return null;
		}


		/*
		|--------------------------------------------------------------------------
		| Current-request Taxonomy Registration
		|--------------------------------------------------------------------------
		|
		| WooCommerce normally registers attribute taxonomies during init.
		|
		| When an attribute is first created during a Promi import, however,
		| its taxonomy did not exist when WooCommerce performed that earlier
		| registration pass.
		|
		| The existing importer explicitly registered it immediately as well.
		*/

		if (
			! taxonomy_exists(
				$taxonomy
			)
		) {

			register_taxonomy(
				$taxonomy,
				[
					'product',
				],
				[
					'hierarchical' =>
						false,

					'show_ui' =>
						false,

					'query_var' =>
						true,

					'rewrite' =>
						false,

					'public' =>
						false,

					'show_admin_column' =>
						false,
				]
			);
		}


		$this->attribute_cache[
			$slug
		] = [
			'id' =>
				$attribute_id,

			'slug' =>
				$slug,

			'taxonomy' =>
				$taxonomy,
		];

		return $this->attribute_cache[
			$slug
		];
	}


	/**
	 * Return the taxonomy name for an attribute label.
	 *
	 * Example:
	 *
	 *     Farbe → pa_farbe
	 */
	public function taxonomy(
		string $label
	): string {

		$slug =
			wc_sanitize_taxonomy_name(
				$label
			);

		if ( '' === $slug ) {
			return '';
		}

		return wc_attribute_taxonomy_name(
			$slug
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Terms
	|--------------------------------------------------------------------------
	*/

	/**
	 * Retrieve an attribute term.
	 *
	 * When $create is true:
	 *
	 * - the global attribute is created if necessary
	 * - the taxonomy is registered if necessary
	 * - the term is created if necessary
	 *
	 * This is the method ProductSync uses when creating variation attributes.
	 */
	public function term(
		string $label,
		string $value,
		bool $create = false
	): ?\WP_Term {

		$label = sanitize_text_field(
			$label
		);

		$value = trim(
			sanitize_text_field(
				$value
			)
		);

		if (
			'' === $label
			|| '' === $value
		) {
			return null;
		}


		/*
		|--------------------------------------------------------------------------
		| Attribute
		|--------------------------------------------------------------------------
		*/

		if ( $create ) {

			$attribute =
				$this->ensure_attribute(
					$label
				);

			if ( ! $attribute ) {
				return null;
			}

			$taxonomy =
				$attribute['taxonomy'];

		} else {

			$taxonomy =
				$this->taxonomy(
					$label
				);

			if (
				'' === $taxonomy
				|| ! taxonomy_exists(
					$taxonomy
				)
			) {
				return null;
			}
		}


		/*
		|--------------------------------------------------------------------------
		| Existing Term
		|--------------------------------------------------------------------------
		*/

		$term =
			get_term_by(
				'name',
				$value,
				$taxonomy
			);

		if (
			$term instanceof \WP_Term
		) {

			$this->sync_term_metadata(
				$taxonomy,
				$term,
				$value
			);

			return $term;
		}


		/*
		 * A historical term may have the expected slug while its displayed
		 * name differs slightly.
		 */
		$term =
			get_term_by(
				'slug',
				sanitize_title(
					$value
				),
				$taxonomy
			);

		if (
			$term instanceof \WP_Term
		) {

			$this->sync_term_metadata(
				$taxonomy,
				$term,
				$value
			);

			return $term;
		}


		if ( ! $create ) {
			return null;
		}


		/*
		|--------------------------------------------------------------------------
		| Create Term
		|--------------------------------------------------------------------------
		*/

		$created =
			wp_insert_term(
				$value,
				$taxonomy
			);

		if ( is_wp_error( $created ) ) {

			/*
			 * wp_insert_term() can report term_exists when another process
			 * created the same term concurrently.
			 */
			$existing_id =
				absint(
					$created->get_error_data(
						'term_exists'
					)
				);

			if ( $existing_id ) {

				$term =
					get_term(
						$existing_id,
						$taxonomy
					);

				if (
					$term instanceof \WP_Term
				) {

					$this->sync_term_metadata(
						$taxonomy,
						$term,
						$value
					);

					return $term;
				}
			}

			return null;
		}

		$term =
			get_term(
				absint(
					$created[
						'term_id'
					]
				),
				$taxonomy
			);

		if (
			! $term instanceof \WP_Term
		) {
			return null;
		}

		$this->sync_term_metadata(
			$taxonomy,
			$term,
			$value
		);

		return $term;
	}


	/**
	 * Keep business metadata attached to special attribute terms.
	 */
	private function sync_term_metadata(
		string $taxonomy,
		\WP_Term $term,
		string $value
	): void {

		/*
		 * Existing XSImpress behavior is specifically tied to the translated
		 * German WooCommerce attribute:
		 *
		 *     pa_farbe
		 *
		 * Do not apply color metadata to every arbitrary attribute whose
		 * label happens to contain the word "color".
		 */
		if (
			'pa_farbe'
			!== $taxonomy
		) {
			return;
		}

		$hex =
			$this->color_hex(
				$value
			);

		$current =
			get_term_meta(
				$term->term_id,
				'hex',
				true
			);

		if ( $current !== $hex ) {

			update_term_meta(
				$term->term_id,
				'hex',
				$hex
			);
		}
	}


	/*
	|--------------------------------------------------------------------------
	| Color Normalization
	|--------------------------------------------------------------------------
	*/

	/**
	 * Normalize Promi color names.
	 *
	 * This reproduces normalize_color_name() from the existing importer.
	 *
	 * Examples:
	 *
	 *     "Navy Blau (PMS 281)" → "Navy Blau"
	 *     "Rot, Weiß"            → "Rot"
	 *     "Blau/Weiß"            → "Blau"
	 *
	 * The first color is intentionally used for multi-color values because
	 * that is how the current XSImpress variation model behaves.
	 */
	public function normalize_color(
		string $input
	): string {

		$input = trim(
			$input
		);

		if ( '' === $input ) {
			return '';
		}


		/*
		 * Remove anything inside parentheses.
		 */
		$input = (string)
			preg_replace(
				'/\s*\(.*?\)/u',
				'',
				$input
			);


		$key = mb_strtolower(
			trim(
				$input
			)
		);


		if (
			str_contains(
				$key,
				','
			)
		) {

			$key = trim(
				explode(
					',',
					$key,
					2
				)[0]
			);
		}


		if (
			str_contains(
				$key,
				'/'
			)
		) {

			$key = trim(
				explode(
					'/',
					$key,
					2
				)[0]
			);
		}


		return mb_convert_case(
			$key,
			MB_CASE_TITLE,
			'UTF-8'
		);
	}


	/**
	 * Return the XSImpress swatch color for a normalized German color name.
	 *
	 * This is the existing canonical map from CX_Promi_Product_Sync.
	 */
	public function color_hex(
		string $color
	): ?string {

		$color = mb_strtolower(
			trim(
				$color
			)
		);

		if ( '' === $color ) {
			return null;
		}

		$map = [

			/*
			|--------------------------------------------------------------------------
			| Basic Colors
			|--------------------------------------------------------------------------
			*/

			'schwarz' =>
				'#000000',

			'weiß' =>
				'#FFFFFF',

			'navy blau' =>
				'#000080',

			'navy-blau' =>
				'#000080',

			'navyblau' =>
				'#000080',

			'navy' =>
				'#000080',

			'rot' =>
				'#FF0000',

			'grün' =>
				'#008000',

			'blau' =>
				'#0000FF',

			'gelb' =>
				'#FFFF00',

			'orange' =>
				'#FFA500',

			'lila' =>
				'#800080',

			'violett' =>
				'#8F00FF',

			'rosa' =>
				'#FFC0CB',

			'pink' =>
				'#FF69B4',

			'braun' =>
				'#8B4513',

			'grau' =>
				'#808080',

			'hellgrau' =>
				'#D3D3D3',

			'dunkelgrau' =>
				'#A9A9A9',

			'beige' =>
				'#F5F5DC',

			'türkis' =>
				'#40E0D0',

			'cyan' =>
				'#00FFFF',

			'magenta' =>
				'#FF00FF',

			'gold' =>
				'#FFD700',

			'silber' =>
				'#C0C0C0',


			/*
			|--------------------------------------------------------------------------
			| Extended / Ecommerce Colors
			|--------------------------------------------------------------------------
			*/

			'dunkelblau' =>
				'#00008B',

			'hellblau' =>
				'#ADD8E6',

			'marineblau' =>
				'#000080',

			'königsblau' =>
				'#4169E1',

			'petrol' =>
				'#005F6A',

			'mint' =>
				'#98FF98',

			'oliv' =>
				'#808000',

			'olivgrün' =>
				'#6B8E23',

			'khaki' =>
				'#F0E68C',

			'bordeaux' =>
				'#800020',

			'weinrot' =>
				'#722F37',

			'koralle' =>
				'#FF7F50',

			'apricot' =>
				'#FBCEB1',

			'creme' =>
				'#FFFDD0',

			'anthrazit' =>
				'#303030',

			'elfenbein' =>
				'#FFFFF0',

			'sand' =>
				'#C2B280',

			'terrakotta' =>
				'#E2725B',

			'lachs' =>
				'#FA8072',

			'flieder' =>
				'#C8A2C8',


			/*
			|--------------------------------------------------------------------------
			| Additional Existing Standard Colors
			|--------------------------------------------------------------------------
			*/

			'dunkelrot' =>
				'#8B0000',

			'feuerrot' =>
				'#B22222',

			'scharlachrot' =>
				'#DC143C',

			'indigo' =>
				'#4B0082',

			'mitternachtsblau' =>
				'#191970',

			'himmelblau' =>
				'#87CEEB',

			'stahlblau' =>
				'#4682B4',

			'waldgrün' =>
				'#228B22',

			'smaragdgrün' =>
				'#50C878',

			'limette' =>
				'#00FF00',

			'schokoladenbraun' =>
				'#D2691E',

			'peru' =>
				'#CD853F',

			'tomatenrot' =>
				'#FF6347',

			'orangerot' =>
				'#FF4500',

			'pflaume' =>
				'#DDA0DD',

			'lavendel' =>
				'#E6E6FA',

			'gainsboro' =>
				'#DCDCDC',

			'schiefergrau' =>
				'#708090',
		];


		return $map[
			$color
		] ?? null;
	}


	/*
	|--------------------------------------------------------------------------
	| Convenience
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return whether a global attribute currently exists.
	 */
	public function exists(
		string $label
	): bool {

		$taxonomy =
			$this->taxonomy(
				$label
			);

		if ( '' === $taxonomy ) {
			return false;
		}

		return (
			(bool)
				wc_attribute_taxonomy_id_by_name(
					$taxonomy
				)
			|| taxonomy_exists(
				$taxonomy
			)
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
