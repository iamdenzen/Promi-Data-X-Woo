<?php

namespace PromiDataXWoo\Frontend;

use PromiDataXWoo\Printing\Printing;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;

defined( 'ABSPATH' ) || exit;

/**
 * Frontend-facing price-calculator data.
 *
 * Powers xsimpress-core's [xsimpress_price_calculator] shortcode, and any
 * later calculator layouts built on the same data: resolving a small
 * product set, exposing a product's first print position's options, and
 * computing the product + print-option price breakdown for a given
 * quantity/option.
 *
 * This is presentation-adjacent plumbing on top of ProductData/Printing.
 * The actual pricing rules still live in Pricing and Printing\Calculator;
 * nothing here recalculates markups, fees or tiers on its own.
 */
final class PriceCalculator {

	private ProductData $product_data;

	private Printing $printing;


	public function __construct(
		ProductData $product_data,
		Printing $printing
	) {
		$this->product_data = $product_data;
		$this->printing      = $printing;
	}


	/*
	|--------------------------------------------------------------------------
	| Product Resolution
	|--------------------------------------------------------------------------
	*/

	/**
	 * Resolve the small product set a calculator instance displays.
	 *
	 * Explicit product IDs take precedence, then SKUs, then the
	 * best-selling published products.
	 *
	 * Variable products are resolved to one representative variation
	 * (the first in the normal attribute-ordered list) since this
	 * calculator has no attribute-selection UI of its own.
	 *
	 * Price-on-request products are skipped entirely; a quick calculator
	 * has no "Preis auf Anfrage" state to show.
	 *
	 * @param array<int,int|string> $ids
	 * @param array<int,string>     $skus
	 * @return array<int,array{product_id:int,variation_id:int,name:string,image:?string,sku:string}>
	 */
	public function resolve_products(
		array $ids,
		array $skus,
		int $limit = 5
	): array {

		$limit = max( 1, $limit );

		$product_ids = [];

		if ( ! empty( $ids ) ) {

			$product_ids = array_values(
				array_filter(
					array_map( 'absint', $ids )
				)
			);

		} elseif ( ! empty( $skus ) ) {

			foreach ( $skus as $sku ) {

				$sku = trim( (string) $sku );

				if ( '' === $sku ) {
					continue;
				}

				$product_id = wc_get_product_id_by_sku( $sku );

				if ( $product_id ) {
					$product_ids[] = $product_id;
				}
			}
		}

		if ( empty( $product_ids ) ) {

			$products = wc_get_products(
				[
					'status'  => 'publish',
					// Extra headroom: price-on-request/unresolvable items
					// get dropped below, so ask for more than $limit.
					'limit'   => $limit * 3,
					'orderby' => 'popularity',
					'order'   => 'DESC',
					'return'  => 'objects',
				]
			);

			foreach ( $products as $product ) {
				$product_ids[] = $product->get_id();
			}
		}

		$items = [];

		foreach ( $product_ids as $product_id ) {

			if ( count( $items ) >= $limit ) {
				break;
			}

			$item = $this->resolve_calculator_item(
				absint( $product_id )
			);

			if ( $item ) {
				$items[] = $item;
			}
		}

		return $items;
	}


	/**
	 * Resolve one product/variation pair plus its display data.
	 *
	 * @return array{product_id:int,variation_id:int,name:string,image:?string,sku:string}|null
	 */
	private function resolve_calculator_item(
		int $product_id
	): ?array {

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		if ( $product instanceof WC_Product_Variation ) {

			$product_id = $product->get_parent_id();
			$product    = wc_get_product( $product_id );

			if ( ! $product instanceof WC_Product ) {
				return null;
			}
		}

		[ $target, $variation_id ] =
			$this->resolve_pricing_target( $product );

		if ( ! $target instanceof WC_Product ) {
			return null;
		}

		if (
			$this->product_data->is_price_on_request(
				$product_id,
				$variation_id
			)
		) {
			return null;
		}

		$image_id =
			$target->get_image_id()
				?: $product->get_image_id();

		return [
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'name'         => $product->get_name(),
			'image'        => $image_id
				? ( wp_get_attachment_image_url( $image_id, 'thumbnail' ) ?: null )
				: null,
			'sku'          => (string) $target->get_sku(),
		];
	}


	/**
	 * Resolve the WC_Product (parent or variation) and variation ID used
	 * for pricing/printing lookups.
	 *
	 * Variable products resolve to their first attribute-ordered
	 * variation; simple products use themselves with variation_id = 0.
	 *
	 * @return array{0:?WC_Product,1:int}
	 */
	private function resolve_pricing_target(
		WC_Product $product
	): array {

		if ( ! $product instanceof WC_Product_Variable ) {
			return [ $product, 0 ];
		}

		$variation_ids =
			$this->product_data->variation_ids( $product );

		$variation_id = (int) ( $variation_ids[0] ?? 0 );

		if ( ! $variation_id ) {
			return [ null, 0 ];
		}

		$variation = wc_get_product( $variation_id );

		if ( ! $variation instanceof WC_Product_Variation ) {
			return [ null, 0 ];
		}

		return [ $variation, $variation_id ];
	}


