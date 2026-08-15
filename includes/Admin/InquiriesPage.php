<?php

namespace PromiDataXWoo\Admin;

use PromiDataXWoo\Frontend\Inquiries;

defined( 'ABSPATH' ) || exit;

/**
 * Price-on-request inquiries admin page.
 *
 * Lists submissions from the frontend "Angebot anfordern" form
 * (templates/frontend/add-to-cart.php, Frontend\Ajax::submit_inquiry()).
 *
 * Storage/query logic lives in Frontend\Inquiries — this class only owns
 * presentation, following the same list-table conventions as PromiPages.
 */
final class InquiriesPage {

	private const PER_PAGE = 20;

	private bool $initialized = false;


	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;


		do_action(
			'pdxw_admin_inquiries_page_init',
			$this
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Render
	|--------------------------------------------------------------------------
	*/

	public function render(): void {

		$this->authorize();


		$search =
			$this->search();

		$status_filter =
			$this->request_key(
				'inquiry_status'
			);

		if (
			'' !== $status_filter
			&& ! Inquiries::is_valid_status(
				$status_filter
			)
		) {
			$status_filter = '';
		}

		$orderby =
			$this->orderby(
				[
					'id',
					'name',
					'email',
					'status',
					'created_at',
				],
				'created_at'
			);

		$order =
			$this->order();

		$page =
			$this->page_number();


		$result =
			Inquiries::paged(
				$search,
				$status_filter,
				$orderby,
				$order,
				$page,
				self::PER_PAGE
			);


		$status_counts =
			Inquiries::counts_by_status();

		?>
		<div class="wrap pdxw-admin">

			<h1>
				<?php
				echo esc_html__(
					'Inquiries',
					'promi-data-x-woo'
				);
				?>
			</h1>

			<p class="description">
				<?php
				echo esc_html__(
					'Price-on-request quote requests submitted from the storefront.',
					'promi-data-x-woo'
				);
				?>
			</p>


			<div
				id="pdxw-inquiries-message"
				class="pdxw-admin-message"
				aria-live="polite"
			></div>


			<?php
			$this->search_form(
				$search,
				[
					'inquiry_status' => $status_filter,
				]
			);
			?>


			<?php
			$this->status_filters(
				$status_filter,
				$status_counts
			);
			?>


			<table class="wp-list-table widefat fixed striped">

				<thead>
					<tr>

						<?php
						$this->sortable_header(
							__( 'ID', 'promi-data-x-woo' ),
							'id',
							$orderby,
							$order
						);

						$this->sortable_header(
							__( 'Name', 'promi-data-x-woo' ),
							'name',
							$orderby,
							$order
						);

						$this->sortable_header(
							__( 'Email', 'promi-data-x-woo' ),
							'email',
							$orderby,
							$order
						);
						?>

						<th scope="col">
							<?php echo esc_html__( 'Phone', 'promi-data-x-woo' ); ?>
						</th>

						<th scope="col">
							<?php echo esc_html__( 'Product', 'promi-data-x-woo' ); ?>
						</th>

						<th scope="col">
							<?php echo esc_html__( 'Qty', 'promi-data-x-woo' ); ?>
						</th>

						<th scope="col">
							<?php echo esc_html__( 'Message', 'promi-data-x-woo' ); ?>
						</th>

						<?php
						$this->sortable_header(
							__( 'Status', 'promi-data-x-woo' ),
							'status',
							$orderby,
							$order
						);

						$this->sortable_header(
							__( 'Received', 'promi-data-x-woo' ),
							'created_at',
							$orderby,
							$order
						);
						?>

						<th scope="col">
							<?php echo esc_html__( 'Actions', 'promi-data-x-woo' ); ?>
						</th>

					</tr>
				</thead>


				<tbody>

					<?php if ( empty( $result['rows'] ) ) : ?>

						<?php
						$this->empty_row(
							9,
							__( 'No inquiries found.', 'promi-data-x-woo' )
						);
						?>

					<?php else : ?>

						<?php foreach ( $result['rows'] as $row ) : ?>

							<?php
							$id =
								(int) ( $row['id'] ?? 0 );

							$status =
								sanitize_key( (string) ( $row['status'] ?? '' ) );

							$product_id =
								(int) ( $row['product_id'] ?? 0 );
							?>

							<tr data-inquiry-id="<?php echo esc_attr( $id ); ?>">

								<td>
									<?php echo esc_html( $id ); ?>
								</td>

								<td>
									<strong>
										<?php echo esc_html( (string) ( $row['name'] ?? '' ) ); ?>
									</strong>
								</td>

								<td>
									<a href="mailto:<?php echo esc_attr( (string) ( $row['email'] ?? '' ) ); ?>">
										<?php echo esc_html( (string) ( $row['email'] ?? '' ) ); ?>
									</a>
								</td>

								<td>
									<?php echo esc_html( (string) ( $row['phone'] ?? '' ) ?: '—' ); ?>
								</td>

								<td>
									<?php $this->render_product_link( $product_id ); ?>
								</td>

								<td>
									<?php echo esc_html( (string) ( ( (int) ( $row['quantity'] ?? 0 ) ) ?: '—' ) ); ?>
								</td>

								<td>
									<?php
									$message =
										trim( (string) ( $row['message'] ?? '' ) );

									if ( '' === $message ) {

										echo '—';

									} else {

										printf(
											'<span title="%1$s">%2$s</span>',
											esc_attr( $message ),
											esc_html( wp_trim_words( $message, 12 ) )
										);
									}
									?>
								</td>

								<td>
									<span class="pdxw-status pdxw-status--<?php echo esc_attr( $status ); ?>">
										<?php echo esc_html( ucfirst( $status ) ); ?>
									</span>
								</td>

								<td>
									<?php echo esc_html( (string) ( $row['created_at'] ?? '' ) ); ?>
								</td>

								<td>

									<select class="pdxw-inquiry-status-select">

										<?php foreach ( Inquiries::statuses() as $option ) : ?>

											<option
												value="<?php echo esc_attr( $option ); ?>"
												<?php selected( $status, $option ); ?>
											>
												<?php echo esc_html( ucfirst( $option ) ); ?>
											</option>

										<?php endforeach; ?>

									</select>

									<button
										type="button"
										class="button button-small pdxw-delete-inquiry"
									>
										<?php echo esc_html__( 'Delete', 'promi-data-x-woo' ); ?>
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
				[
					'inquiry_status' => $status_filter,
				]
			);
			?>

		</div>
		<?php
	}


