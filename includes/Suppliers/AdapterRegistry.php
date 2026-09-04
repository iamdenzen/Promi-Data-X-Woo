<?php

namespace PromiDataXWoo\Suppliers;

use PromiDataXWoo\Suppliers\A34\PfConceptAdapter;
use PromiDataXWoo\Suppliers\A36\MidOceanAdapter;
use PromiDataXWoo\Suppliers\A58\GivingEuropeAdapter;
use PromiDataXWoo\Suppliers\Contracts\SupplierAdapter;
use PromiDataXWoo\Suppliers\Support\HttpJson;

defined( 'ABSPATH' ) || exit;

/**
 * Hardcoded map of adapter_key => adapter implementation.
 *
 * A source row's sku_prefix is free text (a source can exist before any
 * code for it is written); adapter_key is what actually binds it to one
 * of these classes. filterable so a future supplier can be added by a
 * separate must-use plugin without editing this file, but the three
 * suppliers we have today are deliberately just hardcoded here rather
 * than driven by admin-entered configuration — their APIs are too
 * different from each other for that to simplify anything.
 */
final class AdapterRegistry {

	private Logger $logger;

	private HttpJson $http;

	public function __construct( Logger $logger, HttpJson $http ) {
		$this->logger = $logger;
		$this->http   = $http;
	}


	/**
	 * @return array<string,array{label:string,prefix:string,class:class-string<SupplierAdapter>}>
	 */
	public function map(): array {

		$map = [
			'giving_europe' => [
				'label'  => __( 'Giving Europe', 'promi-data-x-woo' ),
				'prefix' => 'A58-',
				'class'  => GivingEuropeAdapter::class,
			],

			'midocean' => [
				'label'  => __( 'MidOcean', 'promi-data-x-woo' ),
				'prefix' => 'A36-',
				'class'  => MidOceanAdapter::class,
			],

			'pfconcept' => [
				'label'  => __( 'PFConcept', 'promi-data-x-woo' ),
				'prefix' => 'A34-',
				'class'  => PfConceptAdapter::class,
			],
		];

		/**
		 * Filter the available supplier adapters.
		 *
		 * @param array $map
		 */
		return apply_filters( 'pdxw_supplier_adapters', $map );
	}


	/**
	 * Instantiate the adapter registered under one key.
	 */
	public function resolve( string $key ): ?SupplierAdapter {

		$key = sanitize_key( $key );

		if ( '' === $key ) {
			return null;
		}

		$map = $this->map();

		if ( ! isset( $map[ $key ]['class'] ) ) {
			return null;
		}

		$class = $map[ $key ]['class'];

		if ( ! class_exists( $class ) ) {
			return null;
		}

		return new $class( $this->logger, $this->http );
	}
}
