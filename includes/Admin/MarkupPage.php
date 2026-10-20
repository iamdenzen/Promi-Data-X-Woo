<?php

namespace PromiDataXWoo\Admin;

use PromiDataXWoo\Core\Database;
use PromiDataXWoo\Pricing\ManufacturerDiscount;
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
	 * Return all WooCommerce product categories as a depth-first tree.
	 *
	 * Many categories share a name (e.g. "Sonstiges" under several parents),
	 * so every entry also carries its full path ("A › B › C") and the path of
	 * its parent, used to tell them apart.
	 *
	 * @return array<int,array{id:int,name:string,depth:int,path:string,context:string}>
	 */
	private function category_tree(): array {

		$terms =
			get_terms(
				[
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
				]
			);

		if (
			is_wp_error( $terms )
			|| empty( $terms )
		) {
			return [];
		}

		$known = [];

		foreach ( $terms as $term ) {
			$known[ (int) $term->term_id ] = true;
		}

		$by_parent = [];

		foreach ( $terms as $term ) {

			$parent =
				(int) $term->parent;

			// A term whose parent no longer exists is shown at the top level.
			if ( $parent && ! isset( $known[ $parent ] ) ) {
				$parent = 0;
			}

			$by_parent[ $parent ][] = $term;
		}

		foreach ( $by_parent as &$children ) {

			usort(
				$children,
				static fn ( $a, $b ): int => strcasecmp( $a->name, $b->name )
			);
		}

		unset( $children );

		$result = [];

		$walk = function (
			int $parent,
			int $depth,
			array $trail
		) use ( &$walk, &$result, $by_parent ): void {

			foreach ( $by_parent[ $parent ] ?? [] as $term ) {

				$path =
					array_merge(
						$trail,
						[
							wp_specialchars_decode( $term->name ),
						]
					);

				$result[] = [
					'id'      => (int) $term->term_id,
					'name'    => wp_specialchars_decode( $term->name ),
					'depth'   => $depth,
					'path'    => implode( ' › ', $path ),
					'context' => implode( ' › ', $trail ),
				];

				$walk(
					(int) $term->term_id,
					$depth + 1,
					$path
				);
			}
		};

		$walk( 0, 0, [] );

		return $result;
	}


	/**
	 * Render a searchable multi-select list (checkbox rows).
	 *
	 * The matching JavaScript factory is createPicker(); element ids are
	 * derived from $prefix: pdxw-{prefix}-search / -select-matching / -clear /
	 * -counter / -list.
	 *
	 * Each item: id, label, optional path (full name, used for the rules table
	 * and search), optional context (muted hint), optional depth (indent).
	 *
	 * @param array<int,array<string,mixed>> $items
	 * @param int[]                          $overridden_ids IDs that already have a rule.
	 */
	private function render_picker(
		string $prefix,
		array $items,
		array $overridden_ids,
		string $placeholder,
		bool $remote = false
	): void {

		$base =
			'pdxw-' . $prefix;

		?>
		<div
			class="pdxw-picker"
			style="width: 100%; max-width: 680px; margin-bottom: 10px;"
		>

			<input
				type="search"
				id="<?php echo esc_attr( $base ); ?>-search"
				class="regular-text"
				style="width: 100%;"
				autocomplete="off"
				placeholder="<?php echo esc_attr( $placeholder ); ?>"
			>

			<div style="margin: 6px 0;">

				<?php if ( ! $remote ) : ?>
					<button
						type="button"
						id="<?php echo esc_attr( $base ); ?>-select-matching"
						class="button button-small"
					>
						<?php echo esc_html__( 'Select all matching', 'promi-data-x-woo' ); ?>
					</button>
				<?php endif; ?>

				<button
					type="button"
					id="<?php echo esc_attr( $base ); ?>-clear"
					class="button button-small"
				>
					<?php echo esc_html__( 'Clear selection', 'promi-data-x-woo' ); ?>
				</button>

				<span
					id="<?php echo esc_attr( $base ); ?>-counter"
					class="description"
					style="margin-left: 8px;"
				></span>

			</div>

			<div
				id="<?php echo esc_attr( $base ); ?>-list"
				style="max-height: 260px; overflow: auto; border: 1px solid #c3c4c7; background: #fff; padding: 4px 8px;"
			>

				<?php if ( $remote ) : ?>
					<p class="description pdxw-pick-hint" style="margin: 6px 0;">
						<?php echo esc_html__( 'Type at least 2 characters to search.', 'promi-data-x-woo' ); ?>
					</p>
				<?php endif; ?>

				<?php foreach ( $items as $item ) : ?>

					<?php
					$item_id =
						absint( $item['id'] );

					$item_label =
						(string) $item['label'];

					$item_path =
						(string) ( $item['path'] ?? $item_label );

					$item_context =
						(string) ( $item['context'] ?? '' );

					$item_depth =
						max( 0, (int) ( $item['depth'] ?? 0 ) );

					$haystack =
						$item_path . ' ' . $item_label;

					$haystack =
						function_exists( 'mb_strtolower' )
							? mb_strtolower( $haystack )
							: strtolower( $haystack );
					?>

					<label
						class="pdxw-pick-item"
						data-search="<?php echo esc_attr( $haystack ); ?>"
						data-path="<?php echo esc_attr( $item_path ); ?>"
						style="display: block; padding: 2px 0 2px <?php echo esc_attr( $item_depth * 18 ); ?>px;"
					>
						<input
							type="checkbox"
							value="<?php echo esc_attr( $item_id ); ?>"
						>

						<span class="pdxw-pick-label"><?php echo esc_html( $item_label ); ?></span>

						<?php if ( '' !== $item_context ) : ?>
							<span class="description">
								<?php echo esc_html( '— ' . $item_context ); ?>
							</span>
						<?php endif; ?>

						<em
							class="pdxw-pick-badge"
							style="color: #2271b1; margin-left: 6px;<?php echo in_array( $item_id, $overridden_ids, true ) ? '' : ' display: none;'; ?>"
						>
							<?php echo esc_html__( 'overridden', 'promi-data-x-woo' ); ?>
						</em>
					</label>

				<?php endforeach; ?>

			</div>

		</div>
		<?php
	}


	/**
	 * Return the print options with the given IDs, keyed by ID.
	 *
	 * The print option table is far too large to load whole, so the page
	 * only fetches the rows it actually shows (the existing overrides).
	 * Everything else is found through the REST search endpoint.
	 *
	 * @param int[] $ids
	 * @return array<int,object>
	 */
	private function print_options_by_id( array $ids ): array {

		global $wpdb;

		$ids =
			array_values(
				array_unique(
					array_filter(
						array_map( 'absint', $ids )
					)
				)
			);

		if ( empty( $ids ) ) {
			return [];
		}

		$table =
			Database::table(
				'print_options'
			);

		$placeholders =
			implode(
				',',
				array_fill( 0, count( $ids ), '%d' )
			);

		$results =
			$wpdb->get_results(
				$wpdb->prepare(
					"SELECT
						id,
						name,
						sku
					FROM {$table}
					WHERE id IN ({$placeholders})",
					$ids
				)
			);

		$by_id = [];

		foreach ( (array) $results as $row ) {
			$by_id[ (int) $row->id ] = $row;
		}

		return $by_id;
	}


	/**
	 * Human label for a print option: "Name — SKU".
	 *
	 * Many print options share the same name, so the SKU is what tells them
	 * apart. Falls back to whichever of the two exists.
	 */
	private function print_option_label(
		object $option
	): string {

		$name =
			trim(
				(string) ( $option->name ?? '' )
			);

		$sku =
			trim(
				(string) ( $option->sku ?? '' )
			);

		if ( '' !== $name && '' !== $sku ) {
			return $name . ' — ' . $sku;
		}

		return '' !== $name
			? $name
			: $sku;
	}


	/**
	 * Return all WooCommerce product brand terms.
	 *
	 * Relies on the product_brand taxonomy already being registered by
	 * whatever brand plugin/integration the store uses (see Catalog\Brands).
	 */
	private function brands(): array {

		$brands =
			get_terms(
				[
					'taxonomy'   => ManufacturerDiscount::TAXONOMY,
					'hide_empty' => false,
				]
			);

		return is_wp_error( $brands )
			? []
			: $brands;
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


		$manufacturer_discounts =
			$this->pricing
				->manufacturer_discount()
				->all();


		$supplier_discounts =
			$this->pricing
				->supplier_discount()
				->all();


		$category_tree =
			$this->category_tree();

		$category_paths = [];

		$category_picker_items = [];

		foreach ( $category_tree as $category ) {

			$category_paths[ $category['id'] ] =
				$category['path'];

			$category_picker_items[] = [
				'id'      => $category['id'],
				'label'   => $category['name'],
				'path'    => $category['path'],
				'context' => $category['context'],
				'depth'   => $category['depth'],
			];
		}

		$print_options =
			$this->print_options_by_id(
				wp_list_pluck(
					$print_option_rules,
					'id'
				)
			);

		$brands =
			$this->brands();


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

		$brand_rule_ids =
			array_map(
				'absint',
				wp_list_pluck(
					$manufacturer_discounts,
					'term_id'
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

				<?php
				$this->render_picker(
					'cat',
					$category_picker_items,
					$category_rule_ids,
					__( 'Search categories…', 'promi-data-x-woo' )
				);
				?>

				<input
					type="number"
					id="pdxw-new-category-markup"
					class="small-text"
					value=""
					min="0"
					step="0.01"
					placeholder="0"
				>

				<button
					type="button"
					id="pdxw-add-category-rule"
					class="button"
				>
					<?php echo esc_html__( 'Apply to selected', 'promi-data-x-woo' ); ?>
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

							$term_name =
								$category_paths[ $category_id ]
								?? sprintf(
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
				<?php echo esc_html__( 'A print-option-specific markup overrides the default print option price / fee markup.', 'promi-data-x-woo' ); ?>
			</p>


			<div class="pdxw-add-rule">

				<?php
				$this->render_picker(
					'po',
					[],
					$print_option_rule_ids,
					__( 'Search name or SKU…', 'promi-data-x-woo' ),
					true
				);
				?>

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
					<?php echo esc_html__( 'Apply to selected', 'promi-data-x-woo' ); ?>
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

							if ( isset( $print_options[ $option_id ] ) ) {
								$option_name =
									$this->print_option_label(
										$print_options[ $option_id ]
									);
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


			<hr>


			<!-- Manufacturer Discounts -->

			<h2>
				<?php echo esc_html__( 'Manufacturer Discounts', 'promi-data-x-woo' ); ?>
			</h2>

			<p class="description">
				<?php echo esc_html__( 'Applied when a product\'s Promi cost is unavailable and its price is derived from the manufacturer\'s recommended selling price (RRP) instead. The discount converts the RRP into an effective cost basis before markup. Configured per brand (WooCommerce product_brand taxonomy). Takes priority over Supplier Discounts.', 'promi-data-x-woo' ); ?>
			</p>


			<div class="pdxw-add-rule">

				<select id="pdxw-new-brand">

					<option value="">
						<?php echo esc_html__( 'Select a brand…', 'promi-data-x-woo' ); ?>
					</option>

					<?php foreach ( $brands as $brand ) : ?>

						<?php
						$brand_id =
							absint(
								$brand->term_id
							);
						?>

						<option
							value="<?php echo esc_attr( $brand_id ); ?>"
							<?php disabled( in_array( $brand_id, $brand_rule_ids, true ) ); ?>
						>
							<?php
							echo esc_html(
								$brand->name
								. (
									in_array(
										$brand_id,
										$brand_rule_ids,
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
					id="pdxw-new-brand-discount"
					class="small-text"
					value=""
					min="0"
					max="100"
					step="0.01"
					placeholder="0"
				>

				<button
					type="button"
					id="pdxw-add-brand-rule"
					class="button"
				>
					<?php echo esc_html__( 'Add Manufacturer Discount', 'promi-data-x-woo' ); ?>
				</button>

			</div>


			<table
				class="wp-list-table widefat fixed striped"
				id="pdxw-brand-rules"
				style="margin-top: 16px;"
			>

				<thead>
					<tr>
						<th>
							<?php echo esc_html__( 'Brand', 'promi-data-x-woo' ); ?>
						</th>

						<th>
							<?php echo esc_html__( 'Discount (%)', 'promi-data-x-woo' ); ?>
						</th>

						<th>
							<?php echo esc_html__( 'Actions', 'promi-data-x-woo' ); ?>
						</th>
					</tr>
				</thead>

				<tbody>

					<?php if ( empty( $manufacturer_discounts ) ) : ?>

						<tr class="pdxw-empty-row">
							<td colspan="3">
								<?php echo esc_html__( 'No manufacturer discounts configured.', 'promi-data-x-woo' ); ?>
							</td>
						</tr>

					<?php else : ?>

						<?php foreach ( $manufacturer_discounts as $rule ) : ?>

							<?php
							$brand_id =
								absint(
									$rule['term_id'] ?? 0
								);
							?>

							<tr
								data-rule-id="<?php echo esc_attr( $brand_id ); ?>"
							>

								<td class="pdxw-rule-name">
									<?php echo esc_html( (string) ( $rule['name'] ?? '' ) ); ?>
								</td>

								<td>
									<input
										type="number"
										class="small-text pdxw-rule-discount"
										value="<?php echo esc_attr( $rule['discount_percent'] ?? 0 ); ?>"
										min="0"
										max="100"
										step="0.01"
									>
								</td>

								<td>

									<button
										type="button"
										class="button button-small pdxw-save-brand-rule"
									>
										<?php echo esc_html__( 'Save', 'promi-data-x-woo' ); ?>
									</button>

									<button
										type="button"
										class="button button-small pdxw-delete-brand-rule"
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


			<!-- Supplier Discounts -->

			<h2>
				<?php echo esc_html__( 'Supplier Discounts', 'promi-data-x-woo' ); ?>
			</h2>

			<p class="description">
				<?php echo esc_html__( 'Applied like a Manufacturer Discount (to the recommended selling price, before markup), but matched on the product SKU. Enter the start of the SKU, for example "A86-", and every product whose SKU starts with it gets the discount. The longest matching prefix wins. Priority: Manufacturer Discount first; a Supplier Discount only applies when the product\'s brand has no Manufacturer Discount configured.', 'promi-data-x-woo' ); ?>
			</p>


			<div class="pdxw-add-rule">

				<input
					type="text"
					id="pdxw-new-supplier-prefix"
					class="regular-text"
					value=""
					maxlength="100"
					placeholder="A86-"
				>

				<input
					type="number"
					id="pdxw-new-supplier-discount"
					class="small-text"
					value=""
					min="0"
					max="100"
					step="0.01"
					placeholder="0"
				>

				<button
					type="button"
					id="pdxw-add-supplier-rule"
					class="button"
				>
					<?php echo esc_html__( 'Add Supplier Discount', 'promi-data-x-woo' ); ?>
				</button>

			</div>


			<table
				class="wp-list-table widefat fixed striped"
				id="pdxw-supplier-rules"
				style="margin-top: 16px;"
			>

				<thead>
					<tr>
						<th>
							<?php echo esc_html__( 'SKU starts with', 'promi-data-x-woo' ); ?>
						</th>

						<th>
							<?php echo esc_html__( 'Discount (%)', 'promi-data-x-woo' ); ?>
						</th>

						<th>
							<?php echo esc_html__( 'Actions', 'promi-data-x-woo' ); ?>
						</th>
					</tr>
				</thead>

				<tbody>

					<?php if ( empty( $supplier_discounts ) ) : ?>

						<tr class="pdxw-empty-row">
							<td colspan="3">
								<?php echo esc_html__( 'No supplier discounts configured.', 'promi-data-x-woo' ); ?>
							</td>
						</tr>

					<?php else : ?>

						<?php foreach ( $supplier_discounts as $rule ) : ?>

							<tr
								data-prefix="<?php echo esc_attr( (string) ( $rule['sku_prefix'] ?? '' ) ); ?>"
							>

								<td class="pdxw-rule-name">
									<?php echo esc_html( (string) ( $rule['sku_prefix'] ?? '' ) ); ?>
								</td>

								<td>
									<input
										type="number"
										class="small-text pdxw-rule-discount"
										value="<?php echo esc_attr( $rule['discount_percent'] ?? 0 ); ?>"
										min="0"
										max="100"
										step="0.01"
									>
								</td>

								<td>

									<button
										type="button"
										class="button button-small pdxw-save-supplier-rule"
									>
										<?php echo esc_html__( 'Save', 'promi-data-x-woo' ); ?>
									</button>

									<button
										type="button"
										class="button button-small pdxw-delete-supplier-rule"
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


			<!-- Catalog price recalculation -->

			<h2>
				<?php echo esc_html__( 'Catalog Prices', 'promi-data-x-woo' ); ?>
			</h2>

			<p class="description">
				<?php echo esc_html__( 'The shop grid, sliders, search, price filter and price sort use a stored price. It is refreshed automatically in the background whenever a markup or discount above changes, or a product\'s category or brand changes. Use this button to force a full recalculation, for example after a bulk import.', 'promi-data-x-woo' ); ?>
			</p>

			<p>
				<button
					type="button"
					id="pdxw-reprice-all"
					class="button"
				>
					<?php echo esc_html__( 'Recalculate all catalog prices', 'promi-data-x-woo' ); ?>
				</button>

				<span
					id="pdxw-reprice-status"
					class="description"
					style="margin-left: 8px;"
				></span>
			</p>

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
			| Searchable multi-select picker
			|--------------------------------------------------------------------------
			|
			| Used by the category and print option sections (markup produced by
			| MarkupPage::render_picker()). A plain checkbox list filtered
			| client-side: it stays fast with thousands of entries, and the
			| selection lives in a Set of ids so it survives changing the search.
			*/

			function createPicker( prefix, remote, overriddenIds ) {

				const base =
					'pdxw-' + prefix;

				// Remote mode: rows come from the REST search endpoint, not
				// from the page. labels remembers every name seen so far so
				// selected / saved ids can still be named after a new search.
				const labels = {};

				const overriddenSet =
					new Set(
						( overriddenIds || [] ).map( String )
					);

				let requestId = 0;

				const list =
					document.getElementById(
						base + '-list'
					);

				const search =
					document.getElementById(
						base + '-search'
					);

				const counter =
					document.getElementById(
						base + '-counter'
					);

				const selected =
					new Set();

				const items = {};

				list
					.querySelectorAll( '.pdxw-pick-item' )
					.forEach(
						function ( item ) {

							items[
								item.querySelector( 'input' ).value
							] = item;
						}
					);

				function updateCounter() {

					let shown = 0;

					Object.keys( items ).forEach(
						function ( key ) {

							if ( items[ key ].style.display !== 'none' ) {
								shown++;
							}
						}
					);

					counter.textContent =
						selected.size
						+ ' '
						+ <?php echo wp_json_encode( __( 'selected', 'promi-data-x-woo' ) ); ?>
						+ ' / '
						+ shown
						+ ' '
						+ <?php echo wp_json_encode( __( 'shown', 'promi-data-x-woo' ) ); ?>;
				}

				function clear() {

					selected.clear();

					list
						.querySelectorAll( 'input[type="checkbox"]' )
						.forEach(
							function ( box ) {
								box.checked = false;
							}
						);

					updateCounter();
				}

				function mark(
					id,
					overridden
				) {

					if ( overridden ) {
						overriddenSet.add( String( id ) );
					} else {
						overriddenSet.delete( String( id ) );
					}

					const item =
						items[ String( id ) ];

					if ( ! item ) {
						return;
					}

					item.querySelector( '.pdxw-pick-badge' ).style.display =
						overridden
							? ''
							: 'none';
				}

				// Full name used for rules-table rows (category: "A › B › C").
				function nameOf( id ) {

					const item =
						items[ String( id ) ];

					if ( item ) {
						return item.dataset.path;
					}

					return labels[ String( id ) ]
						|| '#' + id;
				}

				function renderRemote( rows ) {

					Object.keys( items ).forEach(
						function ( key ) {
							delete items[ key ];
						}
					);

					list.innerHTML = '';

					if ( ! rows.length ) {

						const none =
							document.createElement( 'p' );

						none.className = 'description';

						none.style.margin = '6px 0';

						none.textContent =
							<?php echo wp_json_encode( __( 'No matching print options.', 'promi-data-x-woo' ) ); ?>;

						list.appendChild( none );

						updateCounter();

						return;
					}

					const fragment =
						document.createDocumentFragment();

					rows.forEach(
						function ( row ) {

							const key =
								String( row.id );

							labels[ key ] = row.label;

							const label =
								document.createElement( 'label' );

							label.className = 'pdxw-pick-item';

							label.dataset.path = row.label;

							label.style.display = 'block';

							label.style.padding = '2px 0';

							const box =
								document.createElement( 'input' );

							box.type = 'checkbox';

							box.value = key;

							box.checked = selected.has( key );

							const text =
								document.createElement( 'span' );

							text.className = 'pdxw-pick-label';

							text.textContent = ' ' + row.label;

							const badge =
								document.createElement( 'em' );

							badge.className = 'pdxw-pick-badge';

							badge.style.color = '#2271b1';

							badge.style.marginLeft = '6px';

							badge.style.display =
								overriddenSet.has( key )
									? ''
									: 'none';

							badge.textContent =
								<?php echo wp_json_encode( __( 'overridden', 'promi-data-x-woo' ) ); ?>;

							label.appendChild( box );

							label.appendChild( text );

							label.appendChild( badge );

							fragment.appendChild( label );

							items[ key ] = label;
						}
					);

					list.appendChild( fragment );

					updateCounter();
				}

				function remoteSearch() {

					const term =
						search.value.trim();

					const current =
						++requestId;

					if ( term.length < 2 ) {

						Object.keys( items ).forEach(
							function ( key ) {
								delete items[ key ];
							}
						);

						list.innerHTML =
							'<p class="description pdxw-pick-hint" style="margin:6px 0;">'
							+ escapeHtml(
								<?php echo wp_json_encode( __( 'Type at least 2 characters to search.', 'promi-data-x-woo' ) ); ?>
							)
							+ '</p>';

						updateCounter();

						return;
					}

					apiFetch(
						'/pricing/print-options/search?q='
							+ encodeURIComponent( term )
					)
					.then(
						function ( rows ) {

							// Ignore answers to an outdated query.
							if ( current !== requestId ) {
								return;
							}

							renderRemote(
								Array.isArray( rows )
									? rows
									: []
							);
						}
					)
					.catch(
						function ( error ) {

							showNotice(
								error.message
								|| <?php echo wp_json_encode( __( 'Search failed.', 'promi-data-x-woo' ) ); ?>,
								'error'
							);
						}
					);
				}

				function filter() {

					if ( remote ) {

						remoteSearch();

						return;
					}

					const words =
						search.value
							.toLowerCase()
							.split( /\s+/ )
							.filter( Boolean );

					Object.keys( items ).forEach(
						function ( key ) {

							const haystack =
								items[ key ].dataset.search;

							const match =
								words.every(
									function ( word ) {
										return haystack.indexOf( word ) !== -1;
									}
								);

							items[ key ].style.display =
								match
									? 'block'
									: 'none';
						}
					);

					updateCounter();
				}

				let timer = null;

				search.addEventListener(
					'input',
					function () {

						window.clearTimeout( timer );

						timer =
							window.setTimeout(
								filter,
								remote
									? 300
									: 100
							);
					}
				);

				list.addEventListener(
					'change',
					function ( event ) {

						const box =
							event.target;

						if ( box.type !== 'checkbox' ) {
							return;
						}

						if ( box.checked ) {
							selected.add( box.value );
						} else {
							selected.delete( box.value );
						}

						updateCounter();
					}
				);

				const selectMatching =
					document.getElementById(
						base + '-select-matching'
					);

				// Not rendered for the remote (print option) picker.
				if ( selectMatching ) {

					selectMatching.addEventListener(
						'click',
						function () {

							Object.keys( items ).forEach(
								function ( key ) {

									if ( items[ key ].style.display === 'none' ) {
										return;
									}

									items[ key ].querySelector( 'input' ).checked =
										true;

									selected.add( key );
								}
							);

							updateCounter();
						}
					);
				}

				document
					.getElementById(
						base + '-clear'
					)
					.addEventListener(
						'click',
						clear
					);

				updateCounter();

				return {
					selected: selected,
					mark: mark,
					clear: clear,
					nameOf: nameOf,
				};
			}


			const categoryPicker =
				createPicker( 'cat' );

			const printPicker =
				createPicker(
					'po',
					true,
					<?php echo wp_json_encode( array_values( $print_option_rule_ids ) ); ?>
				);


			/*
			|--------------------------------------------------------------------------
			| Apply Rules (bulk)
			|--------------------------------------------------------------------------
			|
			| Rows for the selected ids are updated in place when they already
			| exist and appended (through one fragment) when they don't.
			*/

			function upsertRuleRows(
				tableId,
				ids,
				updateRow,
				buildRow
			) {

				const tbody =
					document
						.querySelector(
							'#' + tableId + ' tbody'
						);

				const emptyRow =
					tbody.querySelector(
						'.pdxw-empty-row'
					);

				if ( emptyRow ) {
					emptyRow.remove();
				}

				const existing = {};

				tbody
					.querySelectorAll( 'tr[data-rule-id]' )
					.forEach(
						function ( row ) {
							existing[ row.dataset.ruleId ] = row;
						}
					);

				const fragment =
					document.createDocumentFragment();

				ids.forEach(
					function ( id ) {

						const key =
							String( id );

						if ( existing[ key ] ) {

							updateRow(
								existing[ key ]
							);

							return;
						}

						fragment.appendChild(
							buildRow(
								key
							)
						);
					}
				);

				tbody.appendChild(
					fragment
				);
			}


			/*
			|--------------------------------------------------------------------------
			| Apply Category Rules
			|--------------------------------------------------------------------------
			*/

			const applyCategoryButton =
				document.getElementById(
					'pdxw-add-category-rule'
				);

			function applyCategoryRules() {

				const markupInput =
					document.getElementById(
						'pdxw-new-category-markup'
					);

				const ids =
					Array.from( categoryPicker.selected );

				const markup =
					validateMarkup(
						markupInput.value
					);

				if ( ! ids.length ) {

					showNotice(
						<?php echo wp_json_encode( __( 'Please select at least one category.', 'promi-data-x-woo' ) ); ?>,
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

				if (
					! window.confirm(
						<?php echo wp_json_encode( __( 'Apply the markup to', 'promi-data-x-woo' ) ); ?>
						+ ' ' + ids.length + ' '
						+ <?php echo wp_json_encode( __( 'categories:', 'promi-data-x-woo' ) ); ?>
						+ ' ' + markup + '%. '
						+ <?php echo wp_json_encode( __( 'Existing overrides of these categories are replaced.', 'promi-data-x-woo' ) ); ?>
					)
				) {
					return;
				}

				applyCategoryButton.disabled = true;

				apiFetch(
					'/pricing/categories/bulk',
					'POST',
					{
						ids:
							ids,

						markup_percent:
							markup,
					}
				)
				.then(
					function ( data ) {

						upsertRuleRows(
							'pdxw-category-rules',
							ids,
							function ( row ) {

								row.querySelector( '.pdxw-rule-markup' ).value =
									markup;
							},
							function ( key ) {

								const row =
									document.createElement(
										'tr'
									);

								row.dataset.ruleType =
									categoryType;

								row.dataset.ruleId =
									key;

								row.innerHTML =
									'<td class="pdxw-rule-name">'
									+ escapeHtml( categoryPicker.nameOf( key ) )
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

								return row;
							}
						);

						ids.forEach(
							function ( id ) {
								categoryPicker.mark( id, true );
							}
						);

						categoryPicker.clear();

						markupInput.value = '';

						showNotice(
							( data.updated || ids.length )
							+ ' '
							+ <?php echo wp_json_encode( __( 'category markup override(s) saved. Catalog prices are being updated.', 'promi-data-x-woo' ) ); ?>,
							'success'
						);
					}
				)
				.catch(
					function ( error ) {

						showNotice(
							error.message
							|| <?php echo wp_json_encode( __( 'Error saving markup overrides.', 'promi-data-x-woo' ) ); ?>,
							'error'
						);
					}
				)
				.finally(
					function () {
						applyCategoryButton.disabled = false;
					}
				);
			}

			applyCategoryButton.addEventListener(
				'click',
				applyCategoryRules
			);


			/*
			|--------------------------------------------------------------------------
			| Apply Print Option Rules
			|--------------------------------------------------------------------------
			|
			| Print options have two independent markup values (price and
			| fee). One price/fee pair is applied to every selected option in a
			| single request.
			*/

			const applyPrintButton =
				document.getElementById(
					'pdxw-add-print-option-rule'
				);

			function applyPrintOptionRules() {

				const priceInput =
					document.getElementById(
						'pdxw-new-print-option-price-markup'
					);

				const feeInput =
					document.getElementById(
						'pdxw-new-print-option-fee-markup'
					);

				const ids =
					Array.from( printPicker.selected );

				const priceMarkup =
					validateMarkup(
						priceInput.value
					);

				// A blank fee markup reuses the price markup.
				const feeMarkup =
					feeInput.value.trim() === ''
						? priceMarkup
						: validateMarkup(
							feeInput.value
						);

				if ( ! ids.length ) {

					showNotice(
						<?php echo wp_json_encode( __( 'Please select at least one print option.', 'promi-data-x-woo' ) ); ?>,
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

				if (
					! window.confirm(
						<?php echo wp_json_encode( __( 'Apply the markup to', 'promi-data-x-woo' ) ); ?>
						+ ' ' + ids.length + ' '
						+ <?php echo wp_json_encode( __( 'print option(s)? Price', 'promi-data-x-woo' ) ); ?>
						+ ' ' + priceMarkup + '% / '
						+ <?php echo wp_json_encode( __( 'Fee', 'promi-data-x-woo' ) ); ?>
						+ ' ' + feeMarkup + '%. '
						+ <?php echo wp_json_encode( __( 'Existing overrides of these options are replaced.', 'promi-data-x-woo' ) ); ?>
					)
				) {
					return;
				}

				applyPrintButton.disabled = true;

				apiFetch(
					'/pricing/print-options/bulk',
					'POST',
					{
						ids:
							ids,

						price_markup_percent:
							priceMarkup,

						fee_markup_percent:
							feeMarkup,
					}
				)
				.then(
					function ( data ) {

						upsertRuleRows(
							'pdxw-print-option-rules',
							ids,
							function ( row ) {

								row.querySelector( '.pdxw-rule-price-markup' ).value =
									priceMarkup;

								row.querySelector( '.pdxw-rule-fee-markup' ).value =
									feeMarkup;
							},
							function ( key ) {

								const row =
									document.createElement(
										'tr'
									);

								row.dataset.ruleId =
									key;

								row.innerHTML =
									'<td class="pdxw-rule-name">'
									+ escapeHtml( printPicker.nameOf( key ) )
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

								return row;
							}
						);

						ids.forEach(
							function ( id ) {
								printPicker.mark( id, true );
							}
						);

						printPicker.clear();

						priceInput.value = '';

						feeInput.value = '';

						showNotice(
							( data.updated || ids.length )
							+ ' '
							+ <?php echo wp_json_encode( __( 'print option markup override(s) saved.', 'promi-data-x-woo' ) ); ?>,
							'success'
						);
					}
				)
				.catch(
					function ( error ) {

						showNotice(
							error.message
							|| <?php echo wp_json_encode( __( 'Error saving markup overrides.', 'promi-data-x-woo' ) ); ?>,
							'error'
						);
					}
				)
				.finally(
					function () {
						applyPrintButton.disabled = false;
					}
				);
			}

			applyPrintButton.addEventListener(
				'click',
				applyPrintOptionRules
			);


			/*
			|--------------------------------------------------------------------------
			| Add Manufacturer Discount Rule
			|--------------------------------------------------------------------------
			|
			| A discount is bounded to 0-100, unlike an open-ended markup, so
			| it gets its own validator rather than reusing validateMarkup().
			*/

			function validateDiscount(
				value
			) {

				const discount =
					parseFloat( value );

				return (
					! isNaN( discount )
					&& discount >= 0
					&& discount <= 100
				)
					? discount
					: null;
			}


			function addBrandRule() {

				const select =
					document.getElementById(
						'pdxw-new-brand'
					);

				const discountInput =
					document.getElementById(
						'pdxw-new-brand-discount'
					);

				const id =
					parseInt(
						select.value,
						10
					);

				const discount =
					validateDiscount(
						discountInput.value
					);

				if ( ! id ) {

					showNotice(
						<?php echo wp_json_encode( __( 'Please select a brand.', 'promi-data-x-woo' ) ); ?>,
						'error'
					);

					return;
				}

				if ( null === discount ) {

					showNotice(
						<?php echo wp_json_encode( __( 'Please enter a discount between 0 and 100.', 'promi-data-x-woo' ) ); ?>,
						'error'
					);

					return;
				}

				apiFetch(
					'/pricing/manufacturer-discounts/' + id,
					'POST',
					{
						discount_percent:
							discount,
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
									'#pdxw-brand-rules tbody'
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
							+ '<input type="number" class="small-text pdxw-rule-discount" '
							+ 'value="' + discount + '" min="0" max="100" step="0.01">'
							+ '</td>'
							+ '<td>'
							+ '<button type="button" class="button button-small pdxw-save-brand-rule">'
							+ <?php echo wp_json_encode( __( 'Save', 'promi-data-x-woo' ) ); ?>
							+ '</button> '
							+ '<button type="button" class="button button-small pdxw-delete-brand-rule">'
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

						discountInput.value = '';

						showNotice(
							<?php echo wp_json_encode( __( 'Manufacturer discount added.', 'promi-data-x-woo' ) ); ?>,
							'success'
						);
					}
				)
				.catch(
					function ( error ) {

						showNotice(
							error.message
							|| <?php echo wp_json_encode( __( 'Error adding manufacturer discount.', 'promi-data-x-woo' ) ); ?>,
							'error'
						);
					}
				);
			}


			document
				.getElementById(
					'pdxw-add-brand-rule'
				)
				.addEventListener(
					'click',
					addBrandRule
				);


			/*
			|--------------------------------------------------------------------------
			| Add Supplier Discount Rule (SKU prefix)
			|--------------------------------------------------------------------------
			*/

			function addSupplierRule() {

				const prefixInput =
					document.getElementById(
						'pdxw-new-supplier-prefix'
					);

				const discountInput =
					document.getElementById(
						'pdxw-new-supplier-discount'
					);

				const prefix =
					prefixInput.value
						.trim()
						.toUpperCase();

				const discount =
					validateDiscount(
						discountInput.value
					);

				if ( ! prefix ) {

					showNotice(
						<?php echo wp_json_encode( __( 'Please enter a SKU prefix, for example A86-.', 'promi-data-x-woo' ) ); ?>,
						'error'
					);

					return;
				}

				if ( null === discount ) {

					showNotice(
						<?php echo wp_json_encode( __( 'Please enter a discount between 0 and 100.', 'promi-data-x-woo' ) ); ?>,
						'error'
					);

					return;
				}

				apiFetch(
					'/pricing/supplier-discounts',
					'POST',
					{
						sku_prefix:
							prefix,

						discount_percent:
							discount,
					}
				)
				.then(
					function () {

						const tbody =
							document
								.querySelector(
									'#pdxw-supplier-rules tbody'
								);

						const emptyRow =
							tbody.querySelector(
								'.pdxw-empty-row'
							);

						if ( emptyRow ) {
							emptyRow.remove();
						}

						// Saving an existing prefix updates it instead of
						// adding a second row.
						let row = null;

						tbody
							.querySelectorAll( 'tr' )
							.forEach(
								function ( candidate ) {

									if ( candidate.dataset.prefix === prefix ) {
										row = candidate;
									}
								}
							);

						if ( row ) {

							row
								.querySelector( '.pdxw-rule-discount' )
								.value = discount;

						} else {

							row =
								document.createElement(
									'tr'
								);

							row.dataset.prefix =
								prefix;

							row.innerHTML =
								'<td class="pdxw-rule-name">'
								+ escapeHtml( prefix )
								+ '</td>'
								+ '<td>'
								+ '<input type="number" class="small-text pdxw-rule-discount" '
								+ 'value="' + discount + '" min="0" max="100" step="0.01">'
								+ '</td>'
								+ '<td>'
								+ '<button type="button" class="button button-small pdxw-save-supplier-rule">'
								+ <?php echo wp_json_encode( __( 'Save', 'promi-data-x-woo' ) ); ?>
								+ '</button> '
								+ '<button type="button" class="button button-small pdxw-delete-supplier-rule">'
								+ <?php echo wp_json_encode( __( 'Remove', 'promi-data-x-woo' ) ); ?>
								+ '</button>'
								+ '</td>';

							tbody.appendChild(
								row
							);
						}

						prefixInput.value = '';

						discountInput.value = '';

						showNotice(
							<?php echo wp_json_encode( __( 'Supplier discount saved. Catalog prices are being updated.', 'promi-data-x-woo' ) ); ?>,
							'success'
						);
					}
				)
				.catch(
					function ( error ) {

						showNotice(
							error.message
							|| <?php echo wp_json_encode( __( 'Error saving supplier discount.', 'promi-data-x-woo' ) ); ?>,
							'error'
						);
					}
				);
			}


			document
				.getElementById(
					'pdxw-add-supplier-rule'
				)
				.addEventListener(
					'click',
					addSupplierRule
				);


			/*
			|--------------------------------------------------------------------------
			| Recalculate All Catalog Prices
			|--------------------------------------------------------------------------
			*/

			const repriceStatus =
				document.getElementById(
					'pdxw-reprice-status'
				);

			function showRepriceStatus(
				pending
			) {

				repriceStatus.textContent =
					pending > 0
						? pending
							+ ' '
							+ <?php echo wp_json_encode( __( 'background job(s) pending…', 'promi-data-x-woo' ) ); ?>
						: '';
			}

			function pollReprice() {

				apiFetch(
					'/pricing/reprice'
				)
				.then(
					function ( data ) {

						showRepriceStatus(
							data.pending_jobs || 0
						);

						if ( data.pending_jobs > 0 ) {
							window.setTimeout(
								pollReprice,
								5000
							);
						}
					}
				)
				.catch(
					function () {}
				);
			}

			document
				.getElementById(
					'pdxw-reprice-all'
				)
				.addEventListener(
					'click',
					function () {

						apiFetch(
							'/pricing/reprice',
							'POST'
						)
						.then(
							function ( data ) {

								showNotice(
									<?php echo wp_json_encode( __( 'Catalog price recalculation started for', 'promi-data-x-woo' ) ); ?>
									+ ' '
									+ ( data.products || 0 )
									+ ' '
									+ <?php echo wp_json_encode( __( 'products.', 'promi-data-x-woo' ) ); ?>,
									'success'
								);

								showRepriceStatus(
									data.pending_jobs || 0
								);

								if ( data.pending_jobs > 0 ) {
									window.setTimeout(
										pollReprice,
										5000
									);
								}
							}
						)
						.catch(
							function ( error ) {

								showNotice(
									error.message
									|| <?php echo wp_json_encode( __( 'Error starting recalculation.', 'promi-data-x-woo' ) ); ?>,
									'error'
								);
							}
						);
					}
				);

			pollReprice();


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

								printPicker.mark(
									id,
									false
								);

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
					| Supplier Discount Rules (SKU prefix)
					|--------------------------------------------------------------------------
					*/

					const saveSupplierButton =
						event.target.closest(
							'.pdxw-save-supplier-rule'
						);

					if ( saveSupplierButton ) {

						const row =
							saveSupplierButton.closest(
								'tr'
							);

						const prefix =
							row.dataset.prefix;

						const discount =
							validateDiscount(
								row
									.querySelector(
										'.pdxw-rule-discount'
									)
									.value
							);

						if ( null === discount ) {
							showNotice(
								<?php echo wp_json_encode( __( 'Please enter a discount between 0 and 100.', 'promi-data-x-woo' ) ); ?>,
								'error'
							);

							return;
						}

						apiFetch(
							'/pricing/supplier-discounts',
							'POST',
							{
								sku_prefix:
									prefix,

								discount_percent:
									discount,
							}
						)
						.then(
							function () {

								showNotice(
									<?php echo wp_json_encode( __( 'Supplier discount saved. Catalog prices are being updated.', 'promi-data-x-woo' ) ); ?>,
									'success'
								);
							}
						)
						.catch(
							function ( error ) {

								showNotice(
									error.message
									|| <?php echo wp_json_encode( __( 'Error saving supplier discount.', 'promi-data-x-woo' ) ); ?>,
									'error'
								);
							}
						);

						return;
					}


					const deleteSupplierButton =
						event.target.closest(
							'.pdxw-delete-supplier-rule'
						);

					if ( deleteSupplierButton ) {

						const row =
							deleteSupplierButton.closest(
								'tr'
							);

						const prefix =
							row.dataset.prefix;

						if (
							! window.confirm(
								<?php echo wp_json_encode( __( 'Remove the supplier discount for SKU prefix', 'promi-data-x-woo' ) ); ?>
								+ ' "'
								+ prefix
								+ '"?'
							)
						) {
							return;
						}

						apiFetch(
							'/pricing/supplier-discounts',
							'DELETE',
							{
								sku_prefix:
									prefix,
							}
						)
						.then(
							function () {

								const tbody =
									row.closest(
										'tbody'
									);

								row.remove();

								if (
									! tbody.querySelector(
										'tr'
									)
								) {
									tbody.innerHTML =
										'<tr class="pdxw-empty-row">'
										+ '<td colspan="3">'
										+ escapeHtml(
											<?php echo wp_json_encode( __( 'No supplier discounts configured.', 'promi-data-x-woo' ) ); ?>
										)
										+ '</td></tr>';
								}

								showNotice(
									<?php echo wp_json_encode( __( 'Supplier discount removed. Catalog prices are being updated.', 'promi-data-x-woo' ) ); ?>,
									'success'
								);
							}
						)
						.catch(
							function ( error ) {

								showNotice(
									error.message
									|| <?php echo wp_json_encode( __( 'Error removing supplier discount.', 'promi-data-x-woo' ) ); ?>,
									'error'
								);
							}
						);

						return;
					}


					/*
					|--------------------------------------------------------------------------
					| Manufacturer Discount Rules
					|--------------------------------------------------------------------------
					*/

					const saveBrandButton =
						event.target.closest(
							'.pdxw-save-brand-rule'
						);

					if ( saveBrandButton ) {

						const row =
							saveBrandButton.closest(
								'tr'
							);

						const id =
							row.dataset.ruleId;

						const discount =
							validateDiscount(
								row
									.querySelector(
										'.pdxw-rule-discount'
									)
									.value
							);

						if ( null === discount ) {
							showNotice(
								<?php echo wp_json_encode( __( 'Please enter a discount between 0 and 100.', 'promi-data-x-woo' ) ); ?>,
								'error'
							);

							return;
						}

						apiFetch(
							'/pricing/manufacturer-discounts/' + id,
							'POST',
							{
								discount_percent:
									discount,
							}
						)
						.then(
							function () {

								showNotice(
									<?php echo wp_json_encode( __( 'Manufacturer discount saved.', 'promi-data-x-woo' ) ); ?>,
									'success'
								);
							}
						)
						.catch(
							function ( error ) {

								showNotice(
									error.message
									|| <?php echo wp_json_encode( __( 'Error saving manufacturer discount.', 'promi-data-x-woo' ) ); ?>,
									'error'
								);
							}
						);

						return;
					}


					const deleteBrandButton =
						event.target.closest(
							'.pdxw-delete-brand-rule'
						);

					if ( deleteBrandButton ) {

						const row =
							deleteBrandButton.closest(
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
								<?php echo wp_json_encode( __( 'Remove the manufacturer discount for', 'promi-data-x-woo' ) ); ?>
								+ ' "'
								+ name
								+ '"?'
							)
						) {
							return;
						}

						apiFetch(
							'/pricing/manufacturer-discounts/' + id,
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
										'pdxw-new-brand'
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
										+ '<td colspan="3">'
										+ escapeHtml(
											<?php echo wp_json_encode( __( 'No manufacturer discounts configured.', 'promi-data-x-woo' ) ); ?>
										)
										+ '</td></tr>';
								}

								showNotice(
									<?php echo wp_json_encode( __( 'Manufacturer discount removed.', 'promi-data-x-woo' ) ); ?>,
									'success'
								);
							}
						)
						.catch(
							function ( error ) {

								showNotice(
									error.message
									|| <?php echo wp_json_encode( __( 'Error removing manufacturer discount.', 'promi-data-x-woo' ) ); ?>,
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


							( type === categoryType
								? categoryPicker
								: printPicker
							).mark(
								id,
								false
							);


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