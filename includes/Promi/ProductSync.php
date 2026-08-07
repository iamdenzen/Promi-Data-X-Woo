<?php

namespace PromiDataXWoo\Promi;

use PromiDataXWoo\Catalog\Catalog;
use PromiDataXWoo\Pricing\Pricing;
use PromiDataXWoo\Printing\Printing;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;

defined( 'ABSPATH' ) || exit;

final class ProductSync {

	private Catalog $catalog;
	private Pricing $pricing;
	private Printing $printing;
	private Logger $logger;

	public function __construct(
		Catalog $catalog,
		Pricing $pricing,
		Printing $printing,
		Logger $logger
	) {
		$this->catalog  = $catalog;
		$this->pricing  = $pricing;
		$this->printing = $printing;
		$this->logger   = $logger;
	}


	/**
	 * Create or update a Promi product.
	 *
	 * @return int WooCommerce parent product ID.
	 */
	public function sync(
		array $data,
		string $action = Queue::ACTION_UPDATE
	): int {

		$parent_sku = sanitize_text_field(
			$data['Sku'] ?? ''
		);

		if ( '' === $parent_sku ) {
			throw new \RuntimeException(
				'Promi product is missing its SKU.'
			);
		}

		$this->logger->info(
			'Syncing Promi product.',
			[
				'sku'    => $parent_sku,
				'action' => $action,
			]
		);

		$data = $this->normalize_data(
			$data,
			$parent_sku
		);

		$product = $this->get_or_create_product(
			$parent_sku
		);

		$product_id = $product->get_id();

		$is_new = Queue::ACTION_CREATE === $action;

		$this->sync_basic_product_data(
			$product,
			$data,
			$is_new
		);

		$this->sync_product_attributes(
			$product,
			$data
		);

		$product->save();

		/**
		 * Global print methods must exist before variation-position relations
		 * are imported.
		 */
		$this->printing->sync_global_options(
			$data['ImprintReferences'] ?? []
		);

		$this->sync_variations(
			$product,
			$data
		);

		/**
		 * Existing importer intentionally postpones image processing because
		 * image downloading is much more expensive than product data syncing.
		 */
		$product->update_meta_data(
			'_cx_need_to_sync_images',
			1
		);

		/**
		 * A product returning to the feed should no longer be considered
		 * Promi-disabled.
		 */
		$product->delete_meta_data(
			'cx_promi_disabled_product'
		);

		$product->save();

		WC_Product_Variable::sync(
			$product_id
		);

		wc_delete_product_transients(
			$product_id
		);

		do_action(
			'pdxw_promi_product_synced',
			$product_id,
			$data,
			$action
		);

		$this->logger->info(
			'Promi product synchronized.',
			[
				'product_id' => $product_id,
				'sku'        => $parent_sku,
			]
		);

		return $product_id;
	}


	/**
	 * Disable a product which has disappeared from Promi.
	 */
	public function disable(
		string $sku
	): bool {

		$sku = sanitize_text_field(
			$sku
		);

		if ( '' === $sku ) {
			return false;
		}

		$product_id = wc_get_product_id_by_sku(
			$sku
		);

		if ( ! $product_id ) {

			$this->logger->warning(
				'Unable to disable Promi product because WooCommerce product was not found.',
				[
					'sku' => $sku,
				]
			);

			return false;
		}

		$product = wc_get_product(
			$product_id
		);

		if ( ! $product ) {
			return false;
		}

		$product->set_status( 'draft' );

		$product->update_meta_data(
			'cx_promi_disabled_product',
			1
		);

		$product->save();

		do_action(
			'pdxw_promi_product_disabled',
			$product_id,
			$sku
		);

		$this->logger->info(
			'Promi product disabled.',
			[
				'product_id' => $product_id,
				'sku'        => $sku,
			]
		);

		return true;
	}


	/**
	 * Promi occasionally exposes a child product using exactly the same SKU
	 * as its parent.
	 *
	 * Existing XSImpress behavior converts that child SKU to "{SKU}-01".
	 */
	private function normalize_data(
		array $data,
		string $parent_sku
	): array {

		$children = $data['ChildProducts'] ?? [];

		if ( ! is_array( $children ) ) {
			$data['ChildProducts'] = [];
			return $data;
		}

		foreach ( $children as &$child ) {

			if (
				isset( $child['Sku'] )
				&& (string) $child['Sku'] === $parent_sku
			) {
				$child['Sku'] = $parent_sku . '-01';
			}
		}

		unset( $child );

		$data['ChildProducts'] = $children;

		return $data;
	}


