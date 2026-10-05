<?php

namespace PromiDataXWoo\Suppliers\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Optional capability for adapters whose supplier publishes a separate
 * print/decoration price feed.
 *
 * Supplier print feeds are keyed by the supplier's own print code (what
 * Promi exposes as SupplierSku / ImprintCosts[].SupplierSku and we store in
 * cx_print_options.supplier_print_code), never by our Promi SKU.
 */
interface PrintPriceProvider {

	/**
	 * Normalized print prices keyed by supplier print code.
	 *
	 * "variants" are keyed by colour count (int) for colour-dependent codes,
	 * or by 0 for codes whose price does not depend on colours. Codes that
	 * depend on anything else (e.g. logo size) must be omitted so they keep
	 * their Promi pricing.
	 *
	 * "prices" is a quantity-break ladder (min_qty => purchase price);
	 * "setup" is the one-off setup charge, or null when the supplier has none.
	 *
	 * @return array<string,array{
	 *     colors_dependent:bool,
	 *     variants:array<int,array{setup:?float,prices:array<int,float>}>
	 * }>|\WP_Error
	 */
	public function fetch_print_prices( object $source ): array|\WP_Error;
}
