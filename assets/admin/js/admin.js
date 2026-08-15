(function ($, window, document) {

	"use strict";


	/*
	|--------------------------------------------------------------------------
	| Configuration
	|--------------------------------------------------------------------------
	*/

	const config =
		window.pdxw_admin
			|| {};


	const actions =
		config.actions
			|| {};


	const strings =
		config.i18n
			|| {};


	const ajaxUrl =
		config.ajax_url
			|| window.ajaxurl
			|| "";


	/*
	|--------------------------------------------------------------------------
	| State
	|--------------------------------------------------------------------------
	*/

	const state = {

		requests: 0,

		statsTimer: null,

		statsInterval: 15000
	};


	/*
	|--------------------------------------------------------------------------
	| Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return one localized UI string.
	 */
	function text(
		key,
		fallback = ""
	) {

		return (
			strings[key]
			|| fallback
		);
	}


	/**
	 * Return a normalized error message from:
	 *
	 * - WordPress wp_send_json_error()
	 * - jQuery transport errors
	 * - unexpected application failures
	 */
	function errorMessage(
		error
	) {

		if (
			error?.responseJSON?.data?.message
		) {
			return error.responseJSON.data.message;
		}


		if (
			error?.data?.message
		) {
			return error.data.message;
		}


		if (
			typeof error?.message === "string"
			&& error.message
		) {
			return error.message;
		}


		return text(
			"error",
			"An unexpected error occurred."
		);
	}


	/**
	 * Disable/enable one button while it owns an AJAX request.
	 */
	function loading(
		$button,
		active,
		label = null
	) {

		if (
			!$button
			|| !$button.length
		) {
			return;
		}


		if (active) {

			if (
				$button.data(
					"pdxw-original-label"
				) === undefined
			) {

				$button.data(
					"pdxw-original-label",
					$button.text()
				);
			}


			$button
				.prop(
					"disabled",
					true
				)
				.addClass(
					"is-busy"
				);


			if (label) {

				$button.text(
					label
				);
			}

			return;
		}


		const original =
			$button.data(
				"pdxw-original-label"
			);


		$button
			.prop(
				"disabled",
				false
			)
			.removeClass(
				"is-busy"
			);


		if (
			original !== undefined
		) {

			$button.text(
				original
			);


			$button.removeData(
				"pdxw-original-label"
			);
		}
	}


	/**
	 * Show a WordPress-style inline admin message.
	 *
	 * type:
	 *
	 * success
	 * error
	 * warning
	 * info
	 */
	function message(
		target,
		value,
		type = "success"
	) {

		const $target =
			$(target);


		if (!$target.length) {
			return;
		}


		$target
			.removeClass(
				[
					"notice",
					"notice-success",
					"notice-error",
					"notice-warning",
					"notice-info"
				].join(" ")
			)
			.empty();


		if (!value) {
			return;
		}


		const allowed =
			[
				"success",
				"error",
				"warning",
				"info"
			];


		if (
			!allowed.includes(type)
		) {
			type = "info";
		}


		$target
			.addClass(
				`notice notice-${type}`
			)
			.append(
				$("<p>")
					.text(
						value
					)
			);
	}


	/**
	 * Main dashboard message.
	 */
	function dashboardMessage(
		value,
		type = "success"
	) {

		message(
			"#pdxw-admin-message",
			value,
			type
		);
	}


	/**
	 * Ignore-SKU page message.
	 */
	function ignoreSkuMessage(
		value,
		type = "success"
	) {

		message(
			"#pdxw-ignore-sku-message",
			value,
			type
		);
	}


	/**
	 * Ignore-rule page message.
	 */
	function ignoreRuleMessage(
		value,
		type = "success"
	) {

		message(
			"#pdxw-ignore-rule-message",
			value,
			type
		);
	}


	/**
	 * Inquiries page message.
	 */
	function inquiriesMessage(
		value,
		type = "success"
	) {

		message(
			"#pdxw-inquiries-message",
			value,
			type
		);
	}


	/**
	 * Append one line to the dashboard worker log.
	 */
	function workerLog(
		value
	) {

		const $log =
			$("#pdxw-worker-log");


		if (
			!$log.length
			|| !value
		) {
			return;
		}


		const now =
			new Date()
				.toLocaleTimeString();


		const previous =
			$log.text();


		$log.text(
			previous
				+ (previous ? "\n" : "")
				+ `[${now}] ${value}`
		);


		$log.scrollTop(
			$log[0].scrollHeight
		);
	}


	/*
	|--------------------------------------------------------------------------
	| AJAX
	|--------------------------------------------------------------------------
	*/

	/**
	 * Execute one authenticated PDXW admin AJAX request.
	 */
	function request(
		actionKey,
		data = {}
	) {

		const action =
			actions[actionKey];


		if (
			!ajaxUrl
			|| !action
		) {

			return $.Deferred()
				.reject(
					{
						message:
							"PDXW admin AJAX configuration is incomplete."
					}
				)
				.promise();
		}


		state.requests++;


		$(document.body)
			.addClass(
				"pdxw-request-active"
			);


		return $.ajax(
			{
				url:
					ajaxUrl,

				method:
					"POST",

				dataType:
					"json",

				data:
					{
						action,
						security:
							config.nonce
								|| "",
						...data
					}
			}
		)
			.then(
				response => {

					if (
						!response
						|| response.success
							!== true
					) {

						return $.Deferred()
							.reject(
								response
									|| {
										message:
											text(
												"error",
												"An unexpected error occurred."
											)
									}
							)
							.promise();
					}


					return response.data
						?? {};
				}
			)
			.always(
				() => {

					state.requests =
						Math.max(
							0,
							state.requests - 1
						);


					if (
						state.requests === 0
					) {

						$(document.body)
							.removeClass(
								"pdxw-request-active"
							);
					}
				}
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Queue / Runtime State
	|--------------------------------------------------------------------------
	*/

	/**
	 * Read a queue statistic safely.
	 */
	function queueValue(
		queue,
		key
	) {

		const value =
			Number.parseInt(
				queue?.[key],
				10
			);


		return Number.isFinite(value)
			? value
			: 0;
	}


	/**
	 * Determine queue total.
	 *
	 * Queue::stats() may expose total directly; otherwise derive it from
	 * individual statuses.
	 */
	function queueTotal(
		queue
	) {

		const explicit =
			Number.parseInt(
				queue?.total,
				10
			);


		if (
			Number.isFinite(
				explicit
			)
		) {
			return explicit;
		}


		return (
			queueValue(
				queue,
				"pending"
			)
			+ queueValue(
				queue,
				"running"
			)
			+ queueValue(
				queue,
				"done"
			)
			+ queueValue(
				queue,
				"failed"
			)
		);
	}


	/**
	 * Update dashboard queue counters.
	 */
	function renderQueue(
		queue
	) {

		if (
			!queue
			|| typeof queue !== "object"
		) {
			return;
		}


		$("#pdxw-progress-pending")
			.text(
				queueValue(
					queue,
					"pending"
				)
			);


		$("#pdxw-progress-running")
			.text(
				queueValue(
					queue,
					"running"
				)
			);


		$("#pdxw-progress-done")
			.text(
				queueValue(
					queue,
					"done"
				)
			);


		$("#pdxw-progress-failed")
			.text(
				queueValue(
					queue,
					"failed"
				)
			);


		$("#pdxw-progress-total")
			.text(
				queueTotal(
					queue
				)
			);
	}


	/**
	 * Format WordPress cron timestamp.
	 *
	 * PHP returns Unix timestamps from wp_next_scheduled().
	 */
	function formatCronTime(
		value
	) {

		const timestamp =
			Number.parseInt(
				value,
				10
			);


		if (
			!Number.isFinite(
				timestamp
			)
			|| timestamp <= 0
		) {

			return text(
				"not_scheduled",
				"Not scheduled"
			);
		}


		const date =
			new Date(
				timestamp * 1000
			);


		if (
			Number.isNaN(
				date.getTime()
			)
		) {

			return text(
				"not_scheduled",
				"Not scheduled"
			);
		}


		return date.toLocaleString();
	}


	/**
	 * Render one cron row.
	 */
	function renderCronItem(
		selector,
		item
	) {

		if (
			!$(selector).length
		) {
			return;
		}


		$(selector)
			.text(
				formatCronTime(
					item?.next_run
				)
			);
	}


	/**
	 * Render current Promi runtime state.
	 */
	function renderStatus(
		status
	) {

		if (
			!status
			|| typeof status !== "object"
		) {
			return;
		}


		renderQueue(
			status.queue
				|| {}
		);


		const paused =
			Boolean(
				status.paused
			);


		$("#pdxw-cron-status")
			.text(
				paused
					? "Paused"
					: "Running"
			);


		renderCronItem(
			"#pdxw-progress-index-next",
			status.cron?.index
		);


		renderCronItem(
			"#pdxw-progress-worker-next",
			status.cron?.worker
		);


		renderCronItem(
			"#pdxw-progress-images-next",
			status.cron?.images
		);


		/*
		|--------------------------------------------------------------------------
		| Control State
		|--------------------------------------------------------------------------
		*/

		$("#pdxw-pause-cron")
			.prop(
				"disabled",
				paused
			);


		$("#pdxw-resume-cron")
			.prop(
				"disabled",
				!paused
			);


		$("#pdxw-run-index")
			.prop(
				"disabled",
				paused
			);


		$("#pdxw-run-worker")
			.prop(
				"disabled",
				paused
			);
	}


	/**
	 * Fetch current queue/runtime state.
	 */
	function refreshStats(
		silent = true
	) {

		if (
			!actions.queue_stats
		) {
			return $.Deferred()
				.resolve()
				.promise();
		}


		return request(
			"queue_stats"
		)
			.done(
				data => {

					renderStatus(
						data
					);
				}
			)
			.fail(
				error => {

					if (!silent) {

						dashboardMessage(
							errorMessage(
								error
							),
							"error"
						);
					}
				}
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Configuration
	|--------------------------------------------------------------------------
	*/

	$(document).on(
		"click",
		"#pdxw-save-config",
		function () {

			const $button =
				$(this);


			const feedUrl =
				String(
					$("#pdxw-feed-url")
						.val()
						|| ""
				)
					.trim();


			const batchSize =
				Number.parseInt(
					$("#pdxw-batch-size")
						.val(),
					10
				);


			const notificationEmails =
				String(
					$("#pdxw-notification-emails")
						.val()
						|| ""
				);


			loading(
				$button,
				true,
				text(
					"saving",
					"Saving…"
				)
			);


			dashboardMessage(
				"",
				"info"
			);


			request(
				"save_config",
				{
					feed_url:
						feedUrl,

					batch_size:
						Number.isFinite(
							batchSize
						)
							? batchSize
							: 1,

					notification_emails:
						notificationEmails
				}
			)
				.done(
					data => {

						if (
							data.feed_url
								!== undefined
						) {

							$("#pdxw-feed-url")
								.val(
									data.feed_url
								);
						}


						if (
							data.batch_size
								!== undefined
						) {

							$("#pdxw-batch-size")
								.val(
									data.batch_size
								);
						}


						if (
							Array.isArray(
								data.notification_emails
							)
						) {

							$("#pdxw-notification-emails")
								.val(
									data.notification_emails
										.join(
											"\n"
										)
								);
						}


						dashboardMessage(
							data.message
								|| text(
									"saved",
									"Saved."
								),
							"success"
						);
					}
				)
				.fail(
					error => {

						dashboardMessage(
							errorMessage(
								error
							),
							"error"
						);
					}
				)
				.always(
					() => {

						loading(
							$button,
							false
						);
					}
				);
		}
	);


	/*
	|--------------------------------------------------------------------------
	| Index
	|--------------------------------------------------------------------------
	*/

	$(document).on(
		"click",
		"#pdxw-run-index",
		function () {

			const $button =
				$(this);


			loading(
				$button,
				true,
				text(
					"processing",
					"Processing…"
				)
			);


			dashboardMessage(
				"",
				"info"
			);


			request(
				"run_index"
			)
				.done(
					data => {

						if (data.status) {

							renderStatus(
								data.status
							);
						}


						dashboardMessage(
							data.message
								|| text(
									"done",
									"Done."
								),
							"success"
						);
					}
				)
				.fail(
					error => {

						dashboardMessage(
							errorMessage(
								error
							),
							"error"
						);
					}
				)
				.always(
					() => {

						loading(
							$button,
							false
						);
					}
				);
		}
	);


	/*
	|--------------------------------------------------------------------------
	| Queue Recheck
	|--------------------------------------------------------------------------
	*/

	$(document).on(
		"click",
		"#pdxw-recheck-queue",
		function () {

			const $button =
				$(this);


			loading(
				$button,
				true,
				text(
					"processing",
					"Processing…"
				)
			);


			request(
				"recheck_queue"
			)
				.done(
					data => {

						renderQueue(
							data.queue
								|| {}
						);


						dashboardMessage(
							data.message
								|| text(
									"done",
									"Done."
								),
							"success"
						);
					}
				)
				.fail(
					error => {

						dashboardMessage(
							errorMessage(
								error
							),
							"error"
						);
					}
				)
				.always(
					() => {

						loading(
							$button,
							false
						);
					}
				);
		}
	);


	/*
	|--------------------------------------------------------------------------
	| Worker
	|--------------------------------------------------------------------------
	*/

	$(document).on(
		"click",
		"#pdxw-run-worker",
		function () {

			const $button =
				$(this);


			loading(
				$button,
				true,
				text(
					"processing",
					"Processing…"
				)
			);


			workerLog(
				"Worker started."
			);


			request(
				"run_worker"
			)
				.done(
					data => {

						renderQueue(
							data.queue
								|| {}
						);


						const before =
							data.before
								|| {};


						const after =
							data.queue
								|| {};


						const processed =
							Math.max(
								0,
								queueValue(
									before,
									"pending"
								)
								- queueValue(
									after,
									"pending"
								)
							);


						workerLog(
							`${data.message || text("done", "Done.")}`
							+ (
								processed
									? ` Processed approximately ${processed} queued job(s).`
									: ""
							)
						);


						dashboardMessage(
							data.message
								|| text(
									"done",
									"Done."
								),
							"success"
						);
					}
				)
				.fail(
					error => {

						const value =
							errorMessage(
								error
							);


						workerLog(
							value
						);


						dashboardMessage(
							value,
							"error"
						);
					}
				)
				.always(
					() => {

						loading(
							$button,
							false
						);
					}
				);
		}
	);


	/*
	|--------------------------------------------------------------------------
	| Manual SKU
	|--------------------------------------------------------------------------
	*/

	function queueSku(
		sku,
		queueAction,
		$button = null
	) {

		sku =
			String(
				sku
				|| ""
			)
				.trim();


		queueAction =
			String(
				queueAction
					|| "update"
			)
				.trim();


		if (!sku) {

			dashboardMessage(
				"Please provide a Promi SKU.",
				"warning"
			);


			return $.Deferred()
				.reject()
				.promise();
		}


		if ($button?.length) {

			loading(
				$button,
				true,
				text(
					"processing",
					"Processing…"
				)
			);
		}


		return request(
			"process_sku",
			{
				sku,
				queue_action:
					queueAction
			}
		)
			.done(
				data => {

					renderQueue(
						data.queue
							|| {}
					);


					dashboardMessage(
						data.message
							|| text(
								"done",
								"Done."
							),
						"success"
					);
				}
			)
			.fail(
				error => {

					dashboardMessage(
						errorMessage(
							error
						),
						"error"
					);
				}
			)
			.always(
				() => {

					if ($button?.length) {

						loading(
							$button,
							false
						);
					}
				}
			);
	}


	$(document).on(
		"click",
		"#pdxw-process-sku-button",
		function () {

			const $button =
				$(this);


			const sku =
				$("#pdxw-process-sku")
					.val();


			const action =
				$("#pdxw-process-action")
					.val()
					|| "update";


			queueSku(
				sku,
				action,
				$button
			)
				.done(
					() => {

						$("#pdxw-process-sku")
							.val("");
					}
				);
		}
	);


	/*
	|--------------------------------------------------------------------------
	| Queue Table — Requeue
	|--------------------------------------------------------------------------
	*/

	$(document).on(
		"click",
		".pdxw-requeue-sku",
		function () {

			const $button =
				$(this);


			const sku =
				$button.data(
					"sku"
				);


			const queueAction =
				$button.data(
					"queue-action"
				)
				|| "update";


			loading(
				$button,
				true,
				text(
					"processing",
					"Processing…"
				)
			);


			request(
				"process_sku",
				{
					sku,
					queue_action:
						queueAction
				}
			)
				.done(
					data => {

						$button
							.closest("tr")
							.addClass(
								"pdxw-row-updated"
							);


						$button
							.replaceWith(
								$("<span>")
									.text(
										"Queued"
									)
							);


						if (
							$("#pdxw-admin-message")
								.length
						) {

							dashboardMessage(
								data.message
									|| text(
										"done",
										"Done."
									),
								"success"
							);
						}
					}
				)
				.fail(
					error => {

						window.alert(
							errorMessage(
								error
							)
						);
					}
				)
				.always(
					() => {

						if (
							$.contains(
								document,
								$button[0]
							)
						) {

							loading(
								$button,
								false
							);
						}
					}
				);
		}
	);


	/*
	|--------------------------------------------------------------------------
	| Pause / Resume
	|--------------------------------------------------------------------------
	*/

	$(document).on(
		"click",
		"#pdxw-pause-cron",
		function () {

			const $button =
				$(this);


			loading(
				$button,
				true,
				text(
					"processing",
					"Processing…"
				)
			);


			request(
				"pause_cron"
			)
				.done(
					data => {

						renderStatus(
							data.status
								|| {}
						);


						dashboardMessage(
							data.message
								|| text(
									"done",
									"Done."
								),
							"success"
						);
					}
				)
				.fail(
					error => {

						dashboardMessage(
							errorMessage(
								error
							),
							"error"
						);
					}
				)
				.always(
					() => {

						loading(
							$button,
							false
						);
					}
				);
		}
	);


	$(document).on(
		"click",
		"#pdxw-resume-cron",
		function () {

			const $button =
				$(this);


			loading(
				$button,
				true,
				text(
					"processing",
					"Processing…"
				)
			);


			request(
				"resume_cron"
			)
				.done(
					data => {

						renderStatus(
							data.status
								|| {}
						);


						dashboardMessage(
							data.message
								|| text(
									"done",
									"Done."
								),
							"success"
						);
					}
				)
				.fail(
					error => {

						dashboardMessage(
							errorMessage(
								error
							),
							"error"
						);
					}
				)
				.always(
					() => {

						loading(
							$button,
							false
						);
					}
				);
		}
	);


	/*
	|--------------------------------------------------------------------------
	| Ignore SKUs
	|--------------------------------------------------------------------------
	*/

	$(document).on(
		"click",
		"#pdxw-add-ignore-sku",
		function () {

			const $button =
				$(this);


			const sku =
				String(
					$("#pdxw-ignore-sku")
						.val()
						|| ""
				)
					.trim();


			const reason =
				String(
					$("#pdxw-ignore-reason")
						.val()
						|| ""
				)
					.trim();


			if (!sku) {

				ignoreSkuMessage(
					"Please provide an SKU.",
					"warning"
				);

				return;
			}


			loading(
				$button,
				true,
				text(
					"processing",
					"Processing…"
				)
			);


			request(
				"add_ignore_sku",
				{
					sku,
					reason
				}
			)
				.done(
					data => {

						ignoreSkuMessage(
							data.message
								|| text(
									"done",
									"Done."
								),
							"success"
						);


						$("#pdxw-ignore-sku")
							.val("");


						$("#pdxw-ignore-reason")
							.val("");


						/*
						 * The page table is server-rendered and paginated.
						 *
						 * Reloading guarantees sorting/search/pagination remain
						 * authoritative rather than maintaining a second JS
						 * renderer for the same dataset.
						 */
						window.setTimeout(
							() => {
								window.location.reload();
							},
							250
						);
					}
				)
				.fail(
					error => {

						ignoreSkuMessage(
							errorMessage(
								error
							),
							"error"
						);
					}
				)
				.always(
					() => {

						loading(
							$button,
							false
						);
					}
				);
		}
	);


	$(document).on(
		"click",
		".pdxw-remove-ignore-sku",
		function () {

			const $button =
				$(this);


			const sku =
				String(
					$button.data(
						"sku"
					)
					|| ""
				)
					.trim();


			if (!sku) {
				return;
			}


			loading(
				$button,
				true,
				text(
					"processing",
					"Processing…"
				)
			);


			request(
				"remove_ignore_sku",
				{
					sku
				}
			)
				.done(
					data => {

						$button
							.closest("tr")
							.fadeOut(
								150,
								function () {
									$(this).remove();
								}
							);


						ignoreSkuMessage(
							data.message
								|| text(
									"done",
									"Done."
								),
							"success"
						);
					}
				)
				.fail(
					error => {

						ignoreSkuMessage(
							errorMessage(
								error
							),
							"error"
						);
					}
				)
				.always(
					() => {

						if (
							$.contains(
								document,
								$button[0]
							)
						) {

							loading(
								$button,
								false
							);
						}
					}
				);
		}
	);


	/*
	|--------------------------------------------------------------------------
	| Ignore Rules
	|--------------------------------------------------------------------------
	*/

	$(document).on(
		"click",
		"#pdxw-add-ignore-rule",
		function () {

			const $button =
				$(this);


			const sku =
				String(
					$("#pdxw-ignore-rule-sku")
						.val()
						|| ""
				)
					.trim();


			const type =
				String(
					$("#pdxw-ignore-rule-type")
						.val()
						|| ""
				)
					.trim();


			const fieldKey =
				String(
					$("#pdxw-ignore-rule-key")
						.val()
						|| ""
				)
					.trim();


			if (
				!type
				|| !fieldKey
			) {

				ignoreRuleMessage(
					"Rule type and field key are required.",
					"warning"
				);

				return;
			}


			loading(
				$button,
				true,
				text(
					"processing",
					"Processing…"
				)
			);


			request(
				"add_ignore_rule",
				{
					sku,
					type,
					field_key:
						fieldKey
				}
			)
				.done(
					data => {

						ignoreRuleMessage(
							data.message
								|| text(
									"done",
									"Done."
								),
							"success"
						);


						$("#pdxw-ignore-rule-sku")
							.val("");


						$("#pdxw-ignore-rule-key")
							.val("");


						window.setTimeout(
							() => {
								window.location.reload();
							},
							250
						);
					}
				)
				.fail(
					error => {

						ignoreRuleMessage(
							errorMessage(
								error
							),
							"error"
						);
					}
				)
				.always(
					() => {

						loading(
							$button,
							false
						);
					}
				);
		}
	);


	$(document).on(
		"click",
		".pdxw-remove-ignore-rule",
		function () {

			const $button =
				$(this);


			const ruleId =
				Number.parseInt(
					$button.data(
						"rule-id"
					),
					10
				);


			if (
				!Number.isFinite(
					ruleId
				)
				|| ruleId <= 0
			) {
				return;
			}


			loading(
				$button,
				true,
				text(
					"processing",
					"Processing…"
				)
			);


			request(
				"remove_ignore_rule",
				{
					rule_id:
						ruleId
				}
			)
				.done(
					data => {

						$button
							.closest("tr")
							.fadeOut(
								150,
								function () {
									$(this).remove();
								}
							);


						ignoreRuleMessage(
							data.message
								|| text(
									"done",
									"Done."
								),
							"success"
						);
					}
				)
				.fail(
					error => {

						ignoreRuleMessage(
							errorMessage(
								error
							),
							"error"
						);
					}
				)
				.always(
					() => {

						if (
							$.contains(
								document,
								$button[0]
							)
						) {

							loading(
								$button,
								false
							);
						}
					}
				);
		}
	);


	/*
	|--------------------------------------------------------------------------
	| Inquiries
	|--------------------------------------------------------------------------
	*/

	$(document).on(
		"change",
		".pdxw-inquiry-status-select",
		function () {

			const $select =
				$(this);

			const $row =
				$select.closest(
					"tr"
				);

			const id =
				Number.parseInt(
					$row.data(
						"inquiry-id"
					),
					10
				) || 0;

			const status =
				String(
					$select.val()
						|| ""
				);


			if (
				!id
				|| !status
			) {
				return;
			}


			$select.prop(
				"disabled",
				true
			);


			request(
				"update_inquiry_status",
				{
					id,
					status
				}
			)
				.done(
					data => {

						$row
							.find(
								".pdxw-status"
							)
							.attr(
								"class",
								"pdxw-status pdxw-status--"
									+ status
							)
							.text(
								status.charAt(0).toUpperCase()
									+ status.slice(1)
							);


						inquiriesMessage(
							data.message
								|| text(
									"saved",
									"Saved."
								),
							"success"
						);
					}
				)
				.fail(
					error => {

						inquiriesMessage(
							errorMessage(
								error
							),
							"error"
						);
					}
				)
				.always(
					() => {

						$select.prop(
							"disabled",
							false
						);
					}
				);
		}
	);


	$(document).on(
		"click",
		".pdxw-delete-inquiry",
		function () {

			const $button =
				$(this);

			const $row =
				$button.closest(
					"tr"
				);

			const id =
				Number.parseInt(
					$row.data(
						"inquiry-id"
					),
					10
				) || 0;


			if (!id) {
				return;
			}


			if (
				!window.confirm(
					"Delete this inquiry? This cannot be undone."
				)
			) {
				return;
			}


			loading(
				$button,
				true,
				text(
					"processing",
					"Processing…"
				)
			);


			request(
				"delete_inquiry",
				{
					id
				}
			)
				.done(
					data => {

						$row
							.fadeOut(
								150,
								function () {
									$(this).remove();
								}
							);


						inquiriesMessage(
							data.message
								|| text(
									"done",
									"Done."
								),
							"success"
						);
					}
				)
				.fail(
					error => {

						inquiriesMessage(
							errorMessage(
								error
							),
							"error"
						);
					}
				)
				.always(
					() => {

						if (
							$.contains(
								document,
								$button[0]
							)
						) {

							loading(
								$button,
								false
							);
						}
					}
				);
		}
	);


	/*
	|--------------------------------------------------------------------------
	| Tier Pricing Editor
	|--------------------------------------------------------------------------
	|
	| PricingPage renders:
	|
	| .pdxw-tier-target
	|     data-tier-group="0"
	|
	| #pdxw-tier-row-template
	|     hidden <template> containing one blank tier row
	|
	| .pdxw-add-tier
	| .pdxw-remove-tier
	|
	| PHP remains responsible for:
	|
	| - rendering existing stored tiers
	| - validating submitted targets
	| - sanitizing submitted prices
	| - persisting through TieredPricing
	|
	| JavaScript only manages editable rows.
	|--------------------------------------------------------------------------
	*/


	/**
	 * Return the row template element.
	 */
	function tierRowTemplate() {

		const template =
			document.getElementById(
				"pdxw-tier-row-template"
			);


		if (
			!template
			|| !template.content
		) {
			return null;
		}


		return template;
	}


	/**
	 * Update the empty-state message for one pricing target.
	 */
	function updateTierEmptyState(
		$target
	) {

		if (
			!$target
			|| !$target.length
		) {
			return;
		}


		const rows =
			$target
				.find(
					".pdxw-tier-table tbody tr"
				)
				.length;


		$target
			.find(
				".pdxw-tier-empty-message"
			)
			.toggle(
				rows === 0
			);
	}


	/**
	 * Assign form field names to one newly cloned tier row.
	 *
	 * The submitted PHP structure must remain:
	 *
	 * tiers[GROUP][qty][]
	 * tiers[GROUP][price][]
	 * tiers[GROUP][purchase_price][]
	 */
	function prepareTierRow(
		$row,
		group
	) {

		if (
			!$row
			|| !$row.length
		) {
			return;
		}


		$row
			.find(
				'[data-tier-field="qty"]'
			)
			.attr(
				"name",
				`tiers[${group}][qty][]`
			);


		$row
			.find(
				'[data-tier-field="price"]'
			)
			.attr(
				"name",
				`tiers[${group}][price][]`
			);


		$row
			.find(
				'[data-tier-field="purchase_price"]'
			)
			.attr(
				"name",
				`tiers[${group}][purchase_price][]`
			);
	}


	/**
	 * Add one blank quantity tier.
	 */
	$(document).on(
		"click",
		".pdxw-add-tier",
		function () {

			const $button =
				$(this);


			const $target =
				$button.closest(
					".pdxw-tier-target"
				);


			if (!$target.length) {
				return;
			}


			const group =
				$target.attr(
					"data-tier-group"
				);


			if (
				group === undefined
				|| group === ""
			) {
				return;
			}


			const template =
				tierRowTemplate();


			if (!template) {
				return;
			}


			const sourceRow =
				template.content
					.firstElementChild;


			if (!sourceRow) {
				return;
			}


			const $row =
				$(
					sourceRow.cloneNode(
						true
					)
				);


			prepareTierRow(
				$row,
				group
			);


			$target
				.find(
					".pdxw-tier-table tbody"
				)
				.append(
					$row
				);


			updateTierEmptyState(
				$target
			);


			/*
			 * Put the cursor directly into the new quantity field.
			 */
			$row
				.find(
					'[data-tier-field="qty"]'
				)
				.trigger(
					"focus"
				);
		}
	);


	/**
	 * Remove one editable quantity tier.
	 *
	 * Removing every row is allowed.
	 *
	 * PricingPage::save() intentionally treats an empty target as:
	 *
	 *     delete all exact tiers for this product / variation
	 */
	$(document).on(
		"click",
		".pdxw-remove-tier",
		function () {

			const $button =
				$(this);


			const $target =
				$button.closest(
					".pdxw-tier-target"
				);


			const $row =
				$button.closest(
					"tr"
				);


			if (!$row.length) {
				return;
			}


			$row.remove();


			updateTierEmptyState(
				$target
			);
		}
	);


	/**
	 * Normalize decimal input while editing.
	 *
	 * Do not convert the value to Number here because WooCommerce accepts
	 * localized decimal entry and PHP ultimately normalizes the value with:
	 *
	 *     wc_format_decimal()
	 *
	 * We only remove surrounding whitespace.
	 */
	$(document).on(
		"blur",
		'.pdxw-tier-table input[data-tier-field="price"], '
			+ '.pdxw-tier-table input[data-tier-field="purchase_price"]',
		function () {

			const $input =
				$(this);


			$input.val(
				String(
					$input.val()
						|| ""
				)
					.trim()
			);
		}
	);


	/**
	 * Quantity inputs may only represent positive integer tiers.
	 *
	 * Browser validation already enforces min=1 and step=1; this makes pasted
	 * or programmatically supplied values behave consistently before submit.
	 */
	$(document).on(
		"blur",
		'.pdxw-tier-table input[data-tier-field="qty"]',
		function () {

			const $input =
				$(this);


			const quantity =
				Number.parseInt(
					$input.val(),
					10
				);


			if (
				!Number.isFinite(
					quantity
				)
				|| quantity < 1
			) {

				$input.val("");

				return;
			}


			$input.val(
				quantity
			);
		}
	);



	/*
	|--------------------------------------------------------------------------
	| Keyboard Shortcuts
	|--------------------------------------------------------------------------
	*/

	/**
	 * Enter in the manual SKU field queues the SKU.
	 */
	$(document).on(
		"keydown",
		"#pdxw-process-sku",
		function (event) {

			if (
				event.key !== "Enter"
			) {
				return;
			}


			event.preventDefault();


			$("#pdxw-process-sku-button")
				.trigger(
					"click"
				);
		}
	);


	/**
	 * Enter in ignored-SKU fields submits the exclusion.
	 */
	$(document).on(
		"keydown",
		"#pdxw-ignore-sku, #pdxw-ignore-reason",
		function (event) {

			if (
				event.key !== "Enter"
			) {
				return;
			}


			event.preventDefault();


			$("#pdxw-add-ignore-sku")
				.trigger(
					"click"
				);
		}
	);


	/**
	 * Enter in field-rule text inputs submits the rule.
	 */
	$(document).on(
		"keydown",
		"#pdxw-ignore-rule-sku, #pdxw-ignore-rule-key",
		function (event) {

			if (
				event.key !== "Enter"
			) {
				return;
			}


			event.preventDefault();


			$("#pdxw-add-ignore-rule")
				.trigger(
					"click"
				);
		}
	);


	/*
	|--------------------------------------------------------------------------
	| Polling
	|--------------------------------------------------------------------------
	*/

	/**
	 * Queue status is useful while the dashboard is open because cron may be
	 * processing jobs independently from this browser.
	 *
	 * Do not poll every PDXW page.
	 */
	function startStatsPolling() {

		if (
			config.page !== "pdxw"
			|| !actions.queue_stats
		) {
			return;
		}


		refreshStats(
			true
		);


		state.statsTimer =
			window.setInterval(
				() => {

					/*
					 * Do not start another stats request while the tab is
					 * hidden or another admin operation is still running.
					 */
					if (
						document.hidden
						|| state.requests > 0
					) {
						return;
					}


					refreshStats(
						true
					);
				},
				state.statsInterval
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Visibility
	|--------------------------------------------------------------------------
	*/

	document.addEventListener(
		"visibilitychange",
		function () {

			if (
				!document.hidden
				&& config.page === "pdxw"
				&& state.requests === 0
			) {

				refreshStats(
					true
				);
			}
		}
	);


	/*
	|--------------------------------------------------------------------------
	| Initialize
	|--------------------------------------------------------------------------
	*/

	$(function () {

		startStatsPolling();

	});

})(
	jQuery,
	window,
	document
);
