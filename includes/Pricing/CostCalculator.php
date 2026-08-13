<?php

namespace PromiDataXWoo\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the authoritative article cost and article selling price.
 *
 * Price-source rules:
 *
 * Case 1:
 *
 *     cx_tier_prices.purchase_price
 *     = Promi GeneralBuyingPrice
 *
 *     This is the preferred cost source.
 *
 * Case 2:
 *
 *     cx_tier_prices.price
 *     = Promi RecommendedSellingPrice
 *
 *     Used only when purchase_price is unavailable.
 *
 *     cost =
 *         RecommendedSellingPrice
 *         ×
 *         (1 - manufacturer_discount / 100)
 *
 * Case 3:
 *
 *     Neither purchase_price nor price is available.
 *
 *     Result:
 *
 *         price_on_request
 *
 * Once the effective cost is resolved, the applicable article-category
 * markup is applied through SellingPriceCalculator.
 *
 * This class is responsible for:
 *
 * - resolving the applicable raw Promi tier,
 * - resolving the authoritative article cost,
 * - applying the manufacturer discount in Case 2,
 * - resolving the applicable article markup,
 * - calculating the final article selling price.
 *
 * This class does NOT:
 *
 * - modify WooCommerce products,
 * - modify Promi data,
 * - calculate printing,
 * - calculate setup fees,
 * - persist cart data,
 * - decide which category rule wins.
 *
 * Those responsibilities belong to the surrounding Pricing services.
 */
final class CostCalculator {

	/**
	 * A normal calculated customer price is available.
	 */
	public const STATUS_PRICED =
		'priced';


	/**
	 * No usable cost source is available.
	 *
	 * The frontend/cart must treat this as "Price on request", not as a
	 * legitimate zero-price product.
	 */
	public const STATUS_PRICE_ON_REQUEST =
		'price_on_request';


	/**
	 * Raw product/variation tier repository.
	 */
	private PriceRepository $repository;


	/**
	 * Category/default markup rules.
	 */
	private MarkupRules $rules;


	/**
	 * Manufacturer discount service.
	 */
	private ManufacturerDiscount $manufacturer_discount;


	/**
	 * Selling-price calculation service.
	 */
	private SellingPriceCalculator $selling_prices;


	/**
	 * Constructor.
	 */
	public function __construct(
		PriceRepository $repository,
		MarkupRules $rules,
		ManufacturerDiscount $manufacturer_discount,
		SellingPriceCalculator $selling_prices
	) {

		$this->repository =
			$repository;

		$this->rules =
			$rules;

		$this->manufacturer_discount =
			$manufacturer_discount;

		$this->selling_prices =
			$selling_prices;
	}


	/**
	 * Calculate the article selling price for a quantity.
	 *
	 * The applicable raw Promi tier is resolved first.
	 *
	 * The complete tier is required because we need both:
	 *
	 *     purchase_price
	 *     price
	 *
	 * to implement the three pricing cases.
	 *
	 * @return array{
	 *     status:string,
	 *     cost:?float,
	 *     article_markup:float,
	 *     article_price:?float,
	 *     source:?string,
	 *     manufacturer_discount:float,
	 *     tier:?object
	 * }
	 */
	public function calculate(
		int $product_id,
		int $variation_id = 0,
		int $quantity = 1
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


		/*
		|--------------------------------------------------------------------------
		| Resolve effective cost
		|--------------------------------------------------------------------------
		*/

		$cost_result =
			$this->resolve_cost(
				$product_id,
				$variation_id,
				$quantity
			);


		/*
		|--------------------------------------------------------------------------
		| Resolve article markup
		|--------------------------------------------------------------------------
		*/

		$article_markup =
			$this->rules
				->article_markup(
					$product_id
				);


		/*
		|--------------------------------------------------------------------------
		| Case 3 — Price on request
		|--------------------------------------------------------------------------
		*/

		if (
			self::STATUS_PRICE_ON_REQUEST
			=== $cost_result['status']
		) {

			return [
				'status' =>
					self::STATUS_PRICE_ON_REQUEST,

				'cost' =>
					null,

				'article_markup' =>
					(float) $article_markup,

				'article_price' =>
					null,

				'source' =>
					null,

				'manufacturer_discount' =>
					(float) $cost_result[
						'manufacturer_discount'
					],

				'tier' =>
					$cost_result['tier'],
			];
		}


		/*
		|--------------------------------------------------------------------------
		| Calculate article selling price
		|--------------------------------------------------------------------------
		|
		| The actual mathematical markup calculation belongs to
		| SellingPriceCalculator.
		|
		| This keeps the pricing policy and price mathematics separate.
		|--------------------------------------------------------------------------
		*/

		$article_price =
			$this->selling_prices
				->article(
					(float) $cost_result['cost'],
					(float) $article_markup
				);


		return [
			'status' =>
				self::STATUS_PRICED,

			'cost' =>
				(float) $cost_result['cost'],

			'article_markup' =>
				(float) $article_markup,

			'article_price' =>
				(float) $article_price,

			'source' =>
				$cost_result['source'],

			'manufacturer_discount' =>
				(float) $cost_result[
					'manufacturer_discount'
				],

			'tier' =>
				$cost_result['tier'],
		];
	}


