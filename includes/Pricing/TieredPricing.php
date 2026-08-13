<?php

namespace PromiDataXWoo\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Tier-pricing business service.
 *
 * Responsibilities:
 *
 * - Normalize raw Promi tier data.
 * - Resolve article selling prices through CostCalculator.
 * - Apply article pricing to the unified pricing engine.
 * - Synchronize Promi price structures.
 * - Replace selling / purchase / combined tiers.
 * - Keep WooCommerce's stored catalog price synchronized with the
 *   calculated article price.
 *
 * Direct database access belongs in PriceRepository.
 *
 * Important:
 *
 * Promi's RecommendedSellingPrice is NOT the final storefront price.
 *
 * The final article price is calculated from:
 *
 *     GeneralBuyingPrice
 *         OR
 *     RecommendedSellingPrice - manufacturer discount
 *
 * followed by:
 *
 *     category markup
 *
 * Therefore this class never treats the raw Promi selling price as the
 * authoritative customer price.
 */
final class TieredPricing {

	private PriceRepository $repository;

	private CostCalculator $costs;


	public function __construct(
		PriceRepository $repository,
		CostCalculator $costs
	) {
		$this->repository =
			$repository;

		$this->costs =
			$costs;
	}


	/*
	|--------------------------------------------------------------------------
	| Pricing Engine
	|--------------------------------------------------------------------------
	*/

	/**
	 * Pricing-engine callback.
	 *
	 * The engine passes in the current unit price.
	 *
	 * When an applicable article cost exists, this method returns the
	 * calculated customer article price.
	 *
	 * When no usable price exists, the engine receives 0.0.
	 *
	 * The price-on-request state itself is preserved by the pricing
	 * breakdown/context handled by the surrounding Pricing layer.
	 *
	 * Expected context:
	 *
	 * [
	 *     'product_id'   => 123,
	 *     'variation_id' => 456,
	 *     'quantity'     => 100,
	 * ]
	 */
	public function apply(
		float $price,
		array $context
	): float {

		$product_id =
			absint(
				$context['product_id']
					?? 0
			);


		$variation_id =
			absint(
				$context['variation_id']
					?? 0
			);


		$quantity =
			max(
				1,
				absint(
					$context['quantity']
						?? $context['qty']
						?? 1
				)
			);


		if ( ! $product_id ) {
			return 0.0;
		}


		$result =
			$this->costs
				->calculate(
					$product_id,
					$variation_id,
					$quantity
				);


		if (
			CostCalculator::STATUS_PRICE_ON_REQUEST
			=== $result['status']
		) {
			return 0.0;
		}


		return max(
			0.0,
			(float)
				$result['article_price']
		);
	}


