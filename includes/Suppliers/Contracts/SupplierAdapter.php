<?php

namespace PromiDataXWoo\Suppliers\Contracts;

use PromiDataXWoo\Catalog\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * One supplier's fetch/normalize logic.
 *
 * Each supplier's API is structurally its own thing (query-by-SKU vs. bulk
 * GET vs. a deeply nested tree), so this contract is deliberately thin:
 * given the source's own config (endpoint/credential) and a way to look up
 * our existing SKUs, return normalized rows keyed by the FULL WooCommerce
 * SKU (prefix included) so Sync can match and apply them without knowing
 * anything about how they were produced.
 */
interface SupplierAdapter {

	/**
	 * purchase_price is a quantity-break "price ladder": a map of
	 * min_qty => price, e.g. [1 => 3.55, 100 => 3.51, 250 => 3.47]
	 * meaning that price applies from that quantity upward. `null` (or an
	 * empty array) means the supplier has no price data for this row.
	 *
	 * @return array<string,array{
	 *     stock_quantity:?int,
	 *     in_stock:?bool,
	 *     delivery_days:?int,
	 *     delivery_text:?string,
	 *     purchase_price:?array<int,float>
	 * }>|\WP_Error
	 */
	public function fetch( object $source, Catalog $catalog ): array|\WP_Error;
}
