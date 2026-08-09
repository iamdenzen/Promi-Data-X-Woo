window.CX = window.CX || {};

(function ($) {

	"use strict";

	const $form = $("#cxatc-form");
	const $conf = $("#cx-conf-form");


	/**
	 * Synchronize resolved variation attributes across:
	 *
	 * - Main add-to-cart form
	 * - Configurator
	 * - Existing IAS attribute selector
	 *
	 * Silent changes prevent another AJAX request from being triggered.
	 */
	function applyAttributesToUI(attrs) {

		if (
			!attrs
			|| typeof attrs !== "object"
		) {
			return;
		}

		Object.entries(attrs).forEach(
			([key, value]) => {

				$form
					.find(
						`[name="attributes[${key}]"][value="${CSS.escape(String(value))}"]`
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
						`[name="attributes[${key}]"][value="${CSS.escape(String(value))}"]`
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
						".cx-attribute-select"
					)
					.filter(
						function () {

							const name =
								$(this)
									.attr(
										"name"
									);

							return (
								name ===
								`attributes[${key}]`
							);
						}
					)
					.val(
						value
					);
			}
		);
	}


	/**
	 * Read selected attributes from one UI.
	 *
	 * Returns null until every required attribute has been selected.
	 */
	function collectAttributes($scope) {

		const attrs = {};

		let valid = true;


		$scope
			.find(
				".cx-variation-attribute"
			)
			.each(
				function () {

					const $checked =
						$(this)
							.find(
								"input:checked"
							)
							.first();

					if (!$checked.length) {

						valid = false;

						return;
					}


					const name =
						$checked
							.attr(
								"name"
							);

					const match =
						name?.match(
							/\[(.*?)\]/
						);

					if (!match?.[1]) {

						valid = false;

						return;
					}


					attrs[
						match[1]
					] =
						$checked.val();
				}
			);


		if (!valid) {
			return null;
		}


		applyAttributesToUI(
			attrs
		);


		return attrs;
	}


	/**
	 * Calculate the lowest minimum quantity among available print options.
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
	 * Update the complete frontend state after variation resolution.
	 */
	function applyVariationResponse(
		data
	) {

		if (!data) {
			return;
		}


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
					|| CX.state.attrs,

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


		$("#variation_id")
			.val(
				CX.state.variation_id
			);


		$(".cxatc-error")
			.hide();


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


	/**
	 * Resolve a variation using selected attribute values.
	 */
	function handleChange(
		$scope
	) {

		const attrs =
			collectAttributes(
				$scope
			);

		if (!attrs) {
			return;
		}


		CX.state.attrs =
			attrs;


		CX.api
			.updateVariation(
				{
					attributes:
						attrs
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


					applyVariationResponse(
						response.data
					);
				}
			)
			.catch(
				error => {

					console.error(
						"Unable to resolve product variation.",
						error
					);
				}
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Main Add-to-Cart Form
	|--------------------------------------------------------------------------
	*/

	$form.on(
		"change",
		".cx-attribute-input",
		function (
			event,
			meta
		) {

			if (meta?.silent) {
				return;
			}


			handleChange(
				$form
			);
		}
	);


	/*
	|--------------------------------------------------------------------------
	| Configurator
	|--------------------------------------------------------------------------
	*/

	$conf.on(
		"change",
		".cx-attribute-input",
		function (
			event,
			meta
		) {

			if (meta?.silent) {
				return;
			}


			handleChange(
				$conf
			);
		}
	);

})(jQuery);