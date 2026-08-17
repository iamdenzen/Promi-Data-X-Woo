<?php

namespace PromiDataXWoo\Admin;

use PromiDataXWoo\Core\Database;
use PromiDataXWoo\Promi\Config;
use PromiDataXWoo\Promi\Promi;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Promi product diagnostic meta box.
 *
 * Displays the Promi feed-index record associated with the WooCommerce
 * product currently being edited.
 *
 * Existing diagnostic fields:
 *
 * - SKU
 * - Hash
 * - JSON URL
 * - Last Seen
 *
 * This meta box is read-only.
 *
 * It does not:
 *
 * - modify Promi data
 * - trigger synchronization
 * - process queue jobs
 * - update WooCommerce products
 *
 * Those responsibilities belong to the Promi domain and Admin AJAX layer.
 */
final class ProductMetaBox {

	public const ID =
		'pdxw_promi_index';

	private Promi $promi;

	private bool $initialized = false;


	public function __construct(
		Promi $promi
	) {
		$this->promi = $promi;
	}


	/**
	 * Register product-editor hooks.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;


		add_action(
			'add_meta_boxes_product',
			[
				$this,
				'register',
			]
		);


		do_action(
			'pdxw_admin_product_meta_box_init',
			$this
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Registration
	|--------------------------------------------------------------------------
	*/

	/**
	 * Register the Promi Index Data meta box.
	 */
	public function register(): void {

		add_meta_box(
			self::ID,
			__(
				'Promi Index Data',
				'promi-data-x-woo'
			),
			[
				$this,
				'render',
			],
			'product',
			'normal',
			'default'
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Rendering
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render the Promi index diagnostics for one WooCommerce product.
	 */
	public function render(
		WP_Post $post
	): void {

		$product =
			wc_get_product(
				$post->ID
			);


		if (
			! $product instanceof \WC_Product
		) {

			$this->notice(
				__(
					'No WooCommerce product was found.',
					'promi-data-x-woo'
				)
			);

			return;
		}


		/*
		|--------------------------------------------------------------------------
		| SKU
		|--------------------------------------------------------------------------
		*/

		$sku =
			trim(
				(string)
				$product->get_sku()
			);


		if ( '' === $sku ) {

			$this->notice(
				__(
					'This product does not have an SKU.',
					'promi-data-x-woo'
				)
			);

			return;
		}


		/*
		|--------------------------------------------------------------------------
		| Promi Index
		|--------------------------------------------------------------------------
		*/

		$row =
			$this->index_row(
				$sku
			);


		if ( ! $row ) {

			printf(
				'<p>%s</p>',
				wp_kses(
					sprintf(
						/* translators: %s: product SKU. */
						__(
							'No Promi index data was found for SKU <strong>%s</strong>.',
							'promi-data-x-woo'
						),
						esc_html(
							$sku
						)
					),
					[
						'strong' => [],
					]
				)
			);

			return;
		}


		/*
		|--------------------------------------------------------------------------
		| Table
		|--------------------------------------------------------------------------
		*/

		?>
		<table
			class="widefat striped pdxw-promi-index-data"
			style="margin-top: 10px;"
		>
			<thead>
				<tr>
					<th scope="col">
						<?php
						echo esc_html__(
							'Field',
							'promi-data-x-woo'
						);
						?>
					</th>

					<th scope="col">
						<?php
						echo esc_html__(
							'Value',
							'promi-data-x-woo'
						);
						?>
					</th>
				</tr>
			</thead>

			<tbody>

				<?php
				$this->render_row(
					__(
						'SKU',
						'promi-data-x-woo'
					),
					(string) (
						$row['sku']
						?? $sku
					)
				);
				?>


				<?php
				$this->render_row(
					__(
						'Hash',
						'promi-data-x-woo'
					),
					(string) (
						$row['hash']
						?? ''
					),
					true
				);
				?>


				<tr>
					<th scope="row">
						<strong>
							<?php
							echo esc_html__(
								'JSON URL',
								'promi-data-x-woo'
							);
							?>
						</strong>
					</th>

					<td>
						<?php
						$json_url =
							esc_url(
								Config::resolve_promi_url(
									(string) (
										$row['json_url']
										?? ''
									)
								)
							);
						?>

						<?php if ( '' !== $json_url ) : ?>

							<a
								href="<?php echo esc_url(
									$json_url
								); ?>"
								target="_blank"
								rel="noopener noreferrer"
							>
								<?php
								echo esc_html(
									$json_url
								);
								?>
							</a>

						<?php else : ?>

							<span aria-hidden="true">—</span>

						<?php endif; ?>
					</td>
				</tr>


				<?php
				$this->render_row(
					__(
						'Last Seen',
						'promi-data-x-woo'
					),
					(string) (
						$row['last_seen']
						?? ''
					)
				);
				?>

			</tbody>
		</table>
		<?php


		do_action(
			'pdxw_admin_product_meta_box_rendered',
			$post->ID,
			$row,
			$this
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Promi Index
	|--------------------------------------------------------------------------
	*/

	/**
	 * Retrieve the Promi index record for one SKU.
	 *
	 * There is deliberately no network request here.
	 *
	 * The meta box reflects the local feed index exactly as it currently
	 * exists in the Promi index table.
	 *
	 * @return array<string,mixed>|null
	 */
	private function index_row(
		string $sku
	): ?array {

		global $wpdb;


		$sku =
			trim(
				$sku
			);


		if ( '' === $sku ) {
			return null;
		}


		$table =
			Database::table(
				'promi_index'
			);


		$row =
			$wpdb->get_row(
				$wpdb->prepare(
					"
					SELECT
						sku,
						hash,
						json_url,
						last_seen

					FROM {$table}

					WHERE sku = %s

					LIMIT 1
					",
					$sku
				),
				ARRAY_A
			);


		return is_array(
			$row
		)
			? $row
			: null;
	}


	/*
	|--------------------------------------------------------------------------
	| Render Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render one diagnostic table row.
	 */
	private function render_row(
		string $label,
		string $value,
		bool $monospace = false
	): void {

		$value =
			trim(
				$value
			);

		?>
		<tr>
			<th scope="row">
				<strong>
					<?php echo esc_html( $label ); ?>
				</strong>
			</th>

			<td>
				<?php if ( '' === $value ) : ?>

					<span aria-hidden="true">—</span>

				<?php elseif ( $monospace ) : ?>

					<code>
						<?php echo esc_html( $value ); ?>
					</code>

				<?php else : ?>

					<?php echo esc_html( $value ); ?>

				<?php endif; ?>
			</td>
		</tr>
		<?php
	}


	/**
	 * Render a simple read-only meta-box notice.
	 */
	private function notice(
		string $message
	): void {

		printf(
			'<p>%s</p>',
			esc_html(
				$message
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


	/*
	|--------------------------------------------------------------------------
	| State
	|--------------------------------------------------------------------------
	*/

	public function is_initialized(): bool {
		return $this->initialized;
	}
}
