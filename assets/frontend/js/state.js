window.CX = window.CX || {};

/**
 * Promi-Data X Woo frontend application state.
 *
 * This file is intentionally data-only.
 *
 * Events mutate state.
 * Renderers consume state.
 * API transfers state to/from WordPress.
 */

CX.state = {

	/*
	|--------------------------------------------------------------------------
	| Product
	|--------------------------------------------------------------------------
	*/

	product_id:
		Number.parseInt(
			window.cxatc_vars?.product_id
				|| 0,
			10
		) || 0,

	variation_id: 0,

	sku:
		String(
			window.cxatc_vars?.sku
				|| ""
		),


	/*
	|--------------------------------------------------------------------------
	| Variation Attributes
	|--------------------------------------------------------------------------
	|
	| Populated after variation resolution.
	|
	| Example:
	|
	| {
	|     pa_farbe: "schwarz",
	|     pa_groesse: "l"
	| }
	*/

	attrs: {},


	/*
	|--------------------------------------------------------------------------
	| Printing
	|--------------------------------------------------------------------------
	|
	| Backend/frontend contract:
	|
	| {
	|     position_id: {
	|         label,
	|         code,
	|         area,
	|         image,
	|         options: {
	|             option_id: {
	|                 name,
	|                 sku,
	|                 min_order_qty,
	|                 max_colors,
	|                 prices,
	|                 fees
	|             }
	|         }
	|     }
	| }
	*/

	positions: {},


	/*
	|--------------------------------------------------------------------------
	| Product Price Tiers
	|--------------------------------------------------------------------------
	*/

	tiers: [],


	/*
	|--------------------------------------------------------------------------
	| Quantity Rules
	|--------------------------------------------------------------------------
	*/

	min_order_qty: 1,

	qty_increments: 1,

	min_print_qty: 1,


	/*
	|--------------------------------------------------------------------------
	| Current Selection
	|--------------------------------------------------------------------------
	*/

	selectedPosition: null,

	/**
	 * Existing frontend format:
	 *
	 * [
	 *     {
	 *         position_id: 12,
	 *         printer_id: 45
	 *     }
	 * ]
	 *
	 * printer_id currently means print-option ID.
	 */
	selectedPrinters: [],

	selectedQty: null,


	/*
	|--------------------------------------------------------------------------
	| Quantity Entry Mode
	|--------------------------------------------------------------------------
	|
	| false:
	|     predefined quantity tier
	|
	| true:
	|     manually entered custom quantity
	*/

	isCustomQty: false,


	/*
	|--------------------------------------------------------------------------
	| Price on Request
	|--------------------------------------------------------------------------
	|
	| Set to true when the selected variation has no calculable purchase price.
	| Updated by handleVariationChange after each variation AJAX response.
	*/

	price_on_request: false
};