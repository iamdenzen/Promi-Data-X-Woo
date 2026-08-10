<?php

namespace PromiDataXWoo\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Tier-pricing business service.
 *
 * Responsibilities:
 *
 * - Normalize tier data.
 * - Resolve selling and purchase prices.
 * - Apply selling tiers to the unified pricing engine.
 * - Synchronize Promi price structures.
 * - Replace selling / purchase / combined tiers.
 * - Keep the WooCommerce regular price synchronized with the lowest
 *   available selling tier.
 *
 * Direct database access belongs in PriceRepository.
 */
final class TieredPricing {

	private PriceRepository $repository;
	private CostCalculator $costs;


	public function __construct(
		PriceRepository $repository,
		CostCalculator $costs
	) {
		$this->repository = $repository;
		$this->costs = $costs;
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
	 * If an applicable selling tier exists, it replaces that price.
	 * Otherwise the incoming price is returned unchanged.
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


		/*
		|--------------------------------------------------------------------------
		| Case 3
		|--------------------------------------------------------------------------
		|
		| A zero price here is intentional.
		|
		| CartPricing separately sees the price_on_request status and prevents
		| printing from creating a fake priced cart item.
		*/

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


	public function article_price(
		int $product_id,
		int $variation_id,
		int $quantity
	): array {

		return $this->costs
			->calculate(
				$product_id,
				$variation_id,
				$quantity
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Selling Prices
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return the applicable selling price.
	 */
	public function selling_price(
		int $product_id,
		int $variation_id = 0,
		int $quantity = 1
	): ?float {

		$result =
			$this->costs
				->calculate(
					$product_id,
					$variation_id,
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
	 * Return all applicable selling tiers.
	 *
	 * Variation tiers are returned when present.
	 * Otherwise parent tiers are returned.
	 */
	public function selling_tiers(
		int $product_id,
		int $variation_id = 0
	): array {

		return $this->repository
			->get_selling_tiers(
				$product_id,
				$variation_id
			);
	}


	/**
	 * Replace selling tiers while preserving purchasing values at matching
	 * quantities.
	 */
	public function replace_selling(
		int $product_id,
		int $variation_id,
		array $tiers,
		bool $update_wc_price = true
	): bool {

		$product_id = absint(
			$product_id
		);

		$variation_id = absint(
			$variation_id
		);

		if ( ! $product_id ) {
			return false;
		}

		$clean = $this->normalize_selling_tiers(
			$tiers
		);

		$result = $this->repository
			->replace_selling(
				$product_id,
				$variation_id,
				$clean
			);

		if (
			$result
			&& $update_wc_price
			&& ! empty( $clean )
		) {
			$this->sync_woocommerce_price(
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
	 * Return the applicable purchasing price.
	 */
	public function purchase_price(
		int $product_id,
		int $variation_id = 0,
		int $quantity = 1
	): ?float {

		return $this->repository
			->get_applicable_purchase_price(
				$product_id,
				$variation_id,
				$quantity
			);
	}


	/**
	 * Return all applicable purchasing tiers.
	 */
	public function purchase_tiers(
		int $product_id,
		int $variation_id = 0
	): array {

		return $this->repository
			->get_purchase_tiers(
				$product_id,
				$variation_id
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

		$product_id = absint(
			$product_id
		);

		$variation_id = absint(
			$variation_id
		);

		if ( ! $product_id ) {
			return false;
		}

		$clean = $this->normalize_purchase_tiers(
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
	 */
	public function replace_all(
		int $product_id,
		int $variation_id,
		array $tiers,
		bool $update_wc_price = true
	): bool {

		$product_id = absint(
			$product_id
		);

		$variation_id = absint(
			$variation_id
		);

		if ( ! $product_id ) {
			return false;
		}

		$clean = $this->normalize_combined_tiers(
			$tiers
		);

		$result = $this->repository
			->replace(
				$product_id,
				$variation_id,
				$clean
			);

		if (
			$result
			&& $update_wc_price
			&& ! empty( $clean )
		) {
			$this->sync_woocommerce_price(
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

		$result = $this->replace_all(
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
	 */
	private function normalize_promi_prices(
		mixed $selling,
		mixed $purchase
	): array {

		if ( ! is_array( $selling ) ) {
			return [];
		}

		$purchase_by_quantity = [];

		if ( is_array( $purchase ) ) {

			foreach ( $purchase as $row ) {

				if ( ! is_array( $row ) ) {
					continue;
				}

				/*
				 * Promi can mark pricing as "OnRequest". Such entries do not
				 * represent a usable numeric tier.
				 */
				if (
					! empty(
						$row['OnRequest']
					)
				) {
					continue;
				}

				$quantity = absint(
					$row['Quantity']
						?? 0
				);

				$price = $this->normalize_price(
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

				$purchase_by_quantity[
					$quantity
				] = $price;
			}
		}


		$tiers = [];

		foreach ( $selling as $row ) {

			if ( ! is_array( $row ) ) {
				continue;
			}

			if (
				! empty(
					$row['OnRequest']
				)
			) {
				continue;
			}

			$quantity = absint(
				$row['Quantity']
					?? 0
			);

			$price = $this->normalize_price(
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

			$tiers[
				$quantity
			] = [
				'qty' =>
					$quantity,

				'price' =>
					$price,

				'purchase_price' =>
					$purchase_by_quantity[
						$quantity
					] ?? null,
			];
		}

		ksort(
			$tiers,
			SORT_NUMERIC
		);

		return array_values(
			$tiers
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Quantities
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return unique quantities.
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
				$product_id,
				$variation_id
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

		foreach ( $quantities as $quantity ) {

			$quantity = absint(
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
	 */
	private function normalize_combined_tiers(
		array $tiers
	): array {

		$clean = [];

		foreach ( $tiers as $tier ) {

			if ( ! is_array( $tier ) ) {
				continue;
			}

			$quantity = absint(
				$tier['qty']
					?? $tier['quantity']
					?? 0
			);

			$price = $this->normalize_price(
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
				|| null === $price
				|| $price <= 0
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
	 */
	private function normalize_selling_tiers(
		array $tiers
	): array {

		$clean = [];

		foreach ( $tiers as $tier ) {

			if ( ! is_array( $tier ) ) {
				continue;
			}

			$quantity = absint(
				$tier['qty']
					?? $tier['quantity']
					?? 0
			);

			$price = $this->normalize_price(
				$tier['price']
					?? null
			);

			if (
				! $quantity
				|| null === $price
				|| $price <= 0
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

		foreach ( $tiers as $key => $tier ) {

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

				$quantity = absint(
					$tier['qty']
						?? $tier['quantity']
						?? 0
				);

				$price = $this->normalize_price(
					$tier['purchase_price']
						?? $tier['price']
						?? null
				);

			} else {

				$quantity = absint(
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
			] = $price;
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

		$value = wc_format_decimal(
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
	 * Keep WooCommerce's stored product price aligned with the lowest
	 * selling tier.
	 *
	 * The tier table remains authoritative for quantity-dependent pricing;
	 * WooCommerce's normal price is primarily useful for catalog display,
	 * indexing, sorting and fallback behavior.
	 */
	private function sync_woocommerce_price(
		int $product_id,
		int $variation_id,
		array $tiers
	): void {

		if ( empty( $tiers ) ) {
			return;
		}

		$prices = [];

		foreach ( $tiers as $tier ) {

			if (
				isset(
					$tier['price']
				)
				&& is_numeric(
					$tier['price']
				)
			) {
				$prices[] =
					(float)
						$tier['price'];
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

		$current_regular =
			(string)
				$product
					->get_regular_price();

		$current_price =
			(string)
				$product
					->get_price();

		$formatted =
			wc_format_decimal(
				$lowest_price,
				wc_get_price_decimals()
			);

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
			 * Variation price changes can affect the parent's WooCommerce
			 * min/max price cache.
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

		$minimum = max(
			1,
			absint(
				$country_data[
					'MinimumOrderQuantity'
				] ?? 1
			)
		);

		$increment = max(
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