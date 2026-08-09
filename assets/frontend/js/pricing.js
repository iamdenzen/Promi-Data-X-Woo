window.CX = window.CX || {};

/**
 * Frontend pricing preview.
 *
 * IMPORTANT:
 *
 * This JavaScript is only responsible for displaying a pricing preview.
 *
 * WooCommerce's authoritative cart price is always recalculated on the
 * server through:
 *
 * Pricing\Engine
 *     ↓
 * TieredPricing
 *     ↓
 * Printing\Calculator
 */
CX.pricing = {

	/**
	 * Return the tier applicable to a quantity.
	 *
	 * Existing XSImpress rule:
	 *
	 * - Sort tiers by quantity ascending.
	 * - Use the greatest tier threshold <= requested quantity.
	 * - If requested quantity is below every threshold, use the first tier.
	 *
	 * Both forms remain supported:
	 *
	 * {
	 *     qty: 100,
	 *     price: 2.95
	 * }
	 *
	 * {
	 *     min_qty: 100,
	 *     price: 2.95
	 * }
	 */
	getTierPrice(qty, tiers = null) {

		const quantity =
			Math.max(
				1,
				CX.utils.int(
					qty,
					1
				)
			);

		const source =
			Array.isArray(tiers)
			&& tiers.length
				? tiers
				: (
					Array.isArray(
						CX.state.tiers
					)
						? CX.state.tiers
						: []
				);

		if (!source.length) {
			return 0;
		}

		const sorted =
			[...source]
				.filter(
					tier =>
						tier
						&& Number.isFinite(
							CX.utils.float(
								tier.price,
								NaN
							)
						)
				)
				.sort(
					(a, b) => {

						const aQty =
							CX.utils.float(
								a.min_qty
									?? a.qty
									?? 0
							);

						const bQty =
							CX.utils.float(
								b.min_qty
									?? b.qty
									?? 0
							);

						return aQty - bQty;
					}
				);

		if (!sorted.length) {
			return 0;
		}

		/*
		 * Preserve the existing fallback:
		 *
		 * requested quantity below first tier
		 *     ↓
		 * first available tier
		 */
		let match =
			sorted[0];

		for (const tier of sorted) {

			const threshold =
				CX.utils.float(
					tier.min_qty
						?? tier.qty
						?? 0
				);

			if (quantity >= threshold) {
				match = tier;
			}
		}

		return Math.max(
			0,
			CX.utils.float(
				match.price
			)
		);
	},


	/**
	 * Calculate frontend preview totals.
	 *
	 * Returns:
	 *
	 * {
	 *     unitPrice,
	 *     printPrice,
	 *     printFees,
	 *     subtotal,
	 *     total
	 * }
	 *
	 * subtotal
	 *     Effective per-unit total including apportioned fixed print fees.
	 *
	 * total
	 *     Complete line total.
	 */
	getTotals(qty) {

		const quantity =
			Math.max(
				1,
				CX.utils.int(
					qty,
					1
				)
			);

		const unitPrice =
			this.getTierPrice(
				quantity
			);

		let printPrice = 0;
		let printFees = 0;

		const selections =
			Array.isArray(
				CX.state.selectedPrinters
			)
				? CX.state.selectedPrinters
				: [];


		selections.forEach(
			selection => {

				const positionId =
					selection.position_id;

				/*
				 * The existing frontend currently calls this printer_id.
				 *
				 * Support option_id as well so we can eventually rename the
				 * frontend field without another pricing.js change.
				 */
				const optionId =
					selection.option_id
					?? selection.printer_id;

				if (
					!positionId
					|| !optionId
				) {
					return;
				}

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

				const minPrintQty =
					Math.max(
						1,
						CX.utils.int(
							option.min_order_qty,
							1
						)
					);

				if (
					quantity
					< minPrintQty
				) {
					return;
				}


				/*
				|--------------------------------------------------------------------------
				| Per-unit Print Tier
				|--------------------------------------------------------------------------
				*/

				printPrice +=
					this.getTierPrice(
						quantity,
						option.prices || []
					);


				/*
				|--------------------------------------------------------------------------
				| Fixed / Setup Fees
				|--------------------------------------------------------------------------
				|
				| The existing frontend only added fees[0].
				|
				| Print options can contain multiple fees, so include every
				| selling-side fee supplied by the backend.
				|
				| Server-side Printing\Calculator remains authoritative.
				*/

				if (
					Array.isArray(
						option.fees
					)
				) {

					option.fees.forEach(
						fee => {

							printFees +=
								Math.max(
									0,
									CX.utils.float(
										fee?.amount
									)
								);
						}
					);
				}
			}
		);


		const subtotal =
			unitPrice
			+ printPrice
			+ (
				printFees
				/ quantity
			);


		const total =
			quantity
			* (
				unitPrice
				+ printPrice
			)
			+ printFees;


		return {

			unitPrice,

			printPrice,

			printFees,

			subtotal,

			total
		};
	}
};