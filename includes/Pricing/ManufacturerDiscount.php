<?php

namespace PromiDataXWoo\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Manufacturer discount service.
 *
 * The manufacturer is represented by WooCommerce's existing
 * `product_brand` taxonomy.
 *
 * The discount is stored as term meta on that brand:
 *
 *     pdxw_manufacturer_discount
 *
 * The value is a percentage:
 *
 *     30 = 30%
 *
 * This service deliberately contains only manufacturer-discount concerns.
 * Markup rules remain in MarkupRules and database access remains in the
 * appropriate storage layer.
 */
final class ManufacturerDiscount {

	/**
	 * Existing product_brand taxonomy.
	 */
	public const TAXONOMY =
		'product_brand';

	/**
	 * Manufacturer discount term-meta key.
	 *
	 * This must remain identical to the key used by MarkupRules so that the
	 * service and the admin UI read/write the same value.
	 */
	public const META_KEY =
		MarkupRules::MANUFACTURER_DISCOUNT_META;


	/**
	 * Get the manufacturer discount for a product.
	 *
	 * The first assigned product_brand is used.
	 *
	 * Returns 0 when:
	 *
	 * - the product does not exist,
	 * - no brand is assigned,
	 * - the brand has no discount,
	 * - or the stored value is invalid.
	 */
	public function get_for_product(
		int $product_id
	): float {

		$product_id =
			absint(
				$product_id
			);

		if ( ! $product_id ) {
			return 0.0;
		}


		$terms =
			wp_get_post_terms(
				$product_id,
				self::TAXONOMY,
				[
					'number' =>
						1,
				]
			);


		if (
			is_wp_error(
				$terms
			)
			|| empty(
				$terms
			)
		) {
			return 0.0;
		}


		$term =
			reset(
				$terms
			);


		if (
			! $term instanceof \WP_Term
		) {
			return 0.0;
		}


		return $this->get_for_brand(
			$term->term_id
		);
	}


	/**
	 * Get the manufacturer discount for a brand term.
	 */
	public function get_for_brand(
		int $term_id
	): float {

		$term_id =
			absint(
				$term_id
			);

		if ( ! $term_id ) {
			return 0.0;
		}


		$value =
			get_term_meta(
				$term_id,
				self::META_KEY,
				true
			);


		return $this->normalize(
			$value
		);
	}


	/**
	 * Save the manufacturer discount on a brand term.
	 *
	 * The value is always constrained to:
	 *
	 *     0 - 100
	 *
	 * so an invalid API/admin value cannot produce a negative cost or a
	 * discount greater than 100%.
	 */
	public function save_for_brand(
		int $term_id,
		float $discount
	): bool {

		$term_id =
			absint(
				$term_id
			);


		if ( ! $term_id ) {
			return false;
		}


		$term =
			get_term(
				$term_id,
				self::TAXONOMY
			);


		if (
			! $term
			|| is_wp_error(
				$term
			)
		) {
			return false;
		}


		$discount =
			$this->normalize(
				$discount
			);


		return false !== update_term_meta(
			$term_id,
			self::META_KEY,
			$discount
		);
	}


	/**
	 * Remove a manufacturer discount from a brand.
	 *
	 * Removing the value means the manufacturer has no special discount,
	 * which is equivalent to 0%.
	 */
	public function delete_for_brand(
		int $term_id
	): bool {

		$term_id =
			absint(
				$term_id
			);


		if ( ! $term_id ) {
			return false;
		}


		return false !== delete_term_meta(
			$term_id,
			self::META_KEY
		);
	}


	/**
	 * Check whether a product has an explicitly stored manufacturer
	 * discount.
	 *
	 * This is useful for admin/API presentation where we need to distinguish:
	 *
	 *     no manufacturer discount configured
	 *
	 * from:
	 *
	 *     manufacturer discount = 0%
	 */
	public function has_for_product(
		int $product_id
	): bool {

		$product_id =
			absint(
				$product_id
			);

		if ( ! $product_id ) {
			return false;
		}


		$terms =
			wp_get_post_terms(
				$product_id,
				self::TAXONOMY,
				[
					'number' =>
						1,
				],
			);


		if (
			is_wp_error(
				$terms
			)
			|| empty(
				$terms
			)
		) {
			return false;
		}


		$term =
			reset(
				$terms
			);


		if (
			! $term instanceof \WP_Term
		) {
			return false;
		}


		return '' !== get_term_meta(
			$term->term_id,
			self::META_KEY,
			true
		);
	}


	/**
	 * Normalize a percentage.
	 */
	private function normalize(
		$value
	): float {

		if (
			! is_numeric(
				$value
			)
		) {
			return 0.0;
		}


		return min(
			100.0,
			max(
				0.0,
				(float) $value
			)
		);
	}
}