	/**
	 * Retrieve or create the parent variable product.
	 */
	private function get_or_create_product(
		string $sku
	): WC_Product_Variable {

		$product_id = wc_get_product_id_by_sku(
			$sku
		);

		if ( $product_id ) {

			$product = wc_get_product(
				$product_id
			);

			if ( ! $product ) {
				throw new \RuntimeException(
					sprintf(
						'Unable to load WooCommerce product %d.',
						$product_id
					)
				);
			}

			/**
			 * Promi products are expected to be variable products.
			 */
			if ( ! $product instanceof WC_Product_Variable ) {

				wp_set_object_terms(
					$product_id,
					'variable',
					'product_type'
				);

				$product = new WC_Product_Variable(
					$product_id
				);
			}

			do_action(
				'pdxw_promi_updating_product',
				$product_id
			);

			/**
			 * Backwards compatibility with existing XSImpress integrations.
			 */
			do_action(
				'cx_promi_updating_product',
				$product_id
			);

			return $product;
		}

		$product = new WC_Product_Variable();

		$product->set_sku(
			$sku
		);

		$product->set_status(
			'draft'
		);

		$product->save();

		do_action(
			'pdxw_promi_inserting_product',
			$product->get_id()
		);

		/**
		 * Legacy compatibility.
		 */
		do_action(
			'cx_promi_inserting_product',
			$product->get_id()
		);

		return $product;
	}


	/**
	 * Synchronize parent-level WooCommerce fields.
	 */
	private function sync_basic_product_data(
		WC_Product_Variable $product,
		array $data,
		bool $is_new
	): void {

		$details = $data['ProductDetails']['de']
			?? [];

		$other = $data['NonLanguageDependedProductDetails']
			?? [];

		$title = sanitize_text_field(
			$details['Name'] ?? ''
		);

		$short_description = wp_kses_post(
			$details['ShortDescription'] ?? ''
		);

		$description = wp_kses_post(
			$details['Description'] ?? ''
		);

		if ( '' !== $title ) {
			$product->set_name( $title );
		}

		/**
		 * Preserve existing XSImpress behavior:
		 *
		 * Promi descriptions are only placed directly onto the Woo product
		 * during creation. On later imports they are stored in staging meta,
		 * allowing the AI/SEO workflow to manage public content.
		 */
		if ( $is_new ) {

			$product->set_status(
				'draft'
			);

			$product->set_description(
				$description
			);

			$product->set_short_description(
				$short_description
			);

		} else {

			$product->delete_meta_data(
				'_cx_seo_synced_via_ai'
			);

			$product->delete_meta_data(
				'_cx_seo_synced_via_ai_at'
			);
		}

		/**
		 * Existing XSImpress staging/AI metadata.
		 */
		$product->update_meta_data(
			'_cx_staging_rank_math_title',
			$title
		);

		$product->update_meta_data(
			'_cx_staging_rank_math_description',
			$short_description
		);

		$product->update_meta_data(
			'_cx_staging_rank_math_focus_keyword',
			''
		);

		$product->update_meta_data(
			'_cx_staging_description',
			$description
		);

		$product->update_meta_data(
			'_cx_staging_short_description',
			$short_description
		);


		/**
		 * Parent pricing.
		 */
		$price_data = $this->country_price_data(
			$data
		);

		$selling_prices = $price_data['RecommendedSellingPrice']
			?? [];

		$regular_price = $this->last_available_price(
			$selling_prices
		);

		if ( null !== $regular_price ) {

			$product->set_regular_price(
				(string) $regular_price
			);

			$product->update_meta_data(
				'qty_increments',
				max(
					1,
					absint(
						$price_data['QuantityIncrements']
						?? 1
					)
				)
			);

			$product->update_meta_data(
				'min_order_qty',
				max(
					1,
					absint(
						$price_data['MinimumOrderQuantity']
						?? 1
					)
				)
			);
		}


		/**
		 * WooCommerce Brands.
		 *
		 * Important: XSImpress uses WooCommerce's `product_brand`
		 * taxonomy — NOT a separate manufacturer taxonomy.
		 */
		$brand_name = sanitize_text_field(
			$other['Brand'] ?? ''
		);

		if ( $brand_name ) {

			$this->catalog->brands()->assign(
				$product->get_id(),
				$brand_name
			);
		}


		/**
		 * Product category is resolved from the Promi category KEY,
		 * not directly from its textual category name.
		 */
		$category_key = sanitize_text_field(
			$other['Category'] ?? ''
		);

		if ( $category_key ) {

			$this->catalog->categories()->assign_by_promi_key(
				$product->get_id(),
				$category_key
			);
		}


		if ( isset( $details['WebShopInformation'] ) ) {

			$product->update_meta_data(
				'web_shop_information',
				wp_kses_post(
					$details['WebShopInformation']
				)
			);
		}


		if ( isset( $other['CountryOfOrigin'] ) ) {

			$product->update_meta_data(
				'country_of_origin',
				sanitize_text_field(
					$other['CountryOfOrigin']
				)
			);
		}


		/**
		 * Parent dimensions use parent data first and the first child as
		 * fallback, matching the existing importer.
		 */
		$children = $data['ChildProducts']
			?? [];

		$first_child_data = [];

		if (
			is_array( $children )
			&& isset( $children[0] )
		) {
			$first_child_data =
				$children[0]['NonLanguageDependedProductDetails']
				?? [];
		}

		$this->catalog->products()->apply_dimensions(
			$product,
			$other,
			$first_child_data
		);
	}


