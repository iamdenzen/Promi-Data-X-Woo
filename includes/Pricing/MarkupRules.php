<?php

namespace PromiDataXWoo\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Pricing markup business rules.
 *
 * Article:
 *
 *     product category
 *         ↓
 *     most specific matching category rule
 *         ↓
 *     default article markup
 *
 * Printing:
 *
 *     print option
 *         ↓
 *     print-option markup
 *         ↓
 *     default finishing markup
 *
 * Manufacturer discount:
 *
 *     WooCommerce product_brand term meta
 */
final class MarkupRules {

	public const DEFAULT_ARTICLE_MARKUP =
		25.0;

	public const DEFAULT_FINISHING_MARKUP =
		25.0;

	public const MANUFACTURER_DISCOUNT_META =
		'pdxw_manufacturer_discount';


	private MarkupRepository $repository;


	public function __construct(
		MarkupRepository $repository
	) {
		$this->repository =
			$repository;
	}


	/*
	|--------------------------------------------------------------------------
	| Defaults
	|--------------------------------------------------------------------------
	*/

	public function article_default(): float {

		return max(
			0.0,
			(float) get_option(
				'pdxw_default_article_markup',
				self::DEFAULT_ARTICLE_MARKUP
			)
		);
	}


	public function finishing_default(): float {

		return max(
			0.0,
			(float) get_option(
				'pdxw_default_finishing_markup',
				self::DEFAULT_FINISHING_MARKUP
			)
		);
	}


	public function set_article_default(
		float $markup
	): void {

		update_option(
			'pdxw_default_article_markup',
			max(
				0.0,
				$markup
			),
			false
		);
	}


	public function set_finishing_default(
		float $markup
	): void {

		update_option(
			'pdxw_default_finishing_markup',
			max(
				0.0,
				$markup
			),
			false
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Article Markup
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return the applicable article markup for a product.
	 *
	 * The deepest matching product_cat rule wins.
	 *
	 * Example:
	 *
	 * Promotional Products 25%
	 *     USB Sticks 25%
	 *         Metal USB Sticks 20%
	 *
	 * Metal USB Sticks → 20%
	 */
	public function article_markup(
		int $product_id
	): float {

		$product_id =
			absint(
				$product_id
			);

		if ( ! $product_id ) {
			return $this->article_default();
		}

		$category_ids =
			wp_get_post_terms(
				$product_id,
				'product_cat',
				[
					'fields' =>
						'ids',
				]
			);

		if (
			is_wp_error(
				$category_ids
			)
			|| empty(
				$category_ids
			)
		) {
			return $this->article_default();
		}

		$best_markup =
			null;

		$best_depth =
			-1;

		foreach (
			$category_ids as $category_id
		) {

			$category_id =
				absint(
					$category_id
				);

			if ( ! $category_id ) {
				continue;
			}

			$chain = [
				$category_id,
			];

			$ancestors =
				get_ancestors(
					$category_id,
					'product_cat'
				);

			foreach (
				$ancestors as $ancestor
			) {
				$chain[] =
					absint(
						$ancestor
					);
			}

			foreach (
				$chain as $depth => $term_id
			) {

				$markup =
					$this->repository
						->get(
							MarkupRepository::TYPE_CATEGORY,
							$term_id
						);

				if ( null === $markup ) {
					continue;
				}

				/*
				 * A smaller chain index means the exact child category.
				 */
				$actual_depth =
					count( $chain )
					- $depth;

				if (
					$actual_depth
					> $best_depth
				) {

					$best_depth =
						$actual_depth;

					$best_markup =
						$markup;
				}
			}
		}

		return null !== $best_markup
			? $best_markup
			: $this->article_default();
	}


	/*
	|--------------------------------------------------------------------------
	| Finishing / Print Option
	|--------------------------------------------------------------------------
	*/

	public function finishing_markup(
		int $print_option_id
	): float {

		$markup =
			$this->repository
				->get(
					MarkupRepository::TYPE_PRINT_OPTION,
					absint(
						$print_option_id
					)
				);

		return null !== $markup
			? $markup
			: $this->finishing_default();
	}


	/*
	|--------------------------------------------------------------------------
	| Manufacturer
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return manufacturer discount for a WooCommerce product.
	 *
	 * Brand is the existing product_brand taxonomy.
	 */
	public function manufacturer_discount(
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
				'product_brand',
				[
					'number' =>
						1,
				]
			);

		if (
			is_wp_error( $terms )
			|| empty( $terms )
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

		$value =
			get_term_meta(
				$term->term_id,
				self::MANUFACTURER_DISCOUNT_META,
				true
			);

		if (
			! is_numeric( $value )
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


	public function repository(): MarkupRepository {

		return $this->repository;
	}
}