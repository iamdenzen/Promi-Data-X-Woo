<?php

defined( 'ABSPATH' ) || exit;

/**
 * Product add-to-cart configurator.
 *
 * Available context:
 *
 * @var \WC_Product                           $product
 * @var int                                   $product_id
 * @var array                                 $positions
 * @var int                                   $min_order_qty
 * @var int                                   $qty_increments
 * @var \PromiDataXWoo\Frontend\ProductData   $product_data
 * @var \PromiDataXWoo\Pricing\Pricing        $pricing
 * @var \PromiDataXWoo\Printing\Printing      $printing
 * @var \PromiDataXWoo\Frontend\Shortcodes    $shortcodes
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

$min_order_qty =
	max(
		1,
		absint(
			$min_order_qty
			?? 1
		)
	);

$qty_increments =
	max(
		1,
		absint(
			$qty_increments
			?? 1
		)
	);

$positions =
	isset( $positions )
	&& is_array( $positions )
		? $positions
		: [];

$product_sku =
	(string)
		$product->get_sku();

$product_price_html =
	$product->get_price_html();

$product_price =
	(float)
		$product->get_price();

$tax_preview =
	$product_price * 0.19;


/*
|--------------------------------------------------------------------------
| Existing Site Actions
|--------------------------------------------------------------------------
|
| The original frontend uses:
|
| - #individuelle-anfrage-senden
| - [cx_wl_wishlist_button]
|
| The inquiry anchor remains site content behavior.
|
| Wishlist rendering is guarded so this template remains usable while we
| migrate that subsystem into Promi-Data X Woo.
*/

$wishlist_html = '';

if (
	shortcode_exists(
		'cx_wl_wishlist_button'
	)
) {
	$wishlist_html =
		do_shortcode(
			'[cx_wl_wishlist_button]'
		);
}

$is_price_on_request =
	isset( $is_price_on_request )
	&& (bool) $is_price_on_request;

?>

<form
	class="cx-addtocart-form<?php echo $is_price_on_request ? ' cx-por-active' : ''; ?>"
	method="post"
	enctype="multipart/form-data"
	id="cxatc-form"
	data-price-on-request="<?php echo $is_price_on_request ? '1' : '0'; ?>"
