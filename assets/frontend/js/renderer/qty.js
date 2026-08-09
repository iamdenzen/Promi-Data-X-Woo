window.CX = window.CX || {};


/**
 * Render available product quantity tiers in:
 *
 * - Main add-to-cart dropdown.
 * - Multi-step configurator buttons.
 */
CX.renderQty = function () {

	const $form =
		jQuery(
			"#cxatc-form"
		);


	const $select =
		$form
			.find(
				".cx-qty-select"
			)
			.empty();


	$select.append(
		'<option value="">Bitte auswählen</option>'
	);


	let configuratorHtml = "";


	(
		CX.state.tiers
		|| []
	).forEach(
		tier => {

			const quantity =
				CX.utils.int(
					tier.qty
						?? tier.min_qty
				);

			const price =
				CX.utils.float(
					tier.price
				);

			if (!quantity) {
				return;
			}


			$select.append(
				`<option value="${quantity}">${quantity}</option>`
			);


			configuratorHtml += `
				<div
					class="cx-option"
					data-qty="${quantity}"
				>
					<label>
						<span class="cx-option-image"></span>

						<span class="cx-option-label">
							${quantity} Stück

							<small>
								ab ${CX.utils.currency(price)}
							</small>
						</span>
					</label>
				</div>
			`;
		}
	);


	/*
	|--------------------------------------------------------------------------
	| Custom Quantity
	|--------------------------------------------------------------------------
	*/

	$select.append(
		'<option value="-1">Individuelle Menge</option>'
	);


	jQuery(
		".cx-conf-qty"
	)
		.html(
			configuratorHtml
		);


	jQuery(
		".cx_qty_custom"
	)
		.attr(
			"min",
			Math.max(
				1,
				CX.utils.int(
					CX.state.min_order_qty,
					1
				)
			)
		)
		.attr(
			"step",
			Math.max(
				1,
				CX.utils.int(
					CX.state.qty_increments,
					1
				)
			)
		);


	jQuery(
		".cxatc-print-qty"
	)
		.text(
			Math.max(
				1,
				CX.utils.int(
					CX.state.min_print_qty,
					1
				)
			)
		);


	/*
	|--------------------------------------------------------------------------
	| Restore Current Selection
	|--------------------------------------------------------------------------
	*/

	if (CX.state.selectedQty) {

		const current =
			CX.utils.int(
				CX.state.selectedQty
			);


		const exactTier =
			(
				CX.state.tiers
				|| []
			).some(
				tier =>
					CX.utils.int(
						tier.qty
							?? tier.min_qty
					)
					=== current
			);


		if (
			exactTier
			&& !CX.state.isCustomQty
		) {

			$select.val(
				current
			);


			jQuery(
				`.cx-conf-qty .cx-option[data-qty="${current}"]`
			)
				.addClass(
					"cx-active"
				);

		} else {

			$select.val(
				"-1"
			);


			jQuery(
				".cx_qty_custom"
			)
				.val(
					current
				)
				.show();
		}
	}
};
