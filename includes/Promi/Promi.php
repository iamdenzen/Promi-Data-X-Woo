<?php

namespace PromiDataXWoo\Promi;

use PromiDataXWoo\Catalog\Catalog;
use PromiDataXWoo\Core\Plugin;
use PromiDataXWoo\Pricing\Pricing;
use PromiDataXWoo\Printing\Printing;

defined( 'ABSPATH' ) || exit;

/**
 * Promi integration module.
 *
 * Coordinates all Promi-Data related services:
 *
 * - Remote API/feed access
 * - Logging
 * - Feed indexing
 * - Import queue
 * - Product synchronization
 * - Queue workers
 * - Deferred image synchronization
 * - Cron scheduling
 *
 * Promi consumes the Catalog, Pricing and Printing modules.
 * Those modules do not depend on Promi.
 */
final class Promi {

	private Plugin $plugin;

	private Catalog $catalog;

	private Pricing $pricing;

	private Printing $printing;

	private Logger $logger;

	private Notifier $notifier;

	private Client $client;

	private Queue $queue;

	private Indexer $indexer;

	private ProductSync $product_sync;

	private Worker $worker;

	private ImageSync $images;

	private Cron $cron;

	private bool $initialized = false;


	public function __construct(
		Plugin $plugin,
		Catalog $catalog,
		Pricing $pricing,
		Printing $printing
	) {
		$this->plugin   = $plugin;
		$this->catalog  = $catalog;
		$this->pricing  = $pricing;
		$this->printing = $printing;

		$this->register_services();
	}


	/**
	 * Build the Promi service graph.
	 */
	private function register_services(): void {

		/*
		|--------------------------------------------------------------------------
		| Infrastructure
		|--------------------------------------------------------------------------
		*/

		$this->logger = new Logger();

		$this->notifier = new Notifier();

		$this->client = new Client(
			$this->logger
		);

		$this->queue = new Queue(
			$this->logger
		);


		/*
		|--------------------------------------------------------------------------
		| Feed Index
		|--------------------------------------------------------------------------
		*/

		$this->indexer = new Indexer(
			$this->client,
			$this->queue,
			$this->logger,
			$this->notifier
		);


		/*
		|--------------------------------------------------------------------------
		| WooCommerce Product Synchronization
		|--------------------------------------------------------------------------
		*/

		$this->product_sync = new ProductSync(
			$this->catalog,
			$this->pricing,
			$this->printing,
			$this->logger
		);


		/*
		|--------------------------------------------------------------------------
		| Product Worker
		|--------------------------------------------------------------------------
		*/

		$this->worker = new Worker(
			$this->queue,
			$this->indexer,
			$this->client,
			$this->logger,
			$this->product_sync,
			$this->notifier
		);


		/*
		|--------------------------------------------------------------------------
		| Image Worker
		|--------------------------------------------------------------------------
		*/

		$this->images = new ImageSync(
			$this->client,
			$this->logger
		);


		/*
		|--------------------------------------------------------------------------
		| Cron
		|--------------------------------------------------------------------------
		*/

		$this->cron = new Cron(
			$this->indexer,
			$this->worker,
			$this->images
		);
	}


	/**
	 * Initialize Promi functionality.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;


		/*
		|--------------------------------------------------------------------------
		| Image Hooks
		|--------------------------------------------------------------------------
		|
		| ImageSync must register before imports begin because Printing can
		| request Promi print-position images through its image filter.
		*/

		$this->images->init();


		/*
		|--------------------------------------------------------------------------
		| Cron
		|--------------------------------------------------------------------------
		*/

		$this->cron->init();


		/*
		|--------------------------------------------------------------------------
		| Promi Module Ready
		|--------------------------------------------------------------------------
		*/

		do_action(
			'pdxw_promi_init',
			$this
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Services
	|--------------------------------------------------------------------------
	*/

	public function logger(): Logger {
		return $this->logger;
	}


	public function notifier(): Notifier {
		return $this->notifier;
	}


	public function client(): Client {
		return $this->client;
	}


	public function queue(): Queue {
		return $this->queue;
	}


	public function indexer(): Indexer {
		return $this->indexer;
	}


	public function product_sync(): ProductSync {
		return $this->product_sync;
	}


	public function worker(): Worker {
		return $this->worker;
	}


	public function images(): ImageSync {
		return $this->images;
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


	public function printing(): Printing {
		return $this->printing;
	}


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


	public function is_paused(): bool {
		return Config::is_paused();
	}


	/**
	 * Pause automatic Promi synchronization.
	 */
	public function pause(): void {

		Cron::pause();
	}


	/**
	 * Resume automatic Promi synchronization.
	 */
	public function resume(): void {

		Cron::resume();
	}


	/*
	|--------------------------------------------------------------------------
	| Manual Operations
	|--------------------------------------------------------------------------
	|
	| These methods give the future admin layer a clean API without requiring
	| it to know which internal service performs each job.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Run the Promi feed index immediately.
	 */
	public function run_index(): void {

		if ( $this->is_paused() ) {
			return;
		}

		$this->indexer->run();
	}


	/**
	 * Run one product queue batch immediately.
	 */
	public function run_worker(): void {

		if ( $this->is_paused() ) {
			return;
		}

		$this->worker->run();
	}


	/**
	 * Run one image batch immediately.
	 */
	public function run_images(): void {

		if ( $this->is_paused() ) {
			return;
		}

		$this->images->run();
	}


	/**
	 * Queue one SKU manually.
	 */
	public function enqueue(
		string $sku,
		string $action = Queue::ACTION_UPDATE
	): bool {

		return $this->queue->enqueue(
			$sku,
			$action
		);
	}


	/**
	 * Return useful Promi runtime status for the future admin dashboard.
	 */
	public function status(): array {

		return [
			'paused' =>
				$this->is_paused(),

			'feed_url' =>
				Config::feed_url(),

			'batch_size' =>
				Config::batch_size(),

			'queue' =>
				$this->queue->stats(),

			'cron' =>
				Cron::status(),
		];
	}
}