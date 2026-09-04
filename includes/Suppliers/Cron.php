<?php

namespace PromiDataXWoo\Suppliers;

defined( 'ABSPATH' ) || exit;

/**
 * Supplier sync cron coordinator.
 *
 * Deliberately a single recurring tick rather than one WP-cron schedule
 * per source: each source declares its own sync_interval_minutes, and this
 * tick simply asks SourceRepository::due() which sources have waited long
 * enough since their last run. That gives arbitrary per-source intervals
 * from the admin UI without registering a new cron_schedules entry for
 * every distinct interval an admin might choose.
 */
final class Cron {

	public const HOOK = 'pdxw_supplier_sync';

	public const EVERY_FIVE_MINUTES = 'pdxw_supplier_every_five_minutes';

	private SourceRepository $sources;

	private Sync $sync;

	private bool $initialized = false;

	public function __construct( SourceRepository $sources, Sync $sync ) {
		$this->sources = $sources;
		$this->sync    = $sync;
	}


	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		add_filter( 'cron_schedules', [ self::class, 'add_schedules' ] );

		add_action( self::HOOK, [ $this, 'run' ] );

		self::schedule();
	}


	public static function add_schedules( array $schedules ): array {

		$schedules[ self::EVERY_FIVE_MINUTES ] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 Minutes (Suppliers)', 'promi-data-x-woo' ),
		];

		return $schedules;
	}


	public static function activate(): void {

		add_filter( 'cron_schedules', [ self::class, 'add_schedules' ] );

		self::schedule();
	}


	public static function schedule(): void {

		if ( ! wp_next_scheduled( self::HOOK ) ) {

			wp_schedule_event(
				time(),
				self::EVERY_FIVE_MINUTES,
				self::HOOK
			);
		}
	}


	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}


	/**
	 * Run every source whose sync interval has elapsed.
	 */
	public function run(): void {

		foreach ( $this->sources->due() as $source ) {
			$this->sync->run( $source );
		}
	}


	public static function status(): array {

		return [
			'hook'     => self::HOOK,
			'next_run' => wp_next_scheduled( self::HOOK ) ?: null,
		];
	}
}