	/*
	|--------------------------------------------------------------------------
	| Print Position / Options
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return the first available print position + its options for a
	 * product, in the shape the calculator UI needs.
	 *
	 * @return array{id:int,label:string,options:array<int,array{id:int,name:string,min_order_qty:int}>}|null
	 */
	public function first_print_position(
		int $product_id,
		int $variation_id = 0
	): ?array {

		$config = $this->product_data->printing_config(
			$product_id,
			$variation_id
		);

		if ( empty( $config ) ) {
			return null;
		}

		$position_id = array_key_first( $config );
		$position    = $config[ $position_id ];

		$options = [];

		foreach ( $position['options'] ?? [] as $option_id => $option ) {

			$options[] = [
				'id'            => (int) $option_id,

				'name'          => (string) (
					$option['name'] ?? ''
				),

				'min_order_qty' => max(
					1,
					absint( $option['min_order_qty'] ?? 1 )
				),
			];
		}

		if ( empty( $options ) ) {
			return null;
		}

		return [
			'id'      => (int) $position_id,
			'label'   => (string) ( $position['label'] ?? '' ),
			'options' => $options,
		];
	}


	/*
	|--------------------------------------------------------------------------
	| Price Quote
	|--------------------------------------------------------------------------
	*/

	/**
	 * Compute the full calculator state for one product at a given (or
	 * defaulted) quantity/print option.
	 *
	 * This is the single source of truth used both by a calculator
	 * shortcode's first render and by the AJAX endpoint that updates it
	 * live, so the two can never disagree.
	 *
	 * @return array{
	 *     product_id:int,
	 *     variation_id:int,
	 *     min_order_qty:int,
	 *     qty_increment:int,
	 *     quantity:int,
	 *     position:?array{id:int,label:string},
	 *     options:array<int,array{id:int,name:string,min_order_qty:int}>,
	 *     selected_option_id:int,
	 *     product_unit_price:float,
	 *     option_unit_price:float,
	 *     unit_price:float,
	 *     fees:float,
	 *     total:float,
	 *     price_on_request:bool
	 * }|null
	 */
	public function quote(
		int $product_id,
		?int $quantity = null,
		?int $option_id = null
	): ?array {

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		if ( $product instanceof WC_Product_Variation ) {
			$product_id = $product->get_parent_id();
			$product    = wc_get_product( $product_id );
		}

		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		[ $target, $variation_id ] =
			$this->resolve_pricing_target( $product );

		if ( ! $target instanceof WC_Product ) {
			return null;
		}


		/*
		|--------------------------------------------------------------------------
		| Print Position / Option
		|--------------------------------------------------------------------------
		*/

		$position = $this->first_print_position(
			$product_id,
			$variation_id
		);

		$options = $position['options'] ?? [];

		$selected_option = null;

		if ( $option_id ) {

			foreach ( $options as $option ) {

				if ( $option['id'] === $option_id ) {
					$selected_option = $option;
					break;
				}
			}
		}

		if ( ! $selected_option && ! empty( $options ) ) {
			$selected_option = $options[0];
		}


		/*
		|--------------------------------------------------------------------------
		| Quantity Bounds
		|--------------------------------------------------------------------------
		|
		| min_order_qty is a per-product/variation meta value; a selected
		| print option can raise that floor further, never lower it.
		*/

		$min_order_qty =
			$this->product_data->minimum_order_quantity( $target );

		$qty_increment =
			$this->product_data->quantity_increment( $target );

		if ( $selected_option ) {

			$min_order_qty = max(
				$min_order_qty,
				$selected_option['min_order_qty']
			);
		}

		$quantity = max(
			$min_order_qty,
			absint( $quantity ?? $min_order_qty )
		);


		/*
		|--------------------------------------------------------------------------
		| Product Price
		|--------------------------------------------------------------------------
		*/

		$price_on_request =
			$this->product_data->is_price_on_request(
				$product_id,
				$variation_id
			);

		$product_unit_price =
			$price_on_request
				? 0.0
				: (float) (
					$this->product_data->price(
						$product_id,
						$variation_id,
						$quantity
					) ?? 0.0
				);


		/*
		|--------------------------------------------------------------------------
		| Print Option Price
		|--------------------------------------------------------------------------
		|
		| calculate_selection() already applies the same finishing markup
		| and setup/ongoing rounding rules as the product-page configurator
		| and the cart, so this stays correct as those rules change.
		*/

		$option_unit_price = 0.0;
		$fees               = 0.0;

		if ( $selected_option ) {

			$breakdown = $this->printing
				->calculator()
				->calculate_selection(
					$selected_option['id'],
					$quantity,
					[
						'product_id'   => $product_id,
						'variation_id' => $variation_id,
						'colors'       => 1,
						'positions'    => 1,
					]
				);

			$option_unit_price =
				(float) ( $breakdown['unit_price'] ?? 0.0 );

			$fees =
				(float) ( $breakdown['fees'] ?? 0.0 );
		}

		$unit_price = $product_unit_price + $option_unit_price;
		$total      = ( $unit_price * $quantity ) + $fees;

		return [
			'product_id'         => $product_id,
			'variation_id'       => $variation_id,
			'min_order_qty'      => $min_order_qty,
			'qty_increment'      => $qty_increment,
			'quantity'           => $quantity,

			'position'           => $position
				? [
					'id'    => $position['id'],
					'label' => $position['label'],
				]
				: null,

			'options'            => $options,
			'selected_option_id' => $selected_option['id'] ?? 0,

			'product_unit_price' => $product_unit_price,
			'option_unit_price'  => $option_unit_price,
			'unit_price'         => $unit_price,
			'fees'               => $fees,
			'total'              => $total,

			'price_on_request'   => $price_on_request,
		];
	}
}
