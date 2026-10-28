<?php

namespace PromiDataXWoo\Mcp\Tools;

use PromiDataXWoo\Core\Plugin;
use PromiDataXWoo\Pricing\PricingApi;

defined( 'ABSPATH' ) || exit;

final class ListPricingRulesTool extends AbstractPricingTool {

	public function name(): string {
		return 'pdxw_list_pricing_rules';
	}

	public function description(): string {
		return 'Lists every active pricing rule: markups for the three price parts (article, print_price, print_fee) at every level (default, category, supplier SKU prefix, supplier + category, print option, product), plus fixed-price and lock summaries and the precedence order. Admin only.';
	}

	public function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => new \stdClass(),
		];
	}

	public function annotations(): array {
		return [ 'readOnlyHint' => true ];
	}

	public function execute( array $arguments = [] ): array {

		$this->boot();

		return $this->ok( PricingApi::list_rules( Plugin::instance()->pricing() ) );
	}
}
