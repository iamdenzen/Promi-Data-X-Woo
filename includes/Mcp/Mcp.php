<?php

namespace PromiDataXWoo\Mcp;

use PromiDataXWoo\Core\Plugin;
use PromiDataXWoo\Mcp\Tools\GetPrintConfigTool;
use PromiDataXWoo\Mcp\Tools\GetTieredPricesTool;
use PromiDataXWoo\Pricing\Pricing;
use PromiDataXWoo\Printing\Printing;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * MCP (Model Context Protocol) integration.
 *
 * Exposes tiered pricing and print configuration data as MCP tools when a
 * WP MCP Server framework plugin is active, so an AI agent can look up a
 * product's calculated quantity-tier prices and print options directly.
 *
 * Entirely optional: when no MCP Server framework is present,
 * \WP_MCP_Server\Tools\Contracts\ToolInterface never exists, init() detects
 * that and the module does nothing else. The two tool classes are only
 * ever instantiated after that check passes, so their "implements
 * ToolInterface" clause never gets a chance to fatal on a class that isn't
 * there.
 */
final class Mcp {

	private Plugin $plugin;

	private Pricing $pricing;

	private Printing $printing;

	private ?GetTieredPricesTool $tiered_prices_tool = null;

	private ?GetPrintConfigTool $print_config_tool = null;

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

		$this->tiered_prices_tool = new GetTieredPricesTool(
			$this->pricing
		);

		$this->print_config_tool = new GetPrintConfigTool(
			$this->printing
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
			! $this->tiered_prices_tool
			|| ! $this->print_config_tool
		) {
			return;
		}

		$registry->register(
			$this->tiered_prices_tool
		);

		$registry->register(
			$this->print_config_tool
		);
	}


	/**
	 * Append tiered pricing / print config data to the MCP Server's own
	 * WooCommerce variation data payload, so an agent already looking at a
	 * variation gets this without a separate tool call.
	 */
	public function inject_variation_data(
		array $data,
		WC_Product $product,
		array $context
	): array {

		if (
			! $this->tiered_prices_tool
			|| ! $this->print_config_tool
		) {
			return $data;
		}

		$product_id = isset( $context['product_id'] )
			? absint( $context['product_id'] )
			: $product->get_id();

		$variation_id = isset( $context['variation_id'] )
			? absint( $context['variation_id'] )
			: 0;

		$data['tiered_pricing'] = $this->tiered_prices_tool->tiers_for(
			$product_id,
			$variation_id
		);

		$data['print_config'] = $this->print_config_tool->config_for(
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
