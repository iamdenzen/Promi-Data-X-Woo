window.CX = window.CX || {};


/**
 * Render pricing summaries for both:
 *
 * - Main add-to-cart form.
 * - Multi-step configurator.
 */
CX.updateSummary = function () {

	const $summaries =
		jQuery(
			".cxatc-summary"
		);


	if (!$summaries.length) {
		return;
	}


	const quantity =
		Math.max(
			1,
			CX.utils.int(
				CX.state.selectedQty,
				1
			)
		);


	const articlePrice =
		CX.pricing.getTierPrice(
			quantity
		);


	/*
	|--------------------------------------------------------------------------
	| Product Price
	|--------------------------------------------------------------------------
	*/

	$summaries
		.find(
			".cx-artikel"
		)
		.html(
			`
				<div>
					<div>${quantity}</div>
					<div>x</div>
					<div>${CX.utils.currency(articlePrice)}</div>
				</div>
			`
		);


	$summaries
		.find(
			".cx-artikel-total"
		)
		.html(
			CX.utils.currency(
				quantity
				* articlePrice
			)
		);


	jQuery(
		".cx-product-main-price b"
	)
		.html(
			CX.utils.currency(
				articlePrice
			)
		);


	/*
	|--------------------------------------------------------------------------
	| Reset Dynamic Printing Rows
	|--------------------------------------------------------------------------
	*/

	$summaries
		.find(
			"tbody tr:not(.cxatc-summary-base-row)"
		)
		.remove();


	let printerRows = "";

	let printingTotal = 0;


	/*
	|--------------------------------------------------------------------------
	| Printing
	|--------------------------------------------------------------------------
	*/

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


			const position =
				CX.state.positions?.[
					positionId
				];


			const option =
				position?.options?.[
					optionId
				];


			if (!option) {
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


			/*
			|--------------------------------------------------------------------------
			| Print Unit Price
			|--------------------------------------------------------------------------
			*/

			const unitPrice =
				CX.pricing.getTierPrice(
					quantity,
					option.prices
						|| []
				);


			const lineTotal =
				unitPrice
				* quantity;


			printingTotal +=
				lineTotal;


			printerRows += `
				<tr>

					<td>
						${CX.utils.escapeHtml(position.label || "")}

						<small>
							${CX.utils.escapeHtml(option.name || "")}
						</small>
					</td>

					<td>
						<div>
							<div>${quantity}</div>
							<div>x</div>
							<div>${CX.utils.currency(unitPrice)}</div>
						</div>
					</td>

					<td>
						${CX.utils.currency(lineTotal)}
					</td>

				</tr>
			`;


			/*
			|--------------------------------------------------------------------------
			| Fees
			|--------------------------------------------------------------------------
			*/

			(
				option.fees
					|| []
			).forEach(
				fee => {

					const amount =
						Math.max(
							0,
							CX.utils.float(
								fee?.amount
							)
						);


					if (!amount) {
						return;
					}


					printingTotal +=
						amount;


					const label =
						CX.utils.escapeHtml(
							fee?.label
								|| fee?.name
								|| "Setup"
						);


					printerRows += `
						<tr>

							<td>
								${label}
							</td>

							<td>
								<div>
									<div>1</div>
									<div>x</div>
									<div>${CX.utils.currency(amount)}</div>
								</div>
							</td>

							<td>
								${CX.utils.currency(amount)}
							</td>

						</tr>
					`;
				}
			);
		}
	);


	$summaries
		.find(
			"tbody"
		)
		.append(
			printerRows
		);


	/*
	|--------------------------------------------------------------------------
	| Grand Total
	|--------------------------------------------------------------------------
	|
	| Keep this mathematically aligned with CX.pricing.getTotals().
	*/

	const totals =
		CX.pricing.getTotals(
			quantity
		);


	$summaries
		.find(
			".cx-grand-total"
		)
		.html(
			CX.utils.currency(
				totals.total
			)
		);


	/*
	|--------------------------------------------------------------------------
	| VAT Preview
	|--------------------------------------------------------------------------
	|
	| Preserve the existing storefront's hard-coded 19% informational preview.
	| WooCommerce remains authoritative for actual tax calculation.
	*/

	$summaries
		.find(
			".cx-tax"
		)
		.html(
			CX.utils.currency(
				totals.total
					* 0.19
			)
		);
};
