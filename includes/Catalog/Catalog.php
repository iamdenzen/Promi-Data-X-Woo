<?php

namespace PromiDataXWoo\Catalog;

use PromiDataXWoo\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce catalog module.
 *
 * Coordinates catalog-domain services used throughout Promi-Data X Woo:
 *
 * - Products
 * - Global WooCommerce attributes and terms
 * - WooCommerce brands
 * - Product categories / Promi category mapping
 *
 * Promi consumes this module when translating remote product data into
 * WooCommerce entities.
 *
 * Pricing and Printing may also consume catalog services, but Catalog does
 * not depend on either of those modules.
 */
final class Catalog {

	private Plugin $plugin;

	private Products $products;

	private Attributes $attributes;

	private Brands $brands;

	private Categories $categories;

	private bool $initialized = false;


	public function __construct(
		Plugin $plugin
	) {
		$this->plugin = $plugin;

		$this->register_services();
	}


	/**
	 * Build the catalog service graph.
	 */
	private function register_services(): void {

		/*
		|--------------------------------------------------------------------------
		| Products
		|--------------------------------------------------------------------------
		|
		| General WooCommerce product helpers:
		|
		| - SKU lookups
		| - Bulk SKU lookups
		| - Product dimensions
		| - Weight
		| - Promi package/carton metadata
		*/

		$this->products =
			new Products();


		/*
		|--------------------------------------------------------------------------
		| Attributes
		|--------------------------------------------------------------------------
		|
		| Owns global WooCommerce attribute taxonomies and their terms.
		|
		| ProductSync relies on this for:
		|
		| - prepare_product_attribute()
		| - term()
		| - normalize_color()
		*/

		$this->attributes =
			new Attributes();


		/*
		|--------------------------------------------------------------------------
		| Brands
		|--------------------------------------------------------------------------
		|
		| XSImpress uses WooCommerce's native product_brand taxonomy.
		|
		| We deliberately do not introduce a separate manufacturer taxonomy.
		*/

		$this->brands =
			new Brands();


		/*
		|--------------------------------------------------------------------------
		| Categories
		|--------------------------------------------------------------------------
		|
		| Promi products identify categories using Promi category keys.
		|
		| Categories resolves those keys against WooCommerce product_cat
		| terms using the existing cx_category_key term metadata.
		*/

		$this->categories =
			new Categories();
	}


	/**
	 * Initialize catalog functionality.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;


		/*
		|--------------------------------------------------------------------------
		| Service Initialization
		|--------------------------------------------------------------------------
		|
		| Most catalog services are deliberately lightweight and perform
		| their work only when called.
		|
		| init() still gives each service a place to register hooks where
		| needed without moving those hooks into this coordinator.
		*/

		$this->products->init();

		$this->attributes->init();

		$this->brands->init();

		$this->categories->init();


		do_action(
			'pdxw_catalog_init',
			$this
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Product Access
	|--------------------------------------------------------------------------
	*/

	/**
	 * Retrieve a WooCommerce product.
	 */
	public function product(
		int $product_id
	): ?\WC_Product {

		$product_id =
			absint(
				$product_id
			);

		if ( ! $product_id ) {
			return null;
		}

		$product =
			wc_get_product(
				$product_id
			);

		return $product instanceof \WC_Product
			? $product
			: null;
	}


	/**
	 * Retrieve a WooCommerce product by SKU.
	 */
	public function product_by_sku(
		string $sku
	): ?\WC_Product {

		$product_id =
			$this->products
				->id_by_sku(
					$sku
				);

		if ( ! $product_id ) {
			return null;
		}

		return $this->product(
			$product_id
		);
	}


	/**
	 * Retrieve a product ID by SKU.
	 *
	 * Convenience wrapper for code that does not need the full service.
	 */
	public function product_id_by_sku(
		string $sku
	): int {

		return $this->products
			->id_by_sku(
				$sku
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Services
	|--------------------------------------------------------------------------
	*/

	public function products(): Products {
		return $this->products;
	}


	public function attributes(): Attributes {
		return $this->attributes;
	}


	public function brands(): Brands {
		return $this->brands;
	}


	public function categories(): Categories {
		return $this->categories;
	}


	/*
	|--------------------------------------------------------------------------
	| Module
	|--------------------------------------------------------------------------
	*/

	public function plugin(): Plugin {
		return $this->plugin;
	}


	/*
	|--------------------------------------------------------------------------
	| State
	|--------------------------------------------------------------------------
	*/

	public function is_initialized(): bool {
		return $this->initialized;
	}
}