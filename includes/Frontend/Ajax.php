<?php

namespace PromiDataXWoo\Frontend;

use PromiDataXWoo\Printing\Printing;
use WC_Product_Variable;
use WC_Product_Variation;

defined( 'ABSPATH' ) || exit;

/**
 * Frontend AJAX controller.
 *
 * Replaces the AJAX responsibilities previously contained in CX_Product:
 *
 * - cx_get_data
 * - cx_add_to_cart
 *
 * This class is intentionally a transport/controller layer.
 *
 * Product state belongs in ProductData.
 * Printing validation belongs in Printing\Configurator.
 * Cart pricing belongs in Pricing + Printing.
 * Sample cart behavior belongs in Samples.
 */
final class Ajax {

	public const NONCE_ACTION = 'cxatc_nonce';

	public const NONCE_FIELD = 'security';

	public const GET_DATA_ACTION = 'cx_get_data';

	public const ADD_TO_CART_ACTION = 'cx_add_to_cart';

	public const SUBMIT_INQUIRY_ACTION = 'cx_submit_inquiry';

	public const SUBMIT_CART_INQUIRY_ACTION = 'cx_submit_cart_inquiry';


	private ProductData $product_data;

	private Printing $printing;

	private bool $initialized = false;


	public function __construct(
		ProductData $product_data,
		Printing $printing
	) {
		$this->product_data = $product_data;
		$this->printing     = $printing;
	}


	/**
	 * Register frontend AJAX endpoints.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;


		/*
		|--------------------------------------------------------------------------
		| Product / Variation State
		|--------------------------------------------------------------------------
		*/

		add_action(
			'wp_ajax_' . self::GET_DATA_ACTION,
			[
				$this,
				'get_data',
			]
		);

		add_action(
			'wp_ajax_nopriv_' . self::GET_DATA_ACTION,
			[
				$this,
				'get_data',
			]
		);


		/*
		|--------------------------------------------------------------------------
		| Add to Cart
		|--------------------------------------------------------------------------
		*/

		add_action(
			'wp_ajax_' . self::ADD_TO_CART_ACTION,
			[
				$this,
				'add_to_cart',
			]
		);

		add_action(
			'wp_ajax_nopriv_' . self::ADD_TO_CART_ACTION,
			[
				$this,
				'add_to_cart',
			]
		);


		/*
		|--------------------------------------------------------------------------
		| Price-on-Request Inquiries
		|--------------------------------------------------------------------------
		*/

		add_action(
			'wp_ajax_' . self::SUBMIT_INQUIRY_ACTION,
			[
				$this,
				'submit_inquiry',
			]
		);

		add_action(
			'wp_ajax_nopriv_' . self::SUBMIT_INQUIRY_ACTION,
			[
				$this,
				'submit_inquiry',
			]
		);


		add_action(
			'wp_ajax_' . self::SUBMIT_CART_INQUIRY_ACTION,
			[
				$this,
				'submit_cart_inquiry',
			]
		);

		add_action(
			'wp_ajax_nopriv_' . self::SUBMIT_CART_INQUIRY_ACTION,
			[
				$this,
				'submit_cart_inquiry',
			]
		);