	/**
	 * Build all global WooCommerce attributes required by the variations.
	 */
	private function sync_product_attributes(
		WC_Product_Variable $product,
		array $data
	): void {

		$children = $data['ChildProducts']
			?? [];

		if (
			! is_array( $children )
			|| empty( $children )
		) {
			return;
		}

		$attributes = [];

		foreach ( $children as $child ) {

			$fields =
				$child['ProductDetails']['de']['ConfigurationFields']
				?? [];

			if ( empty( $fields ) ) {

				$sku = sanitize_text_field(
					$child['Sku'] ?? ''
				);

				$attributes['Variante'][] =
					$sku ?: 'Standard';

				continue;
			}

			foreach ( $fields as $field ) {

				$name = sanitize_text_field(
					$field['ConfigurationName']
					?? ''
				);

				$label = sanitize_text_field(
					$field['ConfigurationNameTranslated']
					?? $name
				);

				$value = $field['ConfigurationValue']
					?? '';

				if (
					'' === $label
					|| '' === (string) $value
				) {
					continue;
				}

				$value = 'Color' === $name
					? $this->catalog->attributes()->normalize_color(
						(string) $value
					)
					: sanitize_text_field(
						(string) $value
					);

				$attributes[ $label ][] =
					$value;
			}
		}

		$woo_attributes = [];

		foreach (
			$attributes as $label => $values
		) {

			$values = array_values(
				array_unique(
					array_filter(
						array_map(
							'trim',
							$values
						)
					)
				)
			);

			if ( empty( $values ) ) {
				continue;
			}

			$attribute = $this->catalog
				->attributes()
				->prepare_product_attribute(
					$product->get_id(),
					$label,
					$values
				);

			if ( $attribute ) {
				$woo_attributes[] = $attribute;
			}
		}

		if ( $woo_attributes ) {
			$product->set_attributes(
				$woo_attributes
			);
		}
	}


	/**
	 * Synchronize child products into WooCommerce variations.
	 */
	private function sync_variations(
		WC_Product_Variable $parent,
		array $data
	): void {

		$children = $data['ChildProducts']
			?? [];

		if (
			! is_array( $children )
			|| empty( $children )
		) {
			return;
		}

		$parent_id = $parent->get_id();

		$this->logger->info(
			'Syncing Promi variations.',
			[
				'product_id' => $parent_id,
				'count'      => count( $children ),
			]
		);

		$skus = [];

		foreach ( $children as $child ) {

			$sku = sanitize_text_field(
				$child['Sku'] ?? ''
			);

			if ( $sku ) {
				$skus[] = $sku;
			}
		}

		$existing_ids = $this->catalog
			->products()
			->ids_by_skus(
				array_values(
					array_unique( $skus )
				)
			);

		$seen_variation_ids = [];

		foreach ( $children as $child ) {

			$sku = sanitize_text_field(
				$child['Sku'] ?? ''
			);

			if ( ! $sku ) {
				continue;
			}

			$variation_id = $existing_ids[ $sku ]
				?? 0;

			if ( $variation_id ) {

				$variation = wc_get_product(
					$variation_id
				);

				if (
					! $variation
					|| ! $variation instanceof WC_Product_Variation
				) {
					continue;
				}

			} else {

				$variation = new WC_Product_Variation();

				$variation->set_parent_id(
					$parent_id
				);

				$variation->set_sku(
					$sku
				);
			}

			$this->sync_variation(
				$variation,
				$child,
				$data
			);

			$variation_id = $variation->save();

			if ( ! $variation_id ) {
				continue;
			}

			$seen_variation_ids[] =
				$variation_id;

			$this->sync_variation_pricing(
				$parent_id,
				$variation_id,
				$child
			);

			$this->printing->sync_positions(
				$parent_id,
				$variation_id,
				$data['ImprintReferences']
					?? [],
				$child['ImprintPositions']
					?? []
			);
		}


		/**
		 * We deliberately do NOT delete missing Woo variations here yet.
		 *
		 * Promi feeds occasionally contain partial/inconsistent child data.
		 * Variation removal should have its own safety policy rather than
		 * assuming absence from one response means permanent deletion.
		 */
		do_action(
			'pdxw_promi_variations_synced',
			$parent_id,
			$seen_variation_ids
		);
	}


