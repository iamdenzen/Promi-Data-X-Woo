<?php

namespace PromiDataXWoo\Mcp\Tools;

use PromiDataXWoo\Mcp\VariationSummaries;
use WP_MCP_Server\Tools\Contracts\ToolInterface;

defined( 'ABSPATH' ) || exit;

/**
 * MCP tool listing every variation of a WooCommerce variable product, each
 * with its own print positions/options/prices/fees.
 *
 * The parent product itself never carries print configuration in this
 * store — every configuration is defined per variation — so the "parent"
 * entry only identifies the product (name/SKU), never print data.
 *
 * This class is only ever loaded once Mcp::init() has confirmed
 * \WP_MCP_Server\Tools\Contracts\ToolInterface exists, so the "implements"
 * clause below is safe even when the MCP Server framework isn't installed.
 */
final class GetProductVariationsPrintConfigTool implements ToolInterface {

	private GetVariationPrintConfigTool $variation_tool;


	public function __construct(
		GetVariationPrintConfigTool $variation_tool
	) {
		$this->variation_tool = $variation_tool;
	}


	public function name(): string {
		return 'pdxw_get_product_variations_print_config';
	}


	public function description(): string {
		return 'Lists every WooCommerce variation of a variable product, each with its own name, SKU, attributes (e.g. Color/Size), and available print positions/decoration options with their calculated selling prices and fees. Only product_id is required. The parent product itself is identified by name/SKU only and never carries print configuration — this store always configures printing per variation. Use this to discover a product\'s variations and their print options in one call; use pdxw_get_variation_print_config when you already know a specific variation_id.';
	}


	public function input_schema(): array {

		return [
			'type'       => 'object',
			'required'   => [ 'product_id' ],
			'properties' => [
				'product_id' => [
					'type'        => 'integer',
					'description' => 'WooCommerce parent (variable) product ID.',
				],
			],
		];
	}


	public function output_schema(): ?array {
		return null;
	}


	public function annotations(): array {

		return [
			'readOnlyHint' => true,
		];
	}


	public function required_scopes(): array {
		return [ 'woocommerce:read' ];
	}


	public function execute( array $arguments = [] ): array {

		$product_id = isset( $arguments['product_id'] )
			? absint( $arguments['product_id'] )
			: 0;

		if ( ! $product_id ) {

			return self::error_response(
				'Missing or invalid product_id.'
			);
		}

		$summary = VariationSummaries::for_product(
			$product_id
		);

		if ( null === $summary ) {

			return self::error_response(
				'Product not found or is not a variable product.'
			);
		}

		$variations = array_map(
			function ( array $variation ) use ( $product_id ): array {

				$variation['positions'] =
					$this->variation_tool->config_for(
						$product_id,
						$variation['variation_id']
					);

				return $variation;
			},
			$summary['variations']
		);

		$data = [
			'product_id' => $product_id,
			'parent'     => $summary['parent'],
			'variations' => $variations,
		];

		return [
			'content' => [
				[
					'type' => 'text',
					'text' => wp_json_encode(
						$data,
						JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
					),
				],
			],
		];
	}


	private static function error_response(
		string $message
	): array {

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
}
