window.CX = window.CX || {};

(function ($) {

	"use strict";

	const $form =
		$("#cxatc-form");

	const $conf =
		$("#cx-conf-form");


	/**
	 * Apply the currently selected quantity.
	 */
	function setQty(
		qty,
		isCustomQty
	) {

		qty =
			CX.utils.int(
				qty
			);

		if (qty <= 0) {
			return;
		}


		CX.state.selectedQty =
			qty;

		CX.state.isCustomQty =
			Boolean(
				isCustomQty
			);


		/*
		|--------------------------------------------------------------------------
		| Recalculate Display
		|--------------------------------------------------------------------------
		*/

		if (
			typeof CX.updateSummary
			=== "function"
		) {
			CX.updateSummary();
		}


		if (
			typeof CX.updateTable
			=== "function"
		) {
			CX.updateTable();
		}


		$(".cxatc-qty-error, .cxatc-print-qty-error")
			.hide();


		/*
		|--------------------------------------------------------------------------
		| Reset Printing
		|--------------------------------------------------------------------------
		|
		| A print selection valid at one quantity may no longer be valid after
		| the shopper changes quantity.
		|
		| Preserve the original XSImpress behavior and reset printing.
		*/

		$form
			.find(
				'.cxatc-print-logo input[value="0"]'
			)
			.prop(
				"checked",
				true
			)
			.trigger(
				"change"
			);


		$("#cx-conf-step-printers")
			.prop(
				"checked",
				false
			);


		/*
		|--------------------------------------------------------------------------
		| Minimum Printing Quantity
		|--------------------------------------------------------------------------
		*/

		const $printerStep =
			$("#cx-conf-step-wrapper-printers");

		if (!$printerStep.length) {
			return;
		}


		if (
			qty
			< CX.state.min_print_qty
		) {

			$printerStep
				.find(
					".cx-conf-step-content > *"
				)
				.not(
					".cxatc-print-qty-error"
				)
				.hide();


			$printerStep
				.find(
					".cxatc-print-qty-error"
				)
				.show();

		} else {

			$printerStep
				.find(
					".cx-conf-step-content > *"
				)
				.show();


			$printerStep
				.find(
					".cxatc-print-qty-error"
				)
				.hide();
		}
	}


	/*
	|--------------------------------------------------------------------------
	| Main Quantity Dropdown
	|--------------------------------------------------------------------------
	*/

	$form.on(
		"change",
		".cx-qty-select",
		function () {

			const raw =
				$(this)
					.val();


			/*
			 * The renderer may use a non-positive/special option to represent
			 * custom quantity entry.
			 */
			const qty =
				Number.parseInt(
					raw,
					10
				);


			if (
				Number.isFinite(
					qty
				)
				&& qty > 0
			) {

				setQty(
					qty,
					false
				);


				$(".cx_qty_custom")
					.hide();


				$(".cx-conf-qty .cx-option")
					.removeClass(
						"cx-active"
					);


				$(
					`.cx-conf-qty .cx-option[data-qty="${qty}"]`
				)
					.addClass(
						"cx-active"
					);


				$("#cx-conf-step-qty, #cx-conf-step-overview")
					.prop(
						"checked",
						true
					);

				return;
			}


			$(".cx_qty_custom")
				.show()
				.trigger(
					"focus"
				);
		}
	);


	/*
	|--------------------------------------------------------------------------
	| Custom Quantity
	|--------------------------------------------------------------------------
	|
	| Quantities must follow:
	|
	| minimum + N × increment
	|--------------------------------------------------------------------------
	*/

	$form.on(
		"change",
		".cx_qty_custom",
		function (
			event,
			meta
		) {

			if (meta?.silent) {
				return;
			}


			let customQty =
				Number.parseInt(
					$(this)
						.val(),
					10
				);


			if (
				!Number.isFinite(
					customQty
				)
			) {
				return;
			}


			const minimum =
				Math.max(
					1,
					CX.utils.int(
						CX.state.min_order_qty,
						1
					)
				);


			const increment =
				Math.max(
					1,
					CX.utils.int(
						CX.state.qty_increments,
						1
					)
				);


			if (
				customQty
				< minimum
			) {

				customQty =
					minimum;

			} else {

				customQty =
					minimum
					+ Math.round(
						(
							customQty
							- minimum
						)
						/ increment
					)
					* increment;
			}


			setQty(
				customQty,
				true
			);


			$(this)
				.val(
					customQty
				)
				.trigger(
					"change",
					{
						silent: true
					}
				);
		}
	);


	/*
	|--------------------------------------------------------------------------
	| Require Variation First
	|--------------------------------------------------------------------------
	*/

	$form.on(
		"click",
		".cx-qty-select",
		function () {

			if (
				CX.state.variation_id
			) {
				return;
			}


			$(".cxatc-variation-error")
				.show();
		}
	);


	/*
	|--------------------------------------------------------------------------
	| Configurator Quantity Buttons
	|--------------------------------------------------------------------------
	*/

	$conf.on(
		"click",
		".cx-conf-qty .cx-option",
		function () {

			const qty =
				CX.utils.int(
					$(this)
						.data(
							"qty"
						)
				);

			if (!qty) {
				return;
			}


			$form
				.find(
					".cx-qty-select"
				)
				.val(
					qty
				)
				.trigger(
					"change"
				);
		}
	);

})(jQuery);