	private function sync_variation(
		WC_Product_Variation $variation,
		array $child,
		array $parent_data
	): void {

		$variation->set_status(
			'publish'
		);

		$details = $child['ProductDetails']['de']
			?? [];

		$other = $child['NonLanguageDependedProductDetails']
			?? [];

		$this->catalog->products()->apply_dimensions(
			$variation,
			$other
		);


		/**
		 * Pricing.
		 */
		$price_data = $this->country_price_data(
			$child
		);

		$selling_prices = $price_data['RecommendedSellingPrice']
			?? [];

		$regular_price = $this->last_available_price(
			$selling_prices
		);

		if ( null !== $regular_price ) {

			$variation->set_regular_price(
				(string) $regular_price
			);

			$variation->update_meta_data(
				'qty_increments',
				max(
					1,
					absint(
						$price_data['QuantityIncrements']
						?? 1
					)
				)
			);

			$variation->update_meta_data(
				'min_order_qty',
				max(
					1,
					absint(
						$price_data['MinimumOrderQuantity']
						?? 1
					)
				)
			);
		}


		/**
		 * Variation attributes.
		 */
		$fields = $details['ConfigurationFields']
			?? [];

		$attributes = [];

		if ( ! empty( $fields ) ) {

			foreach ( $fields as $field ) {

				$name = sanitize_text_field(
					$field['ConfigurationName']
					?? ''
				);

				$label = sanitize_text_field(
					$field['ConfigurationNameTranslated']
					?? $name
				);

				$value = (string) (
					$field['ConfigurationValue']
					?? ''
				);

				if (
					! $label
					|| '' === $value
				) {
					continue;
				}

				if ( 'Color' === $name ) {

					$value = $this->catalog
						->attributes()
						->normalize_color(
							$value
						);
				}

				$term = $this->catalog
					->attributes()
					->term(
						$label,
						$value,
						true
					);

				if ( ! $term ) {
					continue;
				}

				$taxonomy = 'pa_'
					. sanitize_title(
						$label
					);

				$attributes[ $taxonomy ] =
					$term->slug;
			}

		} else {

			/**
			 * Existing fallback for products where Promi doesn't provide
			 * ConfigurationFields.
			 */
			$sku = $variation->get_sku();

			$term = $this->catalog
				->attributes()
				->term(
					'Variante',
					$sku ?: 'Standard',
					true
				);

			if ( $term ) {
				$attributes['pa_variante'] =
					$term->slug;
			}
		}

		$variation->set_attributes(
			$attributes
		);
	}


	private function sync_variation_pricing(
		int $product_id,
		int $variation_id,
		array $child
	): void {

		$price_data = $this->country_price_data(
			$child
		);

		$this->pricing->replace_promi_tiers(
			$product_id,
			$variation_id,
			$price_data['RecommendedSellingPrice']
				?? [],
			$price_data['GeneralBuyingPrice']
				?? []
		);
	}


	/**
	 * Promi uses DEU on most records but EURO appears on some records.
	 */
	private function country_price_data(
		array $data
	): array {

		$prices = $data['ProductPriceCountryBased']
			?? [];

		if ( ! is_array( $prices ) ) {
			return [];
		}

		return $prices['DEU']
			?? $prices['EURO']
			?? [];
	}


	/**
	 * Existing importer uses the final selling-price tier as the WooCommerce
	 * regular price.
	 *
	 * Tier pricing remains authoritative for quantity calculations.
	 */
	private function last_available_price(
		array $prices
	): ?float {

		if ( empty( $prices ) ) {
			return null;
		}

		$available = [];

		foreach ( $prices as $row ) {

			if ( ! empty( $row['OnRequest'] ) ) {
				continue;
			}

			if (
				! isset( $row['Price'] )
				|| ! is_numeric( $row['Price'] )
			) {
				continue;
			}

			$available[] = (float) $row['Price'];
		}

		if ( empty( $available ) ) {
			return null;
		}

		return $available[
			array_key_last( $available )
		];
	}
}