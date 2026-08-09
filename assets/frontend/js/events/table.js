window.CX = window.CX || {};

(function ($) {

	"use strict";

	const $form =
		$("#cxatc-form");

	const $table =
		$("#cx-price-table");


	/**
	 * Clicking a total-price tier acts as an add-to-cart shortcut.
	 *
	 * Quantity 1 keeps the existing sample-order behavior.
	 */
	$table.on(
		"click",
		".cx-total-tiers [data-qty]",
		function () {

			const $cell =
				$(this);

			const quantity =
				CX.utils.int(
					$cell.data(
						"qty"
					)
				);

			if (!quantity) {
				return;
			}


			/*
			|--------------------------------------------------------------------------
			| Sample
			|--------------------------------------------------------------------------
			*/

			if (1 === quantity) {

				$(".cxatc-sample")
					.first()
					.trigger(
						"click"
					);

				return;
			}


			if (
				$cell.hasClass(
					"cx-processing"
				)
			) {
				return;
			}


			$cell.addClass(
				"cx-processing"
			);


			CX.state.selectedQty =
				quantity;

			CX.state.isCustomQty =
				false;


			$form.trigger(
				"submit"
			);


			window.setTimeout(
				() => {

					$cell.removeClass(
						"cx-processing"
					);
				},
				1000
			);
		}
	);

})(jQuery);
