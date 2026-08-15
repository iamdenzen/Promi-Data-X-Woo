window.CX = window.CX || {};

/**
 * Promi-Data X Woo frontend AJAX client.
 *
 * PHP endpoints:
 *
 * cx_get_data
 *     Frontend\Ajax::get_data()
 *
 * cx_add_to_cart
 *     Frontend\Ajax::add_to_cart()
 */
CX.api = {

	/**
	 * Fetch product / variation configuration.
	 *
	 * Typical payload:
	 *
	 * {
	 *     variation_id: 123,
	 *     attributes: {
	 *         attribute_pa_farbe: "schwarz"
	 *     }
	 * }
	 */
	updateVariation(payload = {}) {

		return jQuery.ajax({

			url:
				window.cxatc_vars.ajax_url,

			type:
				"POST",

			dataType:
				"json",

			data:
				Object.assign(
					{
						action:
							"cx_get_data",

						product_id:
							CX.state.product_id,

						security:
							window.cxatc_vars.nonce
					},
					payload
				)
		});
	},


	/**
	 * Add the currently configured product to WooCommerce.
	 *
	 * Typical payload:
	 *
	 * {
	 *     product_id: 123,
	 *     variation_id: 456,
	 *     qty: 100,
	 *     print_positions: {
	 *         12: 8,
	 *         14: 9
	 *     }
	 * }
	 */
	addToCart(payload = {}) {

		return jQuery.ajax({

			url:
				window.cxatc_vars.ajax_url,

			type:
				"POST",

			dataType:
				"json",

			data:
				Object.assign(
					{
						action:
							"cx_add_to_cart",

						security:
							window.cxatc_vars.nonce
					},
					payload
				)
		});
	},


	/**
	 * Submit a price-on-request inquiry.
	 *
	 * Typical payload:
	 *
	 * {
	 *     product_id: 123,
	 *     variation_id: 456,
	 *     qty: 100,
	 *     name: "...",
	 *     email: "...",
	 *     phone: "...",
	 *     message: "...",
	 *     print_positions: {
	 *         12: 8
	 *     }
	 * }
	 */
	submitInquiry(payload = {}) {

		return jQuery.ajax({

			url:
				window.cxatc_vars.ajax_url,

			type:
				"POST",

			dataType:
				"json",

			data:
				Object.assign(
					{
						action:
							"cx_submit_inquiry",

						security:
							window.cxatc_vars.nonce
					},
					payload
				)
		});
	}
};