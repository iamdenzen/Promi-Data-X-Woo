<?php

namespace PromiDataXWoo\Admin;

use PromiDataXWoo\Catalog\Catalog;
use PromiDataXWoo\Pricing\CostCalculator;
use PromiDataXWoo\Pricing\Pricing;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;

defined( 'ABSPATH' ) || exit;

/**
 * Pricing calculator administration page.
 *
 * Lists WooCommerce products (simple and variable) with SKU and minimum
 * order quantity, and lets an admin drill into one product to see the
 * exact calculated price for every configured quantity tier — including
 * which of the three cost-resolution scenarios
 * (Pricing\CostCalculator::resolve_cost()) it falls under:
 *
 * Case 1: purchase_price
 *     Promi GeneralBuyingPrice is used directly as the cost basis.
 *
 * Case 2: recommended_selling_price
 *     Promi RecommendedSellingPrice, reduced by the manufacturer discount,
 *     is used as the cost basis.
 *
 * Case 3: price_on_request
 *     Neither price source is available.
 *
 * This store prices per variation, never on the parent product, so a
 * variable product's detail view lists every one of its variations
 * individually. Nothing is ever calculated at the parent level for a
 * variable product.
 *
 * Read-only: this page never writes pricing data. Editing stored tiers
 * remains PricingPage's responsibility.
 */
final class PricingCalculatorPage {

	private const PER_PAGE = 20;

	private const PRODUCT_ARG =
		'product';

	private Catalog $catalog;

	private Pricing $pricing;

	private bool $initialized = false;


	public function __construct(
		Catalog $catalog,
		Pricing $pricing
	) {
		$this->catalog = $catalog;
		$this->pricing = $pricing;
	}


	/**
	 * Register pricing-calculator-admin integrations.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		do_action(
			'pdxw_admin_pricing_calculator_page_init',
			$this
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Main Page
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render the product pricing-calculator page.
	 */
	public function render(): void {

		$this->authorize();


		$search =
			$this->search();


		$page =
			$this->page_number();


		$result =
			$this->paginated_products(
				$page,
				$search
			);


		$selected_product_id =
			$this->selected_product_id();


		?>
		<div class="wrap pdxw-admin pdxw-pricing-calculator-page">

			<h1>
				<?php
				echo esc_html__(
					'Pricing Calculator',
					'promi-data-x-woo'
				);
				?>
			</h1>


			<p class="description">
				<?php
				echo esc_html__(
					'Inspect the exact calculated price for every quantity tier of a product, including which pricing scenario it resolves through.',
					'promi-data-x-woo'
				);
				?>
			</p>


			<?php
			/*
			|--------------------------------------------------------------------------
			| Search
			|--------------------------------------------------------------------------
			*/
			?>

			<form
				method="get"
				class="pdxw-pricing-calculator-search"
			>

				<input
					type="hidden"
					name="page"
					value="<?php echo esc_attr(
						Menu::PRICING_CALCULATOR_SLUG
					); ?>"
				>


				<p class="search-box">

					<label
						class="screen-reader-text"
						for="pdxw-pricing-calculator-s"
					>
						<?php
						echo esc_html__(
							'Search products',
							'promi-data-x-woo'
						);
						?>
					</label>


