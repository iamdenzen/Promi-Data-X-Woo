<?php

namespace PromiDataXWoo\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Calculates the authoritative article selling price.
 *
 * Case 1:
 *
 *     purchase_price
 *         ↓
 *     category markup
 *
 * Case 2:
 *
 *     price (RecommendedSellingPrice)
 *         ↓
 *     manufacturer discount
 *         ↓
 *     category markup
 *
 * Case 3:
 *
 *     neither available
 *         ↓
 *     price_on_request
 */
final class CostCalculator {

	public const STATUS_PRICED =
		'priced';

	public const STATUS_PRICE_ON_REQUEST =
		'price_on_request';


	private PriceRepository $repository;

	private MarkupRules $rules;


	public function __construct(
		PriceRepository $repository,
		MarkupRules $rules
	) {
		$this->repository =
			$repository;

		$this->rules =
			$rules;
	}


	/**
	 * Calculate article pricing for one quantity.
	 */
	public function calculate(
		int $product_id,
		int $variation_id,
		int $quantity
	): array {

		$product_id =
			absint(
				$product_id
			);

		$variation_id =
			absint(
				$variation_id
			);

		$quantity =
			max(
				1,
				absint(
					$quantity
				)
			);

		$tier =
			$this->repository
				->get_applicable_tier(
					$product_id,
					$variation_id,
					$quantity
				);

		if ( ! $tier ) {

			return [
				'status' =>
					self::STATUS_PRICE_ON_REQUEST,

				'cost' =>
					null,

				'article_markup' =>
					$this->rules
						->article_markup(
							$product_id
						),

				'article_price' =>
					null,

				'source' =>
					null,

				'manufacturer_discount' =>
					0.0,

				'tier' =>
					null,
			];
		}


		$purchase_price =
			$this->nullable_price(
				$tier->purchase_price
			);

		$industry_price =
			$this->nullable_price(
				$tier->price
			);


		/*
		|--------------------------------------------------------------------------
		| Case 1 — GeneralBuyingPrice
		|--------------------------------------------------------------------------
		*/

		if (
			null !== $purchase_price
			&& $purchase_price > 0
		) {

			$cost =
				$purchase_price;

			$source =
				'purchase_price';

			$manufacturer_discount =
				0.0;

		/*
		|--------------------------------------------------------------------------
		| Case 2 — RecommendedSellingPrice
		|--------------------------------------------------------------------------
		*/

		} elseif (
			null !== $industry_price
			&& $industry_price > 0
		) {

			$manufacturer_discount =
				$this->rules
					->manufacturer_discount(
						$product_id
					);

			$cost =
				$industry_price
				* (
					1
					- (
						$manufacturer_discount
						/ 100
					)
				);

			$source =
				'recommended_selling_price';

		/*
		|--------------------------------------------------------------------------
		| Case 3 — No Price
		|--------------------------------------------------------------------------
		*/

		} else {

			return [
				'status' =>
					self::STATUS_PRICE_ON_REQUEST,

				'cost' =>
					null,

				'article_markup' =>
					$this->rules
						->article_markup(
							$product_id
						),

				'article_price' =>
					null,

				'source' =>
					null,

				'manufacturer_discount' =>
					0.0,

				'tier' =>
					$tier,
			];
		}


		$markup =
			$this->rules
				->article_markup(
					$product_id
				);


		$article_price =
			$cost
			* (
				1
				+ (
					$markup
					/ 100
				)
			);


		return [
			'status' =>
				self::STATUS_PRICED,

			'cost' =>
				(float) $cost,

			'article_markup' =>
				(float) $markup,

			'article_price' =>
				(float) $article_price,

			'source' =>
				$source,

			'manufacturer_discount' =>
				(float) $manufacturer_discount,

			'tier' =>
				$tier,
		];
	}


	private function nullable_price(
		mixed $value
	): ?float {

		if (
			null === $value
			|| '' === (string) $value
			|| ! is_numeric( $value )
		) {
			return null;
		}

		$value =
			(float) $value;

		return $value > 0
			? $value
			: null;
	}
}