<?php

namespace PromiDataXWoo\Mcp\Tools;

use PromiDataXWoo\Pricing\ProductPriceReport;

defined( 'ABSPATH' ) || exit;

final class GetProductPricesTool extends AbstractPricingTool {

	public function name(): string {
		return 'pdxw_get_product_prices';
	}

	public function description(): string {
		return 'Reads every price of a product variation by variation, with the pricing level that produced each price (default / supplier / category / print_option / product / fixed), the markup percent, cost source, lock state, print unit price tiers and print fees. Article tiers and print unit price tiers include purchase_price (raw supplier purchase price; null for fixed prices); print fees include purchase_amount plus unit_price_rounded (final customer amount: setup fees end in 9, others rounded to cents) and unit_price_unrounded (same amount before rounding). Returns ALL variations, paged: use offset/limit (limit up to 100) and follow next_offset until has_more is false; total is always returned. include_print=false returns article prices only (much smaller). Admin only.';
	}

	public function input_schema(): array {

		return [
			'type'       => 'object',
			'required'   => [ 'product_id' ],
			'properties' => [
				'product_id'    => [
					'type'        => 'integer',
					'description' => 'Parent product id.',
				],
				'offset'        => [
					'type'        => 'integer',
					'description' => 'Variations to skip (default 0).',
				],
				'limit'         => [
					'type'        => 'integer',
					'description' => 'Variations per call (1-100, default 25).',
				],
				'include_print' => [
					'type'        => 'boolean',
					'description' => 'Include print unit prices and fees (default true).',
				],
			],
		];
	}

	public function annotations(): array {
		return [ 'readOnlyHint' => true ];
	}

	public function required_scopes(): array {
		return [ 'woocommerce:read' ];
	}

	public function execute( array $arguments = [] ): array {

		$this->boot();

		return $this->respond(
			ProductPriceReport::build(
				absint( $arguments['product_id'] ?? 0 ),
				max( 0, (int) ( $arguments['offset'] ?? 0 ) ),
				(int) ( $arguments['limit'] ?? 25 ),
				! array_key_exists( 'include_print', $arguments ) || ! empty( $arguments['include_print'] )
			)
		);
	}
}
