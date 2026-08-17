<?php

namespace PromiDataXWoo\Promi;

use PromiDataXWoo\Core\Database;

defined( 'ABSPATH' ) || exit;

final class Queue {

	public const STATUS_PENDING = 'pending';
	public const STATUS_RUNNING = 'running';
	public const STATUS_DONE    = 'done';
	public const STATUS_FAILED  = 'failed';

	public const ACTION_CREATE  = 'create';
	public const ACTION_UPDATE  = 'update';
	public const ACTION_DISABLE = 'disable';

	private const MAX_ATTEMPTS = 3;

	private Logger $logger;

	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Add or upgrade a job.
	 */
	public function enqueue(
		string $sku,
		string $action
	): bool {

		global $wpdb;

		$sku = trim( $sku );

		if ( '' === $sku || ! $this->valid_action( $action ) ) {
			return false;
		}

		/**
		 * Ignore support will be implemented in its own class.
		 */
		if (
			class_exists( IgnoreRules::class )
			&& IgnoreRules::is_sku_ignored( $sku )
		) {
			return false;
		}

		$table = Database::table( 'promi_queue' );

		/**
		 * Look for an existing active job.
		 */
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
				FROM {$table}
				WHERE sku = %s
				AND status IN ('pending', 'running')
				ORDER BY id DESC
				LIMIT 1",
				$sku
			)
		);

		/**
		 * Nothing active: create a fresh job.
		 */
		if ( ! $existing ) {

			$result = $wpdb->insert(
				$table,
				[
					'sku'          => $sku,
					'action'       => $action,
					'status'       => self::STATUS_PENDING,
					'attempts'     => 0,
					'available_at' => current_time( 'mysql' ),
					'created_at'   => current_time( 'mysql' ),
					'updated_at'   => current_time( 'mysql' ),
				],
				[
					'%s',
					'%s',
					'%s',
					'%d',
					'%s',
					'%s',
					'%s',
				]
			);

			return false !== $result;
		}

		/**
		 * Same or lower priority action does not need another job.
		 */
		if (
			$this->priority( $action )
			<= $this->priority( $existing->action )
		) {
			return true;
		}

		/**
		 * Pending jobs can safely be upgraded.
		 */
		if ( self::STATUS_PENDING === $existing->status ) {

			$wpdb->update(
				$table,
				[
					'action'     => $action,
					'updated_at' => current_time( 'mysql' ),
				],
				[
					'id' => (int) $existing->id,
				]
			);

			return true;
		}

		/**
		 * Do NOT modify a job that is already running.
		 *
		 * The worker may already have read its action.
		 *
		 * Instead, create another pending higher-priority job.
		 */
		$result = $wpdb->insert(
			$table,
			[
				'sku'          => $sku,
				'action'       => $action,
				'status'       => self::STATUS_PENDING,
				'attempts'     => 0,
				'available_at' => current_time( 'mysql' ),
				'created_at'   => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			]
		);

		return false !== $result;
	}

	/**
	 * Atomically claim a batch of jobs.
	 */
	public function claim(
		int $limit = 10
	): array {

		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );

		$this->release_stale_jobs();

		$table = Database::table( 'promi_queue' );
		$now   = current_time( 'mysql' );

		/**
		 * Find candidates.
		 *
		 * Explicit action ordering replaces the old ORDER BY action ASC.
		 */
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id
				FROM {$table}
				WHERE status = %s
				AND (
					available_at IS NULL
					OR available_at <= %s
				)
				ORDER BY
					CASE action
						WHEN 'disable' THEN 1
						WHEN 'create'  THEN 2
						WHEN 'update'  THEN 3
						ELSE 4
					END,
					id ASC
				LIMIT %d",
				self::STATUS_PENDING,
				$now,
				$limit
			)
		);

		if ( empty( $ids ) ) {
			return [];
		}

		$ids = array_map( 'intval', $ids );

		$token = wp_generate_uuid4();

		$placeholders = implode(
			',',
			array_fill( 0, count( $ids ), '%d' )
		);

		$args = array_merge(
			[
				self::STATUS_RUNNING,
				$token,
				$now,
				$now,
				$now,
			],
			$ids
		);

		/**
		 * Only pending jobs can be claimed.
		 *
		 * If another worker grabbed one between SELECT and UPDATE,
		 * this UPDATE simply won't take ownership of that row.
		 */
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET
					status = %s,
					claim_token = %s,
					claimed_at = %s,
					last_attempt = %s,
					attempts = attempts + 1,
					updated_at = %s
				WHERE status = 'pending'
				AND id IN ({$placeholders})",
				...$args
			)
		);

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM {$table}
				WHERE claim_token = %s
				AND status = %s
				ORDER BY id ASC",
				$token,
				self::STATUS_RUNNING
			)
		);
	}

	/**
	 * Atomically claim one specific pending job by SKU, bypassing its
	 * available_at retry delay and the batch priority ordering claim()
	 * uses.
	 *
	 * Used by the admin "Process Now" action so a specific queued SKU can
	 * be forced to run immediately instead of waiting for the next
	 * scheduled worker tick (and any backoff delay left over from a
	 * previous failed attempt).
	 *
	 * Returns null when no pending job exists for this SKU, including
	 * when another worker claims it in the race between the SELECT and
	 * the UPDATE below.
	 */
	public function claim_sku( string $sku ): ?object {

		global $wpdb;

		$sku = trim( $sku );

		if ( '' === $sku ) {
			return null;
		}

		$this->release_stale_jobs();

		$table = Database::table( 'promi_queue' );
		$now   = current_time( 'mysql' );

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id
				FROM {$table}
				WHERE sku = %s
				AND status = %s
				ORDER BY id DESC
				LIMIT 1",
				$sku,
				self::STATUS_PENDING
			)
		);

		if ( ! $id ) {
			return null;
		}

		$token = wp_generate_uuid4();

		/**
		 * Only claim if it is still pending — guards against a race with
		 * the scheduled worker cron (or another admin click) claiming
		 * the same row concurrently.
		 */
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET
					status = %s,
					claim_token = %s,
					claimed_at = %s,
					last_attempt = %s,
					attempts = attempts + 1,
					updated_at = %s
				WHERE id = %d
				AND status = %s",
				self::STATUS_RUNNING,
				$token,
				$now,
				$now,
				$now,
				(int) $id,
				self::STATUS_PENDING
			)
		);

		if ( ! $claimed ) {
			return null;
		}

		$job = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
				FROM {$table}
				WHERE id = %d",
				(int) $id
			)
		);

		return $job instanceof \stdClass
			? $job
			: null;
	}


	/**
	 * Mark a job complete.
	 */
	public function complete( int $job_id ): void {

		global $wpdb;

		$wpdb->update(
			Database::table( 'promi_queue' ),
			[
				'status'      => self::STATUS_DONE,
				'claim_token' => null,
				'claimed_at'  => null,
				'last_error'  => null,
				'updated_at'  => current_time( 'mysql' ),
			],
			[
				'id' => $job_id,
			]
		);
	}

	/**
	 * Record a failure.
	 *
	 * Failed jobs retry automatically up to MAX_ATTEMPTS.
	 *
	 * @return bool True when this failure was permanent (MAX_ATTEMPTS
	 *              exhausted, status set to STATUS_FAILED). False when the
	 *              job was rescheduled for another attempt.
	 */
	public function fail(
		object $job,
		string $error
	): bool {

		global $wpdb;

		$table = Database::table( 'promi_queue' );

		$attempts = (int) $job->attempts;

		if ( $attempts < self::MAX_ATTEMPTS ) {

			$delay_minutes = min(
				60,
				5 * ( 2 ** max( 0, $attempts - 1 ) )
			);

			$available_at = current_datetime()
				->modify( "+{$delay_minutes} minutes" )
				->format( 'Y-m-d H:i:s' );

			$wpdb->update(
				$table,
				[
					'status'       => self::STATUS_PENDING,
					'available_at' => $available_at,
					'claim_token'  => null,
					'claimed_at'   => null,
					'last_error'   => $error,
					'updated_at'   => current_time( 'mysql' ),
				],
				[
					'id' => (int) $job->id,
				]
			);

			return false;
		}

		$wpdb->update(
			$table,
			[
				'status'      => self::STATUS_FAILED,
				'claim_token' => null,
				'claimed_at'  => null,
				'last_error'  => $error,
				'updated_at'  => current_time( 'mysql' ),
			],
			[
				'id' => (int) $job->id,
			]
		);

		return true;
	}

	/**
	 * Recover workers that died during processing.
	 */
	public function release_stale_jobs(): void {

		global $wpdb;

		$table = Database::table( 'promi_queue' );

		$cutoff = current_datetime()
			->modify( '-1 hour' )
			->format( 'Y-m-d H:i:s' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET
					status = %s,
					claim_token = NULL,
					claimed_at = NULL,
					available_at = %s,
					updated_at = %s
				WHERE status = %s
				AND claimed_at IS NOT NULL
				AND claimed_at <= %s",
				self::STATUS_PENDING,
				current_time( 'mysql' ),
				current_time( 'mysql' ),
				self::STATUS_RUNNING,
				$cutoff
			)
		);
	}

	public function stats(): array {

		global $wpdb;

		$table = Database::table( 'promi_queue' );

		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) AS total
			FROM {$table}
			GROUP BY status",
			OBJECT_K
		);

		return [
			'pending' => isset( $rows['pending'] )
				? (int) $rows['pending']->total
				: 0,

			'running' => isset( $rows['running'] )
				? (int) $rows['running']->total
				: 0,

			'done' => isset( $rows['done'] )
				? (int) $rows['done']->total
				: 0,

			'failed' => isset( $rows['failed'] )
				? (int) $rows['failed']->total
				: 0,
		];
	}

	private function valid_action( string $action ): bool {

		return in_array(
			$action,
			[
				self::ACTION_CREATE,
				self::ACTION_UPDATE,
				self::ACTION_DISABLE,
			],
			true
		);
	}

	private function priority( string $action ): int {

		return match ( $action ) {
			self::ACTION_DISABLE => 30,
			self::ACTION_CREATE  => 20,
			self::ACTION_UPDATE  => 10,
			default              => 0,
		};
	}
}