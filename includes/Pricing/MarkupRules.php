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
 * Printing / decoration:
 *
 *     print option
 *         ↓
 *     print-option markup
 *         ↓
 *     default finishing markup
 *
 * Manufacturer discounts are intentionally NOT handled here.
 *
 * Manufacturer discounts are handled by:
 *
 *     ManufacturerDiscount
 *
 * using the existing WooCommerce `product_brand` taxonomy.
 */
final class MarkupRules {

	/**
	 * Default article/category markup.
	 */
	public const DEFAULT_ARTICLE_MARKUP =
		25.0;

	/**
	 * Default finishing/print-option markup.
	 */
	public const DEFAULT_FINISHING_MARKUP =
		25.0;

	public const DEFAULT_PRINT_PRICE_MARKUP =
		25.0;

	public const DEFAULT_PRINT_FEE_MARKUP =
		25.0;

	/**
	 * Manufacturer discount term-meta key.
	 *
	 * Kept here as a shared compatibility constant because
	 * ManufacturerDiscount currently references this constant.
	 *
	 * The actual manufacturer-discount logic belongs exclusively to
	 * ManufacturerDiscount.
	 */
	public const MANUFACTURER_DISCOUNT_META =
		'pdxw_manufacturer_discount';


	/**
	 * Markup rule repository.
	 */
	private MarkupRepository $repository;


	/**
	 * Constructor.
	 */
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

	/**
	 * Get the default article/category markup.
	 *
	 * Default:
	 *
	 *     25%
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


	/**
	 * Get the default finishing/print-option markup.
	 *
	 * Default:
	 *
	 *     25%
	 */
	public function finishing_default(): float {

		return max(
			0.0,
			(float) get_option(
				'pdxw_default_finishing_markup',
				self::DEFAULT_FINISHING_MARKUP
			)
		);
	}

	/**
	 * Default print option price markup.
	 */
	public function print_price_default(): float {

		return max(
			0.0,
			(float) get_option(
				'pdxw_default_print_price_markup',
				self::DEFAULT_PRINT_PRICE_MARKUP
			)
		);
	}

	/**
	 * Default print option fee/setup markup.
	 */
	public function print_fee_default(): float {

		return max(
			0.0,
			(float) get_option(
				'pdxw_default_print_fee_markup',
				self::DEFAULT_PRINT_FEE_MARKUP
			)
		);
	}


	/**
	 * Set the default article/category markup.
	 */
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


	/**
	 * Set the default finishing/print-option markup.
	 */
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

	public function set_print_price_default(
		float $markup
	): void {

		update_option(
			'pdxw_default_print_price_markup',
			max(
				0.0,
				$markup
			),
			false
		);
	}

	public function set_print_fee_default(
		float $markup
	): void {

		update_option(
			'pdxw_default_print_fee_markup',
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
	 * Article markup is configured against the existing WooCommerce
	 * `product_cat` taxonomy.
	 *
	 * The most specific matching category wins.
	 *
	 * Example:
	 *
	 *     Promotional Products     25%
	 *         USB Sticks           25%
	 *             Metal USB Sticks 20%
	 *
	 * A product in "Metal USB Sticks" receives 20%.
	 *
	 * If no category rule exists, the configured default article markup
	 * is returned.
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


			/*
			 * Build the category chain starting with the actual product
			 * category and then walking up through its ancestors.
			 *
			 * Example:
			 *
			 *     Metal USB Sticks
			 *     USB Sticks
			 *     Promotional Products
			 */
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

				$ancestor =
					absint(
						$ancestor
					);


				if ( ! $ancestor ) {
					continue;
				}


				$chain[] =
					$ancestor;
			}


			/*
			 * Look for the most specific configured rule in this chain.
			 */
			foreach (
				$chain as $depth => $term_id
			) {

				$term_id =
					absint(
						$term_id
					);


				if ( ! $term_id ) {
					continue;
				}


				$markup =
					$this->repository
						->get(
							MarkupRepository::TYPE_CATEGORY,
							$term_id
						);


				if (
					null === $markup
				) {
					continue;
				}


				/*
				 * The first item in the chain is the most specific
				 * category.
				 *
				 * Therefore:
				 *
				 *     child category = greater depth
				 *     parent category = lower depth
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
						(float) $markup;
				}
			}
		}


		return null !== $best_markup
			? $best_markup
			: $this->article_default();
	}


	/*
	|--------------------------------------------------------------------------
	| Finishing / Print Option Markup
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return the applicable finishing/decoration markup.
	 *
	 * The client's terminology may refer to this as a "finishing type",
	 * but in our implementation the existing print-option entity is used.
	 *
	 * There is intentionally no separate finishing-type taxonomy.
	 *
	 * If no specific print-option rule exists, the default finishing markup
	 * is returned.
	 */
	public function finishing_markup(
		int $print_option_id
	): float {

		return $this->print_price_markup(
			$print_option_id
		);
	}

	/**
	 * Return the print-price markup for a single print option.
	 */
	public function print_price_markup(
		int $print_option_id
	): float {

		$print_option_id =
			absint(
				$print_option_id
			);

		if ( ! $print_option_id ) {
			return $this->print_price_default();
		}

		$markup =
			$this->repository
				->get(
					MarkupRepository::TYPE_PRINT_OPTION_PRICE,
					$print_option_id
				);

		if ( null !== $markup ) {
			return (float) $markup;
		}

		$legacy =
			$this->repository
				->get(
					MarkupRepository::TYPE_PRINT_OPTION,
					$print_option_id
				);

		return null !== $legacy
			? (float) $legacy
			: $this->print_price_default();
	}

	/**
	 * Return the setup/fee markup for a single print option.
	 */
	public function print_fee_markup(
		int $print_option_id
	): float {

		$print_option_id =
			absint(
				$print_option_id
			);

		if ( ! $print_option_id ) {
			return $this->print_fee_default();
		}

		$markup =
			$this->repository
				->get(
					MarkupRepository::TYPE_PRINT_OPTION_FEE,
					$print_option_id
				);

		if ( null !== $markup ) {
			return (float) $markup;
		}

		$legacy =
			$this->repository
				->get(
					MarkupRepository::TYPE_PRINT_OPTION,
					$print_option_id
				);

		return null !== $legacy
			? (float) $legacy
			: $this->print_fee_default();
	}


	/*
	|--------------------------------------------------------------------------
	| Repository
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return the underlying markup repository.
	 *
	 * Useful for admin/API layers that need to persist individual rules.
	 */
	public function repository(): MarkupRepository {

		return $this->repository;
	}
}
