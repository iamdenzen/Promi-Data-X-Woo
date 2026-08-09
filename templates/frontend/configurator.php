<?php

defined( 'ABSPATH' ) || exit;

/**
 * Multi-step product configurator.
 *
 * Available context:
 *
 * @var \WC_Product                         $product
 * @var int                                 $product_id
 * @var array                               $positions
 * @var \PromiDataXWoo\Frontend\ProductData $product_data
 * @var \PromiDataXWoo\Pricing\Pricing      $pricing
 * @var \PromiDataXWoo\Printing\Printing    $printing
 * @var \PromiDataXWoo\Frontend\Shortcodes  $shortcodes
 *
 * JavaScript responsibilities:
 *
 * attributes.js
 *     Resolves variation selection.
 *
 * renderer/qty.js
 *     Populates .cx-conf-qty.
 *
 * events/qty.js
 *     Applies selected quantity.
 *
 * renderer/printer.js
 *     Populates .cx-conf-positions and .cx-conf-printers.
 *
 * events/printers.js
 *     Handles print-position / option selection.
 *
 * events/navigation.js
 *     Handles next/previous configurator steps.
 *
 * renderer/summary.js
 *     Populates .cxatc-summary.
 *
 * events/cart.js
 *     Submits .cx-addtocart-form through the frontend AJAX API.
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


$positions =
	isset( $positions )
	&& is_array( $positions )
		? $positions
		: [];


/*
|--------------------------------------------------------------------------
| Step Numbers
|--------------------------------------------------------------------------
|
| Printing is an optional step.
|
| With printing:
|
| 01 Attributes
| 02 Quantity
| 03 Printing
| 04 Overview
|
| Without printing:
|
| 01 Attributes
| 02 Quantity
| 03 Overview
*/

$has_printing =
	! empty(
		$positions
	);

$overview_step =
	$has_printing
		? '04'
		: '03';


/*
|--------------------------------------------------------------------------
| Initial Price
|--------------------------------------------------------------------------
|
| JavaScript replaces these values once a variation and quantity have been
| selected.
*/

$product_price_html =
	$product->get_price_html();

$product_price =
	(float)
		$product->get_price();

$tax_preview =
	$product_price * 0.19;

?>


<form
	class="cx-addtocart-form"
	method="post"
	enctype="multipart/form-data"
	id="cx-conf-form"
