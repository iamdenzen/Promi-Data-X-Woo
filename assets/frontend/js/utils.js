window.CX = window.CX || {};

/**
 * Shared frontend utilities.
 */
CX.utils = {

	/**
	 * Format a numeric value using WooCommerce's configured
	 * currency formatting rules.
	 *
	 * Settings are localized by Frontend\Assets.php:
	 *
	 * cxatc_vars.settings = {
	 *     currency_symbol,
	 *     currency_position,
	 *     decimal_separator,
	 *     thousand_separator,
	 *     num_decimals
	 * }
	 */
	currency(value) {

		if (!window.cxatc_vars?.settings) {
			return value;
		}

		const settings = window.cxatc_vars.settings;

		const number = Number.parseFloat(value);

		if (!Number.isFinite(number)) {
			return value;
		}

		const decimals =
			Number.isFinite(
				Number.parseInt(
					settings.num_decimals,
					10
				)
			)
				? Number.parseInt(
					settings.num_decimals,
					10
				)
				: 2;

		const decimalSeparator =
			settings.decimal_separator || ".";

		const thousandSeparator =
			settings.thousand_separator || ",";

		const currencySymbol =
			settings.currency_symbol || "";

		const parts =
			number
				.toFixed(decimals)
				.split(".");

		let integerPart =
			parts[0];

		const decimalPart =
			parts[1] || "";

		integerPart =
			integerPart.replace(
				/\B(?=(\d{3})+(?!\d))/g,
				thousandSeparator
			);

		const formatted =
			decimalPart
				? integerPart
					+ decimalSeparator
					+ decimalPart
				: integerPart;

		switch (settings.currency_position) {

			case "right":

				return formatted
					+ currencySymbol;

			case "left_space":

				return currencySymbol
					+ " "
					+ formatted;

			case "right_space":

				return formatted
					+ " "
					+ currencySymbol;

			case "left":
			default:

				return currencySymbol
					+ formatted;
		}
	},


	/**
	 * Convert a value to a safe integer.
	 */
	int(value, fallback = 0) {

		const parsed =
			Number.parseInt(
				value,
				10
			);

		return Number.isFinite(parsed)
			? parsed
			: fallback;
	},


	/**
	 * Convert a value to a safe floating-point number.
	 */
	float(value, fallback = 0) {

		const parsed =
			Number.parseFloat(
				value
			);

		return Number.isFinite(parsed)
			? parsed
			: fallback;
	},


	/**
	 * Escape text before inserting it into generated HTML.
	 */
	escapeHtml(value) {

		return String(
			value ?? ""
		)
			.replaceAll(
				"&",
				"&amp;"
			)
			.replaceAll(
				"<",
				"&lt;"
			)
			.replaceAll(
				">",
				"&gt;"
			)
			.replaceAll(
				'"',
				"&quot;"
			)
			.replaceAll(
				"'",
				"&#039;"
			);
	}
};