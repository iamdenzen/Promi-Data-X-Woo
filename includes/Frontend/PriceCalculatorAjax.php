<?php

namespace PromiDataXWoo\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX transport for the xsimpress-core price-calculator shortcode(s).
 *
 * Deliberately separate from Ajax (cx_get_data / cx_add_to_cart), which is
 * the legacy product-page configurator's transport contract. This is a
 * new, independent contract so future calculator layouts can share it
 * without being coupled to the old configurator's request/response shape.
 */
final class PriceCalculatorAjax {

	public const NONCE_ACTION = 'xsimpress_price_calculator';

	public const QUOTE_ACTION = 'xsimpress_price_calculator_quote';


	private PriceCalculator $price_calculator;

	private bool $initialized = false;


	public function __construct(
		PriceCalculator $price_calculator
	) {
		$this->price_calculator = $price_calculator;
	}


	/**
	 * Register the AJAX endpoint.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		add_action(
			'wp_ajax_' . self::QUOTE_ACTION,
			[ $this, 'handle_quote' ]
		);

		add_action(
			'wp_ajax_nopriv_' . self::QUOTE_ACTION,
			[ $this, 'handle_quote' ]
		);
	}


	/**
	 * Return a fresh price quote for a product/quantity/print-option
	 * combination.
	 *
	 * Request:
	 *
	 *     nonce, product_id, quantity?, option_id?
	 *
	 * The server clamps quantity to the applicable minimum order quantity
	 * and resolves a missing/invalid option_id to the first available
	 * print option, then returns the quantity/option it actually used —
	 * callers should always reflect that back rather than trusting their
	 * own last-known values.
	 */
	public function handle_quote(): void {

		check_ajax_referer(
			self::NONCE_ACTION,
			'nonce'
		);

		$product_id = isset( $_POST['product_id'] )
			? absint( $_POST['product_id'] )
			: 0;

		if ( ! $product_id ) {

			wp_send_json_error(
				[ 'message' => 'invalid_product' ]
			);
		}

		$quantity = isset( $_POST['quantity'] )
			? absint( $_POST['quantity'] )
			: null;

		$option_id = isset( $_POST['option_id'] )
			? absint( $_POST['option_id'] )
			: null;

		$quote = $this->price_calculator->quote(
			$product_id,
			$quantity,
			$option_id ?: null
		);

		if ( null === $quote ) {

			wp_send_json_error(
				[ 'message' => 'invalid_product' ]
			);
		}

		wp_send_json_success( $quote );
	}


	public function is_initialized(): bool {
		return $this->initialized;
	}
}
