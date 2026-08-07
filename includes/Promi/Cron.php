<?php

namespace PromiDataXWoo\Promi;

defined( 'ABSPATH' ) || exit;

final class Cron {

	public const INDEX_HOOK  = 'pdxw_promi_index';
	public const WORKER_HOOK = 'pdxw_promi_worker';
	public const IMAGE_HOOK  = 'pdxw_promi_images';

	public const WORKER_SCHEDULE = 'pdxw_every_minute';
	public const IMAGE_SCHEDULE  = 'pdxw_every_5_minutes';

	public function init(): void {

		add_filter(
			'cron_schedules',
			[ self::class, 'add_schedules' ]
		);

		/*
		 * These classes will be written next.
		 */
		if ( class_exists( Indexer::class ) ) {
			add_action(
				self::INDEX_HOOK,
				[ Indexer::class, 'run' ]
			);
		}

		if ( class_exists( Worker::class ) ) {
			add_action(
				self::WORKER_HOOK,
				[ Worker::class, 'run' ]
			);
		}

		if ( class_exists( ImageSync::class ) ) {
			add_action(
				self::IMAGE_HOOK,
				[ ImageSync::class, 'run' ]
			);
		}
	}

	public static function add_schedules( array $schedules ): array {

		$schedules[ self::WORKER_SCHEDULE ] = [
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __(
				'Every Minute',
				'promi-data-x-woo'
			),
		];

		$schedules[ self::IMAGE_SCHEDULE ] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __(
				'Every 5 Minutes',
				'promi-data-x-woo'
			),
		];

		return $schedules;
	}

	public static function activate(): void {

		add_filter(
			'cron_schedules',
			[ self::class, 'add_schedules' ]
		);

		if ( ! wp_next_scheduled( self::INDEX_HOOK ) ) {
			wp_schedule_event(
				time(),
				'daily',
				self::INDEX_HOOK
			);
		}

		if ( ! wp_next_scheduled( self::WORKER_HOOK ) ) {
			wp_schedule_event(
				time(),
				self::WORKER_SCHEDULE,
				self::WORKER_HOOK
			);
		}

		if ( ! wp_next_scheduled( self::IMAGE_HOOK ) ) {
			wp_schedule_event(
				time(),
				self::IMAGE_SCHEDULE,
				self::IMAGE_HOOK
			);
		}
	}

	public static function deactivate(): void {

		wp_clear_scheduled_hook( self::INDEX_HOOK );
		wp_clear_scheduled_hook( self::WORKER_HOOK );
		wp_clear_scheduled_hook( self::IMAGE_HOOK );
	}

	public static function pause(): void {

		Config::pause();

		self::deactivate();
	}

	public static function resume(): void {

		Config::resume();

		self::activate();
	}
}