<?php

namespace PromiDataXWoo\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * REST API for markups at every level, fixed prices, locks, preview/apply,
 * history and the per-product price report. Namespace pdxw/v1.
 *
 * Access: manage_woocommerce (cookie + X-WP-Nonce, or Application Password).
 *
 *   GET  /pricing/rules
 *   POST /pricing/preview        { changes: [...] }          nothing is saved
 *   POST /pricing/apply          { token }                   saves a preview
 *   POST /pricing/markups        { rules: [...] } | single   apply default true
 *   GET  /pricing/fixed-prices   ?product_id&limit&offset
 *   POST /pricing/fixed-prices   { prices: [...] } | single  apply default true
 *   GET  /pricing/locks          ?limit&offset
 *   POST /pricing/locks          { product_ids: [...], locked } | single
 *   GET  /pricing/history        ?limit&offset&action&source
 *   GET  /pricing/products/{id}/prices  ?offset&limit&include_print
 *
 * "apply": false on the write routes returns the preview only.
 */
final class PricingApi {

	private const NS = 'pdxw/v1';


	public static function init(): void {

		add_action( 'rest_api_init', [ new self(), 'register_routes' ] );
	}


	public function register_routes(): void {

		$routes = [
			'/pricing/rules'                      => [ 'GET', 'rules' ],
			'/pricing/preview'                    => [ 'POST', 'preview' ],
			'/pricing/apply'                      => [ 'POST', 'apply' ],
			'/pricing/markups'                    => [ 'POST', 'markups' ],
			'/pricing/history'                    => [ 'GET', 'history' ],
			'/pricing/products/(?P<id>\d+)/prices' => [ 'GET', 'product_prices' ],
		];

		foreach ( $routes as $route => [ $method, $handler ] ) {

			register_rest_route(
				self::NS,
				$route,
				[
					'methods'             => $method,
					'permission_callback' => [ $this, 'permission' ],
					'callback'            => [ $this, $handler ],
				]
			);
		}

		register_rest_route(
			self::NS,
			'/pricing/fixed-prices',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'permission_callback' => [ $this, 'permission' ],
					'callback'            => [ $this, 'fixed_prices' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'permission_callback' => [ $this, 'permission' ],
					'callback'            => [ $this, 'set_fixed_prices' ],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/pricing/locks',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'permission_callback' => [ $this, 'permission' ],
					'callback'            => [ $this, 'locks' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'permission_callback' => [ $this, 'permission' ],
					'callback'            => [ $this, 'set_locks' ],
				],
			]
		);
	}


	public function permission(): bool {

		// Entry point of this request, for the history log.
		History::set_source( History::SOURCE_REST );

		return current_user_can( 'manage_woocommerce' );
	}


	/*
	|--------------------------------------------------------------------------
	| Handlers
	|--------------------------------------------------------------------------
	*/

	public function rules(): \WP_REST_Response {

		$pricing = \PromiDataXWoo\Core\Plugin::instance()->pricing();

		return new \WP_REST_Response(
			self::list_rules( $pricing )
		);
	}

	public function preview( \WP_REST_Request $request ) {

		return $this->respond(
			ChangeSet::preview( (array) ( $request->get_json_params()['changes'] ?? [] ) )
		);
	}

	public function apply( \WP_REST_Request $request ) {

		return $this->respond(
			ChangeSet::apply( (string) ( $request->get_json_params()['token'] ?? '' ) )
		);
	}

	public function markups( \WP_REST_Request $request ) {

		$data  = $request->get_json_params();
		$rules = isset( $data['rules'] ) && is_array( $data['rules'] )
			? $data['rules']
			: [ $data ];

		$changes = array_map(
			static fn( $rule ) => [ 'type' => 'markup' ] + (array) $rule,
			$rules
		);

		return $this->respond(
			ChangeSet::run( $changes, ! array_key_exists( 'apply', $data ) || ! empty( $data['apply'] ) )
		);
	}

	public function fixed_prices( \WP_REST_Request $request ): \WP_REST_Response {

		return new \WP_REST_Response(
			[
				'items' => FixedPrices::list(
					absint( $request->get_param( 'product_id' ) ),
					min( 1000, max( 1, (int) ( $request->get_param( 'limit' ) ?: 200 ) ) ),
					max( 0, (int) $request->get_param( 'offset' ) )
				),
			]
		);
	}