>


	<?php
	/*
	|--------------------------------------------------------------------------
	| Step 01 — Variation Attributes
	|--------------------------------------------------------------------------
	|
	| Preserve the original configurator behavior:
	|
	| - This step exists only for variable products.
	| - It starts expanded.
	| - Attribute markup is shared with the main add-to-cart form.
	|--------------------------------------------------------------------------
	*/
	?>

	<?php if ( $product->is_type( 'variable' ) ) : ?>

		<div
			class="cx-conf-step"
			id="cx-conf-step-wrapper-attributes"
		>

			<label
				class="cx-conf-row cx-conf-step-head"
				for="cx-conf-step-attributes"
			>

				<div class="cx-conf-step-indicator">
					01
				</div>


				<div>

					<h4>
						<?php
						echo esc_html__(
							'Wählen Sie die Farbe des Produkts',
							'promi-data-x-woo'
						);
						?>
					</h4>

					<p>
						<?php
						echo esc_html__(
							'Hier sehen Sie alle verfügbaren Farbvarianten.',
							'promi-data-x-woo'
						);
						?>
					</p>

				</div>

			</label>


			<input
				type="checkbox"
				name="cx-conf-step"
				id="cx-conf-step-attributes"
				checked
			>


			<div class="cx-conf-row cx-conf-step-content">


				<?php
				/*
				|--------------------------------------------------------------------------
				| Variation Selector
				|--------------------------------------------------------------------------
				|
				| Preserve prefix "cx-conf".
				|
				| It keeps these radio IDs separate from the identical attribute
				| selector rendered inside #cxatc-form.
				*/
				?>

				<?php
				echo $shortcodes
					->get_variation_attribute_radios(
						$product_id,
						'cx-conf'
					); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>


				<div class="cx-conf-row cx-conf-nav">

					<a
						href="#"
						class="cx-conf-next"
					>
						<?php
						echo esc_html__(
							'Nächster Schritt',
							'promi-data-x-woo'
						);
						?>
					</a>

				</div>

			</div>

		</div>

	<?php endif; ?>


	<?php
	/*
	|--------------------------------------------------------------------------
	| Step 02 — Quantity
	|--------------------------------------------------------------------------
	|
	| .cx-conf-qty is intentionally empty.
	|
	| renderer/qty.js fills it from CX.state.tiers after a variation is
	| resolved.
	|--------------------------------------------------------------------------
	*/
	?>

	<div
		class="cx-conf-step"
		id="cx-conf-step-wrapper-qty"
	>

		<label
			class="cx-conf-row cx-conf-step-head"
			for="cx-conf-step-qty"
		>

			<div class="cx-conf-step-indicator">
				02
			</div>


			<div>

				<h4>
					<?php
					echo esc_html__(
						'Wählen Sie die Anzahl der Mengen aus',
						'promi-data-x-woo'
					);
					?>
				</h4>

				<p>
					<?php
					echo esc_html__(
						'Hier sehen Sie die verfügbaren Mengen.',
						'promi-data-x-woo'
					);
					?>
				</p>

			</div>

		</label>


		<input
			type="checkbox"
			name="cx-conf-step"
			id="cx-conf-step-qty"
		>


		<div class="cx-conf-row cx-conf-step-content">

			<div class="cx-conf-qty cx-select-btn"></div>


			<?php
			/*
			 * The original template intentionally contains no active
			 * navigation links here.
			 *
			 * Selecting a quantity causes events/qty.js to open the relevant
			 * later steps automatically.
			 */
			?>

			<div class="cx-conf-row cx-conf-nav"></div>

		</div>

	</div>


	<?php
	/*
	|--------------------------------------------------------------------------
	| Step 03 — Printing
	|--------------------------------------------------------------------------
	|
	| Only shown when this product has print positions.
	|
	| The actual position/option markup is populated dynamically from the
	| selected variation through renderer/printer.js.
	|--------------------------------------------------------------------------
	*/
	?>

	<?php if ( $has_printing ) : ?>

		<div
			class="cx-conf-step"
			id="cx-conf-step-wrapper-printers"
		>

			<label
				class="cx-conf-row cx-conf-step-head"
				for="cx-conf-step-printers"
			>

				<div class="cx-conf-step-indicator">
					03
				</div>


				<div>

					<h4>
						<?php
						echo esc_html__(
							'Wählen Sie die Werbeanbringung',
							'promi-data-x-woo'
						);
						?>
					</h4>

					<p>
						<?php
						echo esc_html__(
							'Hier sehen Sie die verfügbaren Seiten- und Druckoptionen für dieses Produkt.',
							'promi-data-x-woo'
						);
						?>
					</p>

				</div>

			</label>


			<input
				type="checkbox"
				name="cx-conf-step"
				id="cx-conf-step-printers"
			>


			<div class="cx-conf-row cx-conf-step-content">


				<?php
				/*
				|--------------------------------------------------------------------------
				| Positions
				|--------------------------------------------------------------------------
				|
				| renderer/printer.js populates this with .cx-option elements
				| containing data-id="<position_id>".
				*/
				?>

				<div class="cx-conf-positions cx-select-img"></div>


				<?php
				/*
				|--------------------------------------------------------------------------
				| Print Options
				|--------------------------------------------------------------------------
				|
				| Clicking a position calls:
				|
				|     CX.renderPrintOptions( posId, "option" )
				|
				| which populates this container.
				*/
				?>

				<div class="cx-conf-printers cx-select-img"></div>


				<button
					class="cx-printer-confirm et_pb_button"
					type="button"
					style="display:none;"
				>
					<?php
					echo esc_html__(
						'Auswahl bestätigen',
						'promi-data-x-woo'
					);
					?>
				</button>


				<?php
				/*
				|--------------------------------------------------------------------------
				| Minimum Print Quantity
				|--------------------------------------------------------------------------
				|
				| events/qty.js determines whether this step can be used and
				| renderer/qty.js updates .cxatc-print-qty.
				*/
				?>

				<div class="cxatc-error cxatc-print-qty-error">

					<?php
					echo esc_html__(
						'Die Option Werbeanbringung ist erst ab',
						'promi-data-x-woo'
					);
					?>

					<span class="cxatc-print-qty">
						1
					</span>

					<?php
					echo esc_html__(
						'Stück verfügbar.',
						'promi-data-x-woo'
					);
					?>

				</div>


				<div class="cx-conf-row cx-conf-nav">

					<a
						href="#"
						class="cx-conf-prev"
					>
						<?php
						echo esc_html__(
							'Vorheriger Schritt',
							'promi-data-x-woo'
						);
						?>
					</a>


					<a
						href="#"
						class="cx-conf-next"
					>
						<?php
						echo esc_html__(
							'Nächster Schritt',
							'promi-data-x-woo'
						);
						?>
					</a>

				</div>

			</div>

		</div>

	<?php endif; ?>


	<?php
	/*
	|--------------------------------------------------------------------------
	| Overview
	|--------------------------------------------------------------------------
	|
	| renderer/summary.js updates this same .cxatc-summary contract used by
	| add-to-cart.php.
	|--------------------------------------------------------------------------
	*/
	?>

	<div
		class="cx-conf-step"
		id="cx-conf-step-wrapper-overview"
	>

		<label
			class="cx-conf-row cx-conf-step-head"
			for="cx-conf-step-overview"
		>

			<div class="cx-conf-step-indicator">
				<?php echo esc_html( $overview_step ); ?>
			</div>


			<div>

				<h4>
					<?php
					echo esc_html__(
						'Bestellübersicht',
						'promi-data-x-woo'
					);
					?>
				</h4>

				<p>
					<?php
					echo esc_html__(
						'Bestellübersicht mit Druck- und Produktpreis (Einzelpreis und Zwischensumme).',
						'promi-data-x-woo'
					);
					?>
				</p>

			</div>

		</label>


		<input
			type="checkbox"
			name="cx-conf-step"
			id="cx-conf-step-overview"
		>


		<div class="cx-conf-row cx-conf-step-content">

			<div class="cx-conf-overview">


				<table class="cx-table cxatc-summary">

					<thead>

						<tr>

							<th></th>

							<th>
								/ Menge
							</th>

							<th>
								/ Gesamt
							</th>

						</tr>

					</thead>


					<tbody>

						<tr class="cxatc-summary-base-row">

							<td>
								<?php
								echo esc_html__(
									'Artikelpreis',
									'promi-data-x-woo'
								);
								?>
							</td>


							<td class="cx-artikel">

								1 x

								<?php
								echo wp_kses_post(
									$product_price_html
								);
								?>

							</td>


							<td class="cx-artikel-total">

								1 x

								<?php
								echo wp_kses_post(
									$product_price_html
								);
								?>

							</td>

						</tr>

					</tbody>


					<tfoot>

						<tr>

							<td>

								<strong>
									<?php
									echo esc_html__(
										'Gesamtsumme',
										'promi-data-x-woo'
									);
									?>
								</strong>


								<small>
									<?php
									echo esc_html__(
										'Stückpreis inkl. Druck',
										'promi-data-x-woo'
									);
									?>
								</small>

							</td>


							<td colspan="2">

								<strong class="cx-grand-total">

									<?php
									echo wp_kses_post(
										$product_price_html
									);
									?>

								</strong>


								<small class="cx-tax">

									<?php
									echo wp_kses_post(
										wc_price(
											$tax_preview
										)
									);
									?>

								</small>


								<span class="cx-tax-tagline">
									<?php
									echo esc_html__(
										'zzgl. MwSt. & Versandkosten',
										'promi-data-x-woo'
									);
									?>
								</span>

							</td>

						</tr>

					</tfoot>

				</table>


				<?php
				/*
				|--------------------------------------------------------------------------
				| Order Actions
				|--------------------------------------------------------------------------
				|
				| Keep the configurator's existing behavior:
				|
				| - Offer request.
				| - Add to cart.
				| - No sample button here.
				|
				| The original sample button was commented out.
				|--------------------------------------------------------------------------
				*/
				?>

				<div class="cxatc-actions">

					<a
						href="#individuelle-anfrage-senden"
						class="cx-trigger-popup cx-offer-btn cx-icon-btn alt"
						data-font="FontAwesome"
						data-icon=""
					>
						<?php
						echo esc_html__(
							'Angebot anfordern',
							'promi-data-x-woo'
						);
						?>
					</a>


					<button
						type="submit"
						class="single_add_to_cart_button cx-icon-btn"
						data-font="ETmodules"
						data-icon=""
					>
						<?php
						echo esc_html__(
							'In den Warenkorb',
							'promi-data-x-woo'
						);
						?>
					</button>


					<div class="cxatc-error cxatc-sample-error">
						<?php
						echo esc_html__(
							'Ein Produktmuster befindet sich bereits im Warenkorb.',
							'promi-data-x-woo'
						);
						?>
					</div>

				</div>

			</div>

		</div>

	</div>