	/**
	 * Return the complete calculated article-price result.
	 *
	 * This is useful to callers that need the calculation breakdown rather
	 * than only the final number.
	 */
	public function article_price(
		int $product_id,
		int $variation_id = 0,
		int $quantity = 1
	): array {

		return $this->costs
			->calculate(
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


	/*
	|--------------------------------------------------------------------------
	| Selling Prices
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return the calculated customer selling price.
	 *
	 * This is NOT Promi's raw RecommendedSellingPrice.
	 *
	 * Returns null for Case 3 / Price on request.
	 */
	public function selling_price(
		int $product_id,
		int $variation_id = 0,
		int $quantity = 1
	): ?float {

		$result =
			$this->costs
				->calculate(
					absint(
						$product_id
					),
					absint(
						$variation_id
					),
					max(
						1,
						$quantity
					)
				);


		if (
			CostCalculator::STATUS_PRICE_ON_REQUEST
			=== $result['status']
		) {
			return null;
		}


		return (float)
			$result['article_price'];
	}


	/**
	 * Return the raw Promi selling tiers.
	 *
	 * These values are source data and must not be confused with the final
	 * storefront selling prices.
	 */
	public function selling_tiers(
		int $product_id,
		int $variation_id = 0
	): array {

		return $this->repository
			->get_selling_tiers(
				absint(
					$product_id
				),
				absint(
					$variation_id
				)
			);
	}


	/**
	 * Replace raw Promi selling tiers while preserving purchase values at
	 * matching quantities.
	 */
	public function replace_selling(
		int $product_id,
		int $variation_id,
		array $tiers,
		bool $update_wc_price = true
	): bool {

		$product_id =
			absint(
				$product_id
			);

		$variation_id =
			absint(
				$variation_id
			);


		if ( ! $product_id ) {
			return false;
		}


		$clean =
			$this->normalize_selling_tiers(
				$tiers
			);


		$result =
			$this->repository
				->replace_selling(
					$product_id,
					$variation_id,
					$clean
				);


		if (
			$result
			&& $update_wc_price
		) {

			$this->sync_woocommerce_price_from_tiers(
				$product_id,
				$variation_id,
				$clean
			);
		}


		return $result;
	}


	/*
	|--------------------------------------------------------------------------
	| Purchase Prices
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return the raw applicable Promi purchase price.
	 *
	 * This is the GeneralBuyingPrice source used by Case 1.
	 */
	public function purchase_price(
		int $product_id,
		int $variation_id = 0,
		int $quantity = 1
	): ?float {

		return $this->repository
			->get_applicable_purchase_price(
				absint(
					$product_id
				),
				absint(
					$variation_id
				),
				max(
					1,
					$quantity
				)
			);
	}


	/**
	 * Return all raw Promi purchasing tiers.
	 */
	public function purchase_tiers(
		int $product_id,
		int $variation_id = 0
	): array {

		return $this->repository
			->get_purchase_tiers(
				absint(
					$product_id
				),
				absint(
					$variation_id
				)
			);
	}


	/**
	 * Replace purchase prices only.
	 *
	 * Existing selling rows are preserved.
	 */
	public function replace_purchase(
		int $product_id,
		int $variation_id,
		array $tiers
	): bool {

		$product_id =
			absint(
				$product_id
			);

		$variation_id =
			absint(
				$variation_id
			);


		if ( ! $product_id ) {
			return false;
		}


		$clean =
			$this->normalize_purchase_tiers(
				$tiers
			);


		return $this->repository
			->replace_purchase(
				$product_id,
				$variation_id,
				$clean
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Combined Tier Replacement
	|--------------------------------------------------------------------------
	*/

	/**
	 * Replace all selling and purchasing tiers for one target.
	 *
	 * Expected input:
	 *
	 * [
	 *     [
	 *         'qty'            => 1,
	 *         'price'          => 10.00,
	 *         'purchase_price' => 5.00,
	 *     ],
	 *     [
	 *         'qty'            => 100,
	 *         'price'          => 8.50,
	 *         'purchase_price' => 4.10,
	 *     ],
	 * ]
	 *
	 * A tier may contain purchase_price without price. This is important for
	 * Case 1 because GeneralBuyingPrice is the authoritative cost source.
	 */
	public function replace_all(
		int $product_id,
		int $variation_id,
		array $tiers,
		bool $update_wc_price = true
	): bool {

		$product_id =
			absint(
				$product_id
			);

		$variation_id =
			absint(
				$variation_id
			);


		if ( ! $product_id ) {
			return false;
		}


		$clean =
			$this->normalize_combined_tiers(
				$tiers
			);


		$result =
			$this->repository
				->replace(
					$product_id,
					$variation_id,
					$clean
				);


		if (
			$result
			&& $update_wc_price
		) {

			$this->sync_woocommerce_price_from_tiers(
				$product_id,
				$variation_id,
				$clean
			);
		}


		do_action(
			'pdxw_tiers_replaced',
			$product_id,
			$variation_id,
			$clean
		);


		return $result;
	}


	/*
	|--------------------------------------------------------------------------
	| Promi Synchronization
	|--------------------------------------------------------------------------
	*/

	/**
	 * Synchronize tier pricing from Promi country-price data.
	 *
	 * Accepted input can be either:
	 *
	 * [
	 *     'DEU'  => [...],
	 *     'EURO' => [...],
	 * ]
	 *
	 * or an already selected country payload:
	 *
	 * [
	 *     'RecommendedSellingPrice' => [...],
	 *     'GeneralBuyingPrice'      => [...],
	 * ]
	 *
	 * Raw Promi prices are stored unchanged.
	 *
	 * The storefront price is calculated later using CostCalculator.
	 */
	public function sync_promi(
		int $product_id,
		int $variation_id,
		array $price_data,
		bool $update_wc_price = true
	): bool {

		$country_data =
			$this->promi_country_data(
				$price_data
			);


		if ( empty( $country_data ) ) {

			/*
			 * An empty Promi price structure should clear old tiers rather
			 * than leave stale pricing behind.
			 */
			return $this->replace_all(
				$product_id,
				$variation_id,
				[],
				false
			);
		}


		$selling =
			$country_data[
				'RecommendedSellingPrice'
			] ?? [];


		$purchase =
			$country_data[
				'GeneralBuyingPrice'
			] ?? [];


		$tiers =
			$this->normalize_promi_prices(
				$selling,
				$purchase
			);


		$result =
			$this->replace_all(
				$product_id,
				$variation_id,
				$tiers,
				$update_wc_price
			);


		if ( $result ) {

			$this->sync_promi_quantity_meta(
				$product_id,
				$variation_id,
				$country_data
			);
		}


		return $result;
	}


	/**
	 * Pick DEU price data first and EURO as fallback.
	 */
	private function promi_country_data(
		array $price_data
	): array {

		if (
			isset(
				$price_data['DEU']
			)
			&& is_array(
				$price_data['DEU']
			)
		) {

			return $price_data['DEU'];
		}


		if (
			isset(
				$price_data['EURO']
			)
			&& is_array(
				$price_data['EURO']
			)
		) {

			return $price_data['EURO'];
		}


		/*
		 * ProductSync may already have selected DEU/EURO before passing the
		 * structure into Pricing.
		 */
		if (
			isset(
				$price_data[
					'RecommendedSellingPrice'
				]
			)
			|| isset(
				$price_data[
					'GeneralBuyingPrice'
				]
			)
		) {

			return $price_data;
		}


		return [];
	}


	/**
	 * Match Promi selling and buying tiers by quantity.
	 *
	 * Purchasing prices are optional.
	 *
	 * Important:
	 *
	 * A purchase-only tier is retained.
	 *
	 * This is required because Case 1 can work with GeneralBuyingPrice even
	 * when RecommendedSellingPrice is unavailable.
	 */
	private function normalize_promi_prices(
		mixed $selling,
		mixed $purchase
	): array {

		$selling_by_quantity =
			$this->promi_price_map(
				$selling
			);


		$purchase_by_quantity =
			$this->promi_price_map(
				$purchase
			);


		$quantities =
			array_unique(
				array_merge(
					array_keys(
						$selling_by_quantity
					),
					array_keys(
						$purchase_by_quantity
					)
				)
			);


		if ( empty( $quantities ) ) {
			return [];
		}


		sort(
			$quantities,
			SORT_NUMERIC
		);


		$tiers = [];


		foreach ( $quantities as $quantity ) {

			$quantity =
				absint(
					$quantity
				);


			if ( ! $quantity ) {
				continue;
			}


			$price =
				$selling_by_quantity[
					$quantity
				] ?? null;


			$purchase_price =
				$purchase_by_quantity[
					$quantity
				] ?? null;


			/*
			 * At least one usable price source must exist.
			 */
			if (
				null === $price
				&& null === $purchase_price
			) {
				continue;
			}


			$tiers[] = [
				'qty' =>
					$quantity,

				'price' =>
					$price,

				'purchase_price' =>
					$purchase_price,
			];
		}


		return $tiers;
	}


	/**
	 * Convert a Promi price-array structure into:
	 *
	 *     quantity => price
	 */
	private function promi_price_map(
		mixed $rows
	): array {

		if ( ! is_array( $rows ) ) {
			return [];
		}


		$prices = [];


		foreach ( $rows as $row ) {

			if ( ! is_array( $row ) ) {
				continue;
			}


			/*
			 * Promi can mark pricing as "OnRequest".
			 *
			 * Such a row does not provide a numeric price, so it cannot be
			 * used as a raw cost source.
			 */
			if (
				! empty(
					$row['OnRequest']
				)
			) {
				continue;
			}


			$quantity =
				absint(
					$row['Quantity']
						?? 0
				);


			$price =
				$this->normalize_price(
					$row['Price']
						?? null
				);


			if (
				! $quantity
				|| null === $price
				|| $price <= 0
			) {
				continue;
			}


			$prices[
				$quantity
			] =
				$price;
		}


		return $prices;
	}


	/*
	|--------------------------------------------------------------------------
	| Quantities
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return unique configured quantities.
	 *
	 * variation_id = NULL means all tiers belonging to the parent product
	 * and its variations.
	 */
	public function quantities(
		int $product_id,
		?int $variation_id = null
	): array {

		return $this->repository
			->get_quantities(
				absint(
					$product_id
				),
				null === $variation_id
					? null
					: absint(
						$variation_id
					)
			);
	}


	/**
	 * Return the first configured tier quantity greater than one.
	 */
	public function lowest_tier_quantity(
		int $product_id,
		?int $variation_id = null
	): ?int {

		$quantities =
			$this->quantities(
				$product_id,
				$variation_id
			);


		foreach (
			$quantities as $quantity
		) {

			$quantity =
				absint(
					$quantity
				);


			if ( $quantity > 1 ) {
				return $quantity;
			}
		}


		return null;
	}


	/*
	|--------------------------------------------------------------------------
	| Normalization
	|--------------------------------------------------------------------------
	*/

	/**
	 * Normalize combined selling + purchasing tiers.
	 *
	 * A combined tier may contain:
	 *
	 * - selling price only,
	 * - purchase price only,
	 * - both.
	 *
	 * At least one usable source is required.
	 */
	private function normalize_combined_tiers(
		array $tiers
	): array {

		$clean = [];


		foreach ( $tiers as $tier ) {

			if ( ! is_array( $tier ) ) {
				continue;
			}


			$quantity =
				absint(
					$tier['qty']
						?? $tier['quantity']
						?? 0
				);


			$price =
				$this->normalize_price(
					$tier['price']
						?? null
				);


			$purchase_price =
				$this->normalize_price(
					$tier['purchase_price']
						?? null
				);


			if (
				! $quantity
				|| (
					null === $price
					&& null === $purchase_price
				)
			) {
				continue;
			}


			$clean[
				$quantity
			] = [
				'qty' =>
					$quantity,

				'price' =>
					$price,

				'purchase_price' =>
					(
						null !== $purchase_price
						&& $purchase_price > 0
					)
						? $purchase_price
						: null,
			];
		}


		ksort(
			$clean,
			SORT_NUMERIC
		);


		return array_values(
			$clean
		);
	}


	/**
	 * Normalize selling tiers.
	 *
	 * Selling price is optional because a purchase-only tier is valid under
	 * the new Case 1 pricing model.
	 */
	private function normalize_selling_tiers(
		array $tiers
	): array {

		$clean = [];


		foreach ( $tiers as $tier ) {

			if ( ! is_array( $tier ) ) {
				continue;
			}


			$quantity =
				absint(
					$tier['qty']
						?? $tier['quantity']
						?? 0
				);


			$price =
				$this->normalize_price(
					$tier['price']
						?? null
				);


			$purchase_price =
				$this->normalize_price(
					$tier['purchase_price']
						?? null
				);


			if (
				! $quantity
				|| (
					null === $price
					&& null === $purchase_price
				)
			) {
				continue;
			}


			$clean[
				$quantity
			] = [
				'qty' =>
					$quantity,

				'price' =>
					$price,

				'purchase_price' =>
					(
						null !== $purchase_price
						&& $purchase_price > 0
					)
						? $purchase_price
						: null,
			];
		}


		ksort(
			$clean,
			SORT_NUMERIC
		);


		return array_values(
			$clean
		);
	}


	/**
	 * Normalize purchasing tiers into quantity => price.
	 */
	private function normalize_purchase_tiers(
		array $tiers
	): array {

		$clean = [];


		foreach (
			$tiers as $key => $tier
		) {

			/*
			 * Accept either:
			 *
			 * [
			 *     ['qty' => 10, 'purchase_price' => 4.50]
			 * ]
			 *
			 * or:
			 *
			 * [
			 *     10 => 4.50
			 * ]
			 */
			if ( is_array( $tier ) ) {

				$quantity =
					absint(
						$tier['qty']
							?? $tier['quantity']
							?? 0
					);


				$price =
					$this->normalize_price(
						$tier['purchase_price']
							?? $tier['price']
							?? null
					);

			} else {

				$quantity =
					absint(
						$key
					);


				$price =
					$this->normalize_price(
						$tier
					);
			}


			if (
				! $quantity
				|| null === $price
				|| $price <= 0
			) {
				continue;
			}


			$clean[
				$quantity
			] =
				$price;
		}


		ksort(
			$clean,
			SORT_NUMERIC
		);


		return $clean;
	}


	/**
	 * Normalize WooCommerce-style decimal input.
	 */
	private function normalize_price(
		mixed $value
	): ?float {

		if (
			null === $value
			|| '' === trim(
				(string) $value
			)
		) {
			return null;
		}


		$value =
			wc_format_decimal(
				$value,
				4
			);


		if (
			'' === $value
			|| ! is_numeric(
				$value
			)
		) {
			return null;
		}


		return (float) $value;
	}


	/*
	|--------------------------------------------------------------------------
	| WooCommerce Synchronization
	|--------------------------------------------------------------------------
	*/

	/**
	 * Synchronize WooCommerce's stored catalog price from the calculated
	 * article price.
	 *
	 * This is deliberately NOT based directly on Promi's raw
	 * RecommendedSellingPrice.
	 *
	 * For each raw tier we ask CostCalculator to calculate the actual
	 * customer price using:
	 *
	 *     purchase_price
	 *         OR
	 *     RecommendedSellingPrice - manufacturer discount
	 *
	 * followed by the applicable category markup.
	 *
	 * The lowest calculated customer price is stored as WooCommerce's
	 * regular/current price.
	 *
	 * Quantity-dependent pricing remains authoritative through the Pricing
	 * Engine at checkout/cart calculation time.
	 */
	private function sync_woocommerce_price_from_tiers(
		int $product_id,
		int $variation_id,
		array $tiers
	): void {

		if ( empty( $tiers ) ) {
			return;
		}


		$prices = [];


		foreach ( $tiers as $tier ) {

			$quantity =
				absint(
					$tier['qty']
						?? 0
				);


			if ( ! $quantity ) {
				continue;
			}


			$result =
				$this->costs
					->calculate(
						$product_id,
						$variation_id,
						$quantity
					);


			if (
				CostCalculator::STATUS_PRICED
				!== $result['status']
			) {
				continue;
			}


			if (
				isset(
					$result['article_price']
				)
				&& is_numeric(
					$result['article_price']
				)
			) {

				$prices[] =
					(float)
						$result['article_price'];
			}
		}


		if ( empty( $prices ) ) {
			return;
		}


		$lowest_price =
			min(
				$prices
			);


		$wc_product_id =
			$variation_id
				?: $product_id;


		$product =
			wc_get_product(
				$wc_product_id
			);


		if ( ! $product ) {
			return;
		}


		$formatted =
			wc_format_decimal(
				$lowest_price,
				wc_get_price_decimals()
			);


		$current_regular =
			(string)
				$product
					->get_regular_price();


		$current_price =
			(string)
				$product
					->get_price();


		if (
			$current_regular
				=== $formatted
			&& $current_price
				=== $formatted
		) {
			return;
		}


		$product->set_regular_price(
			$formatted
		);


		$product->set_price(
			$formatted
		);


		$product->save();


		if ( $variation_id ) {

			/*
			 * Variation price changes can affect the parent variable
			 * product's min/max price cache.
			 */
			\WC_Product_Variable::sync(
				$product_id
			);
		}


		wc_delete_product_transients(
			$product_id
		);
	}


	/**
	 * Preserve Promi minimum-order and quantity-increment values on the
	 * product/variation itself.
	 */
	private function sync_promi_quantity_meta(
		int $product_id,
		int $variation_id,
		array $country_data
	): void {

		$wc_product_id =
			$variation_id
				?: $product_id;


		$product =
			wc_get_product(
				$wc_product_id
			);


		if ( ! $product ) {
			return;
		}


		$minimum =
			max(
				1,
				absint(
					$country_data[
						'MinimumOrderQuantity'
					] ?? 1
				)
			);


		$increment =
			max(
				1,
				absint(
					$country_data[
						'QuantityIncrements'
					] ?? 1
				)
			);


		$product->update_meta_data(
			'min_order_qty',
			$minimum
		);


		$product->update_meta_data(
			'qty_increments',
			$increment
		);


		$product->save();
	}
}
