<?php

namespace PromiDataXWoo\Mcp\Tools;

use PromiDataXWoo\Pricing\ChangeSet;

defined( 'ABSPATH' ) || exit;

final class SetMarkupTool extends AbstractPricingTool {

	public function name(): string {
		return 'pdxw_set_markup';
	}

	public function description(): string {
		return 'Sets (or removes) markup percentages at any level for any of the three price parts. Parts: article, print_price, print_fee. Priority after fixed prices - article: product > supplier_category > supplier (SKU prefix, e.g. "A34") > category > default; print_price and print_fee: print_option > supplier_category > supplier > category > default (print parts have no product level). supplier_category is a single markup for a supplier AND a category together, target "A34:12" (sub-categories included). Pass one rule via part/level/target/percent, or many via "rules". percent null removes a rule. By default the change is applied immediately and the result lists how many products are affected with old -> new examples; pass apply=false to only get the preview and a token for pdxw_apply_pricing_changes. Locked products and products with fixed prices are never changed. Admin only.';
	}

	public function input_schema(): array {

		$rule = self::markup_schema();

		return [
			'type'       => 'object',
			'properties' => $rule['properties'] + [
				'rules' => [
					'type'        => 'array',
					'description' => 'Bulk: several rules in one call (max 500).',
					'items'       => $rule,
				],
				'apply' => [
					'type'        => 'boolean',
					'description' => 'true (default) saves immediately; false returns the preview only.',
				],
			],
		];
	}

	public function execute( array $arguments = [] ): array {

		$this->boot();

		$changes = array_map(
			static fn( $rule ) => [ 'type' => 'markup' ] + $rule,
			$this->items( $arguments, 'rules', [ 'part', 'level', 'target', 'percent' ] )
		);

		return $this->respond( ChangeSet::run( $changes, $this->apply_flag( $arguments ) ) );
	}
}
