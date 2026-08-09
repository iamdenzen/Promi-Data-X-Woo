<?php

namespace PromiDataXWoo\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce product-category service.
 *
 * XSImpress category structure:
 *
 * Promi category CSV
 *        ↓
 * German DE hierarchy
 *        ↓
 * WooCommerce product_cat
 *        ↓
 * Final category stores:
 *
 * - cx_category_key
 * - cx_category_icon
 * - thumbnail_id
 *
 * Promi products reference categories using cx_category_key rather than
 * category names.
 */
final class Categories {

	public const TAXONOMY = 'product_cat';

	public const KEY_META = 'cx_category_key';

	public const ICON_META = 'cx_category_icon';

	private bool $initialized = false;


	/**
	 * Initialize category functionality.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		do_action(
			'pdxw_catalog_categories_init',
			$this
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Product Assignment
	|--------------------------------------------------------------------------
	*/

	/**
	 * Assign a WooCommerce product category using a Promi category key.
	 *
	 * Existing XSImpress behavior:
	 *
	 * - Category is looked up by cx_category_key.
	 * - Exactly one category is assigned.
	 * - Category names from product JSON are not used for matching.
	 */
	public function assign_by_promi_key(
		int $product_id,
		string $key
	): ?\WP_Term {

		$product_id =
			absint(
				$product_id
			);

		$key =
			$this->normalize_key(
				$key
			);

		if (
			! $product_id
			|| '' === $key
		) {
			return null;
		}

		$category =
			$this->find_by_promi_key(
				$key
			);

		if ( ! $category ) {
			return null;
		}

		$result =
			wp_set_object_terms(
				$product_id,
				[
					$category->term_id,
				],
				self::TAXONOMY,
				false
			);

		if ( is_wp_error( $result ) ) {
			return null;
		}

		do_action(
			'pdxw_product_category_assigned',
			$product_id,
			$category,
			$key
		);

		return $category;
	}


