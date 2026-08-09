<?php

namespace PromiDataXWoo\Admin;

use PromiDataXWoo\Catalog\Catalog;
use PromiDataXWoo\Pricing\Pricing;
use Throwable;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;

defined( 'ABSPATH' ) || exit;

/**
 * Tier-pricing administration page.
 *
 * Rebuilds the useful administrative workflow from CX Tiered Pricing:
 *
 * 1. Search for a WooCommerce product or variation by SKU.
 * 2. Resolve editable pricing targets.
 * 3. Display exact quantity tiers for each target.
 * 4. Edit:
 *
 *      - Quantity
 *      - Selling price
 *      - Purchasing price
 *
 * 5. Add/remove tier rows.
 * 6. Save all pricing targets in one submission.
 *
 * Business rules remain inside Pricing\TieredPricing.
 *
 * This class owns:
 *
 * - Admin page rendering.
 * - Request validation.
 * - Form sanitization.
 * - Delegating tier replacement to Pricing.
 *
 * It does not perform direct tier-price SQL.
 */
final class PricingPage {

	public const SAVE_ACTION =
		'pdxw_save_tiers';

	public const NONCE_ACTION =
		'pdxw_save_tiers';

	public const NONCE_FIELD =
		'pdxw_tier_nonce';


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
	 * Register pricing-admin form handlers.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;


		add_action(
			'admin_post_' . self::SAVE_ACTION,
			[
				$this,
				'save',
			]
		);


		do_action(
			'pdxw_admin_pricing_page_init',
			$this
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Page
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render the tier-pricing administration page.
	 */
	public function render(): void {

		$this->authorize();


		$sku =
			$this->requested_sku();


		$notice =
			$this->requested_notice();


		?>
		<div class="wrap pdxw-admin pdxw-pricing-page">

			<h1>
				<?php
				echo esc_html__(
					'Tier Pricing',
					'promi-data-x-woo'
				);
				?>
			</h1>


			<p class="description">
				<?php
				echo esc_html__(
					'Manage quantity-based selling and purchasing prices for WooCommerce products and variations.',
					'promi-data-x-woo'
				);
				?>
			</p>


			<?php
			/*
			|--------------------------------------------------------------------------
			| Result Notice
			|--------------------------------------------------------------------------
			*/
			?>

			<?php if ( $notice ) : ?>

				<div
					class="notice <?php echo esc_attr(
						$notice['class']
					); ?> is-dismissible"
				>
					<p>
						<?php
						echo esc_html(
							$notice['message']
						);
						?>
					</p>
				</div>

			<?php endif; ?>


			<?php
			/*
			|--------------------------------------------------------------------------
			| SKU Search
			|--------------------------------------------------------------------------
			*/
			?>

			<div class="pdxw-box">

				<form method="get">

					<input
						type="hidden"
						name="page"
						value="<?php echo esc_attr(
							Menu::PRICING_SLUG
						); ?>"
					>


					<label
						for="pdxw-tier-sku"
						class="screen-reader-text"
					>
						<?php
						echo esc_html__(
							'Product SKU',
							'promi-data-x-woo'
						);
						?>
					</label>


