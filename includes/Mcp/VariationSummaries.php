<?php

namespace PromiDataXWoo\Mcp;

use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Shared "list every variation of a product" building block for the MCP
 * product-level tools.
 *
 * Does not implement any external interface, so — unlike the Tool classes
 * — it's always safe to load regardless of whether the MCP Server
 * framework is active.
 *
 * The parent product is deliberately identified by name/SKU only. This
 * store never stores pricing or print configuration at the parent level —
 * both are always per-variation — so callers must not attach either kind
 * of data to the "parent" entry.
 */
final class VariationSummaries {

	/**
	 * @return null|array{
	 *     parent:array{product_id:int,name:string,sku:string},
	 *     variations:array<int,array{variation_id:int,sku:string,name:string,attributes:array<string,string>}>
	 * }
	 */
	public static function for_product(
		int $product_id
	): ?array {

		$product = wc_get_product(
			$product_id
		);

		if (
			! $product instanceof WC_Product_Variable
		) {
			return null;
		}

		$variations = [];

		foreach (
			$product->get_children()
				as $variation_id
		) {

			$variation = wc_get_product(
				$variation_id
			);

			if (
				! $variation instanceof WC_Product_Variation
			) {
				continue;
			}

			$variations[] = [
				'variation_id' =>
					$variation->get_id(),

				'sku' =>
					(string) $variation->get_sku(),

				'name' =>
					(string) $variation->get_attribute_summary(),

				'attributes' =>
					self::readable_attributes(
						$variation
					),
			];
		}

		return [
			'parent'     =>
				self::parent_summary(
					$product
				),

			'variations' =>
				$variations,
		];
	}


	/**
	 * Identity-only fields for the parent product — deliberately excludes
	 * price/printing data, which never exists at this level.
	 */
	private static function parent_summary(
		WC_Product $product
	): array {

		return [
			'product_id' =>
				$product->get_id(),

			'name' =>
				(string) $product->get_name(),

			'sku' =>
				(string) $product->get_sku(),
		];
	}


	/**
	 * Human-readable "Attribute label => term label" pairs for one
	 * variation, e.g. {"Color": "Red", "Size": "M"}.
	 */
	private static function readable_attributes(
		WC_Product_Variation $variation
	): array {

		$result = [];

		foreach (
			$variation->get_attributes()
				as $taxonomy => $slug
		) {

			$slug = (string) $slug;

			if ( '' === $slug ) {
				continue;
			}

			$is_taxonomy = taxonomy_exists(
				$taxonomy
			);

			$label = $is_taxonomy
				? wc_attribute_label(
					$taxonomy
				)
				: ucfirst(
					str_replace(
						[ 'pa_', '_', '-' ],
						[ '', ' ', ' ' ],
						(string) $taxonomy
					)
				);

			$value = $slug;

			if ( $is_taxonomy ) {

				$term = get_term_by(
					'slug',
					$slug,
					$taxonomy
				);

				if ( $term instanceof WP_Term ) {
					$value = $term->name;
				}
			}

			$result[ $label ] = (string) $value;
		}

		return $result;
	}
}
