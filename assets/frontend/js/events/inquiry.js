window.CX = window.CX || {};

(function ($) {

	"use strict";


	/**
	 * Convert the current printing selection into the structure expected by
	 * Frontend\Ajax (shared with events/cart.js's getPrintPositions()).
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
	 * .cx-inquiry-form is a plain <div>, not a <form> — it lives inside the
	 * existing #cxatc-form add-to-cart <form>, and browsers do not allow
	 * nested <form> elements (the outer add-to-cart form would silently
	 * auto-close). Submission is therefore driven by a button click, and
	 * Enter inside a text field is prevented from submitting the outer
	 * add-to-cart form instead.
	 */
	$(document).on(
		"keydown",
		".cx-inquiry-form input",
		function (event) {

			if ( "Enter" === event.key ) {

				event.preventDefault();

				$(this)
					.closest(
						".cx-inquiry-form"
					)
					.find(
						".cx-inquiry-submit"
					)
					.trigger(
						"click"
					);
			}
		}
	);


	/**
	 * Submit a price-on-request inquiry.
	 *
	 * Only rendered/active when the product is in "Preis auf Anfrage" mode
	 * (see templates/frontend/add-to-cart.php).
	 */
	$(document).on(
		"click",
		".cx-inquiry-submit",
		function (event) {

			event.preventDefault();

			const $form =
				$(this).closest(
					".cx-inquiry-form"
				);

			const $message =
				$form.find(
					".cx-inquiry-message"
				);

			const $button =
				$(this);


			if (
				$form.data(
					"cx-submitting"
				)
			) {
				return;
			}


			const name =
				String(
					$form
						.find(
							"[name=inquiry_name]"
						)
						.val()
						|| ""
				)
					.trim();

			const email =
				String(
					$form
						.find(
							"[name=inquiry_email]"
						)
						.val()
						|| ""
				)
					.trim();


			if (
				!name
				|| !email
			) {

				$message
					.removeClass(
						"cx-inquiry-message--success"
					)
					.addClass(
						"cx-inquiry-message--error"
					)
					.text(
						"Bitte geben Sie Ihren Namen und Ihre E-Mail-Adresse an."
					)
					.show();

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

			$message
				.hide();


			CX.api
				.submitInquiry(
					{
						product_id:
							CX.state.product_id,

						variation_id:
							CX.state.variation_id,

						qty:
							CX.utils.int(
								CX.state.selectedQty
							),

						name:
							name,

						email:
							email,

						phone:
							String(
								$form
									.find(
										"[name=inquiry_phone]"
									)
									.val()
									|| ""
							),

						message:
							String(
								$form
									.find(
										"[name=inquiry_message]"
									)
									.val()
									|| ""
							),

						print_positions:
							getPrintPositions()
					}
				)
				.then(
					response => {

						if (
							!response?.success
						) {

							const errorText =
								response?.data?.message
								|| "Ihre Anfrage konnte nicht gesendet werden. Bitte versuchen Sie es erneut.";

							$message
								.removeClass(
									"cx-inquiry-message--success"
								)
								.addClass(
									"cx-inquiry-message--error"
								)
								.text(
									errorText
								)
								.show();

							return;
						}


						$message
							.removeClass(
								"cx-inquiry-message--error"
							)
							.addClass(
								"cx-inquiry-message--success"
							)
							.text(
								response?.data?.message
								|| "Vielen Dank — Ihre Anfrage wurde gesendet."
							)
							.show();

						$form
							.find(
								"input, textarea"
							)
							.val(
								""
							);
					}
				)
				.catch(
					error => {

						console.error(
							"Unable to submit inquiry.",
							error
						);

						$message
							.removeClass(
								"cx-inquiry-message--success"
							)
							.addClass(
								"cx-inquiry-message--error"
							)
							.text(
								"Ihre Anfrage konnte nicht gesendet werden. Bitte versuchen Sie es erneut."
							)
							.show();
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
