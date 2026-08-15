<?php

namespace PromiDataXWoo\Admin;

use PromiDataXWoo\Frontend\Inquiries;
use PromiDataXWoo\Promi\Config;
use PromiDataXWoo\Promi\IgnoreRules;
use PromiDataXWoo\Promi\Promi;
use PromiDataXWoo\Promi\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Promi-Data X Woo admin AJAX controller.
 *
 * This class owns request-level responsibilities only:
 *
 * - Capability checks.
 * - Nonce validation.
 * - Request sanitization.
 * - Calling Promi domain services.
 * - Returning JSON responses.
 *
 * Promi business logic remains inside:
 *
 * - Promi
 * - Queue
 * - Indexer
 * - Worker
 * - Config
 * - IgnoreRules
 */
final class Ajax {

	public const NONCE_ACTION =
		'pdxw_admin';

	public const NONCE_FIELD =
		'security';


	/*
	|--------------------------------------------------------------------------
	| AJAX Actions
	|--------------------------------------------------------------------------
	*/

	public const ACTION_SAVE_CONFIG =
		'pdxw_promi_config';

	public const ACTION_RUN_INDEX =
		'pdxw_promi_index';

	public const ACTION_RECHECK_QUEUE =
		'pdxw_promi_recheck_queue';

	public const ACTION_RUN_WORKER =
		'pdxw_promi_run_worker';

	public const ACTION_QUEUE_STATS =
		'pdxw_promi_queue_stats';

	public const ACTION_PAUSE_CRON =
		'pdxw_promi_pause_cron';

	public const ACTION_RESUME_CRON =
		'pdxw_promi_resume_cron';

	public const ACTION_PROCESS_SKU =
		'pdxw_promi_process_sku';

	public const ACTION_ADD_IGNORE_SKU =
		'pdxw_promi_add_ignore_sku';

	public const ACTION_REMOVE_IGNORE_SKU =
		'pdxw_promi_remove_ignore_sku';

	public const ACTION_ADD_IGNORE_RULE =
		'pdxw_promi_add_ignore_rule';

	public const ACTION_REMOVE_IGNORE_RULE =
		'pdxw_promi_remove_ignore_rule';

	public const ACTION_UPDATE_INQUIRY_STATUS =
		'pdxw_update_inquiry_status';

	public const ACTION_DELETE_INQUIRY =
		'pdxw_delete_inquiry';


	private Promi $promi;

	private bool $initialized = false;


	public function __construct(
		Promi $promi
	) {
		$this->promi = $promi;
	}