					<input
						type="text"
						id="pdxw-tier-sku"
						name="sku"
						class="regular-text"
						placeholder="<?php echo esc_attr__(
							'Enter product or variation SKU',
							'promi-data-x-woo'
						); ?>"
						value="<?php echo esc_attr(
							$sku
						); ?>"
					>


					<button
						type="submit"
						class="button button-primary"
					>
						<?php
						echo esc_html__(
							'Load',
							'promi-data-x-woo'
						);
						?>
					</button>

				</form>

			</div>


			<?php
			/*
			|--------------------------------------------------------------------------
			| Pricing Editor
			|--------------------------------------------------------------------------
			*/
			?>

			<?php
			if ( '' !== $sku ) {

				$this->render_editor(
					$sku
				);
			}
			?>

		</div>
		<?php
	}


	/*
	|--------------------------------------------------------------------------
	| Editor
	|--------------------------------------------------------------------------
	*/

	/**
	 * Resolve an SKU and render its editable pricing targets.
	 */
	private function render_editor(
		string $sku
	): void {

		$product =
			$this->catalog
				->products()
				->by_sku(
					$sku
				);


		if ( ! $product ) {

			$this->render_message(
				__(
					'No WooCommerce product or variation was found for that SKU.',
					'promi-data-x-woo'
				),
				'warning'
			);

			return;
		}


		$targets =
			$this->targets(
				$product
			);


		if ( empty( $targets ) ) {

			$this->render_message(
				__(
					'No editable pricing targets were found for this product.',
					'promi-data-x-woo'
				),
				'warning'
			);

			return;
		}


		?>
		<form
			method="post"
			action="<?php echo esc_url(
				admin_url(
					'admin-post.php'
				)
			); ?>"
			class="pdxw-tier-pricing-form"
		>

			<input
				type="hidden"
				name="action"
				value="<?php echo esc_attr(
					self::SAVE_ACTION
				); ?>"
			>


			<input
				type="hidden"
				name="sku"
				value="<?php echo esc_attr(
					$sku
				); ?>"
			>


			<?php
			wp_nonce_field(
				self::NONCE_ACTION,
				self::NONCE_FIELD
			);
			?>


			<?php foreach (
				$targets
					as $index => $target
			) : ?>

				<?php
				$this->render_target(
					$index,
					$target
				);
				?>

			<?php endforeach; ?>


			<p class="submit">

				<button
					type="submit"
					class="button button-primary button-large"
				>
					<?php
					echo esc_html__(
						'Save All',
						'promi-data-x-woo'
					);
					?>
				</button>

			</p>

		</form>


		<?php
		$this->render_row_template();
		
	}


	/**
	 * Render one product/variation pricing target.
	 *
	 * @param array{
	 *     product_id:int,
	 *     variation_id:int,
	 *     product:WC_Product
	 * } $target
	 */
	private function render_target(
		int $index,
		array $target
	): void {

		$product_id =
			absint(
				$target['product_id']
					?? 0
			);


		$variation_id =
			absint(
				$target['variation_id']
					?? 0
			);


		$product =
			$target['product']
				?? null;


		if (
			! $product_id
			|| ! $product instanceof WC_Product
		) {
			return;
		}


		/*
		|--------------------------------------------------------------------------
		| IMPORTANT: Exact Rows
		|--------------------------------------------------------------------------
		|
		| The admin editor must show rows stored against this exact target.
		|
		| We deliberately use repository()->get() rather than:
		|
		|     selling_tiers()
		|     purchase_tiers()
		|
		| because those public pricing APIs intentionally perform parent
		| fallback for runtime pricing.
		|
		| An admin editor must never make inherited parent tiers look as though
		| they are physically stored against a variation.
		*/

		$rows =
			$this->pricing
				->repository()
				->get(
					$product_id,
					$variation_id
				);


		?>
		<div
			class="pdxw-box pdxw-tier-target"
			data-tier-group="<?php echo esc_attr(
				$index
			); ?>"
		>

			<div class="pdxw-tier-target-header">

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
								'SKU:',
								'promi-data-x-woo'
							);
							?>
						</strong>

						<code>
							<?php
							echo esc_html(
								$product->get_sku()
									?: '—'
							);
							?>
						</code>

					</p>


					<?php if ( $variation_id ) : ?>

						<p>

							<strong>
								<?php
								echo esc_html__(
									'Variation:',
									'promi-data-x-woo'
								);
								?>
							</strong>

							<?php
							echo wp_kses_post(
								wc_get_formatted_variation(
									$product,
									true,
									false,
									true
								)
							);
							?>

						</p>

					<?php endif; ?>

				</div>


				<div>

					<?php
					$edit_url =
						get_edit_post_link(
							$product->get_id(),
							''
						);
					?>

					<?php if ( $edit_url ) : ?>

						<a
							href="<?php echo esc_url(
								$edit_url
							); ?>"
							class="button"
						>
							<?php
							echo esc_html__(
								'Edit Product',
								'promi-data-x-woo'
							);
							?>
						</a>

					<?php endif; ?>

				</div>

			</div>


			<input
				type="hidden"
				name="tiers[<?php echo esc_attr(
					$index
				); ?>][product_id]"
				value="<?php echo esc_attr(
					$product_id
				); ?>"
			>


			<input
				type="hidden"
				name="tiers[<?php echo esc_attr(
					$index
				); ?>][variation_id]"
				value="<?php echo esc_attr(
					$variation_id
				); ?>"
			>


			<table class="widefat striped pdxw-tier-table">

				<thead>

					<tr>

						<th style="width:140px;">
							<?php
							echo esc_html__(
								'Quantity',
								'promi-data-x-woo'
							);
							?>
						</th>


						<th style="width:200px;">
							<?php
							echo esc_html__(
								'Selling Price',
								'promi-data-x-woo'
							);
							?>
						</th>


						<th style="width:200px;">
							<?php
							echo esc_html__(
								'Purchasing Price',
								'promi-data-x-woo'
							);
							?>
						</th>


						<th>
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

					<?php foreach ( $rows as $row ) : ?>

						<?php
						$this->render_tier_row(
							$index,
							(int) (
								$row->qty
									?? 0
							),
							$row->price
								?? '',
							$row->purchase_price
								?? ''
						);
						?>

					<?php endforeach; ?>

				</tbody>

			</table>


			<p>

				<button
					type="button"
					class="button pdxw-add-tier"
				>
					<?php
					echo esc_html__(
						'Add Tier',
						'promi-data-x-woo'
					);
					?>
				</button>

			</p>


			<?php if ( empty( $rows ) ) : ?>

				<p class="description pdxw-tier-empty-message">
					<?php
					echo esc_html__(
						'No tiers are stored for this target yet. Add a tier to begin.',
						'promi-data-x-woo'
					);
					?>
				</p>

			<?php endif; ?>

		</div>
		<?php
	}


	/**
	 * Render one editable tier row.
	 */
	private function render_tier_row(
		int $index,
		int $qty,
		mixed $price,
		mixed $purchase_price
	): void {

		?>
		<tr>

			<td>

				<input
					type="number"
					min="1"
					step="1"
					required
					name="tiers[<?php echo esc_attr(
						$index
					); ?>][qty][]"
					value="<?php echo esc_attr(
						$qty
					); ?>"
				>

			</td>


			<td>

				<input
					type="text"
					inputmode="decimal"
					required
					name="tiers[<?php echo esc_attr(
						$index
					); ?>][price][]"
					value="<?php echo esc_attr(
						(string) $price
					); ?>"
					placeholder="0.0000"
				>

			</td>


			<td>

				<input
					type="text"
					inputmode="decimal"
					name="tiers[<?php echo esc_attr(
						$index
					); ?>][purchase_price][]"
					value="<?php echo esc_attr(
						null === $purchase_price
							? ''
							: (string) $purchase_price
					); ?>"
					placeholder="<?php echo esc_attr__(
						'Optional',
						'promi-data-x-woo'
					); ?>"
				>

			</td>


			<td>

				<button
					type="button"
					class="button pdxw-remove-tier"
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
		<?php
	}


	/*
	|--------------------------------------------------------------------------
	| Add-Row Template
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render the hidden row used by the admin JS.
	 */
	private function render_row_template(): void {

		?>
		<template id="pdxw-tier-row-template">

			<tr>

				<td>
					<input
						type="number"
						min="1"
						step="1"
						required
						data-tier-field="qty"
					>
				</td>


				<td>
					<input
						type="text"
						inputmode="decimal"
						required
						data-tier-field="price"
						placeholder="0.0000"
					>
				</td>


				<td>
					<input
						type="text"
						inputmode="decimal"
						data-tier-field="purchase_price"
						placeholder="<?php echo esc_attr__(
							'Optional',
							'promi-data-x-woo'
						); ?>"
					>
				</td>


				<td>

					<button
						type="button"
						class="button pdxw-remove-tier"
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

		</template>
		<?php
	}


	/**
	 * Register the small row-management behavior required by this page.
	 *
	 * This is deliberately tiny.
	 *
	 * The shared Admin JS we write later can absorb this functionality, but
	 * the PHP page remains independently functional during the file-by-file
	 * rebuild.
	 */
	/*private function render_script(): void {

		?>
		<script>
		jQuery(function ($) {

			"use strict";


			$(document).on(
				"click",
				".pdxw-add-tier",
				function () {

					const target =
						$(this).closest(
							".pdxw-tier-target"
						);

					const index =
						target.attr(
							"data-tier-group"
						);

					const template =
						document.getElementById(
							"pdxw-tier-row-template"
						);

					if (
						!template
						|| index === undefined
					) {
						return;
					}


					const row =
						$(
							template.content
								.firstElementChild
								.cloneNode(true)
						);


					row
						.find(
							'[data-tier-field="qty"]'
						)
						.attr(
							"name",
							`tiers[${index}][qty][]`
						);


					row
						.find(
							'[data-tier-field="price"]'
						)
						.attr(
							"name",
							`tiers[${index}][price][]`
						);


					row
						.find(
							'[data-tier-field="purchase_price"]'
						)
						.attr(
							"name",
							`tiers[${index}][purchase_price][]`
						);


					target
						.find(
							".pdxw-tier-table tbody"
						)
						.append(
							row
						);


					target
						.find(
							".pdxw-tier-empty-message"
						)
						.hide();
				}
			);


			$(document).on(
				"click",
				".pdxw-remove-tier",
				function () {

					const target =
						$(this).closest(
							".pdxw-tier-target"
						);


					$(this)
						.closest("tr")
						.remove();


					if (
						!target
							.find(
								".pdxw-tier-table tbody tr"
							)
							.length
					) {

						target
							.find(
								".pdxw-tier-empty-message"
							)
							.show();
					}
				}
			);

		});
		</script>
		<?php
	}*/


	/*
	|--------------------------------------------------------------------------
	| Save
	|--------------------------------------------------------------------------
	*/

	/**
	 * Persist all submitted pricing targets.
	 */
	public function save(): void {

		$this->authorize();


		check_admin_referer(
			self::NONCE_ACTION,
			self::NONCE_FIELD
		);


		$sku =
			isset(
				$_POST['sku']
			)
			&& ! is_array(
				$_POST['sku']
			)
				? sanitize_text_field(
					wp_unslash(
						$_POST['sku']
					)
				)
				: '';


		$groups =
			isset(
				$_POST['tiers']
			)
			&& is_array(
				$_POST['tiers']
			)
				? wp_unslash(
					$_POST['tiers']
				)
				: [];


		if ( empty( $groups ) ) {

			$this->redirect(
				$sku,
				'empty'
			);
		}


		$saved = 0;


		try {

			foreach ( $groups as $group ) {

				if ( ! is_array( $group ) ) {
					continue;
				}


				$product_id =
					absint(
						$group['product_id']
							?? 0
					);


				$variation_id =
					absint(
						$group['variation_id']
							?? 0
					);


				if (
					! $this->valid_target(
						$product_id,
						$variation_id
					)
				) {
					continue;
				}


				$tiers =
					$this->submitted_tiers(
						$group
					);


				/*
				|--------------------------------------------------------------------------
				| Combined Replacement
				|--------------------------------------------------------------------------
				|
				| This intentionally replaces the complete exact target.
				|
				| Empty tiers therefore mean:
				|
				|     delete every tier for this product/variation.
				|
				| TieredPricing handles:
				|
				| - normalization
				| - duplicate quantity collapse
				| - purchasing-price nullability
				| - transaction/storage
				| - WooCommerce lowest selling-price synchronization
				*/

				$result =
					$this->pricing
						->tiers()
						->replace_all(
							$product_id,
							$variation_id,
							$tiers,
							true
						);


				if ( $result ) {
					$saved++;
				}
			}


		} catch ( Throwable $e ) {

			do_action(
				'pdxw_admin_tier_pricing_save_error',
				$e,
				$groups,
				$this
			);


			$this->redirect(
				$sku,
				'error'
			);
		}


		do_action(
			'pdxw_admin_tier_pricing_saved',
			$saved,
			$groups,
			$this
		);


		$this->redirect(
			$sku,
			'saved'
		);
	}


	/**
	 * Normalize one submitted target's row arrays.
	 */
	private function submitted_tiers(
		array $group
	): array {

		$quantities =
			isset(
				$group['qty']
			)
			&& is_array(
				$group['qty']
			)
				? $group['qty']
				: [];


		$prices =
			isset(
				$group['price']
			)
			&& is_array(
				$group['price']
			)
				? $group['price']
				: [];


		$purchase_prices =
			isset(
				$group['purchase_price']
			)
			&& is_array(
				$group['purchase_price']
			)
				? $group['purchase_price']
				: [];


		$tiers = [];


		foreach (
			$quantities
				as $index => $quantity
		) {

			$quantity =
				absint(
					$quantity
				);


			$price =
				$this->price(
					$prices[
						$index
					] ?? null
				);


			$purchase_price =
				$this->price(
					$purchase_prices[
						$index
					] ?? null
				);


			/*
			 * Invalid selling rows are ignored.
			 *
			 * TieredPricing performs normalization again at the domain
			 * boundary, but rejecting obvious invalid rows here keeps the
			 * controller input clean.
			 */
			if (
				$quantity < 1
				|| null === $price
				|| $price <= 0
			) {
				continue;
			}


			$tiers[] = [
				'qty' =>
					$quantity,

				'price' =>
					$price,

				'purchase_price' =>
					(
						null !== $purchase_price
						&& $purchase_price > 0
					)
						? $purchase_price
						: null,
			];
		}


		return $tiers;
	}


	/**
	 * Normalize one WooCommerce decimal input.
	 */
	private function price(
		mixed $value
	): ?float {

		if (
			null === $value
			|| is_array(
				$value
			)
		) {
			return null;
		}


		$value =
			trim(
				(string) $value
			);


		if ( '' === $value ) {
			return null;
		}


		$value =
			wc_format_decimal(
				$value,
				4
			);


		if (
			'' === $value
			|| ! is_numeric(
				$value
			)
		) {
			return null;
		}


		return (float) $value;
	}


	/*
	|--------------------------------------------------------------------------
	| Target Resolution
	|--------------------------------------------------------------------------
	*/

	/**
	 * Resolve the exact pricing targets represented by an SKU.
	 *
	 * Existing CX Tiered Pricing behavior:
	 *
	 * Simple product
	 *     → parent product, variation_id 0
	 *
	 * Variation SKU
	 *     → that exact variation only
	 *
	 * Variable parent SKU
	 *     → every child variation
	 *
	 * The variable parent itself is not added as another target.
	 *
	 * @return array<int,array{
	 *     product_id:int,
	 *     variation_id:int,
	 *     product:WC_Product
	 * }>
	 */
	private function targets(
		WC_Product $product
	): array {

		$targets = [];


		/*
		|--------------------------------------------------------------------------
		| Variation
		|--------------------------------------------------------------------------
		*/

		if (
			$product instanceof WC_Product_Variation
		) {

			$parent_id =
				$product->get_parent_id();


			if ( ! $parent_id ) {
				return [];
			}


			$targets[] = [
				'product_id' =>
					$parent_id,

				'variation_id' =>
					$product->get_id(),

				'product' =>
					$product,
			];


			return $targets;
		}


		/*
		|--------------------------------------------------------------------------
		| Variable Product
		|--------------------------------------------------------------------------
		*/

		if (
			$product instanceof WC_Product_Variable
		) {

			foreach (
				$product->get_children()
					as $variation_id
			) {

				$variation =
					wc_get_product(
						$variation_id
					);


				if (
					! $variation
					|| ! $variation
						instanceof WC_Product_Variation
				) {
					continue;
				}


				$targets[] = [
					'product_id' =>
						$product->get_id(),

					'variation_id' =>
						$variation->get_id(),

					'product' =>
						$variation,
				];
			}


			return $targets;
		}


		/*
		|--------------------------------------------------------------------------
		| Simple / Other Direct Product Type
		|--------------------------------------------------------------------------
		*/

		$targets[] = [
			'product_id' =>
				$product->get_id(),

			'variation_id' =>
				0,

			'product' =>
				$product,
		];


		return $targets;
	}


	/**
	 * Validate that submitted IDs still represent a legitimate pricing
	 * target before changing stored tier data.
	 *
	 * Hidden form fields must not be trusted solely because the current user
	 * has manage_woocommerce.
	 */
	private function valid_target(
		int $product_id,
		int $variation_id
	): bool {

		if ( ! $product_id ) {
			return false;
		}


		$product =
			wc_get_product(
				$product_id
			);


		if (
			! $product
			|| $product instanceof WC_Product_Variation
		) {
			return false;
		}


		if ( ! $variation_id ) {
			return true;
		}


		$variation =
			wc_get_product(
				$variation_id
			);


		if (
			! $variation
			|| ! $variation
				instanceof WC_Product_Variation
		) {
			return false;
		}


		return
			$variation->get_parent_id()
			=== $product_id;
	}


	/*
	|--------------------------------------------------------------------------
	| Notices / Redirect
	|--------------------------------------------------------------------------
	*/

	/**
	 * Redirect back to this pricing page after form processing.
	 */
	private function redirect(
		string $sku,
		string $status
	): never {

		$args = [
			'page' =>
				Menu::PRICING_SLUG,

			'pdxw_pricing_status' =>
				sanitize_key(
					$status
				),
		];


		if ( '' !== $sku ) {
			$args['sku'] = $sku;
		}


		wp_safe_redirect(
			add_query_arg(
				$args,
				admin_url(
					'admin.php'
				)
			)
		);


		exit;
	}


	/**
	 * Resolve page notice from redirect state.
	 *
	 * @return array{class:string,message:string}|null
	 */
	private function requested_notice(): ?array {

		$status =
			isset(
				$_GET[
					'pdxw_pricing_status'
				]
			)
			&& ! is_array(
				$_GET[
					'pdxw_pricing_status'
				]
			)
				? sanitize_key(
					wp_unslash(
						$_GET[
							'pdxw_pricing_status'
						]
					)
				)
				: '';


		return match ( $status ) {

			'saved' => [
				'class' =>
					'notice-success',

				'message' =>
					__(
						'Tier pricing saved.',
						'promi-data-x-woo'
					),
			],

			'empty' => [
				'class' =>
					'notice-warning',

				'message' =>
					__(
						'No tier-pricing data was submitted.',
						'promi-data-x-woo'
					),
			],

			'error' => [
				'class' =>
					'notice-error',

				'message' =>
					__(
						'Tier pricing could not be saved.',
						'promi-data-x-woo'
					),
			],

			default =>
				null,
		};
	}


	/**
	 * Render an inline page message.
	 */
	private function render_message(
		string $message,
		string $type = 'info'
	): void {

		$class =
			match ( $type ) {

				'error' =>
					'notice-error',

				'warning' =>
					'notice-warning',

				'success' =>
					'notice-success',

				default =>
					'notice-info',
			};


		printf(
			'<div class="notice %1$s"><p>%2$s</p></div>',
			esc_attr(
				$class
			),
			esc_html(
				$message
			)
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Request
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return requested SKU.
	 */
	private function requested_sku(): string {

		if (
			! isset(
				$_GET['sku']
			)
			|| is_array(
				$_GET['sku']
			)
		) {
			return '';
		}


		return trim(
			sanitize_text_field(
				wp_unslash(
					$_GET['sku']
				)
			)
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Authorization
	|--------------------------------------------------------------------------
	*/

	/**
	 * Restrict tier administration to WooCommerce managers.
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
				'You are not allowed to manage tiered pricing.',
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
