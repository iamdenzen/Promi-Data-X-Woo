window.CX = window.CX || {};


/**
 * Render the complete tier-pricing table.
 *
 * Includes:
 *
 * - Quantity tiers.
 * - Product unit prices.
 * - Selected printing prices.
 * - Printing fees.
 * - Effective unit totals.
 * - Complete line totals.
 */
CX.updateTable = function () {

	const $table =
		jQuery(
			"#cx-price-table"
		);


	if (!$table.length) {
		return;
	}


	const tiers =
		Array.isArray(
			CX.state.tiers
		)
			? CX.state.tiers
			: [];


	if (!tiers.length) {
		return;
	}


	const selectedQty =
		Math.max(
			1,
			CX.utils.int(
				CX.state.selectedQty,
				1
			)
		);


	/*
	|--------------------------------------------------------------------------
	| Quantity / Product Tiers
	|--------------------------------------------------------------------------
	*/

	$table
		.find(
			".cx-qty-tiers"
		)
		.empty();


	$table
		.find(
			".cx-price-tiers"
		)
		.empty();


	tiers.forEach(
		tier => {

			const quantity =
				CX.utils.int(
					tier.qty
						?? tier.min_qty
				);


			if (!quantity) {
				return;
			}


			$table
				.find(
					".cx-qty-tiers"
				)
				.append(
					`<div>${quantity}</div>`
				);


			$table
				.find(
					".cx-price-tiers"
				)
				.append(
					`<div>${CX.utils.currency(tier.price)}</div>`
				);
		}
	);


	/*
	|--------------------------------------------------------------------------
	| Custom Quantity Column
	|--------------------------------------------------------------------------
	*/

	if (
		CX.state.isCustomQty
		&& CX.state.selectedQty
	) {

		$table
			.find(
				".cx-qty-tiers"
			)
			.append(
				`<div>${selectedQty}</div>`
			);


		$table
			.find(
				".cx-price-tiers"
			)
			.append(
				`
					<div>
						${CX.utils.currency(
							CX.pricing.getTierPrice(
								selectedQty,
								tiers
							)
						)}
					</div>
				`
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Printing Rows
	|--------------------------------------------------------------------------
	*/

	$table
		.find(
			".cx-printer-row"
		)
		.remove();


	let printerRows = "";


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


			let priceColumns = "";


			tiers.forEach(
				tier => {

					const quantity =
						CX.utils.int(
							tier.qty
								?? tier.min_qty
						);


					if (
						quantity
						< minimum
					) {

						priceColumns +=
							"<div>--,--</div>";

						return;
					}


					const printPrice =
						CX.pricing.getTierPrice(
							quantity,
							option.prices
								|| []
						);


					priceColumns += `
						<div>
							${CX.utils.currency(printPrice)}
						</div>
					`;
				}
			);


			if (
				CX.state.isCustomQty
				&& CX.state.selectedQty
			) {

				if (
					selectedQty
					< minimum
				) {

					priceColumns +=
						"<div>--,--</div>";

				} else {

					priceColumns += `
						<div>
							${CX.utils.currency(
								CX.pricing.getTierPrice(
									selectedQty,
									option.prices
										|| []
								)
							)}
						</div>
					`;
				}
			}


			printerRows += `
				<div class="cx-table-row cx-printer-row">

					<div>
						${CX.utils.escapeHtml(position.label || "")}

						<small>
							${CX.utils.escapeHtml(option.name || "")}
						</small>
					</div>

					<div class="cx-printer-tiers cx-tiers">
						${priceColumns}
					</div>

				</div>
			`;


			/*
			|--------------------------------------------------------------------------
			| Fees
			|--------------------------------------------------------------------------
			|
			| Unlike the original frontend, support every fee returned by the
			| backend rather than silently displaying only fees[0].
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


					let feeColumns = "";


					tiers.forEach(
						tier => {

							const quantity =
								CX.utils.int(
									tier.qty
										?? tier.min_qty
								);


							feeColumns +=
								quantity < minimum
									? "<div>--,--</div>"
									: `<div>${CX.utils.currency(amount)}</div>`;
						}
					);


					if (
						CX.state.isCustomQty
						&& CX.state.selectedQty
					) {

						feeColumns +=
							selectedQty < minimum
								? "<div>--,--</div>"
								: `<div>${CX.utils.currency(amount)}</div>`;
					}


					const label =
						CX.utils.escapeHtml(
							fee?.label
								|| fee?.name
								|| "Setup"
						);


					printerRows += `
						<div class="cx-table-row cx-printer-row">

							<div>
								${label}
							</div>

							<div class="cx-printer-tiers cx-tiers">
								${feeColumns}
							</div>

						</div>
					`;
				}
			);
		}
	);


	$table
		.find(
			".cx-price-row"
		)
		.after(
			printerRows
		);


	/*
	|--------------------------------------------------------------------------
	| Subtotals / Totals
	|--------------------------------------------------------------------------
	*/

	let subtotalColumns = "";

	let totalColumns = "";


	tiers.forEach(
		tier => {

			const quantity =
				CX.utils.int(
					tier.qty
						?? tier.min_qty
				);


			if (!quantity) {
				return;
			}


			const totals =
				CX.pricing.getTotals(
					quantity
				);


			subtotalColumns += `
				<div>
					${CX.utils.currency(totals.subtotal)}
				</div>
			`;


			totalColumns += `
				<div data-qty="${quantity}">
					${CX.utils.currency(totals.total)}
				</div>
			`;
		}
	);


	if (
		CX.state.isCustomQty
		&& CX.state.selectedQty
	) {

		const totals =
			CX.pricing.getTotals(
				selectedQty
			);


		subtotalColumns += `
			<div>
				${CX.utils.currency(totals.subtotal)}
			</div>
		`;


		totalColumns += `
			<div data-qty="${selectedQty}">
				${CX.utils.currency(totals.total)}
			</div>
		`;
	}


	$table
		.find(
			".cx-subtotal-tiers"
		)
		.html(
			subtotalColumns
		);


	$table
		.find(
			".cx-total-tiers"
		)
		.html(
			totalColumns
		);
};
