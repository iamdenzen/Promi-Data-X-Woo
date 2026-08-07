<?php

namespace PromiDataXWoo\Promi;

use PromiDataXWoo\Core\Plugin;
use PromiDataXWoo\Catalog\Catalog;
use PromiDataXWoo\Pricing\Pricing;
use PromiDataXWoo\Printing\Printing;

defined( 'ABSPATH' ) || exit;

final class Promi {

	private Plugin $plugin;
	private Catalog $catalog;
	private Pricing $pricing;
	private Printing $printing;

	private Client $client;
	private Logger $logger;
	private Cron $cron;

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

		$this->logger = new Logger();
		$this->client = new Client( $this->logger );
		$this->cron   = new Cron();
	}

	public function init(): void {

		$this->cron->init();

		do_action( 'pdxw_promi_init', $this );
	}

	public function client(): Client {
		return $this->client;
	}

	public function logger(): Logger {
		return $this->logger;
	}

	public function cron(): Cron {
		return $this->cron;
	}

	public function catalog(): Catalog {
		return $this->catalog;
	}

	public function pricing(): Pricing {
		return $this->pricing;
	}

	public function printing(): Printing {
		return $this->printing;
	}
}