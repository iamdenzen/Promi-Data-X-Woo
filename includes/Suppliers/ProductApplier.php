<?php

namespace PromiDataXWoo\Suppliers;

use PromiDataXWoo\Pricing\Pricing;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Applies one normalized supplier row onto an existing WooCommerce
 * product or variation.
 *
 * This is the only place supplier data is written to WooCommerce. The
 * allow-list here (stock, delivery, purchase price) is deliberate: nothing
 * else Promi owns (title, images, attributes, description, ...) is ever
 * touched from this module.
 *
 * Stock and delivery time have no Promi equivalent, so a supplier value
 * always wins. Purchase price does have a Promi equivalent
 * (cx_tier_prices.purchase_price), so it is only written where Promi left
 * a gap — an existing quantity tier with no purchase price yet.
 */
final class ProductApplier {

	private Pricing $pricing;

	public function __construct( Pricing $pricing ) {
		$this->pricing = $pricing;
	}


	/**
	 * @param array{
	 *     stock_quantity:?int,
	 *     in_stock:?bool,
	 *     delivery_days:?int,
	 *     delivery_text:?string,
	 *     purchase_price:?float
	 * } $data
	 *
	 * @return bool Whether anything was actually changed.
	 */
	public function apply( int $product_id, array $data ): bool {

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return false;
		}

		$changed = $this->apply_stock( $product, $data );

		$changed = $this->apply_delivery( $product, $data ) || $changed;

		if ( $changed ) {
			$product->save();
		}

		$changed = $this->apply_purchase_price( $product, $data ) || $changed;

		return $changed;
	}


	private function apply_stock( WC_Product $product, array $data ): bool {

		$changed = false;

		$stock_quantity = $data['stock_quantity'] ?? null;
		$in_stock       = $data['in_stock'] ?? null;

		if ( null !== $stock_quantity ) {

			$product->set_manage_stock( true );
			$product->set_stock_quantity( $stock_quantity );

			$changed = true;
		}

		if ( null !== $in_stock ) {

			$product->set_stock_status(
				$in_stock ? 'instock' : 'outofstock'
			);

			$changed = true;

		} elseif ( null !== $stock_quantity ) {

			$product->set_stock_status(
				$stock_quantity > 0 ? 'instock' : 'outofstock'
			);
		}

		return $changed;
	}


	private function apply_delivery( WC_Product $product, array $data ): bool {

		$changed = false;

		$delivery_days = $data['delivery_days'] ?? null;
		$delivery_text = $data['delivery_text'] ?? null;

		if ( null !== $delivery_days ) {

			$product->update_meta_data(
				'_supplier_delivery_days',
				absint( $delivery_days )
			);

			$changed = true;
		}

		if ( is_string( $delivery_text ) && '' !== $delivery_text ) {

			$product->update_meta_data(
				'_supplier_delivery_text',
				sanitize_text_field( $delivery_text )
			);

			$changed = true;
		}

		return $changed;
	}


	/**
	 * Fill missing purchase-price tiers only.
	 *
	 * A supplier price is only ever attached to quantity tiers that Promi
	 * already created (via ProductSync/TieredPricing) but left without a
	 * purchase price. Products with no existing tiers at all, and tiers
	 * that already have a purchase price, are left untouched.
	 *
	 * purchase_price is a quantity-break "price ladder" (min_qty => price).
	 * For each gap tier, the applicable price is the one at the highest
	 * ladder threshold that does not exceed that tier's own quantity — a
	 * standard price-break lookup. A tier whose quantity is smaller than
	 * every ladder threshold is left unfilled.
	 */
	private function apply_purchase_price( WC_Product $product, array $data ): bool {

		$ladder = $data['purchase_price'] ?? [];

		if ( empty( $ladder ) ) {
			return false;
		}

		ksort( $ladder );

		$repository = $this->pricing->repository();

		$product_id   = $product->get_parent_id() ?: $product->get_id();
		$variation_id = $product->get_parent_id() ? $product->get_id() : 0;

		$targets = $repository->get_target_quantities( $product_id, $variation_id );

		if ( empty( $targets ) ) {
			return false;
		}

		$existing = $repository->get_purchase_prices_by_quantity( $product_id, $variation_id );

		$combined = [];
		$filled   = false;

		foreach ( $targets as $qty ) {

			if ( isset( $existing[ $qty ] ) ) {
				$combined[ $qty ] = $existing[ $qty ];
				continue;
			}

			$price = $this->price_for_quantity( $ladder, $qty );

			if ( null === $price ) {
				continue;
			}

			$combined[ $qty ] = $price;
			$filled           = true;
		}

		if ( ! $filled ) {
			return false;
		}

		return $repository->replace_purchase( $product_id, $variation_id, $combined );
	}


	/**
	 * Resolve the applicable price for one target quantity from a
	 * min_qty => price ladder, sorted ascending by quantity.
	 *
	 * @param array<int,float> $ladder
	 */
	private function price_for_quantity( array $ladder, int $qty ): ?float {

		$price = null;

		foreach ( $ladder as $threshold => $ladder_price ) {

			if ( $threshold > $qty ) {
				break;
			}

			$price = $ladder_price;
		}

		return ( null !== $price && $price > 0 ) ? $price : null;
	}
}
