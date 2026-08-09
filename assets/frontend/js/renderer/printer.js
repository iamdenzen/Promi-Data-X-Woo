window.CX = window.CX || {};


/**
 * Render all available printing positions.
 */
CX.renderPrinters = function () {

	const $wrappers =
		jQuery(
			".cxatc-positions, .cx-conf-positions"
		)
			.empty();


	Object.entries(
		CX.state.positions
			|| {}
	).forEach(
		([id, position]) => {

			const positionId =
				CX.utils.int(
					id
				);

			if (!positionId) {
				return;
			}


			const selected =
				(
					CX.state.selectedPrinters
						|| []
				).some(
					item =>
						CX.utils.int(
							item.position_id
						)
						=== positionId
				);


			const image =
				position.image
					? `
						<img
							src="${CX.utils.escapeHtml(position.image)}"
							width="70"
							alt=""
						>
					`
					: "";


			const label =
				CX.utils.escapeHtml(
					position.label
						|| position.code
						|| ""
				);


			$wrappers.append(
				`
					<div
						class="cx-option ${selected ? "cx-confirmed" : ""}"
						data-id="${positionId}"
					>
						<label>
							<span class="cx-option-image">
								${image}
							</span>

							<span class="cx-option-label">
								${label}
							</span>

							<span class="cx-remove-selection">
								x
							</span>
						</label>
					</div>
				`
			);
		}
	);
};


/**
 * Render the print options belonging to one position.
 *
 * Templates:
 *
 * dropdown
 *     Main add-to-cart form.
 *
 * option
 *     Multi-step configurator.
 */
CX.renderPrintOptions = function (
	positionId,
	template = "dropdown"
) {

	positionId =
		CX.utils.int(
			positionId
		);


	const position =
		CX.state.positions?.[
			positionId
		];


	if (!position) {
		return;
	}


	const selected =
		(
			CX.state.selectedPrinters
				|| []
		).find(
			item =>
				CX.utils.int(
					item.position_id
				)
				=== positionId
		);


	const selectedOptionId =
		CX.utils.int(
			selected?.option_id
				?? selected?.printer_id
		);


	const quantity =
		Math.max(
			1,
			CX.utils.int(
				CX.state.selectedQty,
				1
			)
		);


	let options = "";


	if ("dropdown" === template) {

		options =
			'<option value="">Veredelungsart wählen</option>';
	}


	Object.entries(
		position.options
			|| {}
	).forEach(
		([id, option]) => {

			const optionId =
				CX.utils.int(
					id
				);


			if (!optionId) {
				return;
			}


			const minimum =
				Math.max(
					1,
					CX.utils.int(
						option.min_order_qty,
						1
					)
				);


			if (
				quantity
				< minimum
			) {
				return;
			}


			const isSelected =
				selectedOptionId
				=== optionId;


			const name =
				CX.utils.escapeHtml(
					option.name
						|| ""
				);


			if ("dropdown" === template) {

				options += `
					<option
						value="${optionId}"
						${isSelected ? "selected" : ""}
					>
						${name}
					</option>
				`;

				return;
			}


			if ("option" === template) {

				const price =
					CX.pricing.getTierPrice(
						quantity,
						option.prices
							|| []
					);


				options += `
					<div
						class="cx-option ${isSelected ? "cx-active" : ""}"
						data-id="${optionId}"
					>
						<label>
							<span class="cx-option-label">
								${name}
								<br>

								<strong>
									${CX.utils.currency(price)}
								</strong>
							</span>

							<span class="cx-remove-selection">
								x
							</span>
						</label>
					</div>
				`;
			}
		}
	);


	if ("dropdown" === template) {

		jQuery(
			".cxatc-printers"
		)
			.html(
				`
					<select
						class="cx-printer-select"
						data-position="${positionId}"
					>
						${options}
					</select>
				`
			);

		return;
	}


	if ("option" === template) {

		jQuery(
			".cx-conf-printers"
		)
			.html(
				options
			);
	}
};
