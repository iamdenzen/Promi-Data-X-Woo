<?php

namespace PromiDataXWoo\Admin;

use PromiDataXWoo\Catalog\Catalog;
use PromiDataXWoo\Core\Database;
use PromiDataXWoo\Promi\Config;
use PromiDataXWoo\Promi\IgnoreRules;
use PromiDataXWoo\Promi\Promi;
use PromiDataXWoo\Promi\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Promi administration pages.
 *
 * Replaces the old:
 *
 * - CX_Promi_Admin_Pages
 * - page-dashboard.php
 * - page-index.php
 * - page-queue.php
 * - page-ignore_skus.php
 * - page-ignore_rules.php
 * - CX_Promi_Index_Table
 * - CX_Promi_Queue_Table
 * - CX_Promi_IgnoreSKUs_Table
 * - CX_Promi_IgnoreRules_Table
 *
 * Screens:
 *
 * Promi-Data X Woo
 * ├── Dashboard
 * ├── Promi Index
 * ├── Queue
 * ├── Ignore SKUs
 * └── Ignore Rules
 *
 * This class owns presentation/querying for those admin screens.
 *
 * Promi business operations remain in:
 *
 * - Promi
 * - Queue
 * - Indexer
 * - Worker
 * - Config
 * - IgnoreRules
 *
 * Request-changing operations are handled by Admin\Ajax.
 */
final class PromiPages {

	private const PER_PAGE = 20;

	private Promi $promi;

	private Catalog $catalog;

	private bool $initialized = false;


	public function __construct(
		Promi $promi,
		Catalog $catalog
	) {
		$this->promi   = $promi;
		$this->catalog = $catalog;
	}


	/**
	 * Initialize page-specific functionality.
	 *
	 * Menu callbacks are registered by Admin\Menu.
	 * AJAX operations are registered by Admin\Ajax.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;


		do_action(
			'pdxw_admin_promi_pages_init',
			$this
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Dashboard
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render the Promi synchronization dashboard.
	 */
	public function render_dashboard(): void {

		$this->authorize();


		$status =
			$this->promi
				->status();


		$queue =
			is_array(
				$status['queue']
					?? null
			)
				? $status['queue']
				: [];


		$cron =
			is_array(
				$status['cron']
					?? null
			)
				? $status['cron']
				: [];


		$paused =
			(bool) (
				$status['paused']
				?? false
			);


		$product_count =
			$this->product_count();


		$need_images =
			$this->products_need_images();


		?>
		<div class="wrap pdxw-admin pdxw-promi-dashboard">

			<h1>
				<?php
				echo esc_html__(
					'Promi-Data X Woo',
					'promi-data-x-woo'
				);
				?>
			</h1>


			<p class="description">
				<?php
				echo esc_html__(
					'Monitor and control Promi product synchronization.',
					'promi-data-x-woo'
				);
				?>
			</p>


			<div
				id="pdxw-admin-message"
				class="pdxw-admin-message"
				aria-live="polite"
			></div>


			<?php
			/*
			|--------------------------------------------------------------------------
			| Runtime Status
			|--------------------------------------------------------------------------
			*/
			?>

			<div class="pdxw-admin-grid">

				<div class="pdxw-box">

					<h2>
						<?php
						echo esc_html__(
							'Queue',
							'promi-data-x-woo'
						);
						?>
					</h2>


					<table class="widefat striped">

						<tbody>

							<?php
							$this->stat_row(
								__(
									'Pending',
									'promi-data-x-woo'
								),
								$this->queue_stat(
									$queue,
									'pending'
								),
								'pdxw-progress-pending'
							);


							$this->stat_row(
								__(
									'Running',
									'promi-data-x-woo'
								),
								$this->queue_stat(
									$queue,
									'running'
								),
								'pdxw-progress-running'
							);


							$this->stat_row(
								__(
									'Done',
									'promi-data-x-woo'
								),
								$this->queue_stat(
									$queue,
									'done'
								),
								'pdxw-progress-done'
							);


							$this->stat_row(
								__(
									'Failed',
									'promi-data-x-woo'
								),
								$this->queue_stat(
									$queue,
									'failed'
								),
								'pdxw-progress-failed'
							);


							$this->stat_row(
								__(
									'Total',
									'promi-data-x-woo'
								),
								$this->queue_total(
									$queue
								),
								'pdxw-progress-total'
							);
							?>

						</tbody>

					</table>

				</div>


				<div class="pdxw-box">

					<h2>
						<?php
						echo esc_html__(
							'Cron',
							'promi-data-x-woo'
						);
						?>
					</h2>


					<table class="widefat striped">

						<tbody>

							<?php
							$this->stat_row(
								__(
									'Status',
									'promi-data-x-woo'
								),
								$paused
									? __(
										'Paused',
										'promi-data-x-woo'
									)
									: __(
										'Running',
										'promi-data-x-woo'
									),
								'pdxw-cron-status'
							);


							$this->stat_row(
								__(
									'Next Index',
									'promi-data-x-woo'
								),
								$this->cron_time(
									$cron,
									'index'
								),
								'pdxw-progress-index-next'
							);


							$this->stat_row(
								__(
									'Next Worker',
									'promi-data-x-woo'
								),
								$this->cron_time(
									$cron,
									'worker'
								),
								'pdxw-progress-worker-next'
							);


							$this->stat_row(
								__(
									'Next Image Sync',
									'promi-data-x-woo'
								),
								$this->cron_time(
									$cron,
									'images'
								),
								'pdxw-progress-images-next'
							);
							?>

						</tbody>

					</table>

				</div>


				<div class="pdxw-box">

					<h2>
						<?php
						echo esc_html__(
							'Products',
							'promi-data-x-woo'
						);
						?>
					</h2>


					<table class="widefat striped">

						<tbody>

							<?php
							$this->stat_row(
								__(
									'WooCommerce Products',
									'promi-data-x-woo'
								),
								$product_count,
								'pdxw-progress-products-count'
							);


							$this->stat_row(
								__(
									'Waiting for Images',
									'promi-data-x-woo'
								),
								$need_images,
								'pdxw-progress-products-need-images'
							);


							$this->stat_row(
								__(
									'Indexed Products',
									'promi-data-x-woo'
								),
								$this->index_count(),
								'pdxw-progress-index-count'
							);
							?>

						</tbody>

					</table>

				</div>

			</div>


			<?php
			/*
			|--------------------------------------------------------------------------
			| Configuration
			|--------------------------------------------------------------------------
			*/
			?>

			<div class="pdxw-box">

				<h2>
					<?php
					echo esc_html__(
						'Promi Configuration',
						'promi-data-x-woo'
					);
					?>
				</h2>


				<table class="form-table" role="presentation">

					<tbody>

						<tr>

							<th scope="row">

								<label for="pdxw-feed-url">
									<?php
									echo esc_html__(
										'Feed URL',
										'promi-data-x-woo'
									);
									?>
								</label>

							</th>


							<td>

								<input
									type="url"
									id="pdxw-feed-url"
									class="regular-text"
									value="<?php echo esc_attr(
										Config::feed_url()
									); ?>"
									placeholder="https://..."
								>

