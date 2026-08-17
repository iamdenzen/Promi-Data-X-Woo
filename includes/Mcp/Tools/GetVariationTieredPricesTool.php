<?php

namespace PromiDataXWoo\Mcp\Tools;

use PromiDataXWoo\Pricing\Pricing;
use WC_Product_Variation;
use WP_MCP_Server\Tools\Contracts\ToolInterface;

defined( 'ABSPATH' ) || exit;

/**
 * MCP tool exposing calculated quantity-tier prices for exactly one
 * WooCommerce variation.
 *
 * This store defines pricing per variation, never on the parent product,
 * so both product_id and variation_id are required — there is no
 * "parent-level" fallback to fall back to.
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
final class GetVariationTieredPricesTool implements ToolInterface {

	private Pricing $pricing;


	public function __construct( Pricing $pricing ) {
		$this->pricing = $pricing;
	}


	public function name(): string {
		return 'pdxw_get_variation_tiered_prices';
	}


	public function description(): string {
		return 'Gets the calculated quantity-tier selling prices and cost basis for exactly one WooCommerce product variation. Both product_id AND variation_id are REQUIRED — this store defines pricing per variation, not on the parent product, so there is no product-level price to fall back to. If you do not already know the variation_id, call pdxw_get_product_variations_tiered_prices first to list every variation of the product with its ID.';
	}


	public function input_schema(): array {

		return [
			'type'       => 'object',
			'required'   => [
				'product_id',
				'variation_id',
			],
			'properties' => [
				'product_id'   => [
					'type'        => 'integer',
					'description' => 'WooCommerce parent product ID.',
				],
				'variation_id' => [
					'type'        => 'integer',
					'description' => 'WooCommerce variation ID. Required — pricing is defined per variation. Look it up via pdxw_get_product_variations_tiered_prices if unknown.',
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

		if ( ! $variation_id ) {

			return self::error_response(
				'Missing or invalid variation_id. This tool requires a specific variation — use pdxw_get_product_variations_tiered_prices to list variation IDs for a product.'
			);
		}

		$variation = wc_get_product( $variation_id );

		if (
			! $variation instanceof WC_Product_Variation
			|| $variation->get_parent_id() !== $product_id
		) {

			return self::error_response(
				'variation_id does not belong to product_id.'
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
	 * quantity tier belonging to exactly this variation.
	 */
	public function tiers_for(
		int $product_id,
		int $variation_id
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
