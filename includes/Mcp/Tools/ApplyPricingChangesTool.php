<?php

namespace PromiDataXWoo\Mcp\Tools;

use PromiDataXWoo\Pricing\ChangeSet;

defined( 'ABSPATH' ) || exit;

final class ApplyPricingChangesTool extends AbstractPricingTool {

	public function name(): string {
		return 'pdxw_apply_pricing_changes';
	}

	public function description(): string {
		return 'Applies exactly the changes of an earlier pdxw_preview_pricing_changes (or apply=false write call) by its token. Writes one history entry and reprices the affected products (large sets in the background). Tokens expire after one hour. Admin only.';
	}

	public function input_schema(): array {

		return [
			'type'       => 'object',
			'required'   => [ 'token' ],
			'properties' => [
				'token' => [
					'type'        => 'string',
					'description' => 'Token returned by the preview.',
				],
			],
		];
	}

	public function annotations(): array {

		return [
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		];
	}

	public function execute( array $arguments = [] ): array {

		$this->boot();

		return $this->respond( ChangeSet::apply( (string) ( $arguments['token'] ?? '' ) ) );
	}
}