>

	<div class="cx-wrapper">

		<div class="cx-col1">

			<input
				type="hidden"
				name="product_id"
				value="<?php echo esc_attr( $product_id ); ?>"
			>

			<input
				type="hidden"
				name="sku"
				value="<?php echo esc_attr( $product_sku ); ?>"
			>


			<?php
			/*
			|--------------------------------------------------------------------------
			| Variation Attributes
			|--------------------------------------------------------------------------
			*/
			?>

			<?php if ( $product->is_type( 'variable' ) ) : ?>

				<div class="cxatc-row cxatc-attributes">

					<?php
					echo $shortcodes
						->get_variation_attribute_radios(
							$product_id
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>

				</div>

			<?php endif; ?>


			<?php
			/*
			|--------------------------------------------------------------------------
			| Quantity
			|--------------------------------------------------------------------------
			|
			| renderer/qty.js populates #cx_qty after variation data has been
			| loaded.
			|
			| The custom input remains hidden until frontend logic determines
			| that a manually entered quantity should be used.
			*/
			?>

			<div class="cxatc-row">

				<label
					class="cx-input-label"
					for="cx_qty"
				>
					<?php
					echo esc_html__(
						'Menge',
						'promi-data-x-woo'
					);
					?>
				</label>

				<select
					id="cx_qty"
					class="cx-qty-select"
				>
					<option value="">
						<?php
						echo esc_html__(
							'Bitte auswählen',
							'promi-data-x-woo'
						);
						?>
					</option>
				</select>

				<input
					type="number"
					class="cx_qty_custom"
					value=""
					min="<?php echo esc_attr( $min_order_qty ); ?>"
					step="<?php echo esc_attr( $qty_increments ); ?>"
					placeholder="<?php
					echo esc_attr__(
						'Geben Sie hier die Menge ein',
						'promi-data-x-woo'
					);
					?>"
				>

			</div>


			<div class="cxatc-row cxatc-error cxatc-qty-error">
				<?php
				echo esc_html__(
					'Bitte wählen Sie die Menge aus.',
					'promi-data-x-woo'
				);
				?>
			</div>


			<div class="cxatc-row cxatc-error cxatc-print-qty-error">

				<?php
				echo esc_html__(
					'Die Option Werbeanbringung ist erst ab',
					'promi-data-x-woo'
				);
				?>

				<span class="cxatc-print-qty">1</span>

				<?php
				echo esc_html__(
					'Stück verfügbar.',
					'promi-data-x-woo'
				);
				?>

			</div>


			<?php
			/*
			|--------------------------------------------------------------------------
			| Printing
			|--------------------------------------------------------------------------
			|
			| The original template queried:
			|
			| CX_Print::get_positions_by_product_id( $product_id )
			|
			| Shortcodes.php now resolves the positions before rendering this
			| template.
			|
			| Actual position/option markup is populated by renderer/printer.js
			| after the shopper selects a variation.
			*/
			?>

			<?php if ( ! empty( $positions ) ) : ?>

				<div class="cxatc-row cxatc-print-logo">

					<label class="cx-input-label">
						<?php
						echo esc_html__(
							'Werbeanbringung',
							'promi-data-x-woo'
						);
						?>
					</label>


					<div class="cx-radio">

						<div>

							<input
								type="radio"
								name="logo"
								value="0"
								id="logo_0"
								checked
							>

							<label for="logo_0">
								<?php
								echo esc_html__(
									'Kein Logo',
									'promi-data-x-woo'
								);
								?>
							</label>

						</div>


						<div>

							<input
								type="radio"
								name="logo"
								value="1"
								id="logo_1"
							>

							<label for="logo_1">
								<?php
								echo esc_html__(
									'Logo drucken',
									'promi-data-x-woo'
								);
								?>
							</label>

						</div>

					</div>

				</div>


				<div class="cxatc-print-wrapper">

					<div class="cxatc-row cxatc-positions cx-select-img"></div>

					<div class="cxatc-row cxatc-printers"></div>

					<button
						class="cx-printer-confirm et_pb_button"
						type="button"
						style="display:none;"
					>
						<?php
						echo esc_html__(
							'Auswahl übernehmen',
							'promi-data-x-woo'
						);
						?>
					</button>

				</div>

			<?php endif; ?>


			<?php
			/*
			|--------------------------------------------------------------------------
			| Product Information Links
			|--------------------------------------------------------------------------
			*/
			?>

			<div class="cxatc-row cxatc-info">

				<ul>

					<li>
						<a
							href="/versand-zahlung/"
							target="_blank"
							rel="noopener"
						>
							/ Versanddetails &amp; Lieferzeiten
						</a>
					</li>

					<li>
						<a href="#preisstaffel">
							/ Preisstaffel
						</a>
					</li>

				</ul>

			</div>

		</div>


		<?php
		/*
		|--------------------------------------------------------------------------
		| Summary
		|--------------------------------------------------------------------------
		*/
		?>

		<div class="cx-col2">

			<div class="cx-product-sku-price">

				<div class="cx-product-sku">

					<?php
					echo esc_html__(
						'Art-Nr.:',
						'promi-data-x-woo'
					);
					?>

					<b>
						<?php echo esc_html( $product_sku ); ?>
					</b>

				</div>


				<?php if ( $is_price_on_request ) : ?>

				<div class="cx-product-main-price cx-por-price">
					<b><?php echo esc_html__( 'Preis auf Anfrage', 'promi-data-x-woo' ); ?></b>
				</div>

				<?php else : ?>

				<div class="cx-product-main-price">

					<small>
						<?php
						echo esc_html__(
							'ab',
							'promi-data-x-woo'
						);
						?>
					</small>

					<b>
						<?php
						echo wp_kses_post(
							$product_price_html
						);
						?>
					</b>

				</div>

				<?php endif; ?>

			</div>


			<div class="cx-summary-col">

				<table class="cx-table cxatc-summary">

					<thead>

						<tr>
							<th></th>
							<th>/ Menge</th>
							<th>/ Gesamt</th>
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


						<tr style="display:none;">

							<td>
								<?php
								echo esc_html__(
									'Veredelung',
									'promi-data-x-woo'
								);
								?>
							</td>

							<td class="cx-veredelung">
								1 x 0€
							</td>

							<td class="cx-veredelung-total">
								1 x 0€
							</td>

						</tr>


						<tr style="display:none;">

							<td>
								<?php
								echo esc_html__(
									'Einrichtung',
									'promi-data-x-woo'
								);
								?>
							</td>

							<td class="cx-setup">
								1 × 0€
							</td>

							<td class="cx-setup-total">
								0€
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
										'zzgl. 19% MwSt.',
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
										'zzgl. Versandkosten',
										'promi-data-x-woo'
									);
									?>

									<sup
										data-content="<?php
										echo esc_attr__(
											'Versandkosten werden nach Gewicht berechnet – je angefangene 30 kg 19,50 €. Es gilt das tatsächliche Gewicht oder das Volumengewicht (L × B × H ÷ 5000), je nachdem welcher Wert höher ist.',
											'promi-data-x-woo'
										);
										?>"
									>
										i
									</sup>

								</span>

							</td>

						</tr>

					</tfoot>

				</table>


				<?php
				/*
				|--------------------------------------------------------------------------
				| Actions
				|--------------------------------------------------------------------------
				*/
				?>

				<div class="cxatc-actions">

					<a
						href="#individuelle-anfrage-senden"
						class="cx-trigger-popup cx-offer-btn cx-icon-btn<?php echo $is_price_on_request ? '' : ' alt'; ?>"
					>
						<img
							src="/wp-content/uploads/2026/04/inquiry-icon.webp"
							width="20"
							height="20"
							alt=""
							aria-hidden="true"
						>

						<?php
						echo esc_html__(
							'Angebot anfordern',
							'promi-data-x-woo'
						);
						?>

					</a>


					<button
						type="button"
						class="cxatc-sample cx-icon-btn alt"
					>

						<img
							src="/wp-content/uploads/2026/04/sample-icon.webp"
							width="20"
							height="20"
							alt=""
							aria-hidden="true"
						>

						<?php
						echo esc_html__(
							'Muster bestellen',
							'promi-data-x-woo'
						);
						?>

					</button>


					<?php
					if ( '' !== $wishlist_html ) {

						echo $wishlist_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
					?>


					<button
						type="submit"
						class="single_add_to_cart_button cx-icon-btn cx-cart-btn"
						data-font="ETmodules"
						data-icon=""
						<?php echo $is_price_on_request ? 'style="display:none;"' : ''; ?>
					>
						<?php
						echo esc_html__(
							'In den Warenkorb',
							'promi-data-x-woo'
						);
						?>
					</button>


					<div class="cx-por-notice" style="<?php echo $is_price_on_request ? '' : 'display:none;'; ?>">

						<p>
							<?php echo esc_html__( 'Für dieses Produkt erhalten Sie auf Anfrage ein individuelles Angebot.', 'promi-data-x-woo' ); ?>
						</p>


						<div class="cx-inquiry-form">

							<div class="cx-inquiry-message" style="display:none;"></div>

							<p class="cx-inquiry-field">
								<label for="cx-inquiry-name">
									<?php echo esc_html__( 'Name', 'promi-data-x-woo' ); ?>
								</label>

								<input
									type="text"
									id="cx-inquiry-name"
									name="inquiry_name"
									required
								>
							</p>

							<p class="cx-inquiry-field">
								<label for="cx-inquiry-email">
									<?php echo esc_html__( 'E-Mail', 'promi-data-x-woo' ); ?>
								</label>

								<input
									type="email"
									id="cx-inquiry-email"
									name="inquiry_email"
									required
								>
							</p>

							<p class="cx-inquiry-field">
								<label for="cx-inquiry-phone">
									<?php echo esc_html__( 'Telefon (optional)', 'promi-data-x-woo' ); ?>
								</label>

								<input
									type="tel"
									id="cx-inquiry-phone"
									name="inquiry_phone"
								>
							</p>

							<p class="cx-inquiry-field">
								<label for="cx-inquiry-message">
									<?php echo esc_html__( 'Ihre Nachricht (optional)', 'promi-data-x-woo' ); ?>
								</label>

								<textarea
									id="cx-inquiry-message"
									name="inquiry_message"
									rows="3"
								></textarea>
							</p>

							<button
								type="button"
								class="button cx-inquiry-submit"
							>
								<?php echo esc_html__( 'Anfrage senden', 'promi-data-x-woo' ); ?>
							</button>

						</div>

					</div>

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
	sup[data-content] {
		background: #666;
		color: #FFF;
		height: 16px;
		width: 16px;
		font-size: 12px;
		line-height: 20px;
		font-weight: 400;
		border-radius: 50%;
		display: inline-block;
		text-align: center;
		bottom: 0;
		right: 0%;
		transition: .3s ease-in-out;
		overflow: hidden;
	}

	sup[data-content]:before {
		content: attr(data-content);
		display: block;
		color: #000;
		font-size: 12px;
		line-height: 1.4em;
		width: 300px;
		background: #efefef;
		box-shadow: 0 0 8px rgba(0, 0, 0, 0.2);
		border: 1px solid #ccc;
		border-radius: 10px;
		padding: 10px;
		position: absolute;
		bottom: 150%;
		right: 0%;
		opacity: 0;
	}

	sup[data-content]:hover:before {
		opacity: 1;
	}

	sup[data-content]:hover {
		overflow: visible;
	}

	.cx-product-header {
		display: flex;
		justify-content: space-between;
		align-items: flex-start;
		gap: 10px;
		padding-bottom: 25px;
	}

	.cx-product-header > div:nth-child(2),
	.cx-product-sku-price {
		text-align: right;
	}

	.cx-product-sku-price {
		padding-bottom: 20px;
	}

	.cx-product-header h3 {
		padding: 0;
		margin: 0;
	}

	.cx-product-sku {
		background: #F2F2F2;
		padding: 8px 16px;
		font-weight: 500;
		display: inline-block;
		margin-bottom: 15px;
		font-size: 12px;
		color: #bbb;
	}

	.cx-product-main-price {
		color: #FF7D01;
		font-size: 36px;
		font-weight: bold;
	}

	.cx-product-main-price small {
		font-size: 70%;
		font-weight: 600;
	}

	.cx-product-main-price b {
		font-weight: inherit;
	}

	.cx-product-main-price .woocommerce-Price-amount + span,
	.cx-product-main-price .woocommerce-Price-amount + span + span,
	.cx-addtocart-form .woocommerce-Price-amount + span,
	.cx-addtocart-form .woocommerce-Price-amount + span + span,
	.cx-artikel .woocommerce-Price-amount + span,
	.cx-artikel .woocommerce-Price-amount + span + span {
		display: none !important;
	}

	.cxatc-row {
		padding-bottom: 14px;
	}

	.cx-form-col > .cxatc-row:last-child,
	.cx-form-col > .cxatc-row:last-child ul {
		padding-bottom: 0;
	}

	.cxatc-row:empty {
		display: none;
	}

	.cxatc-row .cx-input-label,
	.cx-radio-label,
	.cx-checkbox-label {
		font-weight: 700;
		display: block;
		margin-bottom: 5px;
	}

	.cxatc-row select,
	.cxatc-row .cx-radio label {
		display: block;
		padding: 10px 20px;
		background-color: #fff;
		border: 1px solid #ddd;
		width: 100%;
		transition: .1s ease-in-out;
	}

	.cx_qty_custom {
		display: none;
		margin-top: 10px;
		padding: 10px 20px;
		background-color: #fff;
		border: 1px solid #ddd;
		width: 100%;
		transition: .1s ease-in-out;
	}

	.cxatc-error {
		color: crimson;
		display: none;
	}

	.cxatc-error.cxatc-variation-error {
		padding-bottom: 8px;
	}

	.cx-select-img,
	.cx-select-btn {
		display: flex;
		flex-wrap: wrap;
		gap: 7px;
	}

	.cx-select-img .cx-option,
	.cx-select-btn .cx-option {
		position: relative;
	}

	.cx-select-img input,
	.cx-select-btn input {
		position: absolute;
		opacity: .1;
		height: 1px;
		width: 1px;
	}

	.cx-select-img label,
	.cx-select-btn label {
		font-weight: 400;
		text-align: center;
		border: 1px solid #ddd;
		padding: 7px;
		width: 108px;
		height: auto;
		font-size: 12px;
		display: block;
		cursor: pointer;
	}

	.cx-select-btn label {
		height: auto;
	}

	.cx-option-label {
		cursor: pointer;
	}

	.cx-select-btn .cx-option label {
		padding-top: 14px;
		padding-bottom: 14px;
		border-radius: 10px;
	}

	.cx-select-btn .cx-option-label small {
		font-weight: bold;
		display: block;
	}

	.cx-select-img label:hover,
	.cx-select-img input:checked + label,
	.cx-select-img .cx-option.cx-active label,
	.cx-select-btn label:hover,
	.cx-select-btn input:checked + label,
	.cx-select-btn .cx-option.cx-active label {
		border-color: #FF7D01;
		outline: none !important;
	}

	.cx-option.cx-confirmed:not(.cx-active) label {
		outline: 2px solid #409A5D;
	}

	.cx-option:not(.cx-confirmed) .cx-remove-selection {
		display: none;
	}

	.cx-remove-selection {
		position: absolute;
		top: -5px;
		right: -5px;
		background: crimson;
		color: #FFF;
		font-size: 10px;
		border-radius: 50%;
		padding: 3px 6px;
		transition: .3s ease-in-out;
		line-height: 1em;
		cursor: pointer;
	}

	.cx-remove-selection:hover {
		opacity: .7;
	}

	.cx-select-img img {
		width: 60px;
		height: 60px;
		display: block;
		margin: 0 auto 5px;
	}

	.cx-select-img .cx-option-label,
	.cx-select-btn .cx-option-label {
		font-size: 12px;
		line-height: 1.3em;
		display: block;
	}

	.cxatc-positions {
		display: flex;
		flex-wrap: wrap;
		gap: 10px;
		max-height: 380px;
		overflow-y: auto;
		padding: 7px;
	}

	.cxatc-printers {
		padding: 0 7px 14px;
	}

	.cxatc-positions > div {
		max-width: calc(50% - 5px);
		min-width: calc(50% - 5px);
		width: calc(50% - 5px);
	}

	.cxatc-positions label {
		width: 100%;
		height: auto;
	}

	.cxatc-positions img {
		max-width: 50px;
		object-fit: contain;
		height: auto;
	}

	.cxatc-print-wrapper {
		display: none;
		padding: 7px;
		border: 1px solid #ccc;
		border-radius: 5px;
		margin-bottom: 15px;
	}

	select.cx-printer-select {
		margin-top: 10px;
		width: 100%;
	}

	.cx-printer-confirm {
		margin: 1px 7px 12px;
		cursor: pointer;
	}

	.cx-radio {
		display: flex;
		gap: 15px;
	}

	.cxatc-print-logo .cx-radio {
		gap: 0;
	}

	.cxatc-print-logo .cx-radio > div:first-child label {
		border-right: 0 !important;
	}

	.cxatc-print-logo .cx-radio > div:nth-child(2) label {
		border-left: 0 !important;
	}

	.cx-radio > div {
		position: relative;
	}

	.cx-radio input {
		position: absolute;
		height: 1px;
		width: 1px;
		opacity: .1;
	}

	.cx-radio label {
		font-weight: 600;
		color: #666;
		text-align: center;
		cursor: pointer;
	}

	.cx-radio label:hover,
	.cx-radio input:checked + label {
		background-color: #FF7D01;
		color: #FFF;
	}

	.cxatc-info ul {
		list-style: none;
		padding: 0 0 15px 0;
		font-weight: 600;
		color: #FF7D01;
	}

	.cxatc-info ul li a {
		color: #FF7D01;
	}

	.cx-wrapper {
		display: flex;
		align-items: stretch;
		gap: 20px;
	}

	.cx-wrapper > div {
		flex: 1;
	}

	.cx-summary-col {
		background: #f2f2f2;
		padding: 15px 25px;
		border-radius: 5px;
	}

	.cx-table {
		width: 100%;
		border-collapse: collapse;
		line-height: 1.3em;
	}

	.cx-table thead,
	.cx-table tbody {
		border-bottom: 1px solid #ddd;
	}

	.cx-table th {
		padding: 6px 0;
	}

	.cx-table tr > th:first-child {
		width: 36%;
	}

	.cx-table tr > th:nth-child(2) {
		width: 32%;
	}

	.cx-table tr > th:nth-child(3),
	.cx-table tr > td:nth-child(3) {
		min-width: 100px;
	}

	.cx-table tr > th:not(:first-child),
	.cx-table tr > td:not(:first-child) {
		text-align: right;
	}

	.cx-table td {
		padding: 4px 0;
		font-size: 13px;
		vertical-align: top;
	}

	.cx-table tbody > tr:first-child td,
	.cx-table tfoot > tr:first-child td {
		padding-top: 12px;
	}

	.cx-table tbody > tr:last-child td {
		padding-bottom: 12px;
	}

	.cx-table tfoot strong {
		font-size: 16px;
		display: block;
	}

	.cx-table tfoot small {
		display: block;
		font-size: 12px;
	}

	.cx-table tfoot .cx-tax-tagline {
		color: #666;
		font-size: 10px;
	}

	.cx-table tr td small {
		font-size: 12px;
		color: #666;
		display: block;
	}

	.cxatc-actions {
		display: flex;
		gap: 15px;
		flex-wrap: wrap;
		padding-top: 5px;
		justify-content: center;
	}

	.cxatc-actions button,
	.cxatc-actions a {
		width: calc(50% - 8px);
		min-width: calc(50% - 8px);
		max-width: calc(50% - 8px);
		text-align: center;
		font-size: 12px !important;
	}

	.cxatc-actions .cx-icon-btn:after {
		font-size: 17px !important;
	}

	.cxatc-actions > button,
	.cxatc-actions > a {
		padding-top: 7px !important;
		padding-bottom: 7px !important;
		padding-left: 10px !important;
		padding-right: 10px !important;
		display: flex;
		align-items: center;
		gap: 10px;
	}

	.cx-sample-disabled {
		cursor: not-allowed;
	}

	.cx-variation-attributes > .cx-variation-attribute:not(:first-child) {
		padding-top: 14px;
	}

	.cxatc-actions .yith-add-to-wishlist-button-block {
		width: calc(50% - 8px);
		min-width: calc(50% - 8px);
		max-width: calc(50% - 8px);
		padding: 0 !important;
		margin: 0 !important;
	}

	.cxatc-actions
	.yith-wcwl-add-to-wishlist-button.yith-wcwl-add-to-wishlist-button--single.yith-wcwl-add-to-wishlist-button--anchor {
		width: 100%;
		max-width: 100%;
		min-width: 100%;
		background: transparent;
		border: 2px solid #ff7d01;
		margin: 0 !important;
		border-radius: 3px !important;
		padding-left: 10px !important;
		padding-top: 0;
		padding-bottom: 0;
		height: 100%;
	}

	.cxatc-actions .yith-wcwl-add-to-wishlist-button svg {
		color: #ff7d01 !important;
		width: 25px !important;
	}

	.cxatc-actions
	.yith-wcwl-add-to-wishlist-button
	.yith-wcwl-add-to-wishlist-button__label {
		display: block;
		flex: 1;
		position: relative;
		width: 100%;
		height: 100%;
	}

	.cxatc-actions
	.yith-wcwl-add-to-wishlist-button
	.yith-wcwl-add-to-wishlist-button__label:after {
		content: "Auf Merkliste";
		position: absolute;
		top: 0;
		bottom: 0;
		left: 0;
		right: 0;
		color: #ff7d01 !important;
		line-height: 1.4em !important;
		text-align: left !important;
		display: flex;
		align-items: center;
		font-weight: 600;
	}

	@media only screen and (min-width: 800px) {

		.cx-summary-col {
			position: sticky;
			top: 50px;
		}

		.cx-sticky-product-gallery {
			position: sticky;
			top: 50px;
		}
	}

	@media only screen and (max-width: 500px) {

		.cx-wrapper {
			flex-direction: column;
		}
	}
</style>