							</td>

						</tr>


						<tr>

							<th scope="row">

								<label for="pdxw-batch-size">
									<?php
									echo esc_html__(
										'Worker Batch Size',
										'promi-data-x-woo'
									);
									?>
								</label>

							</th>


							<td>

								<input
									type="number"
									id="pdxw-batch-size"
									min="1"
									max="100"
									step="1"
									value="<?php echo esc_attr(
										Config::batch_size()
									); ?>"
								>

								<p class="description">
									<?php
									echo esc_html__(
										'Maximum number of queue jobs processed by one worker run.',
										'promi-data-x-woo'
									);
									?>
								</p>

							</td>

						</tr>


						<tr>

							<th scope="row">

								<label for="pdxw-notification-emails">
									<?php
									echo esc_html__(
										'Notification Recipients',
										'promi-data-x-woo'
									);
									?>
								</label>

							</th>


							<td>

								<textarea
									id="pdxw-notification-emails"
									class="large-text"
									rows="4"
									placeholder="<?php echo esc_attr__(
										'one@example.com, two@example.com',
										'promi-data-x-woo'
									); ?>"
								><?php
								echo esc_textarea(
									implode(
										"\n",
										Config::notification_emails()
									)
								);
								?></textarea>

								<p class="description">
									<?php
									echo esc_html__(
										'One email address per line (or comma-separated). These addresses receive notifications about Promi import errors, newly queued work, and index runs that found nothing to update.',
										'promi-data-x-woo'
									);
									?>
								</p>

							</td>

						</tr>

					</tbody>

				</table>


				<p>

					<button
						type="button"
						id="pdxw-save-config"
						class="button button-primary"
					>
						<?php
						echo esc_html__(
							'Save Configuration',
							'promi-data-x-woo'
						);
						?>
					</button>

				</p>

			</div>


			<?php
			/*
			|--------------------------------------------------------------------------
			| Index Controls
			|--------------------------------------------------------------------------
			*/
			?>

			<div class="pdxw-box">

				<h2>
					<?php
					echo esc_html__(
						'Feed Index',
						'promi-data-x-woo'
					);
					?>
				</h2>


				<p>
					<?php
					echo esc_html__(
						'Refresh the local Promi index and enqueue products whose remote hash has changed.',
						'promi-data-x-woo'
					);
					?>
				</p>


				<p>

					<button
						type="button"
						id="pdxw-run-index"
						class="button button-primary"
						<?php disabled( $paused ); ?>
					>
						<?php
						echo esc_html__(
							'Run Index Sync',
							'promi-data-x-woo'
						);
						?>
					</button>


					<button
						type="button"
						id="pdxw-recheck-queue"
						class="button"
					>
						<?php
						echo esc_html__(
							'Recheck Queue',
							'promi-data-x-woo'
						);
						?>
					</button>

				</p>

			</div>


			<?php
			/*
			|--------------------------------------------------------------------------
			| Worker Controls
			|--------------------------------------------------------------------------
			*/
			?>

			<div class="pdxw-box">

				<h2>
					<?php
					echo esc_html__(
						'Queue Worker',
						'promi-data-x-woo'
					);
					?>
				</h2>


				<p>
					<?php
					echo esc_html__(
						'Run one Promi queue batch immediately.',
						'promi-data-x-woo'
					);
					?>
				</p>


				<p>

					<button
						type="button"
						id="pdxw-run-worker"
						class="button button-primary"
						<?php disabled( $paused ); ?>
					>
						<?php
						echo esc_html__(
							'Run Worker',
							'promi-data-x-woo'
						);
						?>
					</button>

				</p>


				<pre
					id="pdxw-worker-log"
					class="pdxw-log"
					aria-live="polite"
				></pre>

			</div>


			<?php
			/*
			|--------------------------------------------------------------------------
			| Manual SKU
			|--------------------------------------------------------------------------
			*/
			?>

			<div class="pdxw-box">

				<h2>
					<?php
					echo esc_html__(
						'Queue SKU Manually',
						'promi-data-x-woo'
					);
					?>
				</h2>


				<p>

					<input
						type="text"
						id="pdxw-process-sku"
						class="regular-text"
						placeholder="<?php echo esc_attr__(
							'Promi SKU',
							'promi-data-x-woo'
						); ?>"
					>


					<select id="pdxw-process-action">

						<option value="<?php echo esc_attr(
							Queue::ACTION_UPDATE
						); ?>">
							<?php
							echo esc_html__(
								'Update',
								'promi-data-x-woo'
							);
							?>
						</option>


						<option value="<?php echo esc_attr(
							Queue::ACTION_CREATE
						); ?>">
							<?php
							echo esc_html__(
								'Create',
								'promi-data-x-woo'
							);
							?>
						</option>


						<option value="<?php echo esc_attr(
							Queue::ACTION_DISABLE
						); ?>">
							<?php
							echo esc_html__(
								'Disable',
								'promi-data-x-woo'
							);
							?>
						</option>

					</select>


					<button
						type="button"
						id="pdxw-process-sku-button"
						class="button"
					>
						<?php
						echo esc_html__(
							'Add to Queue',
							'promi-data-x-woo'
						);
						?>
					</button>

				</p>

			</div>


			<?php
			/*
			|--------------------------------------------------------------------------
			| Cron Controls
			|--------------------------------------------------------------------------
			*/
			?>

			<div class="pdxw-box">

				<h2>
					<?php
					echo esc_html__(
						'Automatic Synchronization',
						'promi-data-x-woo'
					);
					?>
				</h2>


				<p>

					<button
						type="button"
						id="pdxw-resume-cron"
						class="button button-primary"
						<?php disabled( ! $paused ); ?>
					>
						<?php
						echo esc_html__(
							'Resume Cron',
							'promi-data-x-woo'
						);
						?>
					</button>


					<button
						type="button"
						id="pdxw-pause-cron"
						class="button"
						<?php disabled( $paused ); ?>
					>
						<?php
						echo esc_html__(
							'Pause Cron',
							'promi-data-x-woo'
						);
						?>
					</button>

				</p>

			</div>

		</div>
		<?php
	}


	/*
	|--------------------------------------------------------------------------
	| Promi Index
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render the Promi feed index.
	 */
	public function render_index(): void {

		$this->authorize();


		$search =
			$this->search();


		$orderby =
			$this->orderby(
				[
					'sku',
					'last_seen',
				],
				'last_seen'
			);


		$order =
			$this->order();


		$page =
			$this->page_number();


		$result =
			$this->index_rows(
				$search,
				$orderby,
				$order,
				$page
			);


		?>
		<div class="wrap pdxw-admin">

			<h1>
				<?php
				echo esc_html__(
					'Promi Index',
					'promi-data-x-woo'
				);
				?>
			</h1>


			<?php
			$this->search_form(
				Menu::INDEX_SLUG,
				$search
			);
			?>


			<div class="tablenav top">

				<div class="alignleft actions">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of index records. */
							__(
								'%s indexed products',
								'promi-data-x-woo'
							),
							number_format_i18n(
								$result['total']
							)
						)
					);
					?>
				</div>

			</div>


			<table class="wp-list-table widefat fixed striped">

				<thead>
					<tr>

						<?php
						$this->sortable_header(
							__(
								'SKU',
								'promi-data-x-woo'
							),
							'sku',
							$orderby,
							$order
						);
						?>

						<th scope="col">
							<?php
							echo esc_html__(
								'Product',
								'promi-data-x-woo'
							);
							?>
						</th>

						<th scope="col">
							<?php
							echo esc_html__(
								'Hash',
								'promi-data-x-woo'
							);
							?>
						</th>

						<th scope="col">
							<?php
							echo esc_html__(
								'JSON URL',
								'promi-data-x-woo'
							);
							?>
						</th>

						<?php
						$this->sortable_header(
							__(
								'Last Seen',
								'promi-data-x-woo'
							),
							'last_seen',
							$orderby,
							$order
						);
						?>

						<th scope="col">
							<?php
							echo esc_html__(
								'Ignored',
								'promi-data-x-woo'
							);
							?>
						</th>

					</tr>
				</thead>


				<tbody>

					<?php if ( empty( $result['rows'] ) ) : ?>

						<?php
						$this->empty_row(
							6,
							__(
								'No Promi index records found.',
								'promi-data-x-woo'
							)
						);
						?>

					<?php else : ?>

						<?php foreach ( $result['rows'] as $row ) : ?>

							<tr>

								<td>
									<strong>
										<?php
										echo esc_html(
											$row['sku']
												?? ''
										);
										?>
									</strong>
								</td>


								<td>
									<?php
									$this->render_product_link(
										(string) (
											$row['sku']
												?? ''
										)
									);
									?>
								</td>


								<td>
									<code>
										<?php
										echo esc_html(
											$row['hash']
												?? ''
										);
										?>
									</code>
								</td>


								<td>
									<?php
									$url =
										esc_url(
											(string) (
												$row['json_url']
													?? ''
											)
										);
									?>

									<?php if ( $url ) : ?>

										<a
											href="<?php echo esc_url(
												$url
											); ?>"
											target="_blank"
											rel="noopener noreferrer"
										>
											<?php
											echo esc_html__(
												'Open JSON',
												'promi-data-x-woo'
											);
											?>
										</a>

									<?php else : ?>

										—

									<?php endif; ?>
								</td>


								<td>
									<?php
									echo esc_html(
										$row['last_seen']
											?? '—'
									);
									?>
								</td>


								<td>
									<?php
									echo IgnoreRules::is_sku_ignored(
										(string) (
											$row['sku']
												?? ''
										)
									)
										? esc_html__(
											'Yes',
											'promi-data-x-woo'
										)
										: esc_html__(
											'No',
											'promi-data-x-woo'
										);
									?>
								</td>

							</tr>

						<?php endforeach; ?>

					<?php endif; ?>

				</tbody>

			</table>


			<?php
			$this->pagination(
				$result['total'],
				$page,
				Menu::INDEX_SLUG
			);
			?>

		</div>
		<?php
	}


	/*
	|--------------------------------------------------------------------------
	| Queue
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render the Promi processing queue.
	 */
	public function render_queue(): void {

		$this->authorize();


		$search =
			$this->search();


		$status_filter =
			$this->request_key(
				'queue_status'
			);


		$action_filter =
			$this->request_key(
				'queue_action'
			);


		if (
			! in_array(
				$status_filter,
				[
					'',
					Queue::STATUS_PENDING,
					Queue::STATUS_RUNNING,
					Queue::STATUS_DONE,
					Queue::STATUS_FAILED,
				],
				true
			)
		) {
			$status_filter = '';
		}


		if (
			! in_array(
				$action_filter,
				[
					'',
					Queue::ACTION_CREATE,
					Queue::ACTION_UPDATE,
					Queue::ACTION_DISABLE,
				],
				true
			)
		) {
			$action_filter = '';
		}


		$orderby =
			$this->orderby(
				[
					'id',
					'sku',
					'action',
					'status',
					'attempts',
					'last_attempt',
					'available_at',
				],
				'last_attempt'
			);


		$order =
			$this->order();


		$page =
			$this->page_number();


		$result =
			$this->queue_rows(
				$search,
				$status_filter,
				$action_filter,
				$orderby,
				$order,
				$page
			);


		?>
		<div class="wrap pdxw-admin">

			<h1>
				<?php
				echo esc_html__(
					'Promi Queue',
					'promi-data-x-woo'
				);
				?>
			</h1>


			<?php
			$this->search_form(
				Menu::QUEUE_SLUG,
				$search,
				[
					'queue_status' =>
						$status_filter,

					'queue_action' =>
						$action_filter,
				]
			);
			?>


			<?php
			$this->queue_filters(
				$status_filter,
				$action_filter
			);
			?>


			<table class="wp-list-table widefat fixed striped">

				<thead>
					<tr>

						<?php
						$this->sortable_header(
							__(
								'ID',
								'promi-data-x-woo'
							),
							'id',
							$orderby,
							$order
						);


						$this->sortable_header(
							__(
								'SKU',
								'promi-data-x-woo'
							),
							'sku',
							$orderby,
							$order
						);


						$this->sortable_header(
							__(
								'Action',
								'promi-data-x-woo'
							),
							'action',
							$orderby,
							$order
						);


						$this->sortable_header(
							__(
								'Status',
								'promi-data-x-woo'
							),
							'status',
							$orderby,
							$order
						);


						$this->sortable_header(
							__(
								'Attempts',
								'promi-data-x-woo'
							),
							'attempts',
							$orderby,
							$order
						);


						$this->sortable_header(
							__(
								'Available',
								'promi-data-x-woo'
							),
							'available_at',
							$orderby,
							$order
						);


						$this->sortable_header(
							__(
								'Last Attempt',
								'promi-data-x-woo'
							),
							'last_attempt',
							$orderby,
							$order
						);
						?>

						<th scope="col">
							<?php
							echo esc_html__(
								'Last Error',
								'promi-data-x-woo'
							);
							?>
						</th>

						<th scope="col">
							<?php
							echo esc_html__(
								'Action',
								'promi-data-x-woo'
							);
							?>
						</th>

					</tr>
				</thead>


				<tbody>

					<?php if ( empty( $result['rows'] ) ) : ?>

						<?php
						$this->empty_row(
							9,
							__(
								'No Promi queue records found.',
								'promi-data-x-woo'
							)
						);
						?>

					<?php else : ?>

						<?php foreach ( $result['rows'] as $row ) : ?>

							<?php
							$row_status =
								sanitize_key(
									(string) (
										$row['status']
											?? ''
									)
								);


							$row_action =
								sanitize_key(
									(string) (
										$row['action']
											?? ''
									)
								);


							$sku =
								(string) (
									$row['sku']
										?? ''
								);
							?>

							<tr>

								<td>
									<?php echo esc_html(
										(int) (
											$row['id']
												?? 0
										)
									); ?>
								</td>


								<td>
									<strong>
										<?php echo esc_html( $sku ); ?>
									</strong>
								</td>


								<td>
									<code>
										<?php echo esc_html(
											$row_action
										); ?>
									</code>
								</td>


								<td>
									<span
										class="pdxw-status pdxw-status--<?php echo esc_attr(
											$row_status
										); ?>"
									>
										<?php echo esc_html(
											ucfirst(
												$row_status
											)
										); ?>
									</span>
								</td>


								<td>
									<?php echo esc_html(
										(int) (
											$row['attempts']
												?? 0
										)
									); ?>
								</td>


								<td>
									<?php echo esc_html(
										$row['available_at']
											?? '—'
									); ?>
								</td>


								<td>
									<?php echo esc_html(
										$row['last_attempt']
											?? '—'
									); ?>
								</td>


								<td>
									<?php
									$error =
										trim(
											(string) (
												$row['last_error']
													?? ''
											)
										);


									if ( '' === $error ) {

										echo '—';

									} else {

										printf(
											'<span title="%1$s">%2$s</span>',
											esc_attr(
												$error
											),
											esc_html(
												wp_trim_words(
													$error,
													12
												)
											)
										);
									}
									?>
								</td>


								<td>

									<?php
									if (
										in_array(
											$row_status,
											[
												Queue::STATUS_DONE,
												Queue::STATUS_FAILED,
											],
											true
										)
									) :
										?>

										<button
											type="button"
											class="button button-small pdxw-requeue-sku"
											data-sku="<?php echo esc_attr(
												$sku
											); ?>"
											data-queue-action="<?php echo esc_attr(
												$row_action
											); ?>"
										>
											<?php
											echo esc_html__(
												'Requeue',
												'promi-data-x-woo'
											);
											?>
										</button>

									<?php elseif (
										Queue::STATUS_RUNNING
										=== $row_status
									) : ?>

										<?php
										echo esc_html__(
											'Running',
											'promi-data-x-woo'
										);
										?>

									<?php else : ?>

										<?php
										echo esc_html__(
											'Pending',
											'promi-data-x-woo'
										);
										?>

									<?php endif; ?>

								</td>

							</tr>

						<?php endforeach; ?>

					<?php endif; ?>

				</tbody>

			</table>


			<?php
			$this->pagination(
				$result['total'],
				$page,
				Menu::QUEUE_SLUG,
				[
					'queue_status' =>
						$status_filter,

					'queue_action' =>
						$action_filter,
				]
			);
			?>

		</div>
		<?php
	}


	/*
	|--------------------------------------------------------------------------
	| Ignore SKUs
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render complete SKU exclusions.
	 */
	public function render_ignore_skus(): void {

		$this->authorize();


		$search =
			$this->search();


		$orderby =
			$this->orderby(
				[
					'sku',
					'created_at',
				],
				'created_at'
			);


		$order =
			$this->order();


		$page =
			$this->page_number();


		$result =
			$this->ignored_sku_rows(
				$search,
				$orderby,
				$order,
				$page
			);


		?>
		<div class="wrap pdxw-admin">

			<h1>
				<?php
				echo esc_html__(
					'Ignored Promi SKUs',
					'promi-data-x-woo'
				);
				?>
			</h1>


			<p class="description">
				<?php
				echo esc_html__(
					'Products listed here are excluded entirely from Promi synchronization.',
					'promi-data-x-woo'
				);
				?>
			</p>


			<div
				id="pdxw-ignore-sku-message"
				class="pdxw-admin-message"
				aria-live="polite"
			></div>


			<?php
			$this->search_form(
				Menu::IGNORE_SKUS_SLUG,
				$search
			);
			?>


			<table class="wp-list-table widefat fixed striped">

				<thead>
					<tr>

						<?php
						$this->sortable_header(
							__(
								'SKU',
								'promi-data-x-woo'
							),
							'sku',
							$orderby,
							$order
						);
						?>

						<th scope="col">
							<?php
							echo esc_html__(
								'Reason',
								'promi-data-x-woo'
							);
							?>
						</th>

						<?php
						$this->sortable_header(
							__(
								'Created',
								'promi-data-x-woo'
							),
							'created_at',
							$orderby,
							$order
						);
						?>

						<th scope="col">
							<?php
							echo esc_html__(
								'Actions',
								'promi-data-x-woo'
							);
							?>
						</th>

					</tr>
				</thead>


				<tbody>

					<?php if ( empty( $result['rows'] ) ) : ?>

						<?php
						$this->empty_row(
							4,
							__(
								'No ignored SKUs found.',
								'promi-data-x-woo'
							)
						);
						?>

					<?php else : ?>

						<?php foreach ( $result['rows'] as $row ) : ?>

							<tr>

								<td>
									<strong>
										<?php
										echo esc_html(
											$row['sku']
												?? ''
										);
										?>
									</strong>
								</td>


								<td>
									<?php
									echo esc_html(
										$row['reason']
											?? ''
									);
									?>
								</td>


								<td>
									<?php
									echo esc_html(
										$row['created_at']
											?? ''
									);
									?>
								</td>


								<td>

									<button
										type="button"
										class="button button-small pdxw-remove-ignore-sku"
										data-sku="<?php echo esc_attr(
											$row['sku']
												?? ''
										); ?>"
									>
										<?php
										echo esc_html__(
											'Remove',
											'promi-data-x-woo'
										);
										?>
									</button>

								</td>

							</tr>

						<?php endforeach; ?>

					<?php endif; ?>

				</tbody>

			</table>


			<?php
			$this->pagination(
				$result['total'],
				$page,
				Menu::IGNORE_SKUS_SLUG
			);
			?>


			<div class="pdxw-box">

				<h2>
					<?php
					echo esc_html__(
						'Ignore Entire SKU',
						'promi-data-x-woo'
					);
					?>
				</h2>


				<table class="form-table" role="presentation">

					<tbody>

						<tr>

							<th scope="row">

								<label for="pdxw-ignore-sku">
									<?php
									echo esc_html__(
										'SKU',
										'promi-data-x-woo'
									);
									?>
								</label>

							</th>


							<td>

								<input
									type="text"
									id="pdxw-ignore-sku"
									class="regular-text"
									placeholder="<?php echo esc_attr__(
										'Promi SKU',
										'promi-data-x-woo'
									); ?>"
								>

							</td>

						</tr>


						<tr>

							<th scope="row">

								<label for="pdxw-ignore-reason">
									<?php
									echo esc_html__(
										'Reason',
										'promi-data-x-woo'
									);
									?>
								</label>

							</th>


							<td>

								<input
									type="text"
									id="pdxw-ignore-reason"
									class="regular-text"
									placeholder="<?php echo esc_attr__(
										'Optional',
										'promi-data-x-woo'
									); ?>"
								>

							</td>

						</tr>

					</tbody>

				</table>


				<p>

					<button
						type="button"
						id="pdxw-add-ignore-sku"
						class="button button-primary"
					>
						<?php
						echo esc_html__(
							'Ignore SKU',
							'promi-data-x-woo'
						);
						?>
					</button>

				</p>

			</div>

		</div>
		<?php
	}


	/*
	|--------------------------------------------------------------------------
	| Ignore Rules
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render field-level Promi exclusions.
	 */
	public function render_ignore_rules(): void {

		$this->authorize();


		$search =
			$this->search();


		$orderby =
			$this->orderby(
				[
					'id',
					'sku',
					'type',
					'created_at',
				],
				'created_at'
			);


		$order =
			$this->order();


		$page =
			$this->page_number();


		$result =
			$this->ignore_rule_rows(
				$search,
				$orderby,
				$order,
				$page
			);


		?>
		<div class="wrap pdxw-admin">

			<h1>
				<?php
				echo esc_html__(
					'Promi Ignore Rules',
					'promi-data-x-woo'
				);
				?>
			</h1>


			<p class="description">
				<?php
				echo esc_html__(
					'Field rules may be global or limited to one Promi SKU.',
					'promi-data-x-woo'
				);
				?>
			</p>


			<div
				id="pdxw-ignore-rule-message"
				class="pdxw-admin-message"
				aria-live="polite"
			></div>


			<?php
			$this->search_form(
				Menu::IGNORE_RULES_SLUG,
				$search
			);
			?>


			<table class="wp-list-table widefat fixed striped">

				<thead>
					<tr>

						<?php
						$this->sortable_header(
							__(
								'ID',
								'promi-data-x-woo'
							),
							'id',
							$orderby,
							$order
						);


						$this->sortable_header(
							__(
								'SKU',
								'promi-data-x-woo'
							),
							'sku',
							$orderby,
							$order
						);


						$this->sortable_header(
							__(
								'Type',
								'promi-data-x-woo'
							),
							'type',
							$orderby,
							$order
						);
						?>

						<th scope="col">
							<?php
							echo esc_html__(
								'Field Key',
								'promi-data-x-woo'
							);
							?>
						</th>

						<?php
						$this->sortable_header(
							__(
								'Created',
								'promi-data-x-woo'
							),
							'created_at',
							$orderby,
							$order
						);
						?>

						<th scope="col">
							<?php
							echo esc_html__(
								'Actions',
								'promi-data-x-woo'
							);
							?>
						</th>

					</tr>
				</thead>


				<tbody>

					<?php if ( empty( $result['rows'] ) ) : ?>

						<?php
						$this->empty_row(
							6,
							__(
								'No Promi ignore rules found.',
								'promi-data-x-woo'
							)
						);
						?>

					<?php else : ?>

						<?php foreach ( $result['rows'] as $row ) : ?>

							<tr>

								<td>
									<?php echo esc_html(
										(int) (
											$row['id']
												?? 0
										)
									); ?>
								</td>


								<td>
									<?php
									$sku =
										trim(
											(string) (
												$row['sku']
													?? ''
											)
										);


									echo $sku
										? esc_html(
											$sku
										)
										: '<em>'
											. esc_html__(
												'Global',
												'promi-data-x-woo'
											)
											. '</em>';
									?>
								</td>


								<td>
									<code>
										<?php
										echo esc_html(
											$row['type']
												?? ''
										);
										?>
									</code>
								</td>


								<td>
									<code>
										<?php
										echo esc_html(
											$row['field_key']
												?? ''
										);
										?>
									</code>
								</td>


								<td>
									<?php
									echo esc_html(
										$row['created_at']
											?? ''
									);
									?>
								</td>


								<td>

									<button
										type="button"
										class="button button-small pdxw-remove-ignore-rule"
										data-rule-id="<?php echo esc_attr(
											(int) (
												$row['id']
													?? 0
											)
										); ?>"
									>
										<?php
										echo esc_html__(
											'Remove',
											'promi-data-x-woo'
										);
										?>
									</button>

								</td>

							</tr>

						<?php endforeach; ?>

					<?php endif; ?>

				</tbody>

			</table>


			<?php
			$this->pagination(
				$result['total'],
				$page,
				Menu::IGNORE_RULES_SLUG
			);
			?>


			<div class="pdxw-box">

				<h2>
					<?php
					echo esc_html__(
						'Add Ignore Rule',
						'promi-data-x-woo'
					);
					?>
				</h2>


				<table class="form-table" role="presentation">

					<tbody>

						<tr>

							<th scope="row">

								<label for="pdxw-ignore-rule-sku">
									<?php
									echo esc_html__(
										'SKU',
										'promi-data-x-woo'
									);
									?>
								</label>

							</th>


							<td>

								<input
									type="text"
									id="pdxw-ignore-rule-sku"
									class="regular-text"
									placeholder="<?php echo esc_attr__(
										'Leave empty for a global rule',
										'promi-data-x-woo'
									); ?>"
								>

							</td>

						</tr>


						<tr>

							<th scope="row">

								<label for="pdxw-ignore-rule-type">
									<?php
									echo esc_html__(
										'Type',
										'promi-data-x-woo'
									);
									?>
								</label>

							</th>


							<td>

								<select id="pdxw-ignore-rule-type">

									<option value="meta">
										<?php
										echo esc_html__(
											'Meta',
											'promi-data-x-woo'
										);
										?>
									</option>

									<option value="taxonomy">
										<?php
										echo esc_html__(
											'Taxonomy',
											'promi-data-x-woo'
										);
										?>
									</option>

									<option value="core">
										<?php
										echo esc_html__(
											'Core',
											'promi-data-x-woo'
										);
										?>
									</option>

								</select>

							</td>

						</tr>


						<tr>

							<th scope="row">

								<label for="pdxw-ignore-rule-key">
									<?php
									echo esc_html__(
										'Field Key',
										'promi-data-x-woo'
									);
									?>
								</label>

							</th>


							<td>

								<input
									type="text"
									id="pdxw-ignore-rule-key"
									class="regular-text"
									placeholder="_price, product_cat, title"
								>

							</td>

						</tr>

					</tbody>

				</table>


				<p>

					<button
						type="button"
						id="pdxw-add-ignore-rule"
						class="button button-primary"
					>
						<?php
						echo esc_html__(
							'Add Rule',
							'promi-data-x-woo'
						);
						?>
					</button>

				</p>

			</div>

		</div>
		<?php
	}


	/*
	|--------------------------------------------------------------------------
	| Index Queries
	|--------------------------------------------------------------------------
	*/

	/**
	 * Retrieve one page of Promi index records.
	 *
	 * @return array{rows:array,total:int}
	 */
	private function index_rows(
		string $search,
		string $orderby,
		string $order,
		int $page
	): array {

		global $wpdb;


		$table =
			Database::table(
				'promi_index'
			);


		$where = [];

		$params = [];


		if ( '' !== $search ) {

			$like =
				'%'
				. $wpdb->esc_like(
					$search
				)
				. '%';


			$where[] =
				'(sku LIKE %s OR hash LIKE %s)';


			$params[] = $like;
			$params[] = $like;
		}


		return $this->paged_query(
			$table,
			$where,
			$params,
			$orderby,
			$order,
			$page
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Queue Queries
	|--------------------------------------------------------------------------
	*/

	/**
	 * Retrieve one page of queue records.
	 *
	 * @return array{rows:array,total:int}
	 */
	private function queue_rows(
		string $search,
		string $status,
		string $action,
		string $orderby,
		string $order,
		int $page
	): array {

		global $wpdb;


		$table =
			Database::table(
				'promi_queue'
			);


		$where = [];

		$params = [];


		if ( '' !== $search ) {

			$where[] =
				'sku LIKE %s';


			$params[] =
				'%'
				. $wpdb->esc_like(
					$search
				)
				. '%';
		}


		if ( '' !== $status ) {

			$where[] =
				'status = %s';

			$params[] =
				$status;
		}


		if ( '' !== $action ) {

			$where[] =
				'action = %s';

			$params[] =
				$action;
		}


		return $this->paged_query(
			$table,
			$where,
			$params,
			$orderby,
			$order,
			$page
		);
	}


	/**
	 * Return queue counts grouped by status.
	 */
	private function queue_status_counts(): array {

		global $wpdb;


		$table =
			Database::table(
				'promi_queue'
			);


		$rows =
			$wpdb->get_results(
				"
				SELECT
					status,
					COUNT(*) AS total

				FROM {$table}

				GROUP BY status
				",
				ARRAY_A
			);


		$result = [];


		foreach ( $rows as $row ) {

			$result[
				sanitize_key(
					$row['status']
						?? ''
				)
			] =
				(int) (
					$row['total']
						?? 0
				);
		}


		return $result;
	}


	/**
	 * Return queue counts grouped by action.
	 */
	private function queue_action_counts(): array {

		global $wpdb;


		$table =
			Database::table(
				'promi_queue'
			);


		$rows =
			$wpdb->get_results(
				"
				SELECT
					action,
					COUNT(*) AS total

				FROM {$table}

				GROUP BY action
				",
				ARRAY_A
			);


		$result = [];


		foreach ( $rows as $row ) {

			$result[
				sanitize_key(
					$row['action']
						?? ''
				)
			] =
				(int) (
					$row['total']
						?? 0
				);
		}


		return $result;
	}


	/*
	|--------------------------------------------------------------------------
	| Ignore Queries
	|--------------------------------------------------------------------------
	*/

	/**
	 * Retrieve ignored SKUs.
	 */
	private function ignored_sku_rows(
		string $search,
		string $orderby,
		string $order,
		int $page
	): array {

		global $wpdb;


		$table =
			Database::table(
				'promi_ignore_skus'
			);


		$where = [];

		$params = [];


		if ( '' !== $search ) {

			$like =
				'%'
				. $wpdb->esc_like(
					$search
				)
				. '%';


			$where[] =
				'(sku LIKE %s OR reason LIKE %s)';


			$params[] = $like;
			$params[] = $like;
		}


		return $this->paged_query(
			$table,
			$where,
			$params,
			$orderby,
			$order,
			$page
		);
	}


	/**
	 * Retrieve field-level ignore rules.
	 */
	private function ignore_rule_rows(
		string $search,
		string $orderby,
		string $order,
		int $page
	): array {

		global $wpdb;


		$table =
			Database::table(
				'promi_ignore_rules'
			);


		$where = [];

		$params = [];


		if ( '' !== $search ) {

			$like =
				'%'
				. $wpdb->esc_like(
					$search
				)
				. '%';


			$where[] =
				'(
					sku LIKE %s
					OR type LIKE %s
					OR field_key LIKE %s
				)';


			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}


		return $this->paged_query(
			$table,
			$where,
			$params,
			$orderby,
			$order,
			$page
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Generic Paged Query
	|--------------------------------------------------------------------------
	*/

	/**
	 * Run a safe paginated table query.
	 *
	 * $orderby and $order must already have passed whitelist validation.
	 *
	 * @return array{rows:array,total:int}
	 */
	private function paged_query(
		string $table,
		array $where,
		array $params,
		string $orderby,
		string $order,
		int $page
	): array {

		global $wpdb;


		$where_sql =
			$where
				? ' WHERE '
					. implode(
						' AND ',
						$where
					)
				: '';


		$count_sql =
			"SELECT COUNT(*)
			FROM {$table}
			{$where_sql}";


		$total =
			$params
				? (int)
					$wpdb->get_var(
						$wpdb->prepare(
							$count_sql,
							...$params
						)
					)
				: (int)
					$wpdb->get_var(
						$count_sql
					);


		$offset =
			(
				$page - 1
			)
			* self::PER_PAGE;


		$data_sql =
			"SELECT *
			FROM {$table}
			{$where_sql}
			ORDER BY {$orderby} {$order}
			LIMIT %d OFFSET %d";


		$data_params =
			$params;


		$data_params[] =
			self::PER_PAGE;


		$data_params[] =
			$offset;


		$rows =
			$wpdb->get_results(
				$wpdb->prepare(
					$data_sql,
					...$data_params
				),
				ARRAY_A
			);


		return [
			'rows' =>
				is_array(
					$rows
				)
					? $rows
					: [],

			'total' =>
				$total,
		];
	}


	/*
	|--------------------------------------------------------------------------
	| Queue Filters
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render queue status/action filter links.
	 */
	private function queue_filters(
		string $current_status,
		string $current_action
	): void {

		$status_counts =
			$this->queue_status_counts();


		$action_counts =
			$this->queue_action_counts();


		?>
		<div class="pdxw-filters">

			<p>

				<strong>
					<?php
					echo esc_html__(
						'Status:',
						'promi-data-x-woo'
					);
					?>
				</strong>


				<?php
				$statuses = [
					Queue::STATUS_PENDING,
					Queue::STATUS_RUNNING,
					Queue::STATUS_DONE,
					Queue::STATUS_FAILED,
				];


				foreach ( $statuses as $status ) :

					$url =
						$this->admin_page_url(
							Menu::QUEUE_SLUG,
							[
								'queue_status' =>
									$status,

								'queue_action' =>
									$current_action,
							]
						);
					?>

					<a
						href="<?php echo esc_url(
							$url
						); ?>"
						<?php
						if (
							$current_status
							=== $status
						) {
							echo 'class="current" aria-current="page"';
						}
						?>
					>
						<?php
						echo esc_html(
							sprintf(
								'%s (%s)',
								ucfirst(
									$status
								),
								number_format_i18n(
									$status_counts[
										$status
									] ?? 0
								)
							)
						);
						?>
					</a>

					&nbsp;

				<?php endforeach; ?>


				<a
					href="<?php echo esc_url(
						$this->admin_page_url(
							Menu::QUEUE_SLUG,
							[
								'queue_action' =>
									$current_action,
							]
						)
					); ?>"
				>
					<?php
					echo esc_html__(
						'All',
						'promi-data-x-woo'
					);
					?>
				</a>

			</p>


			<p>

				<strong>
					<?php
					echo esc_html__(
						'Action:',
						'promi-data-x-woo'
					);
					?>
				</strong>


				<?php
				foreach (
					[
						Queue::ACTION_CREATE,
						Queue::ACTION_UPDATE,
						Queue::ACTION_DISABLE,
					] as $action
				) :

					$url =
						$this->admin_page_url(
							Menu::QUEUE_SLUG,
							[
								'queue_status' =>
									$current_status,

								'queue_action' =>
									$action,
							]
						);
					?>

					<a
						href="<?php echo esc_url(
							$url
						); ?>"
						<?php
						if (
							$current_action
							=== $action
						) {
							echo 'class="current" aria-current="page"';
						}
						?>
					>
						<?php
						echo esc_html(
							sprintf(
								'%s (%s)',
								ucfirst(
									$action
								),
								number_format_i18n(
									$action_counts[
										$action
									] ?? 0
								)
							)
						);
						?>
					</a>

					&nbsp;

				<?php endforeach; ?>


				<a
					href="<?php echo esc_url(
						$this->admin_page_url(
							Menu::QUEUE_SLUG,
							[
								'queue_status' =>
									$current_status,
							]
						)
					); ?>"
				>
					<?php
					echo esc_html__(
						'All',
						'promi-data-x-woo'
					);
					?>
				</a>

			</p>

		</div>
		<?php
	}


	/*
	|--------------------------------------------------------------------------
	| Dashboard Statistics
	|--------------------------------------------------------------------------
	*/

	/**
	 * Count WooCommerce parent products.
	 */
	private function product_count(): int {

		global $wpdb;


		return (int)
			$wpdb->get_var(
				"
				SELECT COUNT(*)

				FROM {$wpdb->posts}

				WHERE post_type = 'product'
					AND post_status NOT IN (
						'trash',
						'auto-draft'
					)
				"
			);
	}


	/**
	 * Count products waiting for deferred Promi image processing.
	 */
	private function products_need_images(): int {

		global $wpdb;


		return (int)
			$wpdb->get_var(
				$wpdb->prepare(
					"
					SELECT COUNT(DISTINCT p.ID)

					FROM {$wpdb->posts} p

					INNER JOIN {$wpdb->postmeta} pm
						ON pm.post_id = p.ID

					WHERE p.post_type = %s
						AND p.post_status NOT IN (
							'trash',
							'auto-draft'
						)
						AND pm.meta_key = %s
						AND pm.meta_value = %s
					",
					'product',
					ProductAdmin::NEED_IMAGES_META,
					'1'
				)
			);
	}


	/**
	 * Count local Promi index rows.
	 */
	private function index_count(): int {

		global $wpdb;


		return (int)
			$wpdb->get_var(
				'SELECT COUNT(*) FROM '
				. Database::table(
					'promi_index'
				)
			);
	}


	/**
	 * Normalize one queue-stat value.
	 */
	private function queue_stat(
		array $queue,
		string $key
	): int {

		if (
			isset(
				$queue[
					$key
				]
			)
		) {
			return absint(
				$queue[
					$key
				]
			);
		}


		/*
		 * Some queue implementations may return grouped data.
		 */
		if (
			isset(
				$queue['status'][
					$key
				]
			)
		) {
			return absint(
				$queue['status'][
					$key
				]
			);
		}


		return 0;
	}


	/**
	 * Calculate total queue jobs.
	 */
	private function queue_total(
		array $queue
	): int {

		if (
			isset(
				$queue['total']
			)
		) {
			return absint(
				$queue['total']
			);
		}


		return
			$this->queue_stat(
				$queue,
				'pending'
			)
			+ $this->queue_stat(
				$queue,
				'running'
			)
			+ $this->queue_stat(
				$queue,
				'done'
			)
			+ $this->queue_stat(
				$queue,
				'failed'
			);
	}


	/**
	 * Format one cron next-run timestamp.
	 */
	private function cron_time(
		array $cron,
		string $job
	): string {

		$timestamp =
			absint(
				$cron[
					$job
				][
					'next_run'
				]
					?? 0
			);


		if ( ! $timestamp ) {

			return __(
				'Not scheduled',
				'promi-data-x-woo'
			);
		}


		return wp_date(
			get_option(
				'date_format'
			)
			. ' '
			. get_option(
				'time_format'
			),
			$timestamp
		);
	}


	/**
	 * Render one dashboard status row.
	 */
	private function stat_row(
		string $label,
		int|string $value,
		string $id = ''
	): void {

		?>
		<tr>

			<th scope="row">
				<?php echo esc_html( $label ); ?>
			</th>

			<td>
				<span
					<?php
					if ( '' !== $id ) {
						printf(
							'id="%s"',
							esc_attr(
								$id
							)
						);
					}
					?>
				>
					<?php echo esc_html(
						(string) $value
					); ?>
				</span>
			</td>

		</tr>
		<?php
	}


	/*
	|--------------------------------------------------------------------------
	| Product Link
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render the WooCommerce product corresponding to a Promi SKU.
	 */
	private function render_product_link(
		string $sku
	): void {

		$sku =
			trim(
				$sku
			);


		if ( '' === $sku ) {
			echo '—';
			return;
		}


		$product_id =
			$this->catalog
				->products()
				->id_by_sku(
					$sku
				);


		if ( ! $product_id ) {

			echo esc_html__(
				'N/A',
				'promi-data-x-woo'
			);

			return;
		}


		$product =
			wc_get_product(
				$product_id
			);


		if ( ! $product ) {

			echo esc_html__(
				'N/A',
				'promi-data-x-woo'
			);

			return;
		}


		$edit_url =
			get_edit_post_link(
				$product_id,
				''
			);


		$view_url =
			get_permalink(
				$product_id
			);


		if ( $view_url ) {

			printf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer"><strong>%2$s</strong></a>',
				esc_url(
					$view_url
				),
				esc_html(
					$product->get_name()
				)
			);

		} else {

			echo esc_html(
				$product->get_name()
			);
		}


		if ( $edit_url ) {

			printf(
				'<br><a class="button button-small" href="%1$s">%2$s</a>',
				esc_url(
					$edit_url
				),
				esc_html__(
					'Edit',
					'promi-data-x-woo'
				)
			);
		}
	}


	/*
	|--------------------------------------------------------------------------
	| Search
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render an admin list search form.
	 */
	private function search_form(
		string $page_slug,
		string $search,
		array $hidden = []
	): void {

		?>
		<form method="get">

			<input
				type="hidden"
				name="page"
				value="<?php echo esc_attr(
					$page_slug
				); ?>"
			>


			<?php foreach ( $hidden as $key => $value ) : ?>

				<?php if ( '' === $value ) continue; ?>

				<input
					type="hidden"
					name="<?php echo esc_attr(
						$key
					); ?>"
					value="<?php echo esc_attr(
						$value
					); ?>"
				>

			<?php endforeach; ?>


			<p class="search-box">

				<label
					class="screen-reader-text"
					for="pdxw-search-input"
				>
					<?php
					echo esc_html__(
						'Search',
						'promi-data-x-woo'
					);
					?>
				</label>


				<input
					type="search"
					id="pdxw-search-input"
					name="s"
					value="<?php echo esc_attr(
						$search
					); ?>"
				>


				<input
					type="submit"
					class="button"
					value="<?php echo esc_attr__(
						'Search',
						'promi-data-x-woo'
					); ?>"
				>

			</p>

		</form>
		<?php
	}


	/*
	|--------------------------------------------------------------------------
	| Sorting
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render a sortable table heading.
	 */
	private function sortable_header(
		string $label,
		string $column,
		string $current_orderby,
		string $current_order
	): void {

		$next_order =
			(
				$current_orderby === $column
				&& 'ASC' === $current_order
			)
				? 'desc'
				: 'asc';


		$url =
			add_query_arg(
				[
					'orderby' =>
						$column,

					'order' =>
						$next_order,

					'paged' =>
						false,
				]
			);


		$class =
			$current_orderby === $column
				? 'sorted '
					. strtolower(
						$current_order
					)
				: 'sortable desc';


		printf(
			'<th scope="col" class="manage-column %1$s"><a href="%2$s"><span>%3$s</span><span class="sorting-indicators"><span class="sorting-indicator asc"></span><span class="sorting-indicator desc"></span></span></a></th>',
			esc_attr(
				$class
			),
			esc_url(
				$url
			),
			esc_html(
				$label
			)
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Pagination
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render WordPress-style pagination.
	 */
	private function pagination(
		int $total,
		int $page,
		string $page_slug,
		array $extra = []
	): void {

		$total_pages =
			(int)
			ceil(
				$total
				/ self::PER_PAGE
			);


		if ( $total_pages <= 1 ) {
			return;
		}


		$base_args =
			array_merge(
				[
					'page' =>
						$page_slug,
				],
				array_filter(
					$extra,
					static fn( mixed $value ): bool =>
						''
						!== $value
				)
			);


		$search =
			$this->search();


		if ( '' !== $search ) {

			$base_args['s'] =
				$search;
		}


		$orderby =
			$this->request_key(
				'orderby'
			);


		if ( '' !== $orderby ) {

			$base_args[
				'orderby'
			] = $orderby;
		}


		$order =
			$this->request_key(
				'order'
			);


		if ( '' !== $order ) {

			$base_args[
				'order'
			] = $order;
		}


		$base =
			add_query_arg(
				$base_args,
				admin_url(
					'admin.php'
				)
			);


		$links =
			paginate_links(
				[
					'base' =>
						add_query_arg(
							'paged',
							'%#%',
							$base
						),

					'format' =>
						'',

					'current' =>
						$page,

					'total' =>
						$total_pages,

					'prev_text' =>
						'‹',

					'next_text' =>
						'›',
				]
			);


		if ( ! $links ) {
			return;
		}


		?>
		<div class="tablenav bottom">

			<div class="tablenav-pages">

				<span class="displaying-num">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of records. */
							__(
								'%s items',
								'promi-data-x-woo'
							),
							number_format_i18n(
								$total
							)
						)
					);
					?>
				</span>


				<span class="pagination-links">
					<?php
					echo wp_kses_post(
						$links
					);
					?>
				</span>

			</div>

		</div>
		<?php
	}


	/*
	|--------------------------------------------------------------------------
	| General Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Protect page rendering.
	 */
	private function authorize(): void {

		if (
			current_user_can(
				Menu::CAPABILITY
			)
		) {
			return;
		}


		wp_die(
			esc_html__(
				'You do not have permission to manage Promi-Data X Woo.',
				'promi-data-x-woo'
			)
		);
	}


	/**
	 * Render an empty table row.
	 */
	private function empty_row(
		int $columns,
		string $message
	): void {

		printf(
			'<tr class="no-items"><td class="colspanchange" colspan="%1$d">%2$s</td></tr>',
			absint(
				$columns
			),
			esc_html(
				$message
			)
		);
	}


	/**
	 * Return the current search query.
	 */
	private function search(): string {

		if (
			! isset(
				$_GET['s']
			)
			|| is_array(
				$_GET['s']
			)
		) {
			return '';
		}


		return trim(
			sanitize_text_field(
				wp_unslash(
					$_GET['s']
				)
			)
		);
	}


	/**
	 * Return a sanitized request key.
	 */
	private function request_key(
		string $key
	): string {

		if (
			! isset(
				$_GET[
					$key
				]
			)
			|| is_array(
				$_GET[
					$key
				]
			)
		) {
			return '';
		}


		return sanitize_key(
			wp_unslash(
				$_GET[
					$key
				]
			)
		);
	}


	/**
	 * Validate requested ORDER BY column.
	 */
	private function orderby(
		array $allowed,
		string $default
	): string {

		$orderby =
			$this->request_key(
				'orderby'
			);


		return in_array(
			$orderby,
			$allowed,
			true
		)
			? $orderby
			: $default;
	}


	/**
	 * Validate requested SQL order.
	 */
	private function order(): string {

		$order =
			strtoupper(
				$this->request_key(
					'order'
				)
			);


		return 'ASC'
			=== $order
				? 'ASC'
				: 'DESC';
	}


	/**
	 * Current pagination page.
	 */
	private function page_number(): int {

		if (
			! isset(
				$_GET['paged']
			)
			|| is_array(
				$_GET['paged']
			)
		) {
			return 1;
		}


		return max(
			1,
			absint(
				wp_unslash(
					$_GET['paged']
				)
			)
		);
	}


	/**
	 * Build one PDXW admin-page URL.
	 */
	private function admin_page_url(
		string $page,
		array $args = []
	): string {

		$args =
			array_filter(
				array_merge(
					[
						'page' =>
							$page,
					],
					$args
				),
				static fn( mixed $value ): bool =>
					''
					!== $value
			);


		return add_query_arg(
			$args,
			admin_url(
				'admin.php'
			)
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Accessors
	|--------------------------------------------------------------------------
	*/

	public function promi(): Promi {
		return $this->promi;
	}


	public function catalog(): Catalog {
		return $this->catalog;
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
