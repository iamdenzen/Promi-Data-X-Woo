<?php

namespace PromiDataXWoo\Mcp\Tools;

use PromiDataXWoo\Pricing\ChangeSet;

defined( 'ABSPATH' ) || exit;

final class PreviewPricingChangesTool extends AbstractPricingTool {

	public function name(): string {
		return 'pdxw_preview_pricing_changes';
	}

	public function description(): string {
		return 'Previews pricing changes WITHOUT saving anything: returns how many products each change affects (locked products and products with fixed prices are skipped and counted separately) with example old -> new prices, plus a token. Save exactly what was previewed with pdxw_apply_pricing_changes(token). A change is one of: {type:"markup", part, level, target, percent|null}, {type:"fixed_price", product_id, variation_id?, kind, ref_id?, qty, price|null}, {type:"lock", product_id, locked:true|false}. Admin only.';
	}

	public function input_schema(): array {

		return [
			'type'       => 'object',
			'required'   => [ 'changes' ],
			'properties' => [
				'changes' => [
					'type'        => 'array',
					'description' => 'Changes to preview (max 500). See the tool description for the three shapes.',
					'items'       => [ 'type' => 'object' ],
				],
			],
		];
	}

	public function annotations(): array {
		return [ 'readOnlyHint' => true ];
	}

	public function execute( array $arguments = [] ): array {

		$this->boot();

		return $this->respond(
			ChangeSet::preview( (array) ( $arguments['changes'] ?? [] ) )
		);
	}
}