					<input
						type="search"
						id="pdxw-pricing-calculator-s"
						name="s"
						value="<?php echo esc_attr(
							$search
						); ?>"
						placeholder="<?php echo esc_attr__(
							'Search by SKU',
							'promi-data-x-woo'
						); ?>"
					>


					<button
						type="submit"
						class="button"
					>
						<?php
						echo esc_html__(
							'Search',
							'promi-data-x-woo'
						);
						?>
					</button>


					<?php if ( '' !== $search ) : ?>

						<a
							class="button"
							href="<?php echo esc_url(
								$this->page_url()
							); ?>"
						>
							<?php
							echo esc_html__(
								'Clear',
								'promi-data-x-woo'
							);
							?>
						</a>

					<?php endif; ?>

				</p>

			</form>


			<?php
			/*
			|--------------------------------------------------------------------------
			| Selected Product
			|--------------------------------------------------------------------------
			*/
			?>

			<?php if ( $selected_product_id ) : ?>

				<?php
				$this->render_product_details(
					$selected_product_id,
					$search,
					$page
				);
				?>

			<?php endif; ?>


			<?php
			/*
			|--------------------------------------------------------------------------
			| Product List
			|--------------------------------------------------------------------------
			*/
			?>

			<div class="pdxw-box">

				<div class="pdxw-table-header">

					<strong>
						<?php
						printf(
							/* translators: %d: total products. */
							esc_html__(
								'%d products',
								'promi-data-x-woo'
							),
							(int) (
								$result['total']
									?? 0
							)
						);
						?>
					</strong>

				</div>


				<table
					class="
						wp-list-table
						widefat
						fixed
						striped
						pdxw-pricing-calculator-table
					"
				>

					<thead>

						<tr>

							<th
								scope="col"
								style="width:70px;"
							>
								<?php
								echo esc_html__(
									'ID',
									'promi-data-x-woo'
								);
								?>
							</th>


							<th scope="col">
								<?php
								echo esc_html__(
									'Name',
									'promi-data-x-woo'
								);
								?>
							</th>


							<th scope="col">
								<?php
								echo esc_html__(
									'SKU',
									'promi-data-x-woo'
								);
								?>
							</th>


							<th
								scope="col"
								style="width:140px;"
							>
								<?php
								echo esc_html__(
									'Minimum Quantity',
									'promi-data-x-woo'
								);
								?>
							</th>


							<th
								scope="col"
								style="width:130px;"
							>
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

						<?php
						$items =
							is_array(
								$result['items']
									?? null
							)
								? $result['items']
								: [];
						?>


						<?php if ( empty( $items ) ) : ?>

							<tr>

								<td colspan="5">
									<?php
									echo esc_html__(
										'No products found.',
										'promi-data-x-woo'
									);
									?>
								</td>

							</tr>

						<?php else : ?>


							<?php foreach (
								$items
									as $product
							) : ?>

								<?php
								if (
									! $product instanceof WC_Product
								) {
									continue;
								}


								$product_id =
									$product->get_id();
								?>


								<tr>

									<td>
										<?php
										echo esc_html(
											(string)
												$product_id
										);
										?>
									</td>


									<td>

										<strong>
											<?php
											echo esc_html(
												$product->get_name()
											);
											?>
										</strong>


										<?php if (
											$product instanceof WC_Product_Variable
										) : ?>

											<br>

											<span class="description">
												<?php
												echo esc_html__(
													'Variable product',
													'promi-data-x-woo'
												);
												?>
											</span>

										<?php endif; ?>

									</td>


									<td>

										<?php
										$sku =
											(string)
												$product->get_sku();
										?>

										<?php if ( '' !== $sku ) : ?>

											<code>
												<?php
												echo esc_html(
													$sku
												);
												?>
											</code>

										<?php else : ?>

											&mdash;

										<?php endif; ?>

									</td>


									<td>
										<?php
										echo esc_html(
											$this->min_qty_display(
												$product
											)
										);
										?>
									</td>


									<td>

										<a
											class="button button-small"
											href="<?php echo esc_url(
												$this->product_url(
													$product_id,
													$search,
													$page
												)
											); ?>"
										>
											<?php
											echo esc_html__(
												'View Pricing',
												'promi-data-x-woo'
											);
											?>
										</a>

									</td>

								</tr>

							<?php endforeach; ?>


						<?php endif; ?>

					</tbody>

				</table>


				<?php
				$this->pagination(
					$result,
					$search
				);
				?>

			</div>

		</div>
		<?php
	}


	/*
	|--------------------------------------------------------------------------
	| Product Query
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return one page of simple/variable WooCommerce products.
	 *
	 * @return array{items:array<int,WC_Product>,total:int,total_pages:int,page:int}
	 */
	private function paginated_products(
		int $page,
		string $search
	): array {

		$args = [
			'status'   => [
				'publish',
				'draft',
				'pending',
				'private',
			],

			'type'     => [
				'simple',
				'variable',
			],

			'limit'    => self::PER_PAGE,

			'page'     => max(
				1,
				$page
			),

			'orderby'  => 'title',

			'order'    => 'ASC',

			'paginate' => true,

			'return'   => 'objects',
		];

		if ( '' !== $search ) {
			$args['sku'] = $search;
		}

		$result =
			wc_get_products(
				$args
			);

		return [
			'items'       =>
				is_array( $result->products ?? null )
					? $result->products
					: [],

			'total'       =>
				(int) (
					$result->total
						?? 0
				),

			'total_pages' =>
				max(
					1,
					(int) (
						$result->max_num_pages
							?? 1
					)
				),

			'page'        =>
				max(
					1,
					$page
				),
		];
	}


	/**
	 * Return a human-readable minimum-order-quantity value, or an em dash
	 * when none is stored — this deliberately does not default to 1, so
	 * "no data" is never shown as though it were a real configured value.
	 */
	private function min_qty_display(
		WC_Product $product
	): string {

		$raw =
			$product->get_meta(
				'min_order_qty',
				true
			);

		$value =
			absint(
				$raw
			);

		return $value > 0
			? (string) $value
			: '—';
	}


	/*
	|--------------------------------------------------------------------------
	| Product Details
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render the full calculated pricing breakdown for one product.
	 *
	 * Simple products get a single breakdown. Variable products get one
	 * breakdown per variation — never one for the parent itself, since
	 * this store has no pricing data at the parent level.
	 */
	private function render_product_details(
		int $product_id,
		string $search,
		int $page
	): void {

		$product =
			wc_get_product(
				$product_id
			);

		if ( ! $product instanceof WC_Product ) {

			?>
			<div class="notice notice-warning">

				<p>
					<?php
					echo esc_html__(
						'The selected product no longer exists.',
						'promi-data-x-woo'
					);
					?>
				</p>

			</div>
			<?php

			return;
		}


		?>
		<div class="pdxw-box pdxw-pricing-calculator-details">

			<div class="pdxw-section-header">

				<div>

					<h2>
						<?php
						echo esc_html(
							$product->get_name()
						);
						?>
					</h2>


					<p>

						<strong>
							<?php
							echo esc_html__(
								'Product ID:',
								'promi-data-x-woo'
							);
							?>
						</strong>

						<?php
						echo esc_html(
							(string) $product_id
						);
						?>

						<?php
						$sku =
							(string)
								$product->get_sku();
						?>

						<?php if ( '' !== $sku ) : ?>

							&nbsp;&middot;&nbsp;

							<strong>
								<?php
								echo esc_html__(
									'SKU:',
									'promi-data-x-woo'
								);
								?>
							</strong>

							<code>
								<?php
								echo esc_html(
									$sku
								);
								?>
							</code>

						<?php endif; ?>

					</p>

				</div>


				<a
					class="button"
					href="<?php echo esc_url(
						$this->page_url(
							[
								's'     => $search,
								'paged' => $page,
							]
						)
					); ?>"
				>
					<?php
					echo esc_html__(
						'Close Details',
						'promi-data-x-woo'
					);
					?>
				</a>

			</div>


			<?php if ( $product instanceof WC_Product_Variable ) : ?>

				<?php
				$variation_ids =
					$product->get_children();
				?>

				<?php if ( empty( $variation_ids ) ) : ?>

					<p class="description">
						<?php
						echo esc_html__(
							'This variable product has no variations.',
							'promi-data-x-woo'
						);
						?>
					</p>

				<?php else : ?>

					<?php foreach (
						$variation_ids
							as $variation_id
					) : ?>

						<?php
						$variation =
							wc_get_product(
								$variation_id
							);

						if (
							! $variation instanceof WC_Product_Variation
						) {
							continue;
						}

						$label =
							(string)
								$variation->get_attribute_summary();

						if ( '' === $label ) {

							$label =
								sprintf(
									/* translators: %d: variation ID. */
									__(
										'Variation #%d',
										'promi-data-x-woo'
									),
									$variation_id
								);
						}
						?>

						<?php
						$this->render_target(
							$product_id,
							$variation_id,
							$label,
							$variation
						);
						?>

					<?php endforeach; ?>

				<?php endif; ?>

			<?php else : ?>

				<?php
				$this->render_target(
					$product_id,
					0,
					__(
						'Pricing',
						'promi-data-x-woo'
					),
					$product
				);
				?>

			<?php endif; ?>

		</div>
		<?php
	}


	/**
	 * Render one product/variation's full calculated pricing breakdown.
	 */
	private function render_target(
		int $product_id,
		int $variation_id,
		string $label,
		WC_Product $target_product
	): void {

		$sku =
			(string)
				$target_product->get_sku();

		$min_qty =
			$this->min_qty_display(
				$target_product
			);

		$quantities =
			$this->pricing->quantities(
				$product_id,
				$variation_id
			);

		$costs =
			$this->pricing->costs();

		$rows = [];

		foreach ( $quantities as $quantity ) {

			$quantity =
				absint(
					$quantity
				);

			if ( ! $quantity ) {
				continue;
			}

			$rows[] = [
				'qty'    => $quantity,
				'result' => $costs->calculate(
					$product_id,
					$variation_id,
					$quantity
				),
			];
		}

		/*
		 * Even with no configured tiers, resolving quantity 1 still tells
		 * us which scenario this target would fall under — useful for
		 * distinguishing "genuinely price on request" from "just nothing
		 * configured yet".
		 */
		$headline_result =
			! empty( $rows )
				? $rows[0]['result']
				: $costs->calculate(
					$product_id,
					$variation_id,
					1
				);

		?>
		<div class="pdxw-box pdxw-pricing-target">

			<div class="pdxw-tier-target-header">

				<div>

					<h3 style="margin-top:0;">
						<?php
						echo esc_html(
							$label
						);
						?>
					</h3>


					<p>

						<strong>
							<?php
							echo esc_html__(
								'SKU:',
								'promi-data-x-woo'
							);
							?>
						</strong>

						<code>
							<?php
							echo esc_html(
								'' !== $sku
									? $sku
									: '—'
							);
							?>
						</code>

						&nbsp;&middot;&nbsp;

						<strong>
							<?php
							echo esc_html__(
								'Min Qty:',
								'promi-data-x-woo'
							);
							?>
						</strong>

						<?php
						echo esc_html(
							$min_qty
						);
						?>

					</p>

				</div>


				<div>
					<?php
					$this->render_scenario_badge(
						$headline_result
					);
					?>
				</div>

			</div>


			<?php if ( empty( $rows ) ) : ?>

				<p class="description">
					<?php
					echo esc_html__(
						'No quantity tiers are configured for this target.',
						'promi-data-x-woo'
					);
					?>
				</p>

			<?php else : ?>

				<table
					class="
						widefat
						striped
						pdxw-pricing-breakdown-table
					"
				>

					<thead>

						<tr>

							<th>
								<?php
								echo esc_html__(
									'From Qty',
									'promi-data-x-woo'
								);
								?>
							</th>


							<th>
								<?php
								echo esc_html__(
									'Scenario',
									'promi-data-x-woo'
								);
								?>
							</th>


							<th>
								<?php
								echo esc_html__(
									'Raw Selling Price',
									'promi-data-x-woo'
								);
								?>
							</th>


							<th>
								<?php
								echo esc_html__(
									'Raw Purchase Price',
									'promi-data-x-woo'
								);
								?>
							</th>


							<th>
								<?php
								echo esc_html__(
									'Manufacturer Discount',
									'promi-data-x-woo'
								);
								?>
							</th>


							<th>
								<?php
								echo esc_html__(
									'Effective Cost',
									'promi-data-x-woo'
								);
								?>
							</th>


							<th>
								<?php
								echo esc_html__(
									'Article Markup',
									'promi-data-x-woo'
								);
								?>
							</th>


							<th>
								<?php
								echo esc_html__(
									'Calculated Selling Price',
									'promi-data-x-woo'
								);
								?>
							</th>

						</tr>

					</thead>


					<tbody>

						<?php foreach (
							$rows as $row
						) : ?>

							<?php
							$this->render_breakdown_row(
								$row['qty'],
								$row['result']
							);
							?>

						<?php endforeach; ?>

					</tbody>

				</table>

			<?php endif; ?>

		</div>
		<?php
	}


	/**
	 * Render one calculated quantity-tier row.
	 *
	 * @param array{
	 *     status:string,
	 *     cost:?float,
	 *     article_markup:float,
	 *     article_price:?float,
	 *     source:?string,
	 *     manufacturer_discount:float,
	 *     tier:?object
	 * } $result
	 */
	private function render_breakdown_row(
		int $qty,
		array $result
	): void {

		$tier =
			$result['tier']
				?? null;

		?>
		<tr>

			<td>
				<?php
				echo esc_html(
					(string) $qty
				);
				?>
			</td>


			<td>
				<?php
				$this->render_scenario_badge(
					$result
				);
				?>
			</td>


			<td>
				<?php
				echo wp_kses_post(
					$this->money(
						$tier->price
							?? null
					)
				);
				?>
			</td>


			<td>
				<?php
				echo wp_kses_post(
					$this->money(
						$tier->purchase_price
							?? null
					)
				);
				?>
			</td>


			<td>
				<?php
				echo esc_html(
					$this->percent(
						$result['manufacturer_discount']
							?? null
					)
				);
				?>
			</td>


			<td>
				<?php
				echo wp_kses_post(
					$this->money(
						$result['cost']
							?? null
					)
				);
				?>
			</td>


			<td>
				<?php
				echo esc_html(
					$this->percent(
						$result['article_markup']
							?? null
					)
				);
				?>
			</td>


			<td>
				<strong>
					<?php
					echo wp_kses_post(
						$this->money(
							$result['article_price']
								?? null
						)
					);
					?>
				</strong>
			</td>

		</tr>
		<?php
	}


	/**
	 * Render the scenario badge for one calculation result.
	 *
	 * @param array{status:string,source:?string} $result
	 */
	private function render_scenario_badge(
		array $result
	): void {

		printf(
			'<span class="pdxw-scenario-badge pdxw-scenario-%1$s">%2$s</span>',
			esc_attr(
				$this->scenario_slug(
					$result
				)
			),
			esc_html(
				$this->scenario_label(
					$result
				)
			)
		);
	}


	/**
	 * Return a CSS-safe slug identifying which of the three scenarios a
	 * calculation result falls under.
	 */
	private function scenario_slug(
		array $result
	): string {

		if (
			CostCalculator::STATUS_PRICE_ON_REQUEST
			=== ( $result['status'] ?? '' )
		) {
			return 'case-3';
		}

		return match ( $result['source'] ?? '' ) {

			'purchase_price' =>
				'case-1',

			'recommended_selling_price' =>
				'case-2',

			default =>
				'unknown',
		};
	}


	/**
	 * Return a human-readable label for one of the three pricing
	 * scenarios (Pricing\CostCalculator::resolve_cost()).
	 */
	private function scenario_label(
		array $result
	): string {

		if (
			CostCalculator::STATUS_PRICE_ON_REQUEST
			=== ( $result['status'] ?? '' )
		) {
			return __(
				'Case 3 — Price on Request',
				'promi-data-x-woo'
			);
		}

		return match ( $result['source'] ?? '' ) {

			'purchase_price' =>
				__(
					'Case 1 — Purchase Price',
					'promi-data-x-woo'
				),

			'recommended_selling_price' =>
				__(
					'Case 2 — Recommended Selling Price − Manufacturer Discount',
					'promi-data-x-woo'
				),

			default =>
				__(
					'Unknown',
					'promi-data-x-woo'
				),
		};
	}


	/*
	|--------------------------------------------------------------------------
	| Pagination
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render product-list pagination.
	 */
	private function pagination(
		array $result,
		string $search
	): void {

		$total_pages =
			max(
				1,
				absint(
					$result['total_pages']
						?? 1
				)
			);

		$current =
			max(
				1,
				absint(
					$result['page']
						?? 1
				)
			);

		if ( $total_pages <= 1 ) {
			return;
		}

		$links =
			paginate_links(
				[
					'base' =>
						add_query_arg(
							[
								'page' =>
									Menu::PRICING_CALCULATOR_SLUG,

								'paged' =>
									'%#%',

								's' =>
									$search,
							],
							admin_url(
								'admin.php'
							)
						),

					'format' =>
						'',

					'current' =>
						$current,

					'total' =>
						$total_pages,

					'prev_text' =>
						__(
							'‹ Previous',
							'promi-data-x-woo'
						),

					'next_text' =>
						__(
							'Next ›',
							'promi-data-x-woo'
						),

					'type' =>
						'list',
				]
			);

		if ( ! $links ) {
			return;
		}

		?>
		<div class="tablenav bottom">

			<div class="tablenav-pages">

				<?php
				echo wp_kses_post(
					$links
				);
				?>

			</div>

		</div>
		<?php
	}


	/*
	|--------------------------------------------------------------------------
	| Formatting
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render a WooCommerce-formatted monetary value.
	 */
	private function money(
		mixed $value
	): string {

		if (
			null === $value
			|| '' === (string) $value
			|| ! is_numeric(
				$value
			)
		) {
			return '—';
		}

		return wc_price(
			(float) $value
		);
	}


	/**
	 * Render a stored percentage value.
	 */
	private function percent(
		mixed $value
	): string {

		if (
			null === $value
			|| '' === (string) $value
			|| ! is_numeric(
				$value
			)
		) {
			return '—';
		}

		return wc_format_localized_decimal(
			(string) $value
		) . '%';
	}


	/*
	|--------------------------------------------------------------------------
	| Request Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return the current name/SKU search.
	 */
	private function search(): string {

		if (
			! isset( $_GET['s'] )
			|| is_array( $_GET['s'] )
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
	 * Return the current page number.
	 */
	private function page_number(): int {

		if (
			! isset( $_GET['paged'] )
			|| is_array( $_GET['paged'] )
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
	 * Return the selected product ID.
	 */
	private function selected_product_id(): int {

		if (
			! isset(
				$_GET[
					self::PRODUCT_ARG
				]
			)
			|| is_array(
				$_GET[
					self::PRODUCT_ARG
				]
			)
		) {
			return 0;
		}

		return absint(
			wp_unslash(
				$_GET[
					self::PRODUCT_ARG
				]
			)
		);
	}


	/*
	|--------------------------------------------------------------------------
	| URLs
	|--------------------------------------------------------------------------
	*/

	/**
	 * Build Pricing Calculator page URL.
	 */
	private function page_url(
		array $args = []
	): string {

		$args =
			array_filter(
				array_merge(
					[
						'page' =>
							Menu::PRICING_CALCULATOR_SLUG,
					],
					$args
				),
				static fn( mixed $value ): bool =>
					'' !== $value
			);

		return add_query_arg(
			$args,
			admin_url(
				'admin.php'
			)
		);
	}


	/**
	 * Build URL for one product's pricing-detail screen.
	 */
	private function product_url(
		int $product_id,
		string $search = '',
		int $page = 1
	): string {

		return $this->page_url(
			[
				self::PRODUCT_ARG =>
					absint(
						$product_id
					),

				's' =>
					$search,

				'paged' =>
					max(
						1,
						$page
					),
			]
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Authorization
	|--------------------------------------------------------------------------
	*/

	/**
	 * Restrict the pricing calculator to WooCommerce managers.
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
				'You are not allowed to view pricing calculations.',
				'promi-data-x-woo'
			)
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Accessors
	|--------------------------------------------------------------------------
	*/

	public function catalog(): Catalog {
		return $this->catalog;
	}


	public function pricing(): Pricing {
		return $this->pricing;
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