	/**
	 * Determine only the effective article cost.
	 *
	 * This method does not apply article markup.
	 *
	 * It is useful when another pricing component needs the underlying
	 * article cost without calculating the customer selling price.
	 *
	 * @return array{
	 *     status:string,
	 *     cost:?float,
	 *     source:?string,
	 *     manufacturer_discount:float,
	 *     tier:?object
	 * }
	 */
	public function cost(
		int $product_id,
		int $variation_id = 0,
		int $quantity = 1
	): array {

		return $this->resolve_cost(
			absint(
				$product_id
			),
			absint(
				$variation_id
			),
			max(
				1,
				absint(
					$quantity
				)
			)
		);
	}


	/**
	 * Return the article markup currently applicable to a product.
	 *
	 * This helper keeps callers from needing to know where category rules
	 * are stored.
	 */
	public function article_markup(
		int $product_id
	): float {

		return $this->rules
			->article_markup(
				absint(
					$product_id
				)
			);
	}


	/**
	 * Resolve the effective article cost.
	 *
	 * This is the single authoritative implementation of Case 1, Case 2
	 * and Case 3.
	 *
	 * Case 1:
	 *
	 *     purchase_price exists
	 *         ↓
	 *     use purchase_price
	 *
	 * Case 2:
	 *
	 *     purchase_price unavailable
	 *         ↓
	 *     RecommendedSellingPrice
	 *         ↓
	 *     manufacturer discount
	 *         ↓
	 *     effective cost
	 *
	 * Case 3:
	 *
	 *     neither exists
	 *         ↓
	 *     price_on_request
	 *
	 * @return array{
	 *     status:string,
	 *     cost:?float,
	 *     source:?string,
	 *     manufacturer_discount:float,
	 *     tier:?object
	 * }
	 */
	private function resolve_cost(
		int $product_id,
		int $variation_id,
		int $quantity
	): array {

		/*
		|--------------------------------------------------------------------------
		| Resolve raw Promi tier
		|--------------------------------------------------------------------------
		|
		| PriceRepository owns:
		|
		| - variation → parent fallback,
		| - quantity-tier selection,
		| - raw Promi price data.
		|--------------------------------------------------------------------------
		*/

		$tier =
			$this->repository
				->get_applicable_tier(
					$product_id,
					$variation_id,
					$quantity
				);


		/*
		|--------------------------------------------------------------------------
		| No raw tier
		|--------------------------------------------------------------------------
		*/

		if ( ! $tier ) {

			return [
				'status' =>
					self::STATUS_PRICE_ON_REQUEST,

				'cost' =>
					null,

				'source' =>
					null,

				'manufacturer_discount' =>
					0.0,

				'tier' =>
					null,
			];
		}


		/*
		|--------------------------------------------------------------------------
		| Read raw price sources
		|--------------------------------------------------------------------------
		*/

		$purchase_price =
			$this->nullable_price(
				$tier->purchase_price
					?? null
			);


		$recommended_price =
			$this->nullable_price(
				$tier->price
					?? null
			);


		/*
		|--------------------------------------------------------------------------
		| CASE 1 — GeneralBuyingPrice / purchase_price
		|--------------------------------------------------------------------------
		|
		| This source always has priority.
		|
		| Manufacturer discount is deliberately NOT applied here.
		|--------------------------------------------------------------------------
		*/

		if (
			null !== $purchase_price
		) {

			return [
				'status' =>
					self::STATUS_PRICED,

				'cost' =>
					(float) $purchase_price,

				'source' =>
					'purchase_price',

				'manufacturer_discount' =>
					0.0,

				'tier' =>
					$tier,
			];
		}


		/*
		|--------------------------------------------------------------------------
		| CASE 2 — RecommendedSellingPrice
		|--------------------------------------------------------------------------
		|
		| GeneralBuyingPrice is unavailable.
		|
		| RecommendedSellingPrice is converted into our effective cost using
		| the manufacturer discount stored against product_brand.
		|--------------------------------------------------------------------------
		*/

		if (
			null !== $recommended_price
		) {

			$manufacturer_discount =
				$this->manufacturer_discount
					->get_for_product(
						$product_id
					);


			$cost =
				$recommended_price
				* (
					1
					- (
						$manufacturer_discount
						/ 100
					)
				);


			return [
				'status' =>
					self::STATUS_PRICED,

				'cost' =>
					(float) $cost,

				'source' =>
					'recommended_selling_price',

				'manufacturer_discount' =>
					(float) $manufacturer_discount,

				'tier' =>
					$tier,
			];
		}


		/*
		|--------------------------------------------------------------------------
		| CASE 3 — no usable price
		|--------------------------------------------------------------------------
		*/

		return [
			'status' =>
				self::STATUS_PRICE_ON_REQUEST,

			'cost' =>
				null,

			'source' =>
				null,

			'manufacturer_discount' =>
				0.0,

			'tier' =>
				$tier,
		];
	}


	/**
	 * Convert a database price into a usable positive float.
	 *
	 * Zero, negative, empty and non-numeric values are treated as
	 * unavailable.
	 *
	 * This means Case 3 is based on the absence of a usable price rather
	 * than merely checking for SQL NULL.
	 */
	private function nullable_price(
		$value
	): ?float {

		if (
			null === $value
			|| '' === (string) $value
			|| ! is_numeric(
				$value
			)
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
