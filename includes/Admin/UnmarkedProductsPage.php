<?php

namespace PromiDataXWoo\Admin;

use PromiDataXWoo\Pricing\MarkupRepository;
use PromiDataXWoo\Pricing\Pricing;
use PromiDataXWoo\Pricing\PriceSync;

defined( 'ABSPATH' ) || exit;

/**
 * Unmarked products report.
 *
 * Lists the SKUs of products that are sold at exactly the price given by
 * Promi: no article markup (the 0% default, or a category rule set to 0%)
 * and no manufacturer / supplier discount reducing the cost.
 *
 * Price-on-request products are not listed.
 *
 * The data comes from the report meta that Pricing\PriceSync writes
 * whenever a product is repriced, so this page is a simple paginated query
 * and never recalculates prices itself.
 */
final class UnmarkedProductsPage {

	private const PER_PAGE =
		50;

	private Pricing $pricing;

	private bool $initialized = false;


	public function __construct(
		Pricing $pricing
	) {
		$this->pricing =
			$pricing;
	}


	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		do_action(
			'pdxw_admin_unmarked_products_page_init',
			$this
		);
	}


	public function render(): void {

		if (
			! current_user_can(
				Menu::CAPABILITY
			)
		) {
			wp_die(
				esc_html__(
					'You do not have permission to access this page.',
					'promi-data-x-woo'
				)
			);
		}

		$search =
			isset( $_GET['s'] )
				? sanitize_text_field(
					wp_unslash( $_GET['s'] )
				)
				: '';

		$paged =
			isset( $_GET['paged'] )
				? max( 1, absint( $_GET['paged'] ) )
				: 1;

		$reported =
			$this->count_reported();

		$query =
			$this->query(
				$search,
				$paged
			);

		$total =
			(int) $query->found_posts;

		$pages =
			(int) $query->max_num_pages;

		$base_url =
			add_query_arg(
				[
					'page' =>
						Menu::UNMARKED_SLUG,
				],
				admin_url(
					'admin.php'
				)
			);

		$api_root =
			esc_url_raw(
				rest_url(
					'pdxw/v1'
				)
			);

		$nonce =
			wp_create_nonce(
				'wp_rest'
			);

		?>
		<div class="wrap pdxw-admin">

			<h1>
				<?php echo esc_html__( 'Unmarked Products', 'promi-data-x-woo' ); ?>
			</h1>

			<p class="description">
				<?php echo esc_html__( 'Products that are sold at exactly the price given by Promi: no markup applies (default or category rule at 0%) and no manufacturer or supplier discount reduces the cost. Price-on-request products are not listed.', 'promi-data-x-woo' ); ?>
			</p>

			<hr class="wp-header-end">

			<?php if ( 0 === $reported ) : ?>

				<div class="notice notice-warning inline">
					<p>
						<?php echo esc_html__( 'This report has not been generated yet. Click "Recalculate catalog prices" to build it (runs in the background).', 'promi-data-x-woo' ); ?>
					</p>
				</div>

			<?php endif; ?>

			<p>
				<button
					type="button"
					id="pdxw-unmarked-reprice"
					class="button"
				>
					<?php echo esc_html__( 'Recalculate catalog prices', 'promi-data-x-woo' ); ?>
				</button>

				<span
					id="pdxw-unmarked-status"
					class="description"
					style="margin-left: 8px;"
				></span>
			</p>

			<form
				method="get"
				action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>"
			>
				<input
					type="hidden"
					name="page"
					value="<?php echo esc_attr( Menu::UNMARKED_SLUG ); ?>"
				>

				<p class="search-box" style="float:none; margin: 8px 0;">
					<input
						type="search"
						name="s"
						value="<?php echo esc_attr( $search ); ?>"
						placeholder="<?php echo esc_attr__( 'SKU contains…', 'promi-data-x-woo' ); ?>"
					>

					<button
						type="submit"
						class="button"
					>
						<?php echo esc_html__( 'Search SKU', 'promi-data-x-woo' ); ?>
					</button>

					<?php if ( '' !== $search ) : ?>
						<a
							href="<?php echo esc_url( $base_url ); ?>"
							class="button-link"
						>
							<?php echo esc_html__( 'Clear', 'promi-data-x-woo' ); ?>
						</a>
					<?php endif; ?>
				</p>
			</form>

			<p>
				<strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of products */
							_n(
								'%s product',
								'%s products',
								$total,
								'promi-data-x-woo'
							),
							number_format_i18n( $total )
						)
					);
					?>
				</strong>
			</p>

			<table class="wp-list-table widefat fixed striped">

				<thead>
					<tr>
						<th scope="col" style="width: 14%;">
							<?php echo esc_html__( 'SKU', 'promi-data-x-woo' ); ?>
						</th>
						<th scope="col">
							<?php echo esc_html__( 'Product', 'promi-data-x-woo' ); ?>
						</th>
						<th scope="col">
							<?php echo esc_html__( 'Category', 'promi-data-x-woo' ); ?>
						</th>
						<th scope="col" style="width: 12%;">
							<?php echo esc_html__( 'Brand', 'promi-data-x-woo' ); ?>
						</th>
						<th scope="col" style="width: 14%;">
							<?php echo esc_html__( 'Promi price source', 'promi-data-x-woo' ); ?>
						</th>
						<th scope="col" style="width: 9%;">
							<?php echo esc_html__( 'Cost', 'promi-data-x-woo' ); ?>
						</th>
						<th scope="col" style="width: 9%;">
							<?php echo esc_html__( 'Selling price', 'promi-data-x-woo' ); ?>
						</th>
						<th scope="col" style="width: 12%;">
							<?php echo esc_html__( 'Markup rule', 'promi-data-x-woo' ); ?>
						</th>
					</tr>
				</thead>

				<tbody>

					<?php if ( ! $query->have_posts() ) : ?>

						<tr class="no-items">
							<td class="colspanchange" colspan="8">
								<?php echo esc_html__( 'No unmarked products found.', 'promi-data-x-woo' ); ?>
							</td>
						</tr>

					<?php else : ?>

						<?php foreach ( $query->posts as $product_id ) : ?>

							<?php $this->render_row( (int) $product_id ); ?>

						<?php endforeach; ?>

					<?php endif; ?>

				</tbody>

			</table>

			<?php if ( $pages > 1 ) : ?>

				<div class="tablenav">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								[
									'base'      => add_query_arg( 'paged', '%#%', $base_url ),
									'format'    => '',
									'current'   => $paged,
									'total'     => $pages,
									'add_args'  => '' !== $search ? [ 's' => $search ] : false,
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
								]
							)
						);
						?>
					</div>
				</div>

			<?php endif; ?>

		</div>

		<script>
		(function () {

			const button =
				document.getElementById( 'pdxw-unmarked-reprice' );

			const status =
				document.getElementById( 'pdxw-unmarked-status' );

			const headers = {
				'Content-Type': 'application/json',
				'X-WP-Nonce': <?php echo wp_json_encode( $nonce ); ?>,
			};

			const endpoint =
				<?php echo wp_json_encode( $api_root . '/pricing/reprice' ); ?>;

			function poll() {

				fetch( endpoint, { headers: headers } )
					.then( function ( r ) { return r.json(); } )
					.then(
						function ( data ) {

							const pending = data.pending_jobs || 0;

							status.textContent =
								pending > 0
									? pending + ' ' + <?php echo wp_json_encode( __( 'background job(s) pending… reload this page when finished.', 'promi-data-x-woo' ) ); ?>
									: '';

							if ( pending > 0 ) {
								window.setTimeout( poll, 5000 );
							}
						}
					)
					.catch( function () {} );
			}

			button.addEventListener(
				'click',
				function () {

					button.disabled = true;

					fetch( endpoint, { method: 'POST', headers: headers } )
						.then( function ( r ) { return r.json(); } )
						.then(
							function () {
								button.disabled = false;
								poll();
							}
						)
						.catch(
							function () {
								button.disabled = false;
								status.textContent =
									<?php echo wp_json_encode( __( 'Could not start the recalculation.', 'promi-data-x-woo' ) ); ?>;
							}
						);
				}
			);

			poll();

		})();
		</script>
		<?php
	}


	/*
	|--------------------------------------------------------------------------
	| Rows
	|--------------------------------------------------------------------------
	*/

	private function render_row(
		int $product_id
	): void {

		$sku =
			(string) get_post_meta(
				$product_id,
				'_sku',
				true
			);

		$source =
			(string) get_post_meta(
				$product_id,
				PriceSync::META_SOURCE,
				true
			);

		$cost =
			get_post_meta(
				$product_id,
				PriceSync::META_COST,
				true
			);

		$selling =
			get_post_meta(
				$product_id,
				PriceSync::META_SELLING,
				true
			);

		$categories =
			wp_get_post_terms(
				$product_id,
				'product_cat',
				[
					'fields' =>
						'names',
				]
			);

		$brands =
			wp_get_post_terms(
				$product_id,
				'product_brand',
				[
					'fields' =>
						'names',
				]
			);

		?>
		<tr>

			<td>
				<code><?php echo esc_html( $sku ); ?></code>
			</td>

			<td>
				<a href="<?php echo esc_url( get_edit_post_link( $product_id ) ); ?>">
					<?php echo esc_html( get_the_title( $product_id ) ); ?>
				</a>
			</td>

			<td>
				<?php
				echo esc_html(
					is_array( $categories )
						? implode( ', ', $categories )
						: ''
				);
				?>
			</td>

			<td>
				<?php
				echo esc_html(
					is_array( $brands )
						? implode( ', ', $brands )
						: ''
				);
				?>
			</td>

			<td>
				<?php echo esc_html( $this->source_label( $source ) ); ?>
			</td>

			<td>
				<?php echo wp_kses_post( is_numeric( $cost ) ? wc_price( (float) $cost ) : '—' ); ?>
			</td>

			<td>
				<?php echo wp_kses_post( is_numeric( $selling ) ? wc_price( (float) $selling ) : '—' ); ?>
			</td>

			<td>
				<?php echo esc_html( $this->rule_label( $product_id ) ); ?>
			</td>

		</tr>
		<?php
	}


	private function source_label(
		string $source
	): string {

		return match ( $source ) {

			'purchase_price' =>
				__( 'Buying price', 'promi-data-x-woo' ),

			'recommended_selling_price' =>
				__( 'Recommended selling price', 'promi-data-x-woo' ),

			default =>
				'—',
		};
	}


	/**
	 * Which markup rule leaves this product at 0%.
	 */
	private function rule_label(
		int $product_id
	): string {

		$term_ids =
			wp_get_post_terms(
				$product_id,
				'product_cat',
				[
					'fields' =>
						'ids',
				]
			);

		if ( is_array( $term_ids ) ) {

			$repository =
				$this->pricing
					->markup_repository();

			foreach ( $term_ids as $term_id ) {

				if (
					null !== $repository->get(
						MarkupRepository::TYPE_CATEGORY,
						(int) $term_id
					)
				) {

					return __( 'Category rule (0%)', 'promi-data-x-woo' );
				}
			}
		}

		return __( 'Default (0%)', 'promi-data-x-woo' );
	}


	/*
	|--------------------------------------------------------------------------
	| Queries
	|--------------------------------------------------------------------------
	*/

	private function query(
		string $search,
		int $paged
	): \WP_Query {

		$meta_query = [
			'relation' =>
				'AND',

			[
				'key'   =>
					PriceSync::META_UNMARKED,

				'value' =>
					'1',
			],
		];

		if ( '' !== $search ) {

			$meta_query[] = [
				'key'     =>
					'_sku',

				'value'   =>
					$search,

				'compare' =>
					'LIKE',
			];
		}

		return new \WP_Query(
			[
				'post_type'      =>
					'product',

				'post_status'    =>
					[
						'publish',
						'draft',
						'pending',
						'private',
					],

				'fields'         =>
					'ids',

				'posts_per_page' =>
					self::PER_PAGE,

				'paged'          =>
					$paged,

				'meta_query'     =>
					$meta_query,

				'meta_key'       =>
					'_sku',

				'orderby'        =>
					'meta_value',

				'order'          =>
					'ASC',

				'no_found_rows'  =>
					false,
			]
		);
	}


	/**
	 * Number of products that already carry the report meta (0 means the
	 * report was never generated).
	 */
	private function count_reported(): int {

		$query =
			new \WP_Query(
				[
					'post_type'      =>
						'product',

					'post_status'    =>
						'any',

					'fields'         =>
						'ids',

					'posts_per_page' =>
						1,

					'meta_query'     =>
						[
							[
								'key'     =>
									PriceSync::META_UNMARKED,

								'compare' =>
									'EXISTS',
							],
						],
				]
			);

		return (int) $query->found_posts;
	}


	public function is_initialized(): bool {

		return $this->initialized;
	}
}
