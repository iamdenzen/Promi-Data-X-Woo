<?php

namespace PromiDataXWoo\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce brand service.
 *
 * XSImpress uses WooCommerce's native:
 *
 *     product_brand
 *
 * taxonomy.
 *
 * Responsibilities:
 *
 * - Retrieve brands.
 * - Create missing brands.
 * - Assign a brand to a WooCommerce product.
 * - Remove a product's brand assignment.
 *
 * Promi-specific field traversal remains inside ProductSync.
 */
final class Brands {

	public const TAXONOMY = 'product_brand';

	private bool $initialized = false;


	/**
	 * Initialize brand functionality.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		do_action(
			'pdxw_catalog_brands_init',
			$this
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Assignment
	|--------------------------------------------------------------------------
	*/

	/**
	 * Assign one brand to a WooCommerce product.
	 *
	 * This preserves the existing importer behavior:
	 *
	 * - create the brand when missing
	 * - assign exactly that brand
	 * - replace previous product_brand relationships
	 */
	public function assign(
		int $product_id,
		string $brand_name
	): ?\WP_Term {

		$product_id =
			absint(
				$product_id
			);

		$brand_name =
			$this->normalize_name(
				$brand_name
			);

		if (
			! $product_id
			|| '' === $brand_name
		) {
			return null;
		}

		$brand =
			$this->get_or_create(
				$brand_name
			);

		if ( ! $brand ) {
			return null;
		}

		$result =
			wp_set_object_terms(
				$product_id,
				[
					$brand->term_id,
				],
				self::TAXONOMY,
				false
			);

		if ( is_wp_error( $result ) ) {
			return null;
		}

		do_action(
			'pdxw_product_brand_assigned',
			$product_id,
			$brand
		);

		return $brand;
	}


	/**
	 * Remove all brand relationships from a product.
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

		if ( is_wp_error( $result ) ) {
			return false;
		}

		do_action(
			'pdxw_product_brand_removed',
			$product_id
		);

		return true;
	}


	/*
	|--------------------------------------------------------------------------
	| Brand Terms
	|--------------------------------------------------------------------------
	*/

	/**
	 * Retrieve or create a brand.
	 *
	 * This extracts the old:
	 *
	 *     CX_Promi_Product_Sync::get_or_create_brand()
	 *
	 * behavior into the Catalog domain.
	 */
	public function get_or_create(
		string $brand_name
	): ?\WP_Term {

		$brand_name =
			$this->normalize_name(
				$brand_name
			);

		if ( '' === $brand_name ) {
			return null;
		}


		/*
		|--------------------------------------------------------------------------
		| Existing Brand
		|--------------------------------------------------------------------------
		*/

		$brand =
			$this->find(
				$brand_name
			);

		if ( $brand ) {
			return $brand;
		}


		/*
		|--------------------------------------------------------------------------
		| Create Brand
		|--------------------------------------------------------------------------
		|
		| The existing importer explicitly uses sanitize_title() for the
		| generated brand slug, so preserve that behavior.
		*/

		$created =
			wp_insert_term(
				$brand_name,
				self::TAXONOMY,
				[
					'slug' =>
						sanitize_title(
							$brand_name
						),
				]
			);

		if ( is_wp_error( $created ) ) {

			/*
			 * Another import request may have created the brand between
			 * our lookup and insert.
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
						self::TAXONOMY
					);

				return $term instanceof \WP_Term
					? $term
					: null;
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
				self::TAXONOMY
			);

		if (
			! $term instanceof \WP_Term
		) {
			return null;
		}

		do_action(
			'pdxw_brand_created',
			$term
		);

		return $term;
	}


	/**
	 * Find a brand by name.
	 */
	public function find(
		string $brand_name
	): ?\WP_Term {

		$brand_name =
			$this->normalize_name(
				$brand_name
			);

		if ( '' === $brand_name ) {
			return null;
		}


		/*
		|--------------------------------------------------------------------------
		| Name Lookup
		|--------------------------------------------------------------------------
		*/

		$term =
			get_term_by(
				'name',
				$brand_name,
				self::TAXONOMY
			);

		if (
			$term instanceof \WP_Term
		) {
			return $term;
		}


		/*
		|--------------------------------------------------------------------------
		| Slug Lookup
		|--------------------------------------------------------------------------
		|
		| This protects against historical terms whose display name may have
		| changed while retaining the original WooCommerce brand slug.
		*/

		$term =
			get_term_by(
				'slug',
				sanitize_title(
					$brand_name
				),
				self::TAXONOMY
			);

		return $term instanceof \WP_Term
			? $term
			: null;
	}


	/**
	 * Retrieve a brand by term ID.
	 */
	public function find_by_id(
		int $brand_id
	): ?\WP_Term {

		$brand_id =
			absint(
				$brand_id
			);

		if ( ! $brand_id ) {
			return null;
		}

		$term =
			get_term(
				$brand_id,
				self::TAXONOMY
			);

		return $term instanceof \WP_Term
			? $term
			: null;
	}


	/**
	 * Return the assigned brand for a product.
	 *
	 * XSImpress currently treats products as having one primary brand.
	 */
	public function product_brand(
		int $product_id
	): ?\WP_Term {

		$product_id =
			absint(
				$product_id
			);

		if ( ! $product_id ) {
			return null;
		}

		$terms =
			wp_get_object_terms(
				$product_id,
				self::TAXONOMY,
				[
					'number' =>
						1,
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
	 * Return all brands.
	 */
	public function all(
		array $args = []
	): array {

		$args =
			wp_parse_args(
				$args,
				[
					'taxonomy' =>
						self::TAXONOMY,

					'hide_empty' =>
						false,

					'orderby' =>
						'name',

					'order' =>
						'ASC',
				]
			);

		$terms =
			get_terms(
				$args
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

	/**
	 * Normalize a brand name without changing its business meaning.
	 */
	private function normalize_name(
		string $brand_name
	): string {

		return trim(
			sanitize_text_field(
				wp_unslash(
					$brand_name
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
