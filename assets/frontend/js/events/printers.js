window.CX = window.CX || {};

(function ($) {

	"use strict";

	const $form =
		$("#cxatc-form");

	const $conf =
		$("#cx-conf-form");


	/**
	 * Both interfaces represent the same shared printing state.
	 */
	const $positions =
		$(
			".cxatc-positions, .cx-conf-positions"
		);

	const $printers =
		$(
			".cxatc-printers, .cx-conf-printers"
		);


	/**
	 * Remove one print selection by position ID.
	 */
	function removeByPosition(
		positionId
	) {

		positionId =
			CX.utils.int(
				positionId
			);

		if (!positionId) {
			return;
		}


		CX.state.selectedPrinters =
			(
				CX.state.selectedPrinters
				|| []
			).filter(
				selection =>
					CX.utils.int(
						selection.position_id
					)
					!== positionId
			);
	}


	/**
	 * Save a confirmed position -> option selection.
	 *
	 * Keep printer_id for the current frontend contract.
	 * pricing.js already accepts both printer_id and option_id.
	 */
	function saveSelection(
		positionId,
		optionId
	) {

		positionId =
			CX.utils.int(
				positionId
			);

		optionId =
			CX.utils.int(
				optionId
			);


		if (
			!positionId
			|| !optionId
		) {
			return false;
		}


		removeByPosition(
			positionId
		);


		CX.state.selectedPrinters.push(
			{
				position_id:
					positionId,

				printer_id:
					optionId
			}
		);


		return true;
	}


	/**
	 * Update all calculated UI after printing changes.
	 */
	function updatePricingUI() {

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
	}


	/*
	|--------------------------------------------------------------------------
	| Enable / Disable Printing
	|--------------------------------------------------------------------------
	*/

	$form.on(
		"change",
		".cxatc-print-logo input",
		function () {

			let enabled =
				String(
					$form
						.find(
							".cxatc-print-logo input:checked"
						)
						.val()
				)
				=== "1";


			/*
			|--------------------------------------------------------------------------
			| Variation Required
			|--------------------------------------------------------------------------
			*/

			if (
				enabled
				&& !CX.state.variation_id
			) {

				$(".cxatc-variation-error")
					.show();


				$form
					.find(
						'.cxatc-print-logo input[value="1"]'
					)
					.prop(
						"checked",
						false
					);


				$form
					.find(
						'.cxatc-print-logo input[value="0"]'
					)
					.prop(
						"checked",
						true
					);


				return;
			}


			/*
			|--------------------------------------------------------------------------
			| Quantity Required
			|--------------------------------------------------------------------------
			*/

			const selectedQty =
				CX.utils.int(
					CX.state.selectedQty
				);


			if (
				enabled
				&& (
					!selectedQty
					|| selectedQty
						< CX.state.min_print_qty
				)
			) {

				$(".cxatc-print-qty-error")
					.show();


				$form
					.find(
						'.cxatc-print-logo input[value="1"]'
					)
					.prop(
						"checked",
						false
					);


				$form
					.find(
						'.cxatc-print-logo input[value="0"]'
					)
					.prop(
						"checked",
						true
					);


				return;
			}


			enabled =
				String(
					$form
						.find(
							".cxatc-print-logo input:checked"
						)
						.val()
				)
				=== "1";


			if (!enabled) {

				/*
				 * Reset shared printing state.
				 */
				CX.state.selectedPrinters =
					[];

				CX.state.selectedPosition =
					null;


				$form
					.find(
						".cx-printer-confirm"
					)
					.hide();


				$(".cxatc-print-wrapper")
					.stop(
						true,
						true
					)
					.slideUp();


				$positions
					.find(
						".cx-option"
					)
					.removeClass(
						"cx-active cx-confirmed"
					);


				$printers
					.empty();

			} else {

				$(".cxatc-print-wrapper")
					.stop(
						true,
						true
					)
					.slideDown();
			}


			updatePricingUI();
		}
	);


	/*
	|--------------------------------------------------------------------------
	| Main Form — Select Position
	|--------------------------------------------------------------------------
	*/

	$form.on(
		"click",
		".cxatc-positions .cx-option",
		function () {

			const positionId =
				CX.utils.int(
					$(this)
						.data(
							"id"
						)
				);

			if (!positionId) {
				return;
			}


			CX.state.selectedPosition =
				positionId;


			CX.renderPrintOptions(
				positionId
			);


			$form
				.find(
					".cx-printer-confirm"
				)
				.show();


			$positions
				.find(
					".cx-option"
				)
				.removeClass(
					"cx-active"
				);


			$positions
				.find(
					`.cx-option[data-id="${positionId}"]`
				)
				.addClass(
					"cx-active"
				);
		}
	);


	/*
	|--------------------------------------------------------------------------
	| Main Form — Confirm Print Option
	|--------------------------------------------------------------------------
	*/

	$form.on(
		"click",
		".cx-printer-confirm",
		function () {

			const $select =
				$form
					.find(
						".cx-printer-select"
					)
					.first();


			const positionId =
				CX.utils.int(
					$select
						.data(
							"position"
						)
				);


			const optionId =
				CX.utils.int(
					$select
						.val()
				);


			if (!optionId) {

				window.alert(
					"Bitte wählen Sie eine Veredelungsart aus."
				);

				return;
			}


			if (
				!saveSelection(
					positionId,
					optionId
				)
			) {
				return;
			}


			$printers
				.empty();


			$form
				.find(
					".cx-printer-confirm"
				)
				.hide();


			$positions
				.find(
					".cx-option"
				)
				.removeClass(
					"cx-active"
				);


			$positions
				.find(
					`.cx-option[data-id="${positionId}"]`
				)
				.addClass(
					"cx-confirmed"
				);


			$("#cx-conf-step-printers")
				.prop(
					"checked",
					true
				);


			updatePricingUI();
		}
	);


	/*
	|--------------------------------------------------------------------------
	| Configurator — Select Position
	|--------------------------------------------------------------------------
	*/

	$conf.on(
		"click",
		".cx-conf-positions .cx-option",
		function () {

			const positionId =
				CX.utils.int(
					$(this)
						.data(
							"id"
						)
				);

			if (!positionId) {
				return;
			}


			CX.state.selectedPosition =
				positionId;


			CX.renderPrintOptions(
				positionId,
				"option"
			);


			$conf
				.find(
					".cx-printer-confirm"
				)
				.show();


			$positions
				.find(
					".cx-option"
				)
				.removeClass(
					"cx-active"
				);


			$positions
				.find(
					`.cx-option[data-id="${positionId}"]`
				)
				.addClass(
					"cx-active"
				);
		}
	);


	/*
	|--------------------------------------------------------------------------
	| Configurator — Select Option
	|--------------------------------------------------------------------------
	*/

	$conf.on(
		"click",
		".cx-conf-printers .cx-option",
		function () {

			const optionId =
				CX.utils.int(
					$(this)
						.data(
							"id"
						)
				);

			if (!optionId) {
				return;
			}


			$conf
				.find(
					".cx-conf-printers .cx-option"
				)
				.removeClass(
					"cx-active"
				);


			$conf
				.find(
					`.cx-conf-printers .cx-option[data-id="${optionId}"]`
				)
				.addClass(
					"cx-active"
				);
		}
	);


	/*
	|--------------------------------------------------------------------------
	| Configurator — Confirm Option
	|--------------------------------------------------------------------------
	*/

	$conf.on(
		"click",
		".cx-printer-confirm",
		function () {

			const positionId =
				CX.utils.int(
					$conf
						.find(
							".cx-conf-positions .cx-option.cx-active"
						)
						.data(
							"id"
						)
				);


			const optionId =
				CX.utils.int(
					$conf
						.find(
							".cx-conf-printers .cx-option.cx-active"
						)
						.data(
							"id"
						)
				);


			if (!optionId) {

				window.alert(
					"Bitte wählen Sie einen Drucker aus."
				);

				return;
			}


			if (
				!saveSelection(
					positionId,
					optionId
				)
			) {
				return;
			}


			$conf
				.find(
					".cx-conf-printers"
				)
				.empty();


			$conf
				.find(
					".cx-printer-confirm"
				)
				.hide();


			$positions
				.find(
					".cx-active"
				)
				.removeClass(
					"cx-active"
				);


			$positions
				.find(
					`.cx-option[data-id="${positionId}"]`
				)
				.addClass(
					"cx-confirmed"
				);


			updatePricingUI();
		}
	);


	/*
	|--------------------------------------------------------------------------
	| Remove Confirmed Selection
	|--------------------------------------------------------------------------
	*/

	$("body").on(
		"click",
		".cx-remove-selection",
		function (event) {

			event.preventDefault();
			event.stopPropagation();


			const $option =
				$(this)
					.closest(
						".cx-option"
					);


			const $wrapper =
				$(this)
					.closest(
						".cx-select-img"
					);


			const selections =
				CX.state.selectedPrinters
					|| [];


			let positionId = 0;

			let optionId = 0;


			/*
			|--------------------------------------------------------------------------
			| Removing a Position
			|--------------------------------------------------------------------------
			*/

			if (
				$wrapper.hasClass(
					"cxatc-positions"
				)
				|| $wrapper.hasClass(
					"cx-conf-positions"
				)
			) {

				positionId =
					CX.utils.int(
						$option
							.data(
								"id"
							)
					);


				const selection =
					selections.find(
						item =>
							CX.utils.int(
								item.position_id
							)
							=== positionId
					);


				optionId =
					CX.utils.int(
						selection?.printer_id
					);
			}


			/*
			|--------------------------------------------------------------------------
			| Removing an Option
			|--------------------------------------------------------------------------
			*/

			if (
				$wrapper.hasClass(
					"cxatc-printers"
				)
				|| $wrapper.hasClass(
					"cx-conf-printers"
				)
			) {

				optionId =
					CX.utils.int(
						$option
							.data(
								"id"
							)
					);


				const selection =
					selections.find(
						item =>
							CX.utils.int(
								item.printer_id
							)
							=== optionId
					);


				positionId =
					CX.utils.int(
						selection?.position_id
					);
			}


			if (!positionId) {
				return;
			}


			removeByPosition(
				positionId
			);


			$positions
				.find(
					`.cx-option[data-id="${positionId}"]`
				)
				.removeClass(
					"cx-active cx-confirmed"
				);


			if (optionId) {

				$printers
					.find(
						`.cx-option[data-id="${optionId}"]`
					)
					.removeClass(
						"cx-active cx-confirmed"
					);
			}


			updatePricingUI();
		}
	);

})(jQuery);