	/**
	 * Register authenticated admin AJAX actions.
	 *
	 * No nopriv actions exist here.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;


		$this->register_action(
			self::ACTION_SAVE_CONFIG,
			'save_config'
		);

		$this->register_action(
			self::ACTION_RUN_INDEX,
			'run_index'
		);

		$this->register_action(
			self::ACTION_RECHECK_QUEUE,
			'recheck_queue'
		);

		$this->register_action(
			self::ACTION_RUN_WORKER,
			'run_worker'
		);

		$this->register_action(
			self::ACTION_QUEUE_STATS,
			'queue_stats'
		);

		$this->register_action(
			self::ACTION_PAUSE_CRON,
			'pause_cron'
		);

		$this->register_action(
			self::ACTION_RESUME_CRON,
			'resume_cron'
		);

		$this->register_action(
			self::ACTION_PROCESS_SKU,
			'process_sku'
		);

		$this->register_action(
			self::ACTION_ADD_IGNORE_SKU,
			'add_ignore_sku'
		);

		$this->register_action(
			self::ACTION_REMOVE_IGNORE_SKU,
			'remove_ignore_sku'
		);

		$this->register_action(
			self::ACTION_ADD_IGNORE_RULE,
			'add_ignore_rule'
		);

		$this->register_action(
			self::ACTION_REMOVE_IGNORE_RULE,
			'remove_ignore_rule'
		);

		$this->register_action(
			self::ACTION_UPDATE_INQUIRY_STATUS,
			'update_inquiry_status'
		);

		$this->register_action(
			self::ACTION_DELETE_INQUIRY,
			'delete_inquiry'
		);


		do_action(
			'pdxw_admin_ajax_init',
			$this
		);
	}


	/**
	 * Register one authenticated WordPress AJAX endpoint.
	 */
	private function register_action(
		string $action,
		string $method
	): void {

		add_action(
			'wp_ajax_' . $action,
			[
				$this,
				$method,
			]
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Configuration
	|--------------------------------------------------------------------------
	*/

	/**
	 * Save Promi runtime configuration.
	 *
	 * Accepted fields:
	 *
	 * feed_url
	 * batch_size
	 * notification_emails (newline or comma separated)
	 */
	public function save_config(): void {

		$this->authorize();


		$feed_url =
			isset(
				$_POST['feed_url']
			)
				? esc_url_raw(
					wp_unslash(
						$_POST['feed_url']
					)
				)
				: '';


		$batch_size =
			isset(
				$_POST['batch_size']
			)
				? absint(
					$_POST['batch_size']
				)
				: Config::batch_size();


		$notification_emails_raw =
			isset(
				$_POST['notification_emails']
			)
				? sanitize_textarea_field(
					wp_unslash(
						$_POST['notification_emails']
					)
				)
				: null;


		/*
		|--------------------------------------------------------------------------
		| Validation
		|--------------------------------------------------------------------------
		*/

		if (
			''
			!== $feed_url
			&& ! wp_http_validate_url(
				$feed_url
			)
		) {

			wp_send_json_error(
				[
					'message' =>
						__(
							'Please enter a valid Promi feed URL.',
							'promi-data-x-woo'
						),
				],
				400
			);
		}


		$batch_size =
			max(
				1,
				min(
					100,
					$batch_size
				)
			);


		Config::set_feed_url(
			$feed_url
		);

		Config::set_batch_size(
			$batch_size
		);


		if ( null !== $notification_emails_raw ) {

			$emails =
				array_filter(
					array_map(
						'trim',
						preg_split(
							'/[\r\n,]+/',
							$notification_emails_raw
						) ?: []
					)
				);

			Config::set_notification_emails(
				$emails
			);
		}


		wp_send_json_success(
			[
				'message' =>
					__(
						'Promi configuration saved.',
						'promi-data-x-woo'
					),

				'feed_url' =>
					Config::feed_url(),

				'batch_size' =>
					Config::batch_size(),

				'notification_emails' =>
					Config::notification_emails(),
			]
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Index
	|--------------------------------------------------------------------------
	*/

	/**
	 * Run the Promi feed index immediately.
	 */
	public function run_index(): void {

		$this->authorize();


		if (
			$this->promi
				->is_paused()
		) {

			wp_send_json_error(
				[
					'message' =>
						__(
							'Promi synchronization is currently paused.',
							'promi-data-x-woo'
						),
				],
				409
			);
		}


		try {

			$this->promi
				->run_index();

		} catch ( \Throwable $e ) {

			$this->send_exception(
				$e,
				'Unable to refresh the Promi index.'
			);
		}


		wp_send_json_success(
			[
				'message' =>
					__(
						'Promi index refresh completed.',
						'promi-data-x-woo'
					),

				'status' =>
					$this->promi
						->status(),
			]
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Queue
	|--------------------------------------------------------------------------
	*/

	/**
	 * Recheck queue state.
	 *
	 * In the rebuilt queue, "recheck" means:
	 *
	 * - release abandoned running jobs whose claim is stale
	 * - return fresh queue statistics
	 *
	 * Index comparison remains the Indexer's responsibility and should be
	 * triggered through run_index().
	 */
	public function recheck_queue(): void {

		$this->authorize();


		try {

			$this->promi
				->queue()
				->release_stale_jobs();

		} catch ( \Throwable $e ) {

			$this->send_exception(
				$e,
				'Unable to recheck the Promi queue.'
			);
		}


		wp_send_json_success(
			[
				'message' =>
					__(
						'Promi queue rechecked.',
						'promi-data-x-woo'
					),

				'queue' =>
					$this->promi
						->queue()
						->stats(),
			]
		);
	}


	/**
	 * Run one queue-worker batch immediately.
	 */
	public function run_worker(): void {

		$this->authorize();


		if (
			$this->promi
				->is_paused()
		) {

			wp_send_json_error(
				[
					'message' =>
						__(
							'Promi synchronization is currently paused.',
							'promi-data-x-woo'
						),
				],
				409
			);
		}


		$before =
			$this->promi
				->queue()
				->stats();


		try {

			$this->promi
				->run_worker();

		} catch ( \Throwable $e ) {

			$this->send_exception(
				$e,
				'Unable to run the Promi worker.'
			);
		}


		$after =
			$this->promi
				->queue()
				->stats();


		wp_send_json_success(
			[
				'message' =>
					__(
						'Promi worker batch completed.',
						'promi-data-x-woo'
					),

				'before' =>
					$before,

				'queue' =>
					$after,
			]
		);
	}


	/**
	 * Return current Promi queue/runtime statistics.
	 */
	public function queue_stats(): void {

		$this->authorize();


		wp_send_json_success(
			$this->promi
				->status()
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Cron
	|--------------------------------------------------------------------------
	*/

	/**
	 * Pause Promi cron processing.
	 */
	public function pause_cron(): void {

		$this->authorize();


		try {

			$this->promi
				->pause();

		} catch ( \Throwable $e ) {

			$this->send_exception(
				$e,
				'Unable to pause Promi synchronization.'
			);
		}


		wp_send_json_success(
			[
				'message' =>
					__(
						'Promi synchronization paused.',
						'promi-data-x-woo'
					),

				'status' =>
					$this->promi
						->status(),
			]
		);
	}


	/**
	 * Resume Promi cron processing.
	 */
	public function resume_cron(): void {

		$this->authorize();


		try {

			$this->promi
				->resume();

		} catch ( \Throwable $e ) {

			$this->send_exception(
				$e,
				'Unable to resume Promi synchronization.'
			);
		}


		wp_send_json_success(
			[
				'message' =>
					__(
						'Promi synchronization resumed.',
						'promi-data-x-woo'
					),

				'status' =>
					$this->promi
						->status(),
			]
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Manual SKU Processing
	|--------------------------------------------------------------------------
	*/

	/**
	 * Queue one Promi SKU manually.
	 *
	 * This endpoint deliberately queues the SKU rather than bypassing the
	 * queue and invoking ProductSync directly.
	 *
	 * That preserves:
	 *
	 * - retry behavior
	 * - job history
	 * - queue priority
	 * - stale-job handling
	 * - one consistent import path
	 */
	public function process_sku(): void {

		$this->authorize();


		$sku =
			$this->request_string(
				'sku'
			);


		if ( '' === $sku ) {

			wp_send_json_error(
				[
					'message' =>
						__(
							'Please provide a Promi SKU.',
							'promi-data-x-woo'
						),
				],
				400
			);
		}


		$action =
			sanitize_key(
				$this->request_string(
					'queue_action',
					Queue::ACTION_UPDATE
				)
			);


		if (
			! in_array(
				$action,
				[
					Queue::ACTION_CREATE,
					Queue::ACTION_UPDATE,
					Queue::ACTION_DISABLE,
				],
				true
			)
		) {
			$action =
				Queue::ACTION_UPDATE;
		}


		if (
			IgnoreRules::is_sku_ignored(
				$sku
			)
		) {

			wp_send_json_error(
				[
					'message' =>
						__(
							'This SKU is currently excluded from Promi synchronization.',
							'promi-data-x-woo'
						),
				],
				409
			);
		}


		$queued =
			$this->promi
				->enqueue(
					$sku,
					$action
				);


		if ( ! $queued ) {

			wp_send_json_error(
				[
					'message' =>
						__(
							'The SKU could not be added to the Promi queue.',
							'promi-data-x-woo'
						),
				],
				500
			);
		}


		wp_send_json_success(
			[
				'message' =>
					sprintf(
						/* translators: %s: Promi SKU. */
						__(
							'SKU %s was added to the Promi queue.',
							'promi-data-x-woo'
						),
						$sku
					),

				'sku' =>
					$sku,

				'action' =>
					$action,

				'queue' =>
					$this->promi
						->queue()
						->stats(),
			]
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Ignored SKUs
	|--------------------------------------------------------------------------
	*/

	/**
	 * Add an SKU-level Promi exclusion.
	 */
	public function add_ignore_sku(): void {

		$this->authorize();


		$sku =
			$this->request_string(
				'sku'
			);

		$reason =
			$this->request_string(
				'reason'
			);


		if ( '' === $sku ) {

			wp_send_json_error(
				[
					'message' =>
						__(
							'Please provide an SKU.',
							'promi-data-x-woo'
						),
				],
				400
			);
		}


		if (
			! IgnoreRules::add_sku(
				$sku,
				$reason
			)
		) {

			wp_send_json_error(
				[
					'message' =>
						__(
							'The SKU could not be added to the ignore list.',
							'promi-data-x-woo'
						),
				],
				500
			);
		}


		wp_send_json_success(
			[
				'message' =>
					__(
						'SKU added to the Promi ignore list.',
						'promi-data-x-woo'
					),

				'items' =>
					IgnoreRules::get_ignored_skus(),
			]
		);
	}


	/**
	 * Remove an SKU-level Promi exclusion.
	 */
	public function remove_ignore_sku(): void {

		$this->authorize();


		$sku =
			$this->request_string(
				'sku'
			);


		if ( '' === $sku ) {

			wp_send_json_error(
				[
					'message' =>
						__(
							'Please provide an SKU.',
							'promi-data-x-woo'
						),
				],
				400
			);
		}


		if (
			! IgnoreRules::remove_sku(
				$sku
			)
		) {

			wp_send_json_error(
				[
					'message' =>
						__(
							'The SKU could not be removed from the ignore list.',
							'promi-data-x-woo'
						),
				],
				500
			);
		}


		wp_send_json_success(
			[
				'message' =>
					__(
						'SKU removed from the Promi ignore list.',
						'promi-data-x-woo'
					),

				'items' =>
					IgnoreRules::get_ignored_skus(),
			]
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Field Ignore Rules
	|--------------------------------------------------------------------------
	*/

	/**
	 * Add a Promi field-level ignore rule.
	 *
	 * Empty SKU means the rule applies globally.
	 */
	public function add_ignore_rule(): void {

		$this->authorize();


		$type =
			sanitize_key(
				$this->request_string(
					'type'
				)
			);

		$field_key =
			$this->request_string(
				'field_key'
			);

		$sku =
			$this->request_string(
				'sku'
			);


		if (
			'' === $type
			|| '' === $field_key
		) {

			wp_send_json_error(
				[
					'message' =>
						__(
							'Rule type and field key are required.',
							'promi-data-x-woo'
						),
				],
				400
			);
		}


		if (
			! IgnoreRules::add_rule(
				$type,
				$field_key,
				$sku
			)
		) {

			wp_send_json_error(
				[
					'message' =>
						__(
							'The ignore rule could not be saved.',
							'promi-data-x-woo'
						),
				],
				500
			);
		}


		wp_send_json_success(
			[
				'message' =>
					__(
						'Promi ignore rule saved.',
						'promi-data-x-woo'
					),

				'rules' =>
					IgnoreRules::get_rules(),
			]
		);
	}


	/**
	 * Remove one field-level ignore rule by database ID.
	 */
	public function remove_ignore_rule(): void {

		$this->authorize();


		$rule_id =
			isset(
				$_POST['rule_id']
			)
				? absint(
					$_POST['rule_id']
				)
				: 0;


		if ( ! $rule_id ) {

			wp_send_json_error(
				[
					'message' =>
						__(
							'Please provide a valid ignore-rule ID.',
							'promi-data-x-woo'
						),
				],
				400
			);
		}


		if (
			! IgnoreRules::remove_rule(
				$rule_id
			)
		) {

			wp_send_json_error(
				[
					'message' =>
						__(
							'The ignore rule could not be removed.',
							'promi-data-x-woo'
						),
				],
				500
			);
		}


		wp_send_json_success(
			[
				'message' =>
					__(
						'Promi ignore rule removed.',
						'promi-data-x-woo'
					),

				'rules' =>
					IgnoreRules::get_rules(),
			]
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Inquiries
	|--------------------------------------------------------------------------
	*/

	/**
	 * Update one inquiry's triage status.
	 */
	public function update_inquiry_status(): void {

		$this->authorize();


		$id =
			isset( $_POST['id'] )
				? absint( $_POST['id'] )
				: 0;

		$status =
			isset( $_POST['status'] )
				? sanitize_key(
					wp_unslash( $_POST['status'] )
				)
				: '';


		if ( ! $id ) {

			wp_send_json_error(
				[
					'message' =>
						__( 'Please provide a valid inquiry ID.', 'promi-data-x-woo' ),
				],
				400
			);
		}


		if ( ! Inquiries::is_valid_status( $status ) ) {

			wp_send_json_error(
				[
					'message' =>
						__( 'Please provide a valid status.', 'promi-data-x-woo' ),
				],
				400
			);
		}


		if ( ! Inquiries::update_status( $id, $status ) ) {

			wp_send_json_error(
				[
					'message' =>
						__( 'The inquiry status could not be updated.', 'promi-data-x-woo' ),
				],
				500
			);
		}


		wp_send_json_success(
			[
				'message' =>
					__( 'Inquiry status updated.', 'promi-data-x-woo' ),

				'id' =>
					$id,

				'status' =>
					$status,
			]
		);
	}


	/**
	 * Delete one inquiry.
	 */
	public function delete_inquiry(): void {

		$this->authorize();


		$id =
			isset( $_POST['id'] )
				? absint( $_POST['id'] )
				: 0;


		if ( ! $id ) {

			wp_send_json_error(
				[
					'message' =>
						__( 'Please provide a valid inquiry ID.', 'promi-data-x-woo' ),
				],
				400
			);
		}


		if ( ! Inquiries::delete( $id ) ) {

			wp_send_json_error(
				[
					'message' =>
						__( 'The inquiry could not be deleted.', 'promi-data-x-woo' ),
				],
				500
			);
		}


		wp_send_json_success(
			[
				'message' =>
					__( 'Inquiry deleted.', 'promi-data-x-woo' ),

				'id' =>
					$id,
			]
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Authorization
	|--------------------------------------------------------------------------
	*/

	/**
	 * Protect every admin endpoint.
	 */
	private function authorize(): void {

		if (
			! current_user_can(
				Menu::CAPABILITY
			)
		) {

			wp_send_json_error(
				[
					'message' =>
						__(
							'You do not have permission to manage Promi-Data X Woo.',
							'promi-data-x-woo'
						),
				],
				403
			);
		}


		check_ajax_referer(
			self::NONCE_ACTION,
			self::NONCE_FIELD
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Request Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Read and sanitize one scalar POST field.
	 */
	private function request_string(
		string $key,
		string $default = ''
	): string {

		if (
			! isset(
				$_POST[
					$key
				]
			)
			|| ! is_scalar(
				$_POST[
					$key
				]
			)
		) {
			return $default;
		}


		return trim(
			sanitize_text_field(
				wp_unslash(
					(string)
					$_POST[
						$key
					]
				)
			)
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Error Handling
	|--------------------------------------------------------------------------
	*/

	/**
	 * Log and return a safe AJAX error.
	 */
	private function send_exception(
		\Throwable $exception,
		string $fallback
	): never {

		$this->promi
			->logger()
			->error(
				$exception->getMessage(),
				[
					'exception' =>
						get_class(
							$exception
						),

					'admin_ajax' =>
						true,
				]
			);


		wp_send_json_error(
			[
				'message' =>
					__(
						$fallback,
						'promi-data-x-woo'
					),
			],
			500
		);
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
