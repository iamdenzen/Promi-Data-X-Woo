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
 * (cx_tier_prices.purchase_price): a supplier price overwrites it when the
 * supplier has one for that quantity tier, but an existing price is never
 * cleared just because the supplier doesn't have one to offer.
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
	 * Supplier price wins where the supplier has one; an existing price is
	 * never cleared just because the supplier doesn't.
	 *
	 * purchase_price is a quantity-break "price ladder" (min_qty => price).
	 * For each of Promi's own quantity tiers (via ProductSync/TieredPricing)
	 * on this product: if the supplier has an applicable price — the value
	 * at the highest ladder threshold not exceeding that tier's own
	 * quantity — it overwrites whatever purchase price that tier had.
	 * Otherwise the tier's existing purchase price (if any) is kept as-is.
	 * Products with no existing tiers at all are left untouched.
	 */
	private function apply_purchase_price( WC_Product $product, array $data ): bool {

		$ladder = $data['purchase_price'] ?? [];

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
		$changed  = false;

		foreach ( $targets as $qty ) {

			$supplier_price = $this->price_for_quantity( $ladder, $qty );

			if ( null !== $supplier_price ) {

				$combined[ $qty ] = $supplier_price;

				if (
					! isset( $existing[ $qty ] )
					|| abs( (float) $existing[ $qty ] - $supplier_price ) > 0.0001
				) {
					$changed = true;
				}

				continue;
			}

			if ( isset( $existing[ $qty ] ) ) {
				$combined[ $qty ] = $existing[ $qty ];
			}
		}

		if ( ! $changed ) {
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
