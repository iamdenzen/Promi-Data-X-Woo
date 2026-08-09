window.CX = window.CX || {};

(function ($) {

	"use strict";


	/**
	 * Convert the frontend printing selection into the structure expected by
	 * Frontend\Ajax:
	 *
	 * {
	 *     position_id: option_id
	 * }
	 */
	function getPrintPositions() {

		const positions = {};

		(
			CX.state.selectedPrinters
			|| []
		).forEach(
			selection => {

				const positionId =
					CX.utils.int(
						selection.position_id
					);

				const optionId =
					CX.utils.int(
						selection.option_id
							?? selection.printer_id
					);

				if (
					!positionId
					|| !optionId
				) {
					return;
				}

				positions[
					positionId
				] = optionId;
			}
		);

		return positions;
	}


	/**
	 * Add the currently configured product to WooCommerce.
	 *
	 * Both:
	 *
	 *     #cxatc-form
	 *     #cx-conf-form
	 *
	 * use the same shared CX.state, so the submitted form itself does not
	 * need to carry the business configuration.
	 */
	$(document).on(
		"submit",
		".cx-addtocart-form",
		function (event) {

			event.preventDefault();


			/*
			|--------------------------------------------------------------------------
			| Variation
			|--------------------------------------------------------------------------
			*/

			if (!CX.state.variation_id) {

				$(".cxatc-variation-error")
					.show();

				return;
			}


			/*
			|--------------------------------------------------------------------------
			| Quantity
			|--------------------------------------------------------------------------
			*/

			const quantity =
				CX.utils.int(
					CX.state.selectedQty
				);

			if (!quantity) {

				$(".cxatc-qty-error")
					.show();

				return;
			}


			const $form =
				$(this);

			const $button =
				$form
					.find(
						".single_add_to_cart_button"
					)
					.first();


			if (
				$form.data(
					"cx-submitting"
				)
			) {
				return;
			}


			$form.data(
				"cx-submitting",
				true
			);


			$button
				.prop(
					"disabled",
					true
				)
				.addClass(
					"loading"
				);


			CX.api
				.addToCart(
					{
						product_id:
							CX.state.product_id,

						variation_id:
							CX.state.variation_id,

						qty:
							quantity,

						print_positions:
							getPrintPositions()
					}
				)
				.then(
					response => {

						if (
							!response?.success
						) {

							const message =
								response?.data?.message
								|| "Das Produkt konnte nicht in den Warenkorb gelegt werden.";

							window.alert(
								message
							);

							return;
						}


						/*
						|--------------------------------------------------------------------------
						| WooCommerce Fragments
						|--------------------------------------------------------------------------
						*/

						const fragments =
							response?.data?.fragments
								|| {};

						Object.entries(
							fragments
						).forEach(
							([selector, html]) => {

								$(selector)
									.replaceWith(
										html
									);
							}
						);


						/*
						|--------------------------------------------------------------------------
						| WooCommerce Event
						|--------------------------------------------------------------------------
						|
						| Preserve the normal WooCommerce frontend contract so
						| mini-carts/themes/extensions can react.
						*/

						$(document.body)
							.trigger(
								"added_to_cart",
								[
									fragments,
									"",
									$button
								]
							);


						$(document)
							.trigger(
								"pdxw:added_to_cart",
								[
									response.data
								]
							);
					}
				)
				.catch(
					error => {

						console.error(
							"Unable to add product to cart.",
							error
						);
					}
				)
				.always(
					() => {

						$form.data(
							"cx-submitting",
							false
						);


						$button
							.prop(
								"disabled",
								false
							)
							.removeClass(
								"loading"
							);
					}
				);
		}
	);

})(jQuery);