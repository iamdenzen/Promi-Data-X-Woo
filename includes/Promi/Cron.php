<?php

namespace PromiDataXWoo\Promi;

defined( 'ABSPATH' ) || exit;

/**
 * Promi cron coordinator.
 *
 * Schedules:
 *
 * - Index refresh: daily
 * - Product queue worker: every minute
 * - Image worker: every five minutes
 *
 * The schedule mirrors the existing XSImpress Promi implementation,
 * while the callbacks now use instantiated services rather than static
 * legacy classes.
 */
final class Cron {

	public const INDEX_HOOK  = 'pdxw_promi_index';
	public const WORKER_HOOK = 'pdxw_promi_worker';
	public const IMAGE_HOOK  = 'pdxw_promi_images';

	public const EVERY_MINUTE =
		'pdxw_every_minute';

	public const EVERY_FIVE_MINUTES =
		'pdxw_every_five_minutes';


	private Indexer $indexer;

	private Worker $worker;

	private ImageSync $images;

	private bool $initialized = false;


	public function __construct(
		Indexer $indexer,
		Worker $worker,
		ImageSync $images
	) {
		$this->indexer = $indexer;
		$this->worker  = $worker;
		$this->images  = $images;
	}


	/**
	 * Register cron callbacks and schedules.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;


		add_filter(
			'cron_schedules',
			[ self::class, 'add_schedules' ]
		);


		add_action(
			self::INDEX_HOOK,
			[ $this->indexer, 'run' ]
		);

		add_action(
			self::WORKER_HOOK,
			[ $this->worker, 'run' ]
		);

		add_action(
			self::IMAGE_HOOK,
			[ $this->images, 'run' ]
		);


		/*
		 * Ensure jobs exist even if they were accidentally removed from
		 * WordPress cron after plugin activation.
		 */
		if ( ! Config::is_paused() ) {
			self::schedule();
		}
	}


	/**
	 * Register custom WordPress cron intervals.
	 */
	public static function add_schedules(
		array $schedules
	): array {

		$schedules[
			self::EVERY_MINUTE
		] = [
			'interval' =>
				MINUTE_IN_SECONDS,

			'display' =>
				__(
					'Every Minute',
					'promi-data-x-woo'
				),
		];


		$schedules[
			self::EVERY_FIVE_MINUTES
		] = [
			'interval' =>
				5 * MINUTE_IN_SECONDS,

			'display' =>
				__(
					'Every 5 Minutes',
					'promi-data-x-woo'
				),
		];


		return $schedules;
	}


	/*
	|--------------------------------------------------------------------------
	| Activation / Scheduling
	|--------------------------------------------------------------------------
	*/

	/**
	 * Schedule Promi cron jobs.
	 *
	 * Called during plugin activation and whenever cron is resumed.
	 */
	public static function activate(): void {

		/*
		 * wp_schedule_event() needs the custom schedules to already exist.
		 */
		add_filter(
			'cron_schedules',
			[ self::class, 'add_schedules' ]
		);

		self::schedule();
	}


	/**
	 * Ensure all required events are scheduled.
	 */
	public static function schedule(): void {

		if ( Config::is_paused() ) {
			return;
		}


		/*
		|--------------------------------------------------------------------------
		| Feed Index
		|--------------------------------------------------------------------------
		|
		| Preserve existing behavior: refresh the Promi feed index once daily.
		*/

		if (
			! wp_next_scheduled(
				self::INDEX_HOOK
			)
		) {

			wp_schedule_event(
				time(),
				'daily',
				self::INDEX_HOOK
			);
		}


		/*
		|--------------------------------------------------------------------------
		| Product Worker
		|--------------------------------------------------------------------------
		*/

		if (
			! wp_next_scheduled(
				self::WORKER_HOOK
			)
		) {

			wp_schedule_event(
				time(),
				self::EVERY_MINUTE,
				self::WORKER_HOOK
			);
		}


		/*
		|--------------------------------------------------------------------------
		| Image Worker
		|--------------------------------------------------------------------------
		*/

		if (
			! wp_next_scheduled(
				self::IMAGE_HOOK
			)
		) {

			wp_schedule_event(
				time(),
				self::EVERY_FIVE_MINUTES,
				self::IMAGE_HOOK
			);
		}
	}


	/**
	 * Unschedule every Promi cron event.
	 *
	 * This does not delete queue data or imported products.
	 */
	public static function deactivate(): void {

		wp_clear_scheduled_hook(
			self::INDEX_HOOK
		);

		wp_clear_scheduled_hook(
			self::WORKER_HOOK
		);

		wp_clear_scheduled_hook(
			self::IMAGE_HOOK
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Pause / Resume
	|--------------------------------------------------------------------------
	*/

	/**
	 * Pause all automatic Promi processing.
	 */
	public static function pause(): void {

		Config::pause();

		self::deactivate();
	}


	/**
	 * Resume automatic Promi processing.
	 */
	public static function resume(): void {

		Config::resume();

		self::activate();
	}


	/*
	|--------------------------------------------------------------------------
	| Status
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return current cron status.
	 *
	 * Useful later for the Promi admin dashboard.
	 */
	public static function status(): array {

		return [
			'paused' =>
				Config::is_paused(),

			'index' => [
				'hook' =>
					self::INDEX_HOOK,

				'next_run' =>
					wp_next_scheduled(
						self::INDEX_HOOK
					)
					?: null,
			],

			'worker' => [
				'hook' =>
					self::WORKER_HOOK,

				'next_run' =>
					wp_next_scheduled(
						self::WORKER_HOOK
					)
					?: null,
			],

			'images' => [
				'hook' =>
					self::IMAGE_HOOK,

				'next_run' =>
					wp_next_scheduled(
						self::IMAGE_HOOK
					)
					?: null,
			],
		];
	}
}