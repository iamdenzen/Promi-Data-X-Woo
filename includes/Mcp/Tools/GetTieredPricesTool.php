<?php

namespace PromiDataXWoo\Mcp\Tools;

use PromiDataXWoo\Pricing\Pricing;
use WP_MCP_Server\Tools\Contracts\ToolInterface;

defined( 'ABSPATH' ) || exit;

/**
 * MCP tool exposing calculated quantity-tier prices for a product/variation.
 *
 * Deliberately returns the fully calculated customer selling price and its
 * effective cost basis (via Pricing\CostCalculator), never Promi's raw
 * RecommendedSellingPrice/GeneralBuyingPrice figures — those are source
 * data, not the storefront price, and handing an AI agent the wrong one
 * would mean it quotes customers incorrectly.
 *
 * This class is only ever loaded once Mcp::init() has confirmed
 * \WP_MCP_Server\Tools\Contracts\ToolInterface exists, so the "implements"
 * clause below is safe even when the MCP Server framework isn't installed.
 */
final class GetTieredPricesTool implements ToolInterface {

	private Pricing $pricing;


	public function __construct( Pricing $pricing ) {
		$this->pricing = $pricing;
	}


	public function name(): string {
		return 'pdxw_get_tiered_prices';
	}


	public function description(): string {
		return 'Gets calculated quantity-tier selling prices and their cost basis for a WooCommerce product or variation synced from Promi.';
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
					'description' => 'Optional WooCommerce variation ID. Omit for a simple product, or for a variable product\'s own parent-level tiers.',
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
			'tiers'        => $this->tiers_for(
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
	 * Calculated selling price + effective cost basis for every configured
	 * quantity tier belonging to exactly this product/variation.
	 *
	 * $variation_id is used literally (0 means "the parent product's own
	 * tiers", not "every variation combined") so the returned cost basis
	 * always matches the same target the price was calculated against —
	 * mixing tiers from different variations into one calculation would
	 * silently produce wrong numbers.
	 */
	public function tiers_for(
		int $product_id,
		int $variation_id = 0
	): array {

		$quantities = $this->pricing->quantities(
			$product_id,
			$variation_id
		);

		if ( empty( $quantities ) ) {
			return [];
		}

		$costs = $this->pricing->costs();

		$tiers = [];

		foreach ( $quantities as $quantity ) {

			$quantity = absint( $quantity );

			if ( ! $quantity ) {
				continue;
			}

			$result = $costs->calculate(
				$product_id,
				$variation_id,
				$quantity
			);

			$tiers[] = [
				'qty'           => $quantity,
				'status'        => $result['status'],
				'selling_price' => $result['article_price'],
				'cost_price'    => $result['cost'],
				'cost_source'   => $result['source'],
			];
		}

		return $tiers;
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
