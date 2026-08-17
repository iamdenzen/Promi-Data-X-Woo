<?php

namespace PromiDataXWoo\Mcp\Tools;

use PromiDataXWoo\Printing\Printing;
use WP_MCP_Server\Tools\Contracts\ToolInterface;

defined( 'ABSPATH' ) || exit;

/**
 * MCP tool exposing print positions/options/prices/fees for a product.
 *
 * Reuses Printing\Configurator::get_config(), the same source the product
 * page and configurator UI render from, so prices and fees are already run
 * through the real markup pipeline rather than Promi's raw figures.
 *
 * This class is only ever loaded once Mcp::init() has confirmed
 * \WP_MCP_Server\Tools\Contracts\ToolInterface exists, so the "implements"
 * clause below is safe even when the MCP Server framework isn't installed.
 */
final class GetPrintConfigTool implements ToolInterface {

	private Printing $printing;


	public function __construct( Printing $printing ) {
		$this->printing = $printing;
	}


	public function name(): string {
		return 'pdxw_get_print_config';
	}


	public function description(): string {
		return 'Gets available print positions, decoration options, and their calculated selling prices and fees for a WooCommerce product or variation.';
	}


	public function input_schema(): array {

		return [
			'type'       => 'object',
			'required'   => [ 'product_id' ],
			'properties' => [
				'product_id'   => [
					'type'        => 'integer',
					'description' => 'WooCommerce parent product ID.',
				],
				'variation_id' => [
					'type'        => 'integer',
					'description' => 'Optional WooCommerce variation ID.',
					'default'     => 0,
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

		$variation_id = isset( $arguments['variation_id'] )
			? absint( $arguments['variation_id'] )
			: 0;

		if ( ! $product_id ) {

			return self::error_response(
				'Missing or invalid product_id.'
			);
		}

		if ( ! wc_get_product( $product_id ) ) {

			return self::error_response(
				'Product not found.'
			);
		}

		$data = [
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'positions'    => $this->config_for(
				$product_id,
				$variation_id
			),
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


	/**
	 * Print positions/options for one product or variation.
	 *
	 * Configurator::get_config() already falls back to parent-level
	 * positions when a variation has none of its own, so that behavior is
	 * inherited here unchanged.
	 */
	public function config_for(
		int $product_id,
		int $variation_id = 0
	): array {

		return $this->printing
			->configurator()
			->get_config(
				$product_id,
				$variation_id
			);
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
