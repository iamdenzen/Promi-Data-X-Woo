window.CX = window.CX || {};

(function ($) {

	"use strict";

	const $form =
		$("#cxatc-form");


	/**
	 * Order one product as a sample.
	 *
	 * The PHP endpoint preserves the existing XSImpress rule:
	 *
	 *     quantity === 1
	 *
	 * becomes:
	 *
	 *     cart_item['is_sample'] = 1
	 */
	$(document).on(
		"click",
		".cxatc-sample",
		function (event) {

			event.preventDefault();


			const $button =
				$(this);


			/*
			|--------------------------------------------------------------------------
			| Existing Sample Already Added
			|--------------------------------------------------------------------------
			*/

			if (
				$button.hasClass(
					"cx-sample-disabled"
				)
			) {

				$(".cxatc-sample-error")
					.stop(
						true,
						true
					)
					.show();


				window.setTimeout(
					() => {

						$(".cxatc-sample-error")
							.hide();
					},
					3000
				);

				return;
			}


			/*
			|--------------------------------------------------------------------------
			| Variation Required
			|--------------------------------------------------------------------------
			*/

			if (!CX.state.variation_id) {

				$(".cxatc-variation-error")
					.show();

				return;
			}


			/*
			|--------------------------------------------------------------------------
			| Temporarily Submit Quantity 1
			|--------------------------------------------------------------------------
			*/

			const previousQty =
				CX.state.selectedQty;


			const previousCustomState =
				CX.state.isCustomQty;


			CX.state.selectedQty =
				1;

			CX.state.isCustomQty =
				false;


			$form.trigger(
				"submit"
			);


			/*
			 * Restore the shopper's previous quantity selection.
			 *
			 * Keep the existing one-sample-per-page-session behavior by
			 * disabling the sample button after submission.
			 */
			window.setTimeout(
				() => {

					CX.state.selectedQty =
						previousQty;

					CX.state.isCustomQty =
						previousCustomState;


					$(".cxatc-sample")
						.addClass(
							"cx-sample-disabled"
						);
				},
				1000
			);
		}
	);

})(jQuery);
