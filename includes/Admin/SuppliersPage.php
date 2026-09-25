<?php

namespace PromiDataXWoo\Admin;

use PromiDataXWoo\Suppliers\Suppliers;

defined( 'ABSPATH' ) || exit;

/**
 * Supplier source administration page.
 *
 * Lets an admin configure any number of supplier feeds — endpoint,
 * authentication, sync interval, and the dot-notation field paths that map
 * that supplier's own JSON shape onto stock/delivery/purchase-price —
 * without needing new PHP for each supplier.
 *
 * Request-changing operations are handled by Admin\SuppliersAjax.
 */
final class SuppliersPage {

	private Suppliers $suppliers;

	private bool $initialized = false;

	public function __construct( Suppliers $suppliers ) {
		$this->suppliers = $suppliers;
	}


	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		do_action( 'pdxw_admin_suppliers_page_init', $this );
	}


	public function render(): void {

		$this->authorize();

		$sources = $this->suppliers->sources()->all();

		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$editing = $edit_id ? $this->suppliers->sources()->find( $edit_id ) : null;

		?>
		<div class="wrap pdxw-admin">

			<h1>
				<?php esc_html_e( 'Suppliers', 'promi-data-x-woo' ); ?>
			</h1>

			<p class="description">
				<?php
				esc_html_e(
					'Suppliers enrich existing Promi products with data Promi does not reliably provide — stock, delivery time, and purchase price. Each source only ever touches SKUs starting with its configured prefix, and only fields you map below; nothing is created.',
					'promi-data-x-woo'
				);
				?>
			</p>

			<div id="pdxw-suppliers-message" class="pdxw-admin-message" aria-live="polite"></div>

			<?php $this->render_table( $sources ); ?>

			<?php $this->render_form( $editing ); ?>

		</div>
		<?php
	}


	private function render_table( array $sources ): void {

		?>
		<table class="wp-list-table widefat fixed striped">

			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name', 'promi-data-x-woo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'SKU Prefix', 'promi-data-x-woo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Enabled', 'promi-data-x-woo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Interval', 'promi-data-x-woo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last Run', 'promi-data-x-woo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'promi-data-x-woo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'promi-data-x-woo' ); ?></th>
				</tr>
			</thead>

			<tbody>

				<?php if ( empty( $sources ) ) : ?>

					<tr class="no-items">
						<td class="colspanchange" colspan="7">
							<?php esc_html_e( 'No supplier sources configured yet.', 'promi-data-x-woo' ); ?>
						</td>
					</tr>

				<?php else : ?>

					<?php foreach ( $sources as $source ) : ?>

						<tr data-supplier-row="<?php echo esc_attr( (int) $source->id ); ?>">

							<td><strong><?php echo esc_html( $source->name ?: __( '(unnamed)', 'promi-data-x-woo' ) ); ?></strong></td>

							<td><code><?php echo esc_html( $source->sku_prefix ); ?></code></td>

							<td>
								<?php
								echo $source->enabled
									? esc_html__( 'Yes', 'promi-data-x-woo' )
									: esc_html__( 'No', 'promi-data-x-woo' );
								?>
							</td>

							<td>
								<?php
								printf(
									/* translators: %d: minutes. */
									esc_html__( 'Every %d min', 'promi-data-x-woo' ),
									(int) $source->sync_interval_minutes
								);
								?>
							</td>

							<td><?php echo esc_html( $source->last_synced_at ?: __( 'Never', 'promi-data-x-woo' ) ); ?></td>

							<td>
								<?php if ( $source->last_status ) : ?>
									<span class="pdxw-status pdxw-status--<?php echo esc_attr( $source->last_status ); ?>">
										<?php echo esc_html( ucfirst( $source->last_status ) ); ?>
									</span>
									<br>
									<small title="<?php echo esc_attr( (string) $source->last_message ); ?>">
										<?php
										printf(
											/* translators: 1: matched count, 2: updated count. */
											esc_html__( 'matched %1$d, updated %2$d', 'promi-data-x-woo' ),
											(int) $source->last_matched,
											(int) $source->last_updated
										);
										?>
									</small>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>

							<td>
								<a
									class="button button-small"
									href="<?php echo esc_url( $this->edit_url( (int) $source->id ) ); ?>"
								>
									<?php esc_html_e( 'Edit', 'promi-data-x-woo' ); ?>
								</a>

								<button
									type="button"
									class="button button-small pdxw-run-supplier-now"
									data-supplier-id="<?php echo esc_attr( (int) $source->id ); ?>"
								>
									<?php esc_html_e( 'Sync Now', 'promi-data-x-woo' ); ?>
								</button>

								<button
									type="button"
									class="button button-small pdxw-delete-supplier"
									data-supplier-id="<?php echo esc_attr( (int) $source->id ); ?>"
								>
									<?php esc_html_e( 'Delete', 'promi-data-x-woo' ); ?>
								</button>
							</td>

						</tr>

					<?php endforeach; ?>

				<?php endif; ?>

			</tbody>

		</table>
		<?php
	}


	private function render_form( ?object $editing ): void {

		$id                    = $editing->id ?? 0;
		$name                  = $editing->name ?? '';
		$sku_prefix            = $editing->sku_prefix ?? '';
		$adapter_key           = $editing->adapter_key ?? '';
		$enabled               = $editing ? (bool) $editing->enabled : true;
		$endpoint_url          = $editing->endpoint_url ?? '';
		$price_endpoint_url    = $editing->price_endpoint_url ?? '';
		$credential            = $editing->credential ?? '';
		$sync_interval_minutes = $editing->sync_interval_minutes ?? 60;

		$adapters = $this->suppliers->adapters()->map();

		?>
		<div class="pdxw-box">

			<h2>
				<?php
				echo $editing
					? esc_html__( 'Edit Supplier Source', 'promi-data-x-woo' )
					: esc_html__( 'Add Supplier Source', 'promi-data-x-woo' );
				?>
			</h2>

			<?php if ( $editing ) : ?>
				<p>
					<a href="<?php echo esc_url( $this->edit_url( 0 ) ); ?>">
						<?php esc_html_e( '← Add a new source instead', 'promi-data-x-woo' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<table class="form-table" role="presentation" id="pdxw-supplier-form">

				<input type="hidden" id="pdxw-supplier-id" value="<?php echo esc_attr( (int) $id ); ?>">

				<tbody>

					<tr>
						<th scope="row"><label for="pdxw-supplier-name"><?php esc_html_e( 'Name', 'promi-data-x-woo' ); ?></label></th>
						<td>
							<input type="text" id="pdxw-supplier-name" class="regular-text" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php esc_attr_e( 'e.g. Supplier A58', 'promi-data-x-woo' ); ?>">
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="pdxw-supplier-prefix"><?php esc_html_e( 'SKU Prefix', 'promi-data-x-woo' ); ?></label></th>
						<td>
							<input type="text" id="pdxw-supplier-prefix" class="regular-text" value="<?php echo esc_attr( $sku_prefix ); ?>" placeholder="A58-">
							<p class="description"><?php esc_html_e( 'Only existing WooCommerce SKUs starting with this prefix will ever be touched. You can record a source here before code exists for it — just leave Adapter unset below.', 'promi-data-x-woo' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="pdxw-supplier-adapter"><?php esc_html_e( 'Adapter', 'promi-data-x-woo' ); ?></label></th>
						<td>
							<select id="pdxw-supplier-adapter">
								<option value=""><?php esc_html_e( '— Not yet supported (manual) —', 'promi-data-x-woo' ); ?></option>
								<?php foreach ( $adapters as $key => $adapter ) : ?>
									<option
										value="<?php echo esc_attr( $key ); ?>"
										data-prefix="<?php echo esc_attr( $adapter['prefix'] ); ?>"
										<?php selected( $adapter_key, $key ); ?>
									>
										<?php echo esc_html( sprintf( '%s (%s)', $adapter['label'], $adapter['prefix'] ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Which code actually knows how to fetch this supplier\'s feed. Auto-selected when the SKU prefix matches a known adapter; change it manually if needed.', 'promi-data-x-woo' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="pdxw-supplier-enabled"><?php esc_html_e( 'Enabled', 'promi-data-x-woo' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="pdxw-supplier-enabled" <?php checked( $enabled ); ?>>
								<?php esc_html_e( 'Run this source automatically on schedule', 'promi-data-x-woo' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="pdxw-supplier-interval"><?php esc_html_e( 'Sync Interval (minutes)', 'promi-data-x-woo' ); ?></label></th>
						<td>
							<input type="number" id="pdxw-supplier-interval" min="5" step="1" value="<?php echo esc_attr( (int) $sync_interval_minutes ); ?>">
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="pdxw-supplier-url"><?php esc_html_e( 'Endpoint URL', 'promi-data-x-woo' ); ?></label></th>
						<td>
							<input type="url" id="pdxw-supplier-url" class="large-text" value="<?php echo esc_attr( $endpoint_url ); ?>" placeholder="https://...">
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="pdxw-supplier-price-url"><?php esc_html_e( 'Price Feed URL (optional)', 'promi-data-x-woo' ); ?></label></th>
						<td>
							<input type="url" id="pdxw-supplier-price-url" class="large-text" value="<?php echo esc_attr( $price_endpoint_url ); ?>" placeholder="https://...">
							<p class="description"><?php esc_html_e( 'Only used by adapters that get purchase prices from a separate feed than stock (currently PFConcept and Giving Europe). Leave empty if this supplier has no separate pricing feed.', 'promi-data-x-woo' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="pdxw-supplier-credential"><?php esc_html_e( 'Credential / API Key', 'promi-data-x-woo' ); ?></label></th>
						<td>
							<input type="password" id="pdxw-supplier-credential" class="regular-text" value="<?php echo esc_attr( $credential ); ?>" placeholder="<?php esc_attr_e( 'Bearer token / API key, if this supplier needs one', 'promi-data-x-woo' ); ?>" autocomplete="off">
							<p class="description"><?php esc_html_e( 'Each adapter already knows which header its supplier expects this in — you only provide the secret value.', 'promi-data-x-woo' ); ?></p>
						</td>
					</tr>

				</tbody>

			</table>

			<p>
				<button type="button" id="pdxw-save-supplier" class="button button-primary">
					<?php
					echo $editing
						? esc_html__( 'Save Changes', 'promi-data-x-woo' )
						: esc_html__( 'Add Source', 'promi-data-x-woo' );
					?>
				</button>
			</p>

		</div>
		<?php
	}


	private function edit_url( int $id ): string {

		$args = [ 'page' => Menu::SUPPLIERS_SLUG ];

		if ( $id ) {
			$args['edit'] = $id;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}


	private function authorize(): void {

		if ( current_user_can( Menu::CAPABILITY ) ) {
			return;
		}

		wp_die(
			esc_html__( 'You do not have permission to manage Promi-Data X Woo.', 'promi-data-x-woo' )
		);
	}


	public function is_initialized(): bool {
		return $this->initialized;
	}
}
