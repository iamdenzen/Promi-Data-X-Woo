<?php

namespace PromiDataXWoo\Admin;

use PromiDataXWoo\Core\Database;
use PromiDataXWoo\Pricing\MarkupRepository;
use PromiDataXWoo\Pricing\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Pricing markups administration page.
 */
final class MarkupPage {

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

		add_action(
			'admin_enqueue_scripts',
			[
				$this,
				'enqueue_assets',
			]
		);
	}


	public function enqueue_assets(
		string $hook
	): void {

		if (
			false === strpos(
				$hook,
				Menu::MARKUP_SLUG
			)
		) {
			return;
		}

		wp_enqueue_script(
			'wp-api-fetch'
		);
	}


	/**
	 * Return all WooCommerce product categories.
	 */
	private function categories(): array {

		$categories =
			get_terms(
				[
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
				]
			);

		return is_wp_error( $categories )
			? []
			: $categories;
	}


	/**
	 * Return all global print options.
	 *
	 * We read directly from the existing plugin table here because this admin
	 * page only needs a lightweight list of IDs and names.
	 */
	private function print_options(): array {

		global $wpdb;

		$table =
			Database::table(
				'print_options'
			);

		$results =
			$wpdb->get_results(
				"SELECT
					id,
					name,
					sku
				FROM {$table}
				ORDER BY name ASC"
			);

		return is_array( $results )
			? $results
			: [];
	}


	/**
	 * Render the Pricing Markups admin page.
	 */
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


		$article_markup =
			$this->pricing
				->markup_rules()
				->article_default();

		$print_price_markup =
			$this->pricing
				->markup_rules()
				->print_price_default();

		$print_fee_markup =
			$this->pricing
				->markup_rules()
				->print_fee_default();


		$category_rules =
			$this->pricing
				->markup_repository()
				->all(
					MarkupRepository::TYPE_CATEGORY
				);


		$print_option_rules =
			$this->pricing
				->markup_rules()
				->print_option_overrides();


		$categories =
			$this->categories();

		$print_options =
			$this->print_options();


		$category_rule_ids =
			array_map(
				'absint',
				wp_list_pluck(
					$category_rules,
					'target_id'
				)
			);

		$print_option_rule_ids =
			array_map(
				'absint',
				wp_list_pluck(
					$print_option_rules,
					'id'
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
		<div class="wrap">

			<h1>
				<?php echo esc_html__( 'Pricing Markups', 'promi-data-x-woo' ); ?>
			</h1>

			<p class="description">
				<?php echo esc_html__( 'Configure markup percentages applied to Promi purchase prices when calculating selling prices.', 'promi-data-x-woo' ); ?>
			</p>

			<hr class="wp-header-end">

			<div id="pdxw-markup-notices"></div>


			<!-- Default Markups -->

			<h2>
				<?php echo esc_html__( 'Default Markups', 'promi-data-x-woo' ); ?>
			</h2>

			<p class="description">
				<?php echo esc_html__( 'Applied when no category or print-option specific rule matches.', 'promi-data-x-woo' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tbody>

					<tr>
						<th scope="row">
							<label for="pdxw-article-markup">
								<?php echo esc_html__( 'Article Markup (%)', 'promi-data-x-woo' ); ?>
							</label>
						</th>

						<td>
							<input
								type="number"
								id="pdxw-article-markup"
								class="small-text"
								value="<?php echo esc_attr( $article_markup ); ?>"
								min="0"
								step="0.01"
							>

							<p class="description">
								<?php echo esc_html__( 'Default markup applied to article purchase prices.', 'promi-data-x-woo' ); ?>
							</p>
						</td>
					</tr>


					<tr>
						<th scope="row">
							<label for="pdxw-print-price-markup">
								<?php echo esc_html__( 'Print Option Price Markup (%)', 'promi-data-x-woo' ); ?>
							</label>
						</th>

						<td>
							<input
								type="number"
								id="pdxw-print-price-markup"
								class="small-text"
								value="<?php echo esc_attr( $print_price_markup ); ?>"
								min="0"
								step="0.01"
							>

							<p class="description">
								<?php echo esc_html__( 'Default markup applied to print option purchase prices (the per-unit/tier printing cost).', 'promi-data-x-woo' ); ?>
							</p>
						</td>
					</tr>


					<tr>
						<th scope="row">
							<label for="pdxw-print-fee-markup">
								<?php echo esc_html__( 'Print Option Fee Markup (%)', 'promi-data-x-woo' ); ?>
							</label>
						</th>

						<td>
							<input
								type="number"
								id="pdxw-print-fee-markup"
								class="small-text"
								value="<?php echo esc_attr( $print_fee_markup ); ?>"
								min="0"
								step="0.01"
							>

							<p class="description">
								<?php echo esc_html__( 'Default markup applied to print option fees (setup and other fixed/ongoing fees).', 'promi-data-x-woo' ); ?>
							</p>
						</td>
					</tr>

				</tbody>
			</table>

			<p>
				<button
					type="button"
					id="pdxw-save-defaults"
					class="button button-primary"
				>
					<?php echo esc_html__( 'Save Default Markups', 'promi-data-x-woo' ); ?>
				</button>
			</p>


			<hr>


			<!-- Category Overrides -->

			<h2>
				<?php echo esc_html__( 'Category Markup Overrides', 'promi-data-x-woo' ); ?>
			</h2>

			<p class="description">
				<?php echo esc_html__( 'A category-specific markup overrides the default article markup.', 'promi-data-x-woo' ); ?>
			</p>


			<div class="pdxw-add-rule">

				<select id="pdxw-new-category">

					<option value="">
						<?php echo esc_html__( 'Select a category…', 'promi-data-x-woo' ); ?>
					</option>

					<?php foreach ( $categories as $category ) : ?>

						<?php
						$category_id =
							absint(
								$category->term_id
							);
						?>

						<option
							value="<?php echo esc_attr( $category_id ); ?>"
							<?php disabled( in_array( $category_id, $category_rule_ids, true ) ); ?>
						>
							<?php
							echo esc_html(
								$category->name
								. (
									in_array(
										$category_id,
										$category_rule_ids,
										true
									)
									? ' — ' . __( 'Already configured', 'promi-data-x-woo' )
									: ''
								)
							);
							?>
						</option>

					<?php endforeach; ?>

				</select>

				<input
					type="number"
					id="pdxw-new-category-markup"
					class="small-text"
					value=""
					min="0"
					step="0.01"
					placeholder="25"
				>

				<button
					type="button"
					id="pdxw-add-category-rule"
					class="button"
				>
					<?php echo esc_html__( 'Add Category Override', 'promi-data-x-woo' ); ?>
				</button>

			</div>


			<table
				class="wp-list-table widefat fixed striped"
				id="pdxw-category-rules"
				style="margin-top: 16px;"
			>

				<thead>
					<tr>
						<th>
							<?php echo esc_html__( 'Category', 'promi-data-x-woo' ); ?>
						</th>

						<th>
							<?php echo esc_html__( 'Markup (%)', 'promi-data-x-woo' ); ?>
						</th>

						<th>
							<?php echo esc_html__( 'Actions', 'promi-data-x-woo' ); ?>
						</th>
					</tr>
				</thead>

				<tbody>

					<?php if ( empty( $category_rules ) ) : ?>

						<tr class="pdxw-empty-row">
							<td colspan="3">
								<?php echo esc_html__( 'No category markup overrides configured.', 'promi-data-x-woo' ); ?>
							</td>
						</tr>

					<?php else : ?>

						<?php foreach ( $category_rules as $rule ) : ?>

							<?php
							$category_id =
								absint(
									$rule['target_id'] ?? 0
								);

							$term =
								get_term(
									$category_id,
									'product_cat'
								);

							$term_name =
								$term instanceof \WP_Term
									? $term->name
									: sprintf(
										__( 'Category #%d', 'promi-data-x-woo' ),
										$category_id
									);
							?>

							<tr
								data-rule-type="<?php echo esc_attr( MarkupRepository::TYPE_CATEGORY ); ?>"
								data-rule-id="<?php echo esc_attr( $category_id ); ?>"
							>

								<td class="pdxw-rule-name">
									<?php echo esc_html( $term_name ); ?>
								</td>

								<td>
									<input
										type="number"
										class="small-text pdxw-rule-markup"
										value="<?php echo esc_attr( $rule['markup_percent'] ?? 0 ); ?>"
										min="0"
										step="0.01"
									>
								</td>

								<td>

									<button
										type="button"
										class="button button-small pdxw-save-rule"
									>
										<?php echo esc_html__( 'Save', 'promi-data-x-woo' ); ?>
									</button>

									<button
										type="button"
										class="button button-small pdxw-delete-rule"
									>
										<?php echo esc_html__( 'Remove', 'promi-data-x-woo' ); ?>
									</button>

								</td>

							</tr>

						<?php endforeach; ?>

					<?php endif; ?>

				</tbody>

			</table>


			<hr>


			<!-- Print Option Overrides -->

			<h2>
				<?php echo esc_html__( 'Print Option Markup Overrides', 'promi-data-x-woo' ); ?>
			</h2>

			<p class="description">
				<?php echo esc_html__( 'A print-option-specific markup overrides the default finishing markup.', 'promi-data-x-woo' ); ?>
			</p>


			<div class="pdxw-add-rule">

				<select id="pdxw-new-print-option">

					<option value="">
						<?php echo esc_html__( 'Select a print option…', 'promi-data-x-woo' ); ?>
					</option>

					<?php foreach ( $print_options as $option ) : ?>

						<?php
						$option_id =
							absint(
								$option->id
							);

						$option_name =
							trim(
								(string) (
									$option->name
									?: $option->sku
								)
							);
						?>

						<option
							value="<?php echo esc_attr( $option_id ); ?>"
							<?php disabled( in_array( $option_id, $print_option_rule_ids, true ) ); ?>
						>
							<?php
							echo esc_html(
								$option_name
								. (
									in_array(
										$option_id,
										$print_option_rule_ids,
										true
									)
									? ' — ' . __( 'Already configured', 'promi-data-x-woo' )
									: ''
								)
							);
							?>
						</option>

					<?php endforeach; ?>

				</select>

				<input
					type="number"
					id="pdxw-new-print-option-price-markup"
					class="small-text"
					value=""
					min="0"
					step="0.01"
					placeholder="<?php echo esc_attr( $print_price_markup ); ?>"
				>

				<input
					type="number"
					id="pdxw-new-print-option-fee-markup"
					class="small-text"
					value=""
					min="0"
					step="0.01"
					placeholder="<?php echo esc_attr( $print_fee_markup ); ?>"
				>

				<button
					type="button"
					id="pdxw-add-print-option-rule"
					class="button"
				>
					<?php echo esc_html__( 'Add Print Option Override', 'promi-data-x-woo' ); ?>
				</button>

			</div>


			<table
				class="wp-list-table widefat fixed striped"
				id="pdxw-print-option-rules"
				style="margin-top: 16px;"
			>

				<thead>
					<tr>
						<th>
							<?php echo esc_html__( 'Print Option', 'promi-data-x-woo' ); ?>
						</th>

						<th>
							<?php echo esc_html__( 'Price Markup (%)', 'promi-data-x-woo' ); ?>
						</th>

						<th>
							<?php echo esc_html__( 'Fee Markup (%)', 'promi-data-x-woo' ); ?>
						</th>

						<th>
							<?php echo esc_html__( 'Actions', 'promi-data-x-woo' ); ?>
						</th>
					</tr>
				</thead>

				<tbody>

					<?php if ( empty( $print_option_rules ) ) : ?>

						<tr class="pdxw-empty-row">
							<td colspan="4">
								<?php echo esc_html__( 'No print option markup overrides configured.', 'promi-data-x-woo' ); ?>
							</td>
						</tr>

					<?php else : ?>

						<?php foreach ( $print_option_rules as $rule ) : ?>

							<?php
							$option_id =
								absint(
									$rule['id'] ?? 0
								);

							$option_name =
								sprintf(
									__( 'Print Option #%d', 'promi-data-x-woo' ),
									$option_id
								);

							foreach ( $print_options as $option ) {

								if (
									absint( $option->id )
									=== $option_id
								) {
									$option_name =
										trim(
											(string) (
												$option->name
												?: $option->sku
											)
										);

									break;
								}
							}
							?>

							<tr
								data-rule-id="<?php echo esc_attr( $option_id ); ?>"
							>

								<td class="pdxw-rule-name">
									<?php echo esc_html( $option_name ); ?>
								</td>

								<td>
									<input
										type="number"
										class="small-text pdxw-rule-price-markup"
										value="<?php echo esc_attr( $rule['price_markup_percent'] ?? $print_price_markup ); ?>"
										min="0"
										step="0.01"
									>
								</td>

								<td>
									<input
										type="number"
										class="small-text pdxw-rule-fee-markup"
										value="<?php echo esc_attr( $rule['fee_markup_percent'] ?? $print_fee_markup ); ?>"
										min="0"
										step="0.01"
									>
								</td>

								<td>

									<button
										type="button"
										class="button button-small pdxw-save-print-option-rule"
									>
										<?php echo esc_html__( 'Save', 'promi-data-x-woo' ); ?>
									</button>

									<button
										type="button"
										class="button button-small pdxw-delete-print-option-rule"
									>
										<?php echo esc_html__( 'Remove', 'promi-data-x-woo' ); ?>
									</button>

								</td>

							</tr>

						<?php endforeach; ?>

					<?php endif; ?>

				</tbody>

			</table>

		</div>


		<script>
		(function () {

			const apiRoot =
				<?php echo wp_json_encode( $api_root ); ?>;

			const nonce =
				<?php echo wp_json_encode( $nonce ); ?>;

			const notices =
				document.getElementById(
					'pdxw-markup-notices'
				);

			const categoryType =
				<?php echo wp_json_encode( MarkupRepository::TYPE_CATEGORY ); ?>;


			function escapeHtml( value ) {

				const div =
					document.createElement( 'div' );

				div.textContent =
					String( value );

				return div.innerHTML;
			}


			function showNotice( message, type ) {

				notices.innerHTML =
					'<div class="notice notice-'
					+ type
					+ ' is-dismissible"><p>'
					+ escapeHtml( message )
					+ '</p></div>';

				setTimeout(
					function () {
						notices.innerHTML = '';
					},
					4000
				);
			}


			function apiFetch( path, method, body ) {

				return fetch(
					apiRoot + path,
					{
						method:
							method || 'GET',

						headers:
							{
								'Content-Type':
									'application/json',

								'X-WP-Nonce':
									nonce,
							},

						body:
							body
								? JSON.stringify( body )
								: undefined,
					}
				)
				.then(
					function ( response ) {

						return response
							.json()
							.catch(
								function () {
									return {};
								}
							)
							.then(
								function ( data ) {

									if ( ! response.ok ) {
										throw new Error(
											data.message
											|| 'Request failed.'
										);
									}

									return data;
								}
							);
					}
				);
			}


			function getRulePath(
				type,
				id
			) {

				return type === categoryType
					? '/pricing/categories/' + id
					: '/pricing/print-options/' + id;
			}


			function validateMarkup(
				value
			) {

				const markup =
					parseFloat( value );

				return (
					! isNaN( markup )
					&& markup >= 0
				)
					? markup
					: null;
			}


			/*
			|--------------------------------------------------------------------------
			| Default Markups
			|--------------------------------------------------------------------------
			*/

			document
				.getElementById(
					'pdxw-save-defaults'
				)
				.addEventListener(
					'click',
					function () {

						const article =
							validateMarkup(
								document
									.getElementById(
										'pdxw-article-markup'
									)
									.value
							);

						const printPrice =
							validateMarkup(
								document
									.getElementById(
										'pdxw-print-price-markup'
									)
									.value
							);

						const printFee =
							validateMarkup(
								document
									.getElementById(
										'pdxw-print-fee-markup'
									)
									.value
							);

						if (
							null === article
							|| null === printPrice
							|| null === printFee
						) {
							showNotice(
								<?php echo wp_json_encode( __( 'Please enter valid non-negative markup values.', 'promi-data-x-woo' ) ); ?>,
								'error'
							);

							return;
						}

						apiFetch(
							'/pricing/defaults',
							'POST',
							{
								article_markup:
									article,

								print_price_markup:
									printPrice,

								print_fee_markup:
									printFee,
							}
						)
						.then(
							function () {

								showNotice(
									<?php echo wp_json_encode( __( 'Default markups saved.', 'promi-data-x-woo' ) ); ?>,
									'success'
								);
							}
						)
						.catch(
							function ( error ) {

								showNotice(
									error.message
									|| <?php echo wp_json_encode( __( 'Error saving defaults.', 'promi-data-x-woo' ) ); ?>,
									'error'
								);
							}
						);
					}
				);


			/*
			|--------------------------------------------------------------------------
			| Add Rules
			|--------------------------------------------------------------------------
			*/

			function addRule(
				type,
				selectId,
				markupId,
				tableId
			) {

				const select =
					document.getElementById(
						selectId
					);

				const markupInput =
					document.getElementById(
						markupId
					);

				const id =
					parseInt(
						select.value,
						10
					);

				const markup =
					validateMarkup(
						markupInput.value
					);

				if ( ! id ) {

					showNotice(
						<?php echo wp_json_encode( __( 'Please select an item.', 'promi-data-x-woo' ) ); ?>,
						'error'
					);

					return;
				}

				if ( null === markup ) {

					showNotice(
						<?php echo wp_json_encode( __( 'Please enter a valid non-negative markup.', 'promi-data-x-woo' ) ); ?>,
						'error'
					);

					return;
				}

				apiFetch(
					getRulePath(
						type,
						id
					),
					'POST',
					{
						markup_percent:
							markup,
					}
				)
				.then(
					function () {

						const option =
							select.options[
								select.selectedIndex
							];

						const name =
							option.text
								.replace(
									' — <?php echo esc_js( __( 'Already configured', 'promi-data-x-woo' ) ); ?>',
									''
								);

						const tbody =
							document
								.querySelector(
									'#'
									+ tableId
									+ ' tbody'
								);

						const emptyRow =
							tbody.querySelector(
								'.pdxw-empty-row'
							);

						if ( emptyRow ) {
							emptyRow.remove();
						}

						const row =
							document.createElement(
								'tr'
							);

						row.dataset.ruleType =
							type;

						row.dataset.ruleId =
							id;

						row.innerHTML =
							'<td class="pdxw-rule-name">'
							+ escapeHtml( name )
							+ '</td>'
							+ '<td>'
							+ '<input type="number" class="small-text pdxw-rule-markup" '
							+ 'value="' + markup + '" min="0" step="0.01">'
							+ '</td>'
							+ '<td>'
							+ '<button type="button" class="button button-small pdxw-save-rule">'
							+ <?php echo wp_json_encode( __( 'Save', 'promi-data-x-woo' ) ); ?>
							+ '</button> '
							+ '<button type="button" class="button button-small pdxw-delete-rule">'
							+ <?php echo wp_json_encode( __( 'Remove', 'promi-data-x-woo' ) ); ?>
							+ '</button>'
							+ '</td>';

						tbody.appendChild(
							row
						);

						select
							.querySelector(
								'option[value="'
								+ id
								+ '"]'
							)
							.disabled =
								true;

						select.value = '';

						markupInput.value = '';

						showNotice(
							<?php echo wp_json_encode( __( 'Markup override added.', 'promi-data-x-woo' ) ); ?>,
							'success'
						);
					}
				)
				.catch(
					function ( error ) {

						showNotice(
							error.message
							|| <?php echo wp_json_encode( __( 'Error adding markup override.', 'promi-data-x-woo' ) ); ?>,
							'error'
						);
					}
				);
			}


			document
				.getElementById(
					'pdxw-add-category-rule'
				)
				.addEventListener(
					'click',
					function () {

						addRule(
							categoryType,
							'pdxw-new-category',
							'pdxw-new-category-markup',
							'pdxw-category-rules'
						);
					}
				);


			/*
			|--------------------------------------------------------------------------
			| Add Print Option Rule
			|--------------------------------------------------------------------------
			|
			| Print options have two independent markup values (price and
			| fee), so they can't reuse the single-value addRule() helper
			| used for categories.
			*/

			function addPrintOptionRule() {

				const select =
					document.getElementById(
						'pdxw-new-print-option'
					);

				const priceInput =
					document.getElementById(
						'pdxw-new-print-option-price-markup'
					);

				const feeInput =
					document.getElementById(
						'pdxw-new-print-option-fee-markup'
					);

				const id =
					parseInt(
						select.value,
						10
					);

				const priceMarkup =
					validateMarkup(
						priceInput.value
					);

				const feeMarkup =
					validateMarkup(
						feeInput.value
					);

				if ( ! id ) {

					showNotice(
						<?php echo wp_json_encode( __( 'Please select an item.', 'promi-data-x-woo' ) ); ?>,
						'error'
					);

					return;
				}

				if (
					null === priceMarkup
					|| null === feeMarkup
				) {

					showNotice(
						<?php echo wp_json_encode( __( 'Please enter valid non-negative markups.', 'promi-data-x-woo' ) ); ?>,
						'error'
					);

					return;
				}

				apiFetch(
					'/pricing/print-options/' + id,
					'POST',
					{
						price_markup_percent:
							priceMarkup,

						fee_markup_percent:
							feeMarkup,
					}
				)
				.then(
					function () {

						const option =
							select.options[
								select.selectedIndex
							];

						const name =
							option.text
								.replace(
									' — <?php echo esc_js( __( 'Already configured', 'promi-data-x-woo' ) ); ?>',
									''
								);

						const tbody =
							document
								.querySelector(
									'#pdxw-print-option-rules tbody'
								);

						const emptyRow =
							tbody.querySelector(
								'.pdxw-empty-row'
							);

						if ( emptyRow ) {
							emptyRow.remove();
						}

						const row =
							document.createElement(
								'tr'
							);

						row.dataset.ruleId =
							id;

						row.innerHTML =
							'<td class="pdxw-rule-name">'
							+ escapeHtml( name )
							+ '</td>'
							+ '<td>'
							+ '<input type="number" class="small-text pdxw-rule-price-markup" '
							+ 'value="' + priceMarkup + '" min="0" step="0.01">'
							+ '</td>'
							+ '<td>'
							+ '<input type="number" class="small-text pdxw-rule-fee-markup" '
							+ 'value="' + feeMarkup + '" min="0" step="0.01">'
							+ '</td>'
							+ '<td>'
							+ '<button type="button" class="button button-small pdxw-save-print-option-rule">'
							+ <?php echo wp_json_encode( __( 'Save', 'promi-data-x-woo' ) ); ?>
							+ '</button> '
							+ '<button type="button" class="button button-small pdxw-delete-print-option-rule">'
							+ <?php echo wp_json_encode( __( 'Remove', 'promi-data-x-woo' ) ); ?>
							+ '</button>'
							+ '</td>';

						tbody.appendChild(
							row
						);

						select
							.querySelector(
								'option[value="'
								+ id
								+ '"]'
							)
							.disabled =
								true;

						select.value = '';

						priceInput.value = '';

						feeInput.value = '';

						showNotice(
							<?php echo wp_json_encode( __( 'Markup override added.', 'promi-data-x-woo' ) ); ?>,
							'success'
						);
					}
				)
				.catch(
					function ( error ) {

						showNotice(
							error.message
							|| <?php echo wp_json_encode( __( 'Error adding markup override.', 'promi-data-x-woo' ) ); ?>,
							'error'
						);
					}
				);
			}


			document
				.getElementById(
					'pdxw-add-print-option-rule'
				)
				.addEventListener(
					'click',
					addPrintOptionRule
				);


			/*
			|--------------------------------------------------------------------------
			| Save And Delete Existing Rules
			|--------------------------------------------------------------------------
			*/

			document.addEventListener(
				'click',
				function ( event ) {

					/*
					|--------------------------------------------------------------------------
					| Print Option Rules (Price + Fee)
					|--------------------------------------------------------------------------
					*/

					const savePrintOptionButton =
						event.target.closest(
							'.pdxw-save-print-option-rule'
						);

					if ( savePrintOptionButton ) {

						const row =
							savePrintOptionButton.closest(
								'tr'
							);

						const id =
							row.dataset.ruleId;

						const priceMarkup =
							validateMarkup(
								row
									.querySelector(
										'.pdxw-rule-price-markup'
									)
									.value
							);

						const feeMarkup =
							validateMarkup(
								row
									.querySelector(
										'.pdxw-rule-fee-markup'
									)
									.value
							);

						if (
							null === priceMarkup
							|| null === feeMarkup
						) {
							showNotice(
								<?php echo wp_json_encode( __( 'Please enter valid non-negative markups.', 'promi-data-x-woo' ) ); ?>,
								'error'
							);

							return;
						}

						apiFetch(
							'/pricing/print-options/' + id,
							'POST',
							{
								price_markup_percent:
									priceMarkup,

								fee_markup_percent:
									feeMarkup,
							}
						)
						.then(
							function () {

								showNotice(
									<?php echo wp_json_encode( __( 'Markup saved.', 'promi-data-x-woo' ) ); ?>,
									'success'
								);
							}
						)
						.catch(
							function ( error ) {

								showNotice(
									error.message
									|| <?php echo wp_json_encode( __( 'Error saving markup.', 'promi-data-x-woo' ) ); ?>,
									'error'
								);
							}
						);

						return;
					}


					const deletePrintOptionButton =
						event.target.closest(
							'.pdxw-delete-print-option-rule'
						);

					if ( deletePrintOptionButton ) {

						const row =
							deletePrintOptionButton.closest(
								'tr'
							);

						const id =
							row.dataset.ruleId;

						const name =
							row
								.querySelector(
									'.pdxw-rule-name'
								)
								.textContent
								.trim();

						if (
							! window.confirm(
								<?php echo wp_json_encode( __( 'Remove the markup override for', 'promi-data-x-woo' ) ); ?>
								+ ' "'
								+ name
								+ '"?'
							)
						) {
							return;
						}

						apiFetch(
							'/pricing/print-options/' + id,
							'DELETE'
						)
						.then(
							function () {

								const tbody =
									row.closest(
										'tbody'
									);

								row.remove();

								const select =
									document.getElementById(
										'pdxw-new-print-option'
									);

								const option =
									select.querySelector(
										'option[value="'
										+ id
										+ '"]'
									);

								if ( option ) {
									option.disabled = false;
								}

								if (
									! tbody.querySelector(
										'tr'
									)
								) {
									tbody.innerHTML =
										'<tr class="pdxw-empty-row">'
										+ '<td colspan="4">'
										+ escapeHtml(
											<?php echo wp_json_encode( __( 'No print option markup overrides configured.', 'promi-data-x-woo' ) ); ?>
										)
										+ '</td></tr>';
								}

								showNotice(
									<?php echo wp_json_encode( __( 'Markup override removed.', 'promi-data-x-woo' ) ); ?>,
									'success'
								);
							}
						)
						.catch(
							function ( error ) {

								showNotice(
									error.message
									|| <?php echo wp_json_encode( __( 'Error removing markup override.', 'promi-data-x-woo' ) ); ?>,
									'error'
								);
							}
						);

						return;
					}


					/*
					|--------------------------------------------------------------------------
					| Category Rules
					|--------------------------------------------------------------------------
					*/

					const saveButton =
						event.target.closest(
							'.pdxw-save-rule'
						);

					if ( saveButton ) {

						const row =
							saveButton.closest(
								'tr'
							);

						const type =
							row.dataset.ruleType;

						const id =
							row.dataset.ruleId;

						const markup =
							validateMarkup(
								row
									.querySelector(
										'.pdxw-rule-markup'
									)
									.value
							);

						if (
							null === markup
						) {
							showNotice(
								<?php echo wp_json_encode( __( 'Please enter a valid non-negative markup.', 'promi-data-x-woo' ) ); ?>,
								'error'
							);

							return;
						}

						apiFetch(
							getRulePath(
								type,
								id
							),
							'POST',
							{
								markup_percent:
									markup,
							}
						)
						.then(
							function () {

								showNotice(
									<?php echo wp_json_encode( __( 'Markup saved.', 'promi-data-x-woo' ) ); ?>,
									'success'
								);
							}
						)
						.catch(
							function ( error ) {

								showNotice(
									error.message
									|| <?php echo wp_json_encode( __( 'Error saving markup.', 'promi-data-x-woo' ) ); ?>,
									'error'
								);
							}
						);

						return;
					}


					const deleteButton =
						event.target.closest(
							'.pdxw-delete-rule'
						);

					if ( ! deleteButton ) {
						return;
					}

					const row =
						deleteButton.closest(
							'tr'
						);

					const type =
						row.dataset.ruleType;

					const id =
						row.dataset.ruleId;

					const name =
						row
							.querySelector(
								'.pdxw-rule-name'
							)
							.textContent
							.trim();


					if (
						! window.confirm(
							<?php echo wp_json_encode( __( 'Remove the markup override for', 'promi-data-x-woo' ) ); ?>
							+ ' "'
							+ name
							+ '"?'
						)
					) {
						return;
					}


					apiFetch(
						getRulePath(
							type,
							id
						),
						'DELETE'
					)
					.then(
						function () {

							const table =
								row.closest(
									'table'
								);

							row.remove();


							const select =
								type === categoryType
									? document.getElementById(
										'pdxw-new-category'
									)
									: document.getElementById(
										'pdxw-new-print-option'
									);

							const option =
								select.querySelector(
									'option[value="'
									+ id
									+ '"]'
								);

							if ( option ) {
								option.disabled = false;
							}


							const tbody =
								table.querySelector(
									'tbody'
								);

							if (
								! tbody.querySelector(
									'tr'
								)
							) {
								tbody.innerHTML =
									'<tr class="pdxw-empty-row">'
									+ '<td colspan="3">'
									+ escapeHtml(
										<?php echo wp_json_encode( __( 'No markup overrides configured.', 'promi-data-x-woo' ) ); ?>
									)
									+ '</td></tr>';
							}


							showNotice(
								<?php echo wp_json_encode( __( 'Markup override removed.', 'promi-data-x-woo' ) ); ?>,
								'success'
							);
						}
					)
					.catch(
						function ( error ) {

							showNotice(
								error.message
								|| <?php echo wp_json_encode( __( 'Error removing markup override.', 'promi-data-x-woo' ) ); ?>,
								'error'
							);
						}
					);
				}
			);

		})();
		</script>

		<?php
	}


	public function is_initialized(): bool {

		return $this->initialized;
	}
}