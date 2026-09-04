<?php

namespace PromiDataXWoo\Suppliers;

use PromiDataXWoo\Catalog\Catalog;
use PromiDataXWoo\Core\Plugin;
use PromiDataXWoo\Pricing\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Supplier integration module.
 *
 * Enriches existing Promi-imported WooCommerce products with data that
 * Promi does not reliably provide — stock, delivery time, purchase price —
 * pulled from each supplier's own JSON feed and matched onto products by
 * SKU prefix (e.g. "A58-").
 *
 * Unlike Promi, this module never creates products; it only updates a
 * fixed allow-list of fields on SKUs that already exist.
 *
 * Consumes Catalog (SKU lookup) and Pricing (purchase-price tiers).
 * Neither of those modules depends on Suppliers.
 */
final class Suppliers {

	private Plugin $plugin;

	private Catalog $catalog;

	private Pricing $pricing;

	private Logger $logger;

	private SourceRepository $sources;

	private Support\HttpJson $http;

	private AdapterRegistry $adapters;

	private ProductApplier $applier;

	private Sync $sync;

	private Cron $cron;

	private bool $initialized = false;


	public function __construct(
		Plugin $plugin,
		Catalog $catalog,
		Pricing $pricing
	) {
		$this->plugin  = $plugin;
		$this->catalog = $catalog;
		$this->pricing = $pricing;

		$this->register_services();
	}


	private function register_services(): void {

		$this->logger = new Logger();

		$this->sources = new SourceRepository();

		$this->http = new Support\HttpJson( $this->logger );

		$this->adapters = new AdapterRegistry( $this->logger, $this->http );

		$this->applier = new ProductApplier( $this->pricing );

		$this->sync = new Sync(
			$this->adapters,
			$this->applier,
			$this->catalog,
			$this->sources,
			$this->logger
		);

		$this->cron = new Cron( $this->sources, $this->sync );
	}


	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		$this->cron->init();

		do_action( 'pdxw_suppliers_init', $this );
	}


	/*
	|--------------------------------------------------------------------------
	| Services
	|--------------------------------------------------------------------------
	*/

	public function logger(): Logger {
		return $this->logger;
	}


	public function sources(): SourceRepository {
		return $this->sources;
	}


	public function adapters(): AdapterRegistry {
		return $this->adapters;
	}


	public function sync(): Sync {
		return $this->sync;
	}


	public function cron(): Cron {
		return $this->cron;
	}


	/*
	|--------------------------------------------------------------------------
	| Modules
	|--------------------------------------------------------------------------
	*/

	public function catalog(): Catalog {
		return $this->catalog;
	}


	public function pricing(): Pricing {
		return $this->pricing;
	}


	public function plugin(): Plugin {
		return $this->plugin;
	}


	/*
	|--------------------------------------------------------------------------
	| Manual Operations
	|--------------------------------------------------------------------------
	*/

	/**
	 * Run one source immediately, regardless of its sync interval.
	 *
	 * @return array{found:bool,status?:string,message?:string,matched?:int,updated?:int}
	 */
	public function run_source_now( int $id ): array {

		$source = $this->sources->find( $id );

		if ( ! $source ) {

			return [
				'found' => false,
			];
		}

		return array_merge(
			[ 'found' => true ],
			$this->sync->run( $source )
		);
	}


	/**
	 * Runtime status for the admin screen.
	 */
	public function status(): array {

		return [
			'sources' => $this->sources->all(),
			'cron'    => Cron::status(),
		];
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
