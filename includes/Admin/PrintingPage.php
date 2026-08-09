<?php

namespace PromiDataXWoo\Admin;

use PromiDataXWoo\Catalog\Catalog;
use PromiDataXWoo\Printing\Printing;
use WC_Product_Variation;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Printing administration.
 *
 * Provides:
 *
 * - Global print-option list.
 * - Print-option SKU search.
 * - Pagination.
 * - Print-option price inspection.
 * - Print-option purchasing-price inspection.
 * - Print-fee inspection.
 * - WooCommerce variation printing diagnostics.
 *
 * Global print options are primarily Promi-managed data.
 *
 * For that reason this admin service is intentionally diagnostic rather than
 * providing arbitrary CRUD operations which would be overwritten by a later
 * Promi synchronization.
 *
 * Printing business logic and storage remain inside the Printing module.
 */
final class PrintingPage {

	private const PER_PAGE = 50;

	private const OPTION_ARG =
		'print_option';

	private Catalog $catalog;

	private Printing $printing;

	private bool $initialized = false;


	public function __construct(
		Catalog $catalog,
		Printing $printing
	) {
		$this->catalog  = $catalog;
		$this->printing = $printing;
	}


	/**
	 * Register printing-admin integrations.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;


		/*
		|--------------------------------------------------------------------------
		| Variation Printing Diagnostics
		|--------------------------------------------------------------------------
		|
		| Preserve the useful behavior of CX_Print_Admin::variation_ui():
		| display the printing configuration belonging to each variation in
		| the normal WooCommerce variation editor.
		*/

		add_action(
			'woocommerce_product_after_variable_attributes',
			[
				$this,
				'render_variation_printing',
			],
			30,
			3
		);


