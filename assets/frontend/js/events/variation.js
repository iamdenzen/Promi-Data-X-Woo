window.CX = window.CX || {};

(function ($) {

	"use strict";

	const $form =
		$("#cxatc-form");

	const $conf =
		$("#cx-conf-form");

	const $table =
		$("#cx-price-table");


	/**
	 * Synchronize variation attributes to the two radio selectors and the
	 * existing IAS selector.
	 *
	 * ProductData returns variation attributes as:
	 *
	 * {
	 *     pa_farbe: "schwarz"
	 * }
	 *
	 * while the radio fields use:
	 *
	 * attributes[attribute_pa_farbe]
	 */
	function applyAttributesToUI(
		attrs
	) {

		if (
			!attrs
			|| typeof attrs !== "object"
		) {
			return;
		}


		Object.entries(
			attrs
		).forEach(
			([key, value]) => {

				const field =
					`attribute_${key}`;

				const escapedValue =
					CSS.escape(
						String(
							value
						)
					);


				$form
					.find(
						`[name="attributes[${field}]"][value="${escapedValue}"]`
					)
					.prop(
						"checked",
						true
					)
					.trigger(
						"change",
						{
							silent: true
						}
					);


				$conf
					.find(
						`[name="attributes[${field}]"][value="${escapedValue}"]`
					)
					.prop(
						"checked",
						true
					)
					.trigger(
						"change",
						{
							silent: true
						}
					);


				$("#cx-ias-attributes")
					.find(
						`.cx-attribute-select[name="attributes[${field}]"]`
					)
					.val(
						value
					);
			}
		);
	}


	/**
	 * Return the smallest print-option minimum quantity.
	 */
	function minimumPrintQuantity(
		positions
	) {

		if (
			!positions
			|| typeof positions !== "object"
		) {
			return 1;
		}


		const quantities =
			Object.values(
				positions
			)
				.flatMap(
					position =>
						Object.values(
							position?.options
								|| {}
						)
				)
				.map(
					option =>
						Number.parseInt(
							option?.min_order_qty,
							10
						)
				)
				.filter(
					quantity =>
						Number.isFinite(
							quantity
						)
						&& quantity > 0
				);


		return quantities.length
			? Math.min(
				...quantities
			)
			: 1;
	}


	/**
	 * Resolve a variation selected from the tier-pricing table.
	 */
	function handleVariationChange(
		variationId
	) {

		variationId =
			CX.utils.int(
				variationId
			);

		if (!variationId) {
			return;
		}


		CX.api
			.updateVariation(
				{
					variation_id:
						variationId
				}
			)
			.then(
				response => {

					if (
						!response?.success
						|| !response.data
					) {
						return;
					}


					const data =
						response.data;


					const minPrintQty =
						minimumPrintQuantity(
							data.positions
						);


					Object.assign(
						CX.state,
						{
							positions:
								data.positions
								|| {},

							tiers:
								Array.isArray(
									data.tiers
								)
									? data.tiers
									: [],

							sku:
								data.sku
								|| "",

							variation_id:
								CX.utils.int(
									data.variation_id
								),

							attrs:
								data.attributes
								|| {},

							selectedPrinters:
								[],

							selectedPosition:
								null,

							selectedQty:
								null,

							isCustomQty:
								false,

							min_order_qty:
								Math.max(
									1,
									CX.utils.int(
										data.min_order_qty,
										1
									)
								),

							min_print_qty:
								minPrintQty,

							qty_increments:
								Math.max(
									1,
									CX.utils.int(
										data.qty_increments,
										1
									)
								)
						}
					);


					$(".cxatc-error")
						.hide();


					applyAttributesToUI(
						CX.state.attrs
					);


					if (
						typeof CX.renderQty
						=== "function"
					) {
						CX.renderQty();
					}


					if (
						typeof CX.renderPrinters
						=== "function"
					) {
						CX.renderPrinters();
					}


					if (
						typeof CX.updateTable
						=== "function"
					) {
						CX.updateTable();
					}


					if (
						typeof CX.updateSummary
						=== "function"
					) {
						CX.updateSummary();
					}


					if (
						typeof CX.renderGallery
						=== "function"
					) {
						CX.renderGallery(
							CX.state.variation_id
						);
					}
				}
			)
			.catch(
				error => {

					console.error(
						"Unable to load variation.",
						error
					);
				}
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Price Table Variation Dropdown
	|--------------------------------------------------------------------------
	*/

	$table.on(
		"change",
		"#variation_id",
		function () {

			const variationId =
				$(this)
					.val();

			if (!variationId) {
				return;
			}


			handleVariationChange(
				variationId
			);
		}
	);

})(jQuery);