	public function set_fixed_prices( \WP_REST_Request $request ) {

		$data   = $request->get_json_params();
		$prices = isset( $data['prices'] ) && is_array( $data['prices'] )
			? $data['prices']
			: [ $data ];

		$changes = array_map(
			static fn( $row ) => [ 'type' => 'fixed_price' ] + (array) $row,
			$prices
		);

		return $this->respond(
			ChangeSet::run( $changes, ! array_key_exists( 'apply', $data ) || ! empty( $data['apply'] ) )
		);
	}

	public function locks( \WP_REST_Request $request ): \WP_REST_Response {

		return new \WP_REST_Response(
			[
				'total' => PriceLocks::count(),
				'items' => PriceLocks::all(
					min( 1000, max( 1, (int) ( $request->get_param( 'limit' ) ?: 200 ) ) ),
					max( 0, (int) $request->get_param( 'offset' ) )
				),
			]
		);
	}

	public function set_locks( \WP_REST_Request $request ) {

		$data = $request->get_json_params();
		$ids  = isset( $data['product_ids'] ) && is_array( $data['product_ids'] )
			? $data['product_ids']
			: [ $data['product_id'] ?? 0 ];

		$locked = ! empty( $data['locked'] );

		$changes = array_map(
			static fn( $id ) => [
				'type'       => 'lock',
				'product_id' => $id,
				'locked'     => $locked,
			],
			$ids
		);

		return $this->respond(
			ChangeSet::run( $changes, ! array_key_exists( 'apply', $data ) || ! empty( $data['apply'] ) )
		);
	}

	public function history( \WP_REST_Request $request ): \WP_REST_Response {

		return new \WP_REST_Response(
			History::list(
				(int) ( $request->get_param( 'limit' ) ?: 50 ),
				max( 0, (int) $request->get_param( 'offset' ) ),
				sanitize_key( (string) $request->get_param( 'action' ) ),
				sanitize_key( (string) $request->get_param( 'source' ) )
			)
		);
	}

	public function product_prices( \WP_REST_Request $request ) {

		return $this->respond(
			ProductPriceReport::build(
				(int) $request['id'],
				(int) $request->get_param( 'offset' ),
				(int) ( $request->get_param( 'limit' ) ?: 25 ),
				null === $request->get_param( 'include_print' )
					|| rest_sanitize_boolean( $request->get_param( 'include_print' ) )
			)
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Shared
	|--------------------------------------------------------------------------
	*/

	/**
	 * Every active rule at every level and part, plus fixed prices and locks.
	 *
	 * @return array<string,mixed>
	 */
	public static function list_rules( ?Pricing $pricing ): array {

		return [
			'markups'      => $pricing ? $pricing->markup_rules()->all_rules() : [],
			'fixed_prices' => [
				'total'  => count( FixedPrices::list( 0, 100000 ) ),
				'sample' => FixedPrices::list( 0, 20 ),
			],
			// Fixed print fees for a print option / supplier / both.
			'fixed_fees'   => ScopedFixedFees::all(),
			'locks'        => [
				'total'  => PriceLocks::count(),
				'sample' => PriceLocks::all( 20 ),
			],
			'resolution'   => [
				'article'     => 'fixed price > product > supplier + category > supplier (SKU prefix) > category (deepest) > default',
				'print_price' => 'fixed price > print_option > supplier + category > supplier (SKU prefix) > category (deepest) > default',
				'print_fee'   => 'fixed price on the product > fixed fee rule (supplier + print option > print option > supplier) > print_option markup > supplier + category > supplier (SKU prefix) > category (deepest) > default',
				'note'        => 'Supplier + category is one markup for a supplier prefix AND a category together (level supplier_category, target "A34:12"); it is not the sum of the two.',
				'fixed'       => 'Fixed prices bypass discount, markup and rounding; locks freeze current prices as fixed prices.',
			],
		];
	}

	/**
	 * @param mixed $result
	 */
	private function respond( $result ) {

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result );
	}
}