</form>


<style>
	/*
	|--------------------------------------------------------------------------
	| Step Headers
	|--------------------------------------------------------------------------
	*/

	.cx-conf-step-head,
	.cx-conf-step-indicator {
		display: flex;
		align-items: center;
		gap: 20px;
	}

	.cx-conf-step-indicator {
		justify-content: center;

		background-color: #FF7D01;

		height: 60px;
		width: 60px;

		min-width: 60px;

		color: #FFF;

		font-size: 25px;
		font-weight: 600;

		border-radius: 50%;
	}

	.cx-conf-step-head {
		padding: 20px 65px;

		border-radius: 20px;

		box-shadow:
			0 0 15px -3px
			rgba(0, 0, 0, 0.2);

		cursor: pointer;

		transition: .3s ease-in-out;
	}

	.cx-conf-step-head:hover {
		box-shadow:
			0 4px 15px -3px
			rgba(0, 0, 0, 0.2);
	}

	.cx-conf-step-head h4 {
		font-weight: 600;
		color: #000;
		font-size: 16px;

		margin: 0;
		padding: 0;
	}

	.cx-conf-step-head p {
		padding: 0;
		margin: 0;
	}


	/*
	|--------------------------------------------------------------------------
	| Expand / Collapse
	|--------------------------------------------------------------------------
	|
	| Existing navigation.js works by checking each step's hidden checkbox.
	|--------------------------------------------------------------------------
	*/

	.cx-conf-step {
		position: relative;
	}

	input[name="cx-conf-step"] {
		position: absolute;

		height: 0;
		width: 0;

		opacity: .1;
	}

	.cx-conf-step-content {
		height: 0;

		overflow: hidden;

		opacity: 0;

		transition: .3s ease-in-out;

		display: flex;
		flex-direction: column;

		justify-content: center;
		align-items: center;

		gap: 20px;

		padding: 30px 24px;
	}

	input[name="cx-conf-step"]:checked
	+ .cx-conf-step-content {
		opacity: 1;
		height: auto;
	}


	/*
	|--------------------------------------------------------------------------
	| Attribute Selector
	|--------------------------------------------------------------------------
	*/

	.cx-conf-step .cx-radio-label {
		display: none;
	}

	.cx-conf-row
	.cx-variation-attribute
	.cx-option,
	.cx-conf-row
	.cx-variation-attribute
	.cx-option label {
		position: relative;
	}

	.cx-conf-row
	.cx-variation-attribute
	.cx-option:after {
		content: "";

		background:
			var(--bg-hex-code);

		height: 20px;
		width: 20px;

		border-radius: 50%;

		display: block;

		position: relative;

		left: 50%;
		bottom: 0;

		transform:
			translateX(-50%);

		line-height: 20px;

		font-weight: 600;

		border: 1px solid #ccc;

		color: #fff;

		margin-top: 7px;
		margin-bottom: 5px;
	}

	.cx-conf-row
	.cx-variation-attribute
	.cx-option
	input:checked
	+ label:after {
		content: "✓";

		line-height: 20px;

		font-weight: 600;

		color: #fff;

		position: absolute;

		bottom: -30px;

		z-index: 1;

		left: 50%;

		transform:
			translateX(-50%);
	}

	.cx-conf-row
	.cx-variation-attributes
	.cx-variation-attribute {
		padding-bottom: 30px;
	}


	/*
	|--------------------------------------------------------------------------
	| Configurator Choices
	|--------------------------------------------------------------------------
	*/

	.cx-conf-row .cx-select-img {
		justify-content: center;
	}


	/*
	|--------------------------------------------------------------------------
	| Navigation
	|--------------------------------------------------------------------------
	*/

	.cx-conf-nav {
		display: flex;

		justify-content: center;
		align-items: center;

		gap: 25px;
	}

	.cx-conf-nav > a {
		display: block;

		font-weight: bold;
		font-size: 16px;

		position: relative;
	}

	.cx-conf-next {
		color: #409A5D;

		padding-right: 22px;
	}

	.cx-conf-prev {
		color: #B5B5B5;

		padding-left: 22px;
	}

	.cx-conf-next:after,
	.cx-conf-prev:after {
		content: "$";

		position: absolute;

		right: 0;
		top: 50%;

		transform:
			translateY(-50%);

		font-family:
			ETmodules !important;

		font-size: 17px;
	}

	.cx-conf-prev:after {
		content: "#";

		right: auto;
		left: 0;
	}


	/*
	|--------------------------------------------------------------------------
	| Overview
	|--------------------------------------------------------------------------
	*/

	.cx-conf-overview {
		width: 100%;

		max-width: 600px;

		margin: auto;
	}

	.cx-icon-btn[data-font="FontAwesome"]:before {
		display: none !important;
	}


	/*
	|--------------------------------------------------------------------------
	| Summary Column Alignment
	|--------------------------------------------------------------------------
	|
	| renderer/summary.js generates:
	|
	| <div>
	|     <div>qty</div>
	|     <div>x</div>
	|     <div>price</div>
	| </div>
	|--------------------------------------------------------------------------
	*/

	.cxatc-summary
	tr > td:nth-child(2) > div {
		display: flex;

		justify-content: flex-end;
	}

	.cxatc-summary
	tr > td:nth-child(2)
	> div > div {
		min-width: 50px;
	}

	.cxatc-summary
	tr > td:nth-child(2)
	> div > div:nth-child(2) {
		min-width: 25px;
	}

	.cxatc-summary
	tr > td:nth-child(2)
	> div > div:nth-child(3) {
		min-width: 60px;
	}


	/*
	|--------------------------------------------------------------------------
	| Desktop
	|--------------------------------------------------------------------------
	*/

	@media only screen and (min-width: 800px) {

		.cx-conf-overview
		.cxatc-actions a,
		.cx-conf-overview
		.cxatc-actions button {
			width: calc(33% - 8px);
			min-width: calc(33% - 8px);
			max-width: calc(33% - 8px);
		}

		.cx-conf-overview
		.cxatc-actions {
			transform: scale(1.3);

			margin-top: 30px;
		}
	}
</style>