		do_action(
			'pdxw_admin_printing_page_init',
			$this
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Main Page
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render the global print-option administration page.
	 */
	public function render(): void {

		$this->authorize();


		$search =
			$this->search();


		$page =
			$this->page_number();


		$result =
			$this->printing
				->options()
				->paginated(
					$page,
					self::PER_PAGE,
					$search
				);


		$selected_option_id =
			$this->selected_option_id();


		?>
		<div class="wrap pdxw-admin pdxw-printing-page">

			<h1>
				<?php
				echo esc_html__(
					'Printing',
					'promi-data-x-woo'
				);
				?>
			</h1>


			<p class="description">
				<?php
				echo esc_html__(
					'Inspect global Promi print options, tier prices, purchasing prices and fees.',
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
				class="pdxw-print-search"
			>

				<input
					type="hidden"
					name="page"
					value="<?php echo esc_attr(
						Menu::PRINTING_SLUG
					); ?>"
				>


				<p class="search-box">

					<label
						class="screen-reader-text"
						for="pdxw-print-sku"
					>
						<?php
						echo esc_html__(
							'Search print-option SKU',
							'promi-data-x-woo'
						);
						?>
					</label>


					<input
						type="search"
						id="pdxw-print-sku"
						name="s"
						value="<?php echo esc_attr(
							$search
						); ?>"
						placeholder="<?php echo esc_attr__(
							'Search SKU',
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
			| Selected Print Option
			|--------------------------------------------------------------------------
			*/
			?>

			<?php if ( $selected_option_id ) : ?>

				<?php
				$this->render_option_details(
					$selected_option_id
				);
				?>

			<?php endif; ?>


			<?php
			/*
			|--------------------------------------------------------------------------
			| Option List
			|--------------------------------------------------------------------------
			*/
			?>

			<div class="pdxw-box">

				<div class="pdxw-table-header">

					<strong>
						<?php
						printf(
							/* translators: %d: total print options. */
							esc_html__(
								'%d print options',
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
						pdxw-print-options-table
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
									'SKU',
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
								style="width:110px;"
							>
								<?php
								echo esc_html__(
									'Max Colors',
									'promi-data-x-woo'
								);
								?>
							</th>


							<th
								scope="col"
								style="width:120px;"
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

								<td colspan="6">

									<?php
									echo esc_html__(
										'No print options found.',
										'promi-data-x-woo'
									);
									?>

								</td>

							</tr>

						<?php else : ?>


							<?php foreach (
								$items
									as $option
							) : ?>

								<?php
								if (
									! is_object(
										$option
									)
									|| empty(
										$option->id
									)
								) {
									continue;
								}


								$option_id =
									absint(
										$option->id
									);
								?>


								<tr>

									<td>
										<?php
										echo esc_html(
											(string)
												$option_id
										);
										?>
									</td>


									<td>

										<code>
											<?php
											echo esc_html(
												(string) (
													$option->sku
														?? ''
												)
											);
											?>
										</code>

									</td>


									<td>

										<strong>
											<?php
											echo esc_html(
												(string) (
													$option->name
														?? ''
												)
											);
											?>
										</strong>

									</td>


									<td>
										<?php
										echo esc_html(
											(string)
												max(
													1,
													(int) (
														$option
															->min_order_qty
														?? 1
													)
												)
										);
										?>
									</td>


									<td>
										<?php
										echo esc_html(
											(string)
												max(
													0,
													(int) (
														$option
															->max_colors
														?? 0
													)
												)
										);
										?>
									</td>


									<td>

										<a
											class="button button-small"
											href="<?php echo esc_url(
												$this->option_url(
													$option_id,
													$search,
													$page
												)
											); ?>"
										>
											<?php
											echo esc_html__(
												'View',
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
	| Option Details
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render full configuration for one global print option.
	 */
	private function render_option_details(
		int $option_id
	): void {

		$config =
			$this->printing
				->options()
				->config(
					$option_id
				);


		if ( ! $config ) {

			?>
			<div class="notice notice-warning">

				<p>
					<?php
					echo esc_html__(
						'The selected print option no longer exists.',
						'promi-data-x-woo'
					);
					?>
				</p>

			</div>
			<?php

			return;
		}


		$prices =
			is_array(
				$config['prices']
					?? null
			)
				? $config['prices']
				: [];


		$fees =
			is_array(
				$config['fees']
					?? null
			)
				? $config['fees']
				: [];


		?>
		<div class="pdxw-box pdxw-print-option-details">

			<div class="pdxw-section-header">

				<div>

					<h2>
						<?php
						echo esc_html(
							(string) (
								$config['name']
									?? ''
							)
						);
						?>
					</h2>


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
								(string) (
									$config['sku']
										?? ''
								)
							);
							?>
						</code>

					</p>

				</div>


				<a
					class="button"
					href="<?php echo esc_url(
						$this->page_url(
							[
								's' =>
									$this->search(),

								'paged' =>
									$this
										->page_number(),
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


			<table class="widefat striped">

				<tbody>

					<tr>

						<th style="width:220px;">
							<?php
							echo esc_html__(
								'Minimum Order Quantity',
								'promi-data-x-woo'
							);
							?>
						</th>

						<td>
							<?php
							echo esc_html(
								(string)
									max(
										1,
										absint(
											$config[
												'min_order_qty'
											] ?? 1
										)
									)
							);
							?>
						</td>

					</tr>


					<tr>

						<th>
							<?php
							echo esc_html__(
								'Maximum Colors',
								'promi-data-x-woo'
							);
							?>
						</th>

						<td>
							<?php
							echo esc_html(
								(string)
									absint(
										$config[
											'max_colors'
										] ?? 0
									)
							);
							?>
						</td>

					</tr>

				</tbody>

			</table>


			<?php
			/*
			|--------------------------------------------------------------------------
			| Prices
			|--------------------------------------------------------------------------
			*/
			?>

			<h3>
				<?php
				echo esc_html__(
					'Tier Prices',
					'promi-data-x-woo'
				);
				?>
			</h3>


			<table
				class="
					widefat
					striped
					pdxw-print-price-table
				"
			>

				<thead>

					<tr>

						<th>
							<?php
							echo esc_html__(
								'From Quantity',
								'promi-data-x-woo'
							);
							?>
						</th>


						<th>
							<?php
							echo esc_html__(
								'Selling Price',
								'promi-data-x-woo'
							);
							?>
						</th>


						<th>
							<?php
							echo esc_html__(
								'Purchasing Price',
								'promi-data-x-woo'
							);
							?>
						</th>

					</tr>

				</thead>


				<tbody>

					<?php if ( empty( $prices ) ) : ?>

						<tr>

							<td colspan="3">
								<?php
								echo esc_html__(
									'No print prices are stored.',
									'promi-data-x-woo'
								);
								?>
							</td>

						</tr>

					<?php else : ?>


						<?php foreach (
							$prices
								as $price
						) : ?>

							<?php
							if (
								! is_object(
									$price
								)
							) {
								continue;
							}
							?>


							<tr>

								<td>
									<?php
									echo esc_html(
										(string)
											absint(
												$price->min_qty
													?? 0
											)
									);
									?>
								</td>


								<td>
									<?php
									echo wp_kses_post(
										$this->money(
											$price->price
												?? null
										)
									);
									?>
								</td>


								<td>
									<?php
									echo wp_kses_post(
										$this->money(
											$price
												->purchase_price
											?? null
										)
									);
									?>
								</td>

							</tr>

						<?php endforeach; ?>


					<?php endif; ?>

				</tbody>

			</table>


			<?php
			/*
			|--------------------------------------------------------------------------
			| Fees
			|--------------------------------------------------------------------------
			*/
			?>

			<h3>
				<?php
				echo esc_html__(
					'Fees',
					'promi-data-x-woo'
				);
				?>
			</h3>


			<table
				class="
					widefat
					striped
					pdxw-print-fees-table
				"
			>

				<thead>

					<tr>

						<th>
							<?php
							echo esc_html__(
								'Label',
								'promi-data-x-woo'
							);
							?>
						</th>


						<th>
							<?php
							echo esc_html__(
								'Type',
								'promi-data-x-woo'
							);
							?>
						</th>


						<th>
							<?php
							echo esc_html__(
								'Calculation',
								'promi-data-x-woo'
							);
							?>
						</th>


						<th>
							<?php
							echo esc_html__(
								'Calculation Type',
								'promi-data-x-woo'
							);
							?>
						</th>


						<th>
							<?php
							echo esc_html__(
								'Calculation Amount',
								'promi-data-x-woo'
							);
							?>
						</th>


						<th>
							<?php
							echo esc_html__(
								'Selling Amount',
								'promi-data-x-woo'
							);
							?>
						</th>


						<th>
							<?php
							echo esc_html__(
								'Purchasing Amount',
								'promi-data-x-woo'
							);
							?>
						</th>


						<th>
							<?php
							echo esc_html__(
								'Requirement',
								'promi-data-x-woo'
							);
							?>
						</th>

					</tr>

				</thead>


				<tbody>

					<?php if ( empty( $fees ) ) : ?>

						<tr>

							<td colspan="8">
								<?php
								echo esc_html__(
									'No fees are stored.',
									'promi-data-x-woo'
								);
								?>
							</td>

						</tr>

					<?php else : ?>


						<?php foreach (
							$fees
								as $fee
						) : ?>

							<?php
							if (
								! is_object(
									$fee
								)
							) {
								continue;
							}
							?>


							<tr>

								<td>
									<?php
									echo esc_html(
										(string) (
											$fee->fee_label
												?? ''
										)
									);
									?>
								</td>


								<td>
									<code>
										<?php
										echo esc_html(
											(string) (
												$fee->fee_type
													?? ''
											)
										);
										?>
									</code>
								</td>


								<td>
									<code>
										<?php
										echo esc_html(
											(string) (
												$fee->calculation
													?? ''
											)
										);
										?>
									</code>
								</td>


								<td>
									<?php
									echo esc_html(
										(string) (
											$fee
												->calculation_type
											?? ''
										)
									);
									?>
								</td>


								<td>
									<?php
									echo esc_html(
										$this->decimal(
											$fee
												->calculation_amount
											?? null
										)
									);
									?>
								</td>


								<td>
									<?php
									echo wp_kses_post(
										$this->money(
											$fee->amount
												?? null
										)
									);
									?>
								</td>


								<td>
									<?php
									echo wp_kses_post(
										$this->money(
											$fee
												->purchase_amount
											?? null
										)
									);
									?>
								</td>


								<td>
									<?php
									$this
										->render_requirement(
											$fee
												->requirement
											?? null
										);
									?>
								</td>

							</tr>

						<?php endforeach; ?>


					<?php endif; ?>

				</tbody>

			</table>

		</div>
		<?php
	}


	/*
	|--------------------------------------------------------------------------
	| WooCommerce Variation UI
	|--------------------------------------------------------------------------
	*/

	/**
	 * Display printing information inside one variation editor.
	 *
	 * WooCommerce passes:
	 *
	 * $loop
	 *     Variation index.
	 *
	 * $variation_data
	 *     Existing variation metadata.
	 *
	 * $variation
	 *     Usually WP_Post.
	 */
	public function render_variation_printing(
		int $loop,
		array $variation_data,
		mixed $variation
	): void {

		if (
			! current_user_can(
				Menu::CAPABILITY
			)
		) {
			return;
		}


		$variation_id =
			$this->variation_id(
				$variation
			);


		if ( ! $variation_id ) {
			return;
		}


		$product =
			wc_get_product(
				$variation_id
			);


		if (
			! $product
			|| ! $product
				instanceof WC_Product_Variation
		) {
			return;
		}


		$product_id =
			absint(
				$product->get_parent_id()
			);


		if ( ! $product_id ) {
			return;
		}


		$config =
			$this->printing
				->configurator()
				->get_config(
					$product_id,
					$variation_id
				);


		?>
		<div
			class="
				form-row
				form-row-full
				pdxw-variation-printing
			"
			style="
				clear:both;
				padding:10px 12px;
			"
		>

			<h4>
				<?php
				echo esc_html__(
					'Printing',
					'promi-data-x-woo'
				);
				?>
			</h4>


			<?php if ( empty( $config ) ) : ?>

				<p class="description">
					<?php
					echo esc_html__(
						'No print positions are configured for this variation.',
						'promi-data-x-woo'
					);
					?>
				</p>

			<?php else : ?>


				<?php foreach (
					$config
						as $position
				) : ?>

					<?php
					$position_id =
						absint(
							$position['id']
								?? 0
						);


					$label =
						trim(
							(string) (
								$position['label']
									?? ''
							)
						);


					$code =
						trim(
							(string) (
								$position['code']
									?? ''
							)
						);


					$area =
						trim(
							(string) (
								$position['area']
									?? ''
							)
						);


					$options =
						is_array(
							$position['options']
								?? null
						)
							? $position['options']
							: [];
					?>


					<div
						class="pdxw-variation-print-position"
						style="
							border:1px solid #ddd;
							background:#fff;
							margin:0 0 10px;
							padding:10px;
						"
					>

						<p style="margin-top:0;">

							<strong>
								<?php
								echo esc_html(
									$label
										?: $code
								);
								?>
							</strong>


							<?php if ( '' !== $code ) : ?>

								<code>
									<?php
									echo esc_html(
										$code
									);
									?>
								</code>

							<?php endif; ?>


							<?php if ( '' !== $area ) : ?>

								<span>
									—
									<?php
									echo esc_html(
										$area
									);
									?>
								</span>

							<?php endif; ?>

						</p>


						<?php if ( empty( $options ) ) : ?>

							<p class="description">
								<?php
								echo esc_html__(
									'No print options are assigned to this position.',
									'promi-data-x-woo'
								);
								?>
							</p>

						<?php else : ?>

							<table
								class="
									widefat
									striped
									pdxw-variation-print-options
								"
							>

								<thead>

									<tr>

										<th>
											<?php
											echo esc_html__(
												'Print Option',
												'promi-data-x-woo'
											);
											?>
										</th>


										<th>
											<?php
											echo esc_html__(
												'SKU',
												'promi-data-x-woo'
											);
											?>
										</th>


										<th>
											<?php
											echo esc_html__(
												'Min Qty',
												'promi-data-x-woo'
											);
											?>
										</th>


										<th>
											<?php
											echo esc_html__(
												'Price Tiers',
												'promi-data-x-woo'
											);
											?>
										</th>


										<th>
											<?php
											echo esc_html__(
												'Fees',
												'promi-data-x-woo'
											);
											?>
										</th>

									</tr>

								</thead>


								<tbody>

									<?php foreach (
										$options
											as $option
									) : ?>

										<?php
										$option_id =
											absint(
												$option['id']
													?? 0
											);
										?>


										<tr>

											<td>

												<?php if ( $option_id ) : ?>

													<a
														href="<?php echo esc_url(
															$this->page_url(
																[
																	self::OPTION_ARG =>
																		$option_id,
																]
															)
														); ?>"
													>
														<?php
														echo esc_html(
															(string) (
																$option['name']
																	?? ''
															)
														);
														?>
													</a>

												<?php else : ?>

													<?php
													echo esc_html(
														(string) (
															$option['name']
																?? ''
														)
													);
													?>

												<?php endif; ?>

											</td>


											<td>
												<code>
													<?php
													echo esc_html(
														(string) (
															$option['sku']
																?? ''
														)
													);
													?>
												</code>
											</td>


											<td>
												<?php
												echo esc_html(
													(string)
														max(
															1,
															absint(
																$option[
																	'min_order_qty'
																] ?? 1
															)
														)
												);
												?>
											</td>


											<td>
												<?php
												echo esc_html(
													(string)
														count(
															(array) (
																$option['prices']
																	?? []
															)
														)
												);
												?>
											</td>


											<td>
												<?php
												echo esc_html(
													(string)
														count(
															(array) (
																$option['fees']
																	?? []
															)
														)
												);
												?>
											</td>

										</tr>

									<?php endforeach; ?>

								</tbody>

							</table>

						<?php endif; ?>


						<?php if ( $position_id ) : ?>

							<input
								type="hidden"
								value="<?php echo esc_attr(
									$position_id
								); ?>"
							>

						<?php endif; ?>

					</div>

				<?php endforeach; ?>


			<?php endif; ?>

		</div>
		<?php
	}


	/*
	|--------------------------------------------------------------------------
	| Pagination
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render print-option pagination.
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
									Menu::PRINTING_SLUG,

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
	 * Render a stored decimal without currency.
	 */
	private function decimal(
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
		);
	}


	/**
	 * Render a raw stored fee requirement safely.
	 *
	 * Requirements originate from Promi and may be JSON.
	 * We intentionally do not reinterpret them here.
	 */
	private function render_requirement(
		mixed $requirement
	): void {

		if (
			null === $requirement
			|| '' === trim(
				(string) $requirement
			)
		) {

			echo '—';

			return;
		}


		$raw =
			(string) $requirement;


		$decoded =
			json_decode(
				$raw,
				true
			);


		if (
			JSON_ERROR_NONE
			=== json_last_error()
			&& null !== $decoded
		) {

			$pretty =
				wp_json_encode(
					$decoded,
					JSON_PRETTY_PRINT
					| JSON_UNESCAPED_UNICODE
					| JSON_UNESCAPED_SLASHES
				);


			if ( $pretty ) {

				printf(
					'<pre style="white-space:pre-wrap;margin:0;">%s</pre>',
					esc_html(
						$pretty
					)
				);

				return;
			}
		}


		echo esc_html(
			$raw
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Request Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return SKU search.
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
	 * Return current page number.
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
	 * Return selected print-option ID.
	 */
	private function selected_option_id(): int {

		if (
			! isset(
				$_GET[
					self::OPTION_ARG
				]
			)
			|| is_array(
				$_GET[
					self::OPTION_ARG
				]
			)
		) {
			return 0;
		}


		return absint(
			wp_unslash(
				$_GET[
					self::OPTION_ARG
				]
			)
		);
	}


	/**
	 * Resolve a variation ID from WooCommerce's variation-editor argument.
	 */
	private function variation_id(
		mixed $variation
	): int {

		if (
			$variation
			instanceof WC_Product_Variation
		) {
			return absint(
				$variation->get_id()
			);
		}


		if (
			$variation
			instanceof WP_Post
		) {
			return absint(
				$variation->ID
			);
		}


		if (
			is_object(
				$variation
			)
			&& isset(
				$variation->ID
			)
		) {
			return absint(
				$variation->ID
			);
		}


		return 0;
	}


	/*
	|--------------------------------------------------------------------------
	| URLs
	|--------------------------------------------------------------------------
	*/

	/**
	 * Build Printing page URL.
	 */
	private function page_url(
		array $args = []
	): string {

		$args =
			array_filter(
				array_merge(
					[
						'page' =>
							Menu::PRINTING_SLUG,
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


	/**
	 * Build URL for one print-option detail screen.
	 */
	private function option_url(
		int $option_id,
		string $search = '',
		int $page = 1
	): string {

		return $this->page_url(
			[
				self::OPTION_ARG =>
					absint(
						$option_id
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
	 * Restrict print administration to WooCommerce managers.
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
				'You are not allowed to manage printing.',
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


	public function printing(): Printing {
		return $this->printing;
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