	/*
	|--------------------------------------------------------------------------
	| Status Filters
	|--------------------------------------------------------------------------
	*/

	private function status_filters(
		string $current_status,
		array $status_counts
	): void {

		$total =
			array_sum( $status_counts );

		?>
		<div class="pdxw-filters">

			<p>

				<strong>
					<?php echo esc_html__( 'Status:', 'promi-data-x-woo' ); ?>
				</strong>


				<?php foreach ( Inquiries::statuses() as $status ) : ?>

					<a
						href="<?php echo esc_url( $this->admin_page_url( [ 'inquiry_status' => $status ] ) ); ?>"
						<?php
						if ( $current_status === $status ) {
							echo 'class="current" aria-current="page"';
						}
						?>
					>
						<?php
						echo esc_html(
							sprintf(
								'%s (%s)',
								ucfirst( $status ),
								number_format_i18n( $status_counts[ $status ] ?? 0 )
							)
						);
						?>
					</a>

					&nbsp;

				<?php endforeach; ?>


				<a href="<?php echo esc_url( $this->admin_page_url( [] ) ); ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: total number of inquiries. */
							__( 'All (%s)', 'promi-data-x-woo' ),
							number_format_i18n( $total )
						)
					);
					?>
				</a>

			</p>

		</div>
		<?php
	}


	/*
	|--------------------------------------------------------------------------
	| Product Link
	|--------------------------------------------------------------------------
	*/

	private function render_product_link(
		int $product_id
	): void {

		if ( ! $product_id ) {
			echo '—';
			return;
		}

		$product =
			wc_get_product( $product_id );

		if ( ! $product ) {
			echo esc_html__( 'N/A', 'promi-data-x-woo' );
			return;
		}

		$edit_url =
			get_edit_post_link( $product_id, '' );

		if ( $edit_url ) {

			printf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $edit_url ),
				esc_html( $product->get_name() )
			);

		} else {

			echo esc_html( $product->get_name() );
		}
	}


	/*
	|--------------------------------------------------------------------------
	| Search / Sorting / Pagination
	|--------------------------------------------------------------------------
	|
	| Mirrors PromiPages' list-table helpers. Kept self-contained rather than
	| shared, matching this admin's existing per-page-class convention (see
	| MarkupPage, PricingPage, PrintingPage).
	*/

	private function search_form(
		string $search,
		array $hidden = []
	): void {

		?>
		<form method="get">

			<input type="hidden" name="page" value="<?php echo esc_attr( Menu::INQUIRIES_SLUG ); ?>">

			<?php foreach ( $hidden as $key => $value ) : ?>

				<?php if ( '' === $value ) continue; ?>

				<input
					type="hidden"
					name="<?php echo esc_attr( $key ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
				>

			<?php endforeach; ?>

			<p class="search-box">

				<label class="screen-reader-text" for="pdxw-inquiries-search-input">
					<?php echo esc_html__( 'Search', 'promi-data-x-woo' ); ?>
				</label>

				<input
					type="search"
					id="pdxw-inquiries-search-input"
					name="s"
					value="<?php echo esc_attr( $search ); ?>"
				>

				<input
					type="submit"
					class="button"
					value="<?php echo esc_attr__( 'Search', 'promi-data-x-woo' ); ?>"
				>

			</p>

		</form>
		<?php
	}


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
					'orderby' => $column,
					'order'   => $next_order,
					'paged'   => false,
				]
			);

		$class =
			$current_orderby === $column
				? 'sorted ' . strtolower( $current_order )
				: 'sortable desc';

		printf(
			'<th scope="col" class="manage-column %1$s"><a href="%2$s"><span>%3$s</span><span class="sorting-indicators"><span class="sorting-indicator asc"></span><span class="sorting-indicator desc"></span></span></a></th>',
			esc_attr( $class ),
			esc_url( $url ),
			esc_html( $label )
		);
	}


	private function pagination(
		int $total,
		int $page,
		array $extra = []
	): void {

		$total_pages =
			(int) ceil( $total / self::PER_PAGE );

		if ( $total_pages <= 1 ) {
			return;
		}

		$base_args =
			array_merge(
				[ 'page' => Menu::INQUIRIES_SLUG ],
				array_filter(
					$extra,
					static fn( mixed $value ): bool => '' !== $value
				)
			);

		$search = $this->search();

		if ( '' !== $search ) {
			$base_args['s'] = $search;
		}

		$orderby = $this->request_key( 'orderby' );

		if ( '' !== $orderby ) {
			$base_args['orderby'] = $orderby;
		}

		$order = $this->request_key( 'order' );

		if ( '' !== $order ) {
			$base_args['order'] = $order;
		}

		$base =
			add_query_arg(
				$base_args,
				admin_url( 'admin.php' )
			);

		$links =
			paginate_links(
				[
					'base'      => add_query_arg( 'paged', '%#%', $base ),
					'format'    => '',
					'current'   => $page,
					'total'     => $total_pages,
					'prev_text' => '‹',
					'next_text' => '›',
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
							__( '%s items', 'promi-data-x-woo' ),
							number_format_i18n( $total )
						)
					);
					?>
				</span>

				<span class="pagination-links">
					<?php echo wp_kses_post( $links ); ?>
				</span>

			</div>

		</div>
		<?php
	}


	private function empty_row(
		int $columns,
		string $message
	): void {

		printf(
			'<tr class="no-items"><td class="colspanchange" colspan="%1$d">%2$s</td></tr>',
			absint( $columns ),
			esc_html( $message )
		);
	}


	private function admin_page_url(
		array $args = []
	): string {

		$args =
			array_filter(
				array_merge(
					[ 'page' => Menu::INQUIRIES_SLUG ],
					$args
				),
				static fn( mixed $value ): bool => '' !== $value
			);

		return add_query_arg(
			$args,
			admin_url( 'admin.php' )
		);
	}


	private function authorize(): void {

		if ( current_user_can( Menu::CAPABILITY ) ) {
			return;
		}

		wp_die(
			esc_html__(
				'You do not have permission to manage Promi-Data X Woo.',
				'promi-data-x-woo'
			)
		);
	}


	private function search(): string {

		if (
			! isset( $_GET['s'] )
			|| is_array( $_GET['s'] )
		) {
			return '';
		}

		return trim(
			sanitize_text_field(
				wp_unslash( $_GET['s'] )
			)
		);
	}


	private function request_key(
		string $key
	): string {

		if (
			! isset( $_GET[ $key ] )
			|| is_array( $_GET[ $key ] )
		) {
			return '';
		}

		return sanitize_key(
			wp_unslash( $_GET[ $key ] )
		);
	}


	private function orderby(
		array $allowed,
		string $default
	): string {

		$orderby = $this->request_key( 'orderby' );

		return in_array( $orderby, $allowed, true )
			? $orderby
			: $default;
	}


	private function order(): string {

		$order = strtoupper( $this->request_key( 'order' ) );

		return 'ASC' === $order
			? 'ASC'
			: 'DESC';
	}


	private function page_number(): int {

		if (
			! isset( $_GET['paged'] )
			|| is_array( $_GET['paged'] )
		) {
			return 1;
		}

		return max(
			1,
			absint( wp_unslash( $_GET['paged'] ) )
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