	/**
	 * Remove all WooCommerce categories from a product.
	 */
	public function remove(
		int $product_id
	): bool {

		$product_id =
			absint(
				$product_id
			);

		if ( ! $product_id ) {
			return false;
		}

		$result =
			wp_set_object_terms(
				$product_id,
				[],
				self::TAXONOMY,
				false
			);

		return ! is_wp_error(
			$result
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Promi Category Lookup
	|--------------------------------------------------------------------------
	*/

	/**
	 * Find a WooCommerce category by Promi category key.
	 *
	 * This replaces:
	 *
	 *     CX_Promi_Product_Sync::get_category_by_key()
	 */
	public function find_by_promi_key(
		string $key
	): ?\WP_Term {

		$key =
			$this->normalize_key(
				$key
			);

		if ( '' === $key ) {
			return null;
		}

		$terms =
			get_terms(
				[
					'taxonomy' =>
						self::TAXONOMY,

					'hide_empty' =>
						false,

					'number' =>
						1,

					'meta_query' => [
						[
							'key' =>
								self::KEY_META,

							'value' =>
								$key,
						],
					],
				]
			);

		if (
			is_wp_error( $terms )
			|| empty( $terms )
		) {
			return null;
		}

		$term =
			reset(
				$terms
			);

		return $term instanceof \WP_Term
			? $term
			: null;
	}


	/**
	 * Return the Promi key stored on a category.
	 */
	public function promi_key(
		int $category_id
	): string {

		$category_id =
			absint(
				$category_id
			);

		if ( ! $category_id ) {
			return '';
		}

		return (string)
			get_term_meta(
				$category_id,
				self::KEY_META,
				true
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Category CSV Import
	|--------------------------------------------------------------------------
	*/

	/**
	 * Import Promi category CSV content.
	 *
	 * The remote download deliberately does NOT happen here.
	 *
	 * Promi\Client / Indexer should retrieve the category CSV and pass the
	 * body into this method. This keeps Catalog independent from the Promi
	 * transport layer.
	 *
	 * Expected CSV columns from the existing feed:
	 *
	 *     DE
	 *     KEY
	 *     ICON
	 *     IMAGE
	 *
	 * Example DE value:
	 *
	 *     Büro / Schreibwaren / Kugelschreiber
	 */
	public function import_csv(
		string $csv
	): bool {

		$csv = trim(
			$csv
		);

		if ( '' === $csv ) {
			return false;
		}


		/*
		|--------------------------------------------------------------------------
		| Rows
		|--------------------------------------------------------------------------
		*/

		$rows =
			preg_split(
				'/\r\n|\n|\r/',
				$csv
			);

		if (
			! is_array( $rows )
			|| empty( $rows )
		) {
			return false;
		}

		$rows =
			array_values(
				array_filter(
					$rows,
					static fn( string $row ): bool =>
						'' !== trim( $row )
				)
			);

		if ( empty( $rows ) ) {
			return false;
		}


		/*
		|--------------------------------------------------------------------------
		| Header
		|--------------------------------------------------------------------------
		*/

		$header =
			str_getcsv(
				array_shift( $rows ),
				';',
				'"',
				''
			);

		$header =
			array_map(
				'trim',
				$header
			);

		if (
			empty( $header )
			|| ! in_array(
				'DE',
				$header,
				true
			)
		) {
			return false;
		}


		/*
		|--------------------------------------------------------------------------
		| Import Rows
		|--------------------------------------------------------------------------
		*/

		foreach ( $rows as $row ) {

			$columns =
				str_getcsv(
					$row,
					';',
					'"',
					''
				);

			if (
				count( $columns )
				!== count( $header )
			) {
				continue;
			}

			$data =
				array_combine(
					$header,
					$columns
				);

			if (
				! is_array( $data )
			) {
				continue;
			}

			$this->import_row(
				$data
			);
		}


		do_action(
			'pdxw_categories_imported'
		);

		return true;
	}


	/**
	 * Import one Promi category CSV row.
	 */
	private function import_row(
		array $data
	): void {

		$path =
			sanitize_text_field(
				$data['DE']
					?? ''
			);

		if ( '' === $path ) {
			return;
		}

		$levels =
			array_values(
				array_filter(
					array_map(
						static function (
							string $level
						): string {

							return trim(
								sanitize_text_field(
									$level
								)
							);
						},
						explode(
							'/',
							$path
						)
					)
				)
			);

		if ( empty( $levels ) ) {
			return;
		}

		$parent_id = 0;

		$last_index =
			array_key_last(
				$levels
			);


		foreach (
			$levels as $index => $name
		) {

			$category =
				$this->get_or_create(
					$name,
					$parent_id
				);

			if ( ! $category ) {
				return;
			}

			$parent_id =
				$category->term_id;


			/*
			|--------------------------------------------------------------------------
			| Final Category Metadata
			|--------------------------------------------------------------------------
			|
			| Existing Promi behavior stores KEY, ICON and IMAGE only against
			| the final category in the hierarchy.
			*/

			if ( $index !== $last_index ) {
				continue;
			}

			$this->sync_promi_metadata(
				$category,
				$data
			);
		}
	}


	/*
	|--------------------------------------------------------------------------
	| Category Creation
	|--------------------------------------------------------------------------
	*/

	/**
	 * Retrieve or create one category beneath a specific parent.
	 *
	 * Parent ID is part of the lookup because identical names can legitimately
	 * exist in different WooCommerce category branches.
	 */
	public function get_or_create(
		string $name,
		int $parent_id = 0
	): ?\WP_Term {

		$name =
			trim(
				sanitize_text_field(
					$name
				)
			);

		$parent_id =
			absint(
				$parent_id
			);

		if ( '' === $name ) {
			return null;
		}


		/*
		|--------------------------------------------------------------------------
		| Existing Category
		|--------------------------------------------------------------------------
		*/

		$existing =
			term_exists(
				$name,
				self::TAXONOMY,
				$parent_id
			);

		if ( $existing ) {

			$term_id =
				is_array( $existing )
					? absint(
						$existing['term_id']
							?? 0
					)
					: absint(
						$existing
					);

			if ( $term_id ) {

				$term =
					get_term(
						$term_id,
						self::TAXONOMY
					);

				if (
					$term instanceof \WP_Term
				) {
					return $term;
				}
			}
		}


		/*
		|--------------------------------------------------------------------------
		| Create Category
		|--------------------------------------------------------------------------
		|
		| Preserve the old importer:
		|
		|     slug   = sanitize_title( $name )
		|     parent = current hierarchy parent
		*/

		$created =
			wp_insert_term(
				$name,
				self::TAXONOMY,
				[
					'parent' =>
						$parent_id,

					'slug' =>
						sanitize_title(
							$name
						),
				]
			);

		if ( is_wp_error( $created ) ) {

			/*
			 * Another worker could create the category concurrently.
			 */
			$existing_id =
				absint(
					$created->get_error_data(
						'term_exists'
					)
				);

			if ( ! $existing_id ) {
				return null;
			}

			$term =
				get_term(
					$existing_id,
					self::TAXONOMY
				);

			return $term instanceof \WP_Term
				? $term
				: null;
		}

		$term =
			get_term(
				absint(
					$created[
						'term_id'
					]
				),
				self::TAXONOMY
			);

		if (
			! $term instanceof \WP_Term
		) {
			return null;
		}

		do_action(
			'pdxw_category_created',
			$term
		);

		return $term;
	}


	/*
	|--------------------------------------------------------------------------
	| Promi Metadata
	|--------------------------------------------------------------------------
	*/

	/**
	 * Apply Promi metadata to the final category.
	 */
	private function sync_promi_metadata(
		\WP_Term $category,
		array $data
	): void {

		$key =
			$this->normalize_key(
				(string) (
					$data['KEY']
						?? ''
				)
			);

		if ( '' !== $key ) {

			update_term_meta(
				$category->term_id,
				self::KEY_META,
				$key
			);
		}


		$icon =
			trim(
				sanitize_text_field(
					(string) (
						$data['ICON']
							?? ''
					)
				)
			);

		if ( '' !== $icon ) {

			update_term_meta(
				$category->term_id,
				self::ICON_META,
				$icon
			);
		}


		/*
		|--------------------------------------------------------------------------
		| Category Image
		|--------------------------------------------------------------------------
		|
		| Image transport belongs to Promi\ImageSync.
		|
		| The old importer called CX_Promi_Image directly. The rebuilt
		| Catalog domain instead exposes a filter so it does not depend on
		| Promi.
		*/

		$image_url =
			esc_url_raw(
				(string) (
					$data['IMAGE']
						?? ''
				)
			);

		if ( '' === $image_url ) {
			return;
		}

		$attachment_id =
			absint(
				apply_filters(
					'pdxw_category_image_id',
					0,
					$image_url,
					$category->name,
					$category->term_id
				)
			);

		if ( ! $attachment_id ) {
			return;
		}

		update_term_meta(
			$category->term_id,
			'thumbnail_id',
			$attachment_id
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Category Access
	|--------------------------------------------------------------------------
	*/

	/**
	 * Retrieve a category by term ID.
	 */
	public function find(
		int $category_id
	): ?\WP_Term {

		$category_id =
			absint(
				$category_id
			);

		if ( ! $category_id ) {
			return null;
		}

		$term =
			get_term(
				$category_id,
				self::TAXONOMY
			);

		return $term instanceof \WP_Term
			? $term
			: null;
	}


	/**
	 * Return categories currently assigned to a product.
	 */
	public function product_categories(
		int $product_id
	): array {

		$product_id =
			absint(
				$product_id
			);

		if ( ! $product_id ) {
			return [];
		}

		$terms =
			wp_get_object_terms(
				$product_id,
				self::TAXONOMY
			);

		return is_wp_error( $terms )
			? []
			: $terms;
	}


	/*
	|--------------------------------------------------------------------------
	| Helpers
	|--------------------------------------------------------------------------
	*/

	private function normalize_key(
		string $key
	): string {

		return trim(
			sanitize_text_field(
				wp_unslash(
					$key
				)
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
