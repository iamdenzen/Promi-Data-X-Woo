<?php

namespace PromiDataXWoo\Suppliers\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Optional capability for adapters whose supplier publishes a separate
 * print/decoration price feed.
 *
 * Supplier print feeds are keyed by the supplier's own print code (what
 * Promi exposes as SupplierSku / SupplierPrintCode and we store in
 * cx_print_options.supplier_print_code), never by our Promi SKU.
 */
interface PrintPriceProvider {

	/**
	 * Normalized print prices.
	 *
	 * "codes" is keyed by supplier print code. Each code says how one of its
	 * price variants is selected for a given print option (variant_by):
	 *
	 *  - "colors": variant key = the option's colour count (min 1)
	 *  - "range":  variant key = the option's supplier_sku with the print
	 *              code stripped from the front (e.g. "DLA" -> "A")
	 *  - "none":   a single variant, key "0"
	 *
	 * Codes priced by anything else (e.g. logo size) must be omitted so they
	 * keep their Promi pricing.
	 *
	 * "prices" is a quantity-break ladder (min_qty => purchase price);
	 * "setup" is the one-off setup charge per calculation unit, or null.
	 *
	 * "handling" maps a supplier fee SKU (cx_print_fees.supplier_sku) to the
	 * per-piece handling purchase price.
	 *
	 * @return array{
	 *     codes:array<string,array{
	 *         variant_by:string,
	 *         variants:array<string,array{setup:?float,prices:array<int,float>}>
	 *     }>,
	 *     handling:array<string,float>
	 * }|\WP_Error
	 */
	public function fetch_print_prices( object $source ): array|\WP_Error;
}
