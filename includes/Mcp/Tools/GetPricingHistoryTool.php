<?php

namespace PromiDataXWoo\Mcp\Tools;

use PromiDataXWoo\Pricing\History;

defined( 'ABSPATH' ) || exit;

final class GetPricingHistoryTool extends AbstractPricingTool {

	public function name(): string {
		return 'pdxw_get_pricing_history';
	}

	public function description(): string {
		return 'Lists pricing changes, newest first: date (UTC), who (WordPress user, or "MCP"), source (admin/rest/mcp), action (apply/lock/unlock), a summary of what changed, the number of products affected and the full change details. Paged with limit/offset; total is returned. Admin only.';
	}

	public function input_schema(): array {

		return [
			'type'       => 'object',
			'properties' => [
				'limit'  => [
					'type'        => 'integer',
					'description' => 'Entries per call (1-500, default 50).',
				],
				'offset' => [
					'type'        => 'integer',
					'description' => 'Entries to skip.',
				],
				'action' => [
					'type'        => 'string',
					'description' => 'Optional filter: apply, lock or unlock.',
				],
				'source' => [
					'type'        => 'string',
					'description' => 'Optional filter: admin, rest or mcp.',
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

		return $this->ok(
			History::list(
				(int) ( $arguments['limit'] ?? 50 ),
				max( 0, (int) ( $arguments['offset'] ?? 0 ) ),
				sanitize_key( (string) ( $arguments['action'] ?? '' ) ),
				sanitize_key( (string) ( $arguments['source'] ?? '' ) )
			)
		);
	}
}