		do_action(
			'pdxw_frontend_ajax_init',
			$this
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Product Data
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return frontend state for a product / variation.
	 *
	 * Existing JS request:
	 *
	 * {
	 *     action:       "cx_get_data",
	 *     product_id:   ...,
	 *     variation_id: ...,
	 *     attributes:   ...,
	 *     security:     ...
	 * }
	 *
	 * Existing response contract:
	 *
	 * {
	 *     product_id,
	 *     variation_id,
	 *     attributes,
	 *     sku,
	 *     tiers,
	 *     positions,
	 *     min_order_qty,
	 *     qty_increments
	 * }
	 */
	public function get_data(): void {

		$this->verify_nonce();


		$product_id =
			absint(
				$_POST[
					'product_id'
				] ?? 0
			);

		$variation_id =
			absint(
				$_POST[
					'variation_id'
				] ?? 0
			);

		$attributes =
			$this->sanitize_attributes(
				$_POST[
					'attributes'
				] ?? []
			);


		/*
		|--------------------------------------------------------------------------
		| Product
		|--------------------------------------------------------------------------
		*/

		if ( ! $product_id ) {

			wp_send_json_error(
				'Invalid product.'
			);
		}

		$product =
			wc_get_product(
				$product_id
			);

		if ( ! $product ) {

			wp_send_json_error(
				'Invalid product.'
			);
		}


		/*
		|--------------------------------------------------------------------------
		| Explicit Variation Validation
		|--------------------------------------------------------------------------
		|
		| ProductData performs this check too, but rejecting an unrelated
		| variation here gives the request layer an explicit security boundary.
		*/

		if ( $variation_id ) {

			$variation =
				wc_get_product(
					$variation_id
				);

			if (
				! $variation instanceof WC_Product_Variation
				|| $variation->get_parent_id()
					!== $product_id
			) {
				$variation_id = 0;
			}
		}


		/*
		|--------------------------------------------------------------------------
		| State
		|--------------------------------------------------------------------------
		*/

		$data =
			$this->product_data
				->state(
					$product_id,
					$variation_id,
					$attributes
				);

		if ( ! empty( $data['invalid_combination'] ) ) {

			wp_send_json_error(
				'This combination is currently unavailable.'
			);
		}

		if ( empty( $data ) ) {

			wp_send_json_error(
				'Invalid product.'
			);
		}


		wp_send_json_success(
			$data
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Add to Cart
	|--------------------------------------------------------------------------
	*/

	/**
	 * Add a configured product to the WooCommerce cart.
	 *
	 * Existing request:
	 *
	 * {
	 *     product_id:     123,
	 *     variation_id:   456,
	 *     qty:            100,
	 *     print_positions: {
	 *         12: 7,
	 *         14: 9
	 *     }
	 * }
	 *
	 * Existing cart storage:
	 *
	 * [
	 *     'cx_print' => [
	 *         position_id => option_id,
	 *     ]
	 * ]
	 *
	 * Quantity 1 continues to mark the item as a sample.
	 */
	public function add_to_cart(): void {

		$this->verify_nonce();

		$this->ensure_cart();


		$product_id =
			absint(
				$_POST[
					'product_id'
				] ?? 0
			);

		$variation_id =
			absint(
				$_POST[
					'variation_id'
				] ?? 0
			);

		$quantity = max(
			1,
			absint(
				$_POST[
					'qty'
				] ?? 1
			)
		);


		if ( ! $product_id ) {

			wp_send_json_error(
				[
					'message' =>
						__(
							'Invalid product.',
							'promi-data-x-woo'
						),
				]
			);
		}

		$product =
			wc_get_product(
				$product_id
			);

		if ( ! $product ) {

			wp_send_json_error(
				[
					'message' =>
						__(
							'Invalid product.',
							'promi-data-x-woo'
						),
				]
			);
		}


		/*
		|--------------------------------------------------------------------------
		| Variation
		|--------------------------------------------------------------------------
		*/

		if (
			$product instanceof WC_Product_Variable
		) {

			if ( ! $variation_id ) {

				wp_send_json_error(
					[
						'message' =>
							__(
								'Please select a product variation.',
								'promi-data-x-woo'
							),
					]
				);
			}

			$variation =
				wc_get_product(
					$variation_id
				);

			if (
				! $variation instanceof WC_Product_Variation
				|| $variation->get_parent_id()
					!== $product_id
			) {

				wp_send_json_error(
					[
						'message' =>
							__(
								'Invalid product variation.',
								'promi-data-x-woo'
							),
					]
				);
			}

		} else {

			/*
			 * A simple product must not be allowed to smuggle an unrelated
			 * variation ID into the cart.
			 */
			$variation_id = 0;
		}


		/*
		|--------------------------------------------------------------------------
		| Purchasability
		|--------------------------------------------------------------------------
		*/

		$target =
			$variation_id
				? wc_get_product(
					$variation_id
				)
				: $product;

		if (
			! $target
			|| ! $target->is_purchasable()
		) {

			wp_send_json_error(
				[
					'message' =>
						__(
							'This product cannot currently be purchased.',
							'promi-data-x-woo'
						),
				]
			);
		}


		/*
		|--------------------------------------------------------------------------
		| Stock
		|--------------------------------------------------------------------------
		|
		| is_purchasable() does not check stock status, so a variation whose
		| supplier feed reported it out of stock could otherwise still reach
		| the cart via a direct AJAX request even though the UI blocks it.
		*/

		if ( ! $target->is_in_stock() ) {

			wp_send_json_error(
				[
					'message' =>
						__(
							'This product variation is currently out of stock.',
							'promi-data-x-woo'
						),
				]
			);
		}


		/*
		|--------------------------------------------------------------------------
		| Cart Item Data
		|--------------------------------------------------------------------------
		*/

		$cart_item_data = [];


		/*
		|--------------------------------------------------------------------------
		| Sample
		|--------------------------------------------------------------------------
		|
		| Preserve the actual current CX Product rule:
		|
		|     qty === 1
		|
		| means:
		|
		|     is_sample = 1
		|
		| Samples.php will later own the "(muster)" label and quantity lock.
		*/

		if ( 1 === $quantity ) {

			$cart_item_data[
				'is_sample'
			] = 1;
		}


		/*
		|--------------------------------------------------------------------------
		| Printing
		|--------------------------------------------------------------------------
		|
		| The previous AJAX handler:
		|
		| 1. sanitized position => option IDs
		| 2. loaded the selected options
		| 3. removed options whose min_order_qty exceeded the cart quantity
		| 4. stored the remaining selections as cx_print
		|
		| The rebuilt version additionally validates that each selected option
		| actually belongs to the submitted product/variation/position.
		*/

		$print_positions =
			$this->sanitize_print_positions(
				$_POST[
					'print_positions'
				] ?? []
			);

		if ( ! empty( $print_positions ) ) {

			$print_positions =
				$this->printing
					->configurator()
					->validate_selections(
						$product_id,
						$variation_id,
						$print_positions,
						$quantity
					);

			if ( ! empty( $print_positions ) ) {

				$cart_item_data[
					'cx_print'
				] = $print_positions;
			}
		}


		/*
		|--------------------------------------------------------------------------
		| Add
		|--------------------------------------------------------------------------
		*/

		$cart_item_key =
			WC()
				->cart
				->add_to_cart(
					$product_id,
					$quantity,
					$variation_id,
					[],
					$cart_item_data
				);

		if ( ! $cart_item_key ) {

			wp_send_json_error(
				[
					'message' =>
						__(
							'Product could not be added to the cart.',
							'promi-data-x-woo'
						),
				]
			);
		}


		/*
		|--------------------------------------------------------------------------
		| Persist Cart
		|--------------------------------------------------------------------------
		|
		| Preserve the behavior of the existing CX Product endpoint.
		*/

		WC()
			->cart
			->set_session();

		WC()
			->cart
			->maybe_set_cart_cookies();


		/*
		|--------------------------------------------------------------------------
		| Fragments
		|--------------------------------------------------------------------------
		|
		| The current events/cart.js expects:
		|
		|     res.data.fragments
		|
		| and replaces every selector returned in that object.
		*/

		$fragments =
			apply_filters(
				'woocommerce_add_to_cart_fragments',
				[]
			);


		do_action(
			'pdxw_frontend_product_added_to_cart',
			$cart_item_key,
			$product_id,
			$variation_id,
			$quantity,
			$cart_item_data
		);


		wp_send_json_success(
			[
				'message' =>
					__(
						'Produkt wurde in den Warenkorb gelegt.',
						'promi-data-x-woo'
					),

				'cart_url' =>
					wc_get_cart_url(),

				'cart_count' =>
					WC()
						->cart
						->get_cart_contents_count(),

				'fragments' =>
					$fragments,
			]
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Price-on-Request Inquiries
	|--------------------------------------------------------------------------
	*/

	/**
	 * Submit a price-on-request inquiry.
	 *
	 * Existing request:
	 *
	 * {
	 *     product_id:   123,
	 *     variation_id: 456,
	 *     qty:          100,
	 *     name:         "...",
	 *     email:        "...",
	 *     phone:        "...",
	 *     message:      "...",
	 *     print_positions: {
	 *         12: 7
	 *     }
	 * }
	 */
	public function submit_inquiry(): void {

		$this->verify_nonce();


		$product_id =
			absint(
				$_POST['product_id']
					?? 0
			);

		$variation_id =
			absint(
				$_POST['variation_id']
					?? 0
			);

		$quantity =
			absint(
				$_POST['qty']
					?? 0
			);

		$print_positions =
			$this->sanitize_print_positions(
				$_POST['print_positions']
					?? []
			);


		$result =
			Inquiries::submit(
				[
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
					'quantity'     => $quantity,
					'name'         => wp_unslash( $_POST['name'] ?? '' ),
					'email'        => wp_unslash( $_POST['email'] ?? '' ),
					'phone'        => wp_unslash( $_POST['phone'] ?? '' ),
					'message'      => wp_unslash( $_POST['message'] ?? '' ),
					'selections'   => $print_positions,
				]
			);


		if ( is_wp_error( $result ) ) {

			wp_send_json_error(
				[
					'message' =>
						$result->get_error_message(),
				],
				400
			);
		}


		wp_send_json_success(
			[
				'message' =>
					__(
						'Thank you — your request has been sent. We will get back to you shortly.',
						'promi-data-x-woo'
					),
			]
		);
	}


	/**
	 * Submit a quote request for the whole cart.
	 *
	 * Cart lines and totals are always read from WC()->cart server-side;
	 * only the customer details are taken from the request.
	 */
	public function submit_cart_inquiry(): void {

		$this->verify_nonce();

		// Honeypot: real users never fill this hidden field.
		if ( ! empty( $_POST['website'] ) ) {

			wp_send_json_success(
				[
					'message' => __( 'Thank you — your request has been sent. We will get back to you shortly.', 'promi-data-x-woo' ),
				]
			);
		}

		if ( empty( $_POST['consent'] ) ) {

			wp_send_json_error(
				[ 'message' => __( 'Please accept the privacy policy.', 'promi-data-x-woo' ) ],
				400
			);
		}

		$ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) $_SERVER['REMOTE_ADDR'] ) : '';
		$rate_key = 'pdxw_cart_inq_' . md5( $ip );
		$count    = (int) get_transient( $rate_key );

		if ( $count >= 5 ) {

			wp_send_json_error(
				[ 'message' => __( 'Too many requests. Please try again later.', 'promi-data-x-woo' ) ],
				429
			);
		}

		$this->ensure_cart();

		$cart = WC()->cart;

		if ( $cart->is_empty() ) {

			wp_send_json_error(
				[ 'message' => __( 'Your cart is empty.', 'promi-data-x-woo' ) ],
				400
			);
		}

		$pricing_cart = ( function_exists( 'pdxw' ) && pdxw() && pdxw()->pricing() )
			? pdxw()->pricing()->cart()
			: null;

		$items     = [];
		$total_qty = 0;

		foreach ( $cart->get_cart() as $cart_item ) {

			$product = $cart_item['data'] ?? null;

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$qty     = absint( $cart_item['quantity'] ?? 0 );
			$details = [];

			if ( $product instanceof WC_Product_Variation ) {

				foreach ( $product->get_variation_attributes() as $attr_key => $attr_value ) {

					if ( '' === $attr_value ) {
						continue;
					}

					$taxonomy = str_replace( 'attribute_', '', $attr_key );
					$term     = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', $attr_value, $taxonomy ) : false;

					$details[] = sprintf(
						'%s: %s',
						wc_attribute_label( $taxonomy ),
						$term && ! is_wp_error( $term ) ? $term->name : $attr_value
					);
				}
			}

			foreach ( $this->printing->cart()->selections( $cart_item ) as $position_id => $selection ) {

				$position = $this->printing->positions()->find( absint( $position_id ) );
				$option   = $this->printing->options()->find( absint( $selection['option_id'] ?? 0 ) );

				if ( ! $position || ! $option ) {
					continue;
				}

				$colors = absint( $selection['colors'] ?? 0 );

				$details[] = sprintf(
					'%s · %s%s',
					$option->name,
					$position->position_label,
					$colors ? ' (' . $colors . ' ' . __( 'colors', 'promi-data-x-woo' ) . ')' : ''
				);
			}

			$line_total = $pricing_cart
				? $pricing_cart->breakdown_value( $cart_item, 'line_total' )
				: (float) ( $cart_item['line_total'] ?? 0 );

			$items[] = [
				'product_id'   => (int) $product->get_parent_id() ?: (int) $product->get_id(),
				'variation_id' => absint( $cart_item['variation_id'] ?? 0 ),
				'name'         => wp_strip_all_tags( $product->get_name() ),
				'sku'          => (string) $product->get_sku(),
				'quantity'     => $qty,
				'line_total'   => round( $line_total, 2 ),
				'details'      => $details,
			];

			$total_qty += $qty;
		}

		if ( ! $items ) {

			wp_send_json_error(
				[ 'message' => __( 'Your cart is empty.', 'promi-data-x-woo' ) ],
				400
			);
		}

		$net_total = (float) $cart->get_total( 'edit' ) - (float) $cart->get_total_tax();

		$result = Inquiries::submit(
			[
				'source'       => 'cart',
				'product_id'   => 0,
				'variation_id' => 0,
				'quantity'     => $total_qty,
				'name'         => wp_unslash( $_POST['name'] ?? '' ),
				'email'        => wp_unslash( $_POST['email'] ?? '' ),
				'phone'        => wp_unslash( $_POST['phone'] ?? '' ),
				'company'      => wp_unslash( $_POST['company'] ?? '' ),
				'desired_date' => wp_unslash( $_POST['desired_date'] ?? '' ),
				'message'      => wp_unslash( $_POST['message'] ?? '' ),
				'items'        => $items,
				'total'        => $net_total,
			]
		);

		if ( is_wp_error( $result ) ) {

			wp_send_json_error(
				[ 'message' => $result->get_error_message() ],
				400
			);
		}

		set_transient( $rate_key, $count + 1, HOUR_IN_SECONDS );

		wp_send_json_success(
			[
				'message' => __( 'Thank you — your request has been sent. We will get back to you shortly.', 'promi-data-x-woo' ),
			]
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Request Validation
	|--------------------------------------------------------------------------
	*/

	/**
	 * Validate the frontend nonce.
	 *
	 * Assets.php intentionally continues creating:
	 *
	 *     wp_create_nonce( 'cxatc_nonce' )
	 *
	 * because the migrated JS currently submits it as:
	 *
	 *     security: cxatc_vars.nonce
	 */
	private function verify_nonce(): void {

		check_ajax_referer(
			self::NONCE_ACTION,
			self::NONCE_FIELD
		);
	}


	/**
	 * Ensure the WooCommerce cart/session has been initialized.
	 *
	 * Normally WooCommerce does this before admin-ajax requests, but this
	 * guard makes the endpoint safe when invoked in unusual integrations.
	 */
	private function ensure_cart(): void {

		if (
			null !== WC()->cart
		) {
			return;
		}

		if (
			function_exists(
				'wc_load_cart'
			)
		) {
			wc_load_cart();
		}

		if (
			null === WC()->cart
		) {

			wp_send_json_error(
				[
					'message' =>
						__(
							'WooCommerce cart is unavailable.',
							'promi-data-x-woo'
						),
				]
			);
		}
	}


	/*
	|--------------------------------------------------------------------------
	| Sanitization
	|--------------------------------------------------------------------------
	*/

	/**
	 * Sanitize submitted WooCommerce variation attributes.
	 */
	private function sanitize_attributes(
		mixed $raw
	): array {

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$raw =
			wp_unslash(
				$raw
			);

		$attributes = [];

		foreach (
			$raw as $key => $value
		) {

			if (
				! is_scalar(
					$value
				)
			) {
				continue;
			}

			$key =
				sanitize_title(
					(string) $key
				);

			$value =
				sanitize_title(
					(string) $value
				);

			if (
				'' === $key
				|| '' === $value
			) {
				continue;
			}

			$attributes[
				$key
			] = $value;
		}

		return $attributes;
	}


	/**
	 * Sanitize submitted print selections.
	 *
	 * Canonical structure remains:
	 *
	 * [
	 *     position_id => option_id,
	 * ]
	 */
	private function sanitize_print_positions(
		mixed $raw
	): array {

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$positions = [];

		foreach (
			$raw as $position_id => $option_id
		) {

			$position_id =
				absint(
					$position_id
				);

			$option_id =
				absint(
					$option_id
				);

			if (
				! $position_id
				|| ! $option_id
			) {
				continue;
			}

			$positions[
				$position_id
			] = $option_id;
		}

		return $positions;
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
