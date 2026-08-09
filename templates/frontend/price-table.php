<?php

defined( 'ABSPATH' ) || exit;

/**
 * Product tier-price table.
 *
 * Available context:
 *
 * @var \WC_Product                        $product
 * @var int                                $product_id
 * @var \PromiDataXWoo\Frontend\ProductData $product_data
 * @var \PromiDataXWoo\Pricing\Pricing     $pricing
 * @var \PromiDataXWoo\Printing\Printing   $printing
 * @var \PromiDataXWoo\Frontend\Shortcodes $shortcodes
 *
 * The table itself is populated dynamically by:
 *
 *     assets/frontend/js/renderer/table.js
 *
 * Cart interaction is handled by:
 *
 *     assets/frontend/js/events/table.js
 */

if (
	! isset( $product )
	|| ! $product instanceof \WC_Product
) {
	return;
}

$product_id =
	absint(
		$product_id
		?: $product->get_id()
	);

if ( ! $product_id ) {
	return;
}

?>

<form
	id="cx-price-table"
	class="cx-product-staffelpreis"
>

	<div>

		<div class="cx-price-table">


			<?php
			/*
			|--------------------------------------------------------------------------
			| Quantity Header
			|--------------------------------------------------------------------------
			|
			| renderer/table.js replaces .cx-qty-tiers with the actual
			| quantity tiers returned for the selected variation.
			*/
			?>

			<div class="cx-price-table-head">

				<div class="cx-table-row">

					<div>
						<?php
						echo esc_html__(
							'Stückzahl',
							'promi-data-x-woo'
						);
						?>
					</div>


					<div class="cx-qty-tiers">

						<div>
							<?php
							echo esc_html__(
								'Muster',
								'promi-data-x-woo'
							);
							?>
						</div>

					</div>

				</div>

			</div>


			<div class="cx-price-table-body">


				<?php
				/*
				|--------------------------------------------------------------------------
				| Base Product Price
				|--------------------------------------------------------------------------
				|
				| renderer/table.js populates .cx-price-tiers.
				|
				| The variation selector is still important because
				| events/variation.js uses its variation ID to request the
				| correct tier/printing state.
				*/
				?>

				<div class="cx-table-row cx-price-row">

					<div>

						<?php
						echo esc_html__(
							'Grundpreis pro Stück.',
							'promi-data-x-woo'
						);
						?>


						<?php
						if (
							$product->is_type(
								'variable'
							)
						) {

							echo $shortcodes
								->get_variation_dropdown(
									$product_id,
									'variation_id'
								); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
						?>

					</div>


					<div class="cx-price-tiers cx-tiers">
						<div>--,--</div>
					</div>

				</div>


				<?php
				/*
				|--------------------------------------------------------------------------
				| Dynamic Printing Rows
				|--------------------------------------------------------------------------
				|
				| Printing rows are intentionally NOT rendered here.
				|
				| renderer/table.js inserts them immediately after:
				|
				|     .cx-price-row
				|
				| for every selected print position/option.
				|
				| Generated structure:
				|
				| .cx-printer-row
				|     position + option
				|     print-price tiers
				|
				| and, when applicable:
				|
				| .cx-printer-row
				|     Setup
				|     fee tiers
				*/
				?>


				<?php
				/*
				|--------------------------------------------------------------------------
				| Unit Subtotal
				|--------------------------------------------------------------------------
				|
				| This represents:
				|
				| product unit tier price
				|     +
				| selected print unit prices
				|     +
				| apportioned fees
				|
				| renderer/table.js fills this using CX.pricing.getTotals().
				*/
				?>

				<div class="cx-table-row cx-subtotal-row">

					<div>
						<b>
							<?php
							echo esc_html__(
								'Stückpreis (netto)',
								'promi-data-x-woo'
							);
							?>
						</b>
					</div>


					<div class="cx-subtotal-tiers cx-tiers">
						<div>--,--</div>
					</div>

				</div>


				<?php
				/*
				|--------------------------------------------------------------------------
				| Order Total
				|--------------------------------------------------------------------------
				|
				| Each generated price cell receives:
				|
				|     data-qty="..."
				|
				| events/table.js listens for clicks on those cells.
				|
				| qty === 1:
				|     triggers sample ordering
				|
				| qty > 1:
				|     sets CX.state.selectedQty and submits #cxatc-form
				*/
				?>

				<div class="cx-table-row cx-total-row">

					<div>
						<?php
						echo esc_html__(
							'Gesamtpreis (netto)',
							'promi-data-x-woo'
						);
						?>
					</div>


					<div class="cx-total-tiers cx-tiers">

						<div data-qty="">
							--,--
						</div>

					</div>

				</div>


			</div>

		</div>

	</div>

</form>


<style>
	.cx-product-staffelpreis > div {
		width: 100%;
		margin-top: 20px;
		text-align: left;
	}

	.cx-price-table-body > div:nth-child(odd) {
		background-color: #f8f8f8;
	}

	.cx-table-row {
		display: flex;
		padding: 4px 20px;
	}

	.cx-table-row > div:nth-child(2) {
		display: flex;
		flex: 2;
	}

	.cx-table-row > div,
	.cx-table-row > div:nth-child(2) > div {
		flex: 1;
	}

	.cx-table-row > div:first-child {
		width: 280px;
		max-width: 280px;
		min-width: 280px;
	}

	.cx-table-row > div:nth-child(3) {
		width: 200px;
		max-width: 200px;
		min-width: 200px;
	}

	.cx-table-row > div:nth-child(2) > div {
		min-width: 50px;
		text-align: center;
	}

	.cx-table-row > div:not(:nth-child(2)),
	.cx-table-row > div:nth-child(2) > div {
		padding: 10px 0;
	}


	/*
	|--------------------------------------------------------------------------
	| Variation Selector
	|--------------------------------------------------------------------------
	*/

	.cx-product-staffelpreis select {
		padding: 0;
		width: 100%;
		min-height: auto !important;
		border: 0;
		font-weight: 700;
		color: #2a7f70;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
		max-width: 300px;
		padding-right: 35px !important;

		-moz-appearance: none !important;
		-webkit-appearance: none !important;
		appearance: none !important;

		display: block;

		background:
			#fff
			url(/wp-content/themes/divi-child/assets/select-bg.svg)
			no-repeat
			100% 45%;

		background-color: transparent;
		background-clip: padding-box;
	}

	.cx-product-staffelpreis input {
		width: 100%;
		padding: 10px 20px;
		font-size: 16px;
	}


	/*
	|--------------------------------------------------------------------------
	| Table
	|--------------------------------------------------------------------------
	*/

	.cx-price-table-head {
		background-color: #FF7D01;
		color: #FFF;
		border-radius: 7px 7px 0 0;
	}

	.cx-price-table .cx-subtotal-row {
		border-top: 1px solid #ccc;
	}

	.cx-price-table .cx-total-row {
		border-top: 2px solid;
	}

	.cx-price-table .cx-total-row > div {
		padding-top: 20px;
		padding-bottom: 15px;
	}

	.cx-price-table
	.cx-total-row
	.cx-total-tiers {
		padding-top: 10px;
		padding-bottom: 10px;
	}


	/*
	|--------------------------------------------------------------------------
	| Tier → Add to Cart Interaction
	|--------------------------------------------------------------------------
	|
	| Existing events/table.js makes each [data-qty] cell actionable.
	|--------------------------------------------------------------------------
	*/

	.cx-total-row [data-qty] {
		position: relative;
	}

	.cx-total-row [data-qty]:before {
		content: "Warenkorb";

		position: absolute;
		top: -2px;
		left: -10px;
		right: -10px;

		background-color: #FF7D01;
		color: #FFF;

		font-weight: 500;
		font-size: 12px;

		padding: 10px 18px;

		border-radius: 10px;

		opacity: 0;
		visibility: hidden;

		cursor: pointer;
	}

	.cx-total-row
	[data-qty].cx-processing:before {
		content: "Wird hinzugefügt...";
	}

	.cx-total-row
	[data-qty]:hover:before {
		opacity: 1;
		visibility: visible;
	}
</style>
