<?php

namespace PromiDataXWoo\Mcp;

use PromiDataXWoo\Core\Plugin;
use PromiDataXWoo\Mcp\Tools\GetProductVariationsPrintConfigTool;
use PromiDataXWoo\Mcp\Tools\GetProductVariationsTieredPricesTool;
use PromiDataXWoo\Mcp\Tools\GetVariationPrintConfigTool;
use PromiDataXWoo\Mcp\Tools\GetVariationTieredPricesTool;
use PromiDataXWoo\Pricing\Pricing;
use PromiDataXWoo\Printing\Printing;
use WC_Product;
use WC_Product_Variation;

defined( 'ABSPATH' ) || exit;

/**
 * MCP (Model Context Protocol) integration.
 *
 * Exposes tiered pricing and print configuration data as MCP tools when a
 * WP MCP Server framework plugin is active, so an AI agent can look up a
 * variation's calculated quantity-tier prices and print options directly.
 *
 * Four tools are registered:
 *
 * - pdxw_get_variation_tiered_prices / pdxw_get_variation_print_config
 *   Single-variation lookup. product_id AND variation_id are both
 *   required, since this store prices and configures printing per
 *   variation — there is no parent-level data to fall back to.
 *
 * - pdxw_get_product_variations_tiered_prices /
 *   pdxw_get_product_variations_print_config
 *   Lists every variation of a product (name, SKU, attributes) together
 *   with its own pricing/print data in one call. product_id only.
 *
 * Entirely optional: when no MCP Server framework is present,
 * \WP_MCP_Server\Tools\Contracts\ToolInterface never exists, init() detects
 * that and the module does nothing else. The tool classes are only ever
 * instantiated after that check passes, so their "implements
 * ToolInterface" clause never gets a chance to fatal on a class that isn't
 * there.
 */
final class Mcp {

	private Plugin $plugin;

	private Pricing $pricing;

	private Printing $printing;

	private ?GetVariationTieredPricesTool $variation_tiered_prices_tool = null;

	private ?GetVariationPrintConfigTool $variation_print_config_tool = null;

	private ?GetProductVariationsTieredPricesTool $product_variations_tiered_prices_tool = null;

	private ?GetProductVariationsPrintConfigTool $product_variations_print_config_tool = null;

	private bool $initialized = false;


	public function __construct(
		Plugin $plugin,
		Pricing $pricing,
		Printing $printing
	) {
		$this->plugin   = $plugin;
		$this->pricing  = $pricing;
		$this->printing = $printing;
	}


	/**
	 * Register with the MCP Server framework, if one is active.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		if (
			! interface_exists(
				'\WP_MCP_Server\Tools\Contracts\ToolInterface'
			)
		) {
			return;
		}

		$this->variation_tiered_prices_tool =
			new GetVariationTieredPricesTool(
				$this->pricing
			);

		$this->variation_print_config_tool =
			new GetVariationPrintConfigTool(
				$this->printing
			);

		$this->product_variations_tiered_prices_tool =
			new GetProductVariationsTieredPricesTool(
				$this->variation_tiered_prices_tool
			);

		$this->product_variations_print_config_tool =
			new GetProductVariationsPrintConfigTool(
				$this->variation_print_config_tool
			);

		add_action(
			'wp_mcp_server_register_tools',
			[ $this, 'register_tools' ]
		);

		add_filter(
			'wp_mcp_server_woocommerce_variation_data',
			[ $this, 'inject_variation_data' ],
			10,
			3
		);

		do_action(
			'pdxw_mcp_init',
			$this
		);
	}


	/**
	 * Register this plugin's tools with the MCP tool registry.
	 *
	 * @param \WP_MCP_Server\Tools\ToolRegistry $registry
	 */
	public function register_tools( $registry ): void {

		if (
			! $this->variation_tiered_prices_tool
			|| ! $this->variation_print_config_tool
			|| ! $this->product_variations_tiered_prices_tool
			|| ! $this->product_variations_print_config_tool
		) {
			return;
		}

		$registry->register(
			$this->variation_tiered_prices_tool
		);

		$registry->register(
			$this->variation_print_config_tool
		);

		$registry->register(
			$this->product_variations_tiered_prices_tool
		);

		$registry->register(
			$this->product_variations_print_config_tool
		);
	}


	/**
	 * Append tiered pricing / print config data to the MCP Server's own
	 * WooCommerce variation data payload, so an agent already looking at a
	 * variation gets this without a separate tool call.
	 *
	 * Both tools require a real variation_id — this store never has
	 * pricing/print data at the parent level — so nothing is injected when
	 * the payload isn't actually about a specific variation.
	 */
	public function inject_variation_data(
		array $data,
		WC_Product $product,
		array $context
	): array {

		if (
			! $this->variation_tiered_prices_tool
			|| ! $this->variation_print_config_tool
		) {
			return $data;
		}

		$variation_id = isset( $context['variation_id'] )
			? absint( $context['variation_id'] )
			: 0;

		if (
			! $variation_id
			&& $product instanceof WC_Product_Variation
		) {
			$variation_id = $product->get_id();
		}

		if ( ! $variation_id ) {
			return $data;
		}

		$product_id = isset( $context['product_id'] )
			? absint( $context['product_id'] )
			: 0;

		if ( ! $product_id ) {

			$product_id = $product instanceof WC_Product_Variation
				? $product->get_parent_id()
				: $product->get_id();
		}

		if ( ! $product_id ) {
			return $data;
		}

		$data['tiered_pricing'] =
			$this->variation_tiered_prices_tool->tiers_for(
				$product_id,
				$variation_id
			);

		$data['print_config'] =
			$this->variation_print_config_tool->config_for(
				$product_id,
				$variation_id
			);

		return $data;
	}


	/*
	|--------------------------------------------------------------------------
	| Module Dependencies
	|--------------------------------------------------------------------------
	*/

	public function pricing(): Pricing {
		return $this->pricing;
	}


	public function printing(): Printing {
		return $this->printing;
	}


	public function plugin(): Plugin {
		return $this->plugin;
	}


	/*
	|--------------------------------------------------------------------------
	| State
	|--------------------------------------------------------------------------
	*/

	public function is_initialized(): bool {
		return $this->initialized;
	}
}
