<?php

namespace PromiDataXWoo\Mcp\Tools;

use PromiDataXWoo\Pricing\History;
use WP_MCP_Server\Tools\Contracts\ToolInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Base for the admin pricing tools (markups, fixed prices, locks, preview,
 * history).
 *
 * All of them require the woocommerce:write scope. That scope can only be
 * granted on the OAuth authorize screen, which is restricted to users with
 * manage_options, so these tools are admin-only.
 *
 * Only loaded after Mcp::init() has confirmed ToolInterface exists.
 */
abstract class AbstractPricingTool implements ToolInterface {

	public function output_schema(): ?array {
		return null;
	}

	public function required_scopes(): array {
		return [ 'woocommerce:write' ];
	}

	public function annotations(): array {

		return [
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		];
	}


	/**
	 * Mark this request as an MCP call so history logs the actor as "MCP".
	 */
	protected function boot(): void {

		History::set_source( History::SOURCE_MCP );
	}

	/**
	 * @param mixed $data
	 */
	protected function ok( $data ): array {

		return [
			'content' => [
				[
					'type' => 'text',
					'text' => wp_json_encode(
						$data,
						JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
					),
				],
			],
		];
	}

	protected function fail( string $message ): array {

		return [
			'isError' => true,
			'content' => [
				[
					'type' => 'text',
					'text' => $message,
				],
			],
		];
	}

	/**
	 * @param mixed $result array|\WP_Error from the pricing services
	 */
	protected function respond( $result ): array {

		return is_wp_error( $result )
			? $this->fail( $result->get_error_message() )
			: $this->ok( $result );
	}

	protected function apply_flag( array $arguments ): bool {

		return ! array_key_exists( 'apply', $arguments ) || ! empty( $arguments['apply'] );
	}

	/**
	 * Items of a bulk argument, or the arguments themselves for a single call.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function items( array $arguments, string $key, array $single_keys ): array {

		if ( isset( $arguments[ $key ] ) && is_array( $arguments[ $key ] ) ) {
			return array_values( array_filter( $arguments[ $key ], 'is_array' ) );
		}

		return [ array_intersect_key( $arguments, array_flip( $single_keys ) ) ];
	}


	/**
	 * JSON schema of one markup rule.
	 */
	protected static function markup_schema(): array {

		return [
			'type'       => 'object',
			'required'   => [ 'part', 'level' ],
			'properties' => [
				'part'    => [
					'type'        => 'string',
					'enum'        => [ 'article', 'print_price', 'print_fee' ],
					'description' => 'Which price: article (product price tiers), print_price (print unit price tiers) or print_fee (setup/handling fees).',
				],
				'level'   => [
					'type'        => 'string',
					'enum'        => [ 'default', 'category', 'supplier', 'supplier_category', 'print_option', 'product' ],
					'description' => 'Where the rule applies. Priority after fixed prices - article: product > supplier_category > supplier > category > default. print_price/print_fee: print_option > supplier_category > supplier > category > default (no product level for print parts; use a fixed price). supplier_category is ONE markup for a supplier prefix AND a category together (includes sub-categories); it is not the sum of the supplier and category markups.',
				],
				'target'  => [
					'type'        => 'string',
					'description' => 'supplier: SKU prefix such as "A34". category: product_cat term id. supplier_category: "PREFIX:CATEGORY_ID", e.g. "A34:12". print_option: print option id. product: product id (article only). Omit for default.',
				],
				'percent' => [
					'type'        => [ 'number', 'null' ],
					'description' => 'Markup percent (0-1000). null removes the rule (not possible for default).',
				],
			],
		];
	}
}
