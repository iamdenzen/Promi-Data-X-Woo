<?php

namespace PromiDataXWoo\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * REST API for pricing markup rules.
 *
 * The API intentionally talks to Pricing services rather than directly
 * exposing database tables.
 */
final class RestController {

	private Pricing $pricing;

	private bool $initialized = false;


	public function __construct(
		Pricing $pricing
	) {
		$this->pricing =
			$pricing;
	}


	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		add_action(
			'rest_api_init',
			[
				$this,
				'register_routes',
			]
		);
	}


	public function register_routes(): void {

		/*
		|--------------------------------------------------------------------------
		| Default Markups
		|--------------------------------------------------------------------------
		*/

		register_rest_route(
			'pdxw/v1',
			'/pricing/defaults',
			[
				'methods' =>
					\WP_REST_Server::READABLE,

				'permission_callback' =>
					[
						$this,
						'permission',
					],

				'callback' =>
					[
						$this,
						'get_defaults',
					],
			]
		);


		register_rest_route(
			'pdxw/v1',
			'/pricing/defaults',
			[
				'methods' =>
					\WP_REST_Server::EDITABLE,

				'permission_callback' =>
					[
						$this,
						'permission',
					],

				'callback' =>
					[
						$this,
						'save_defaults',
					],
			]
		);


		/*
		|--------------------------------------------------------------------------
		| Category Markup Overrides
		|--------------------------------------------------------------------------
		*/

		register_rest_route(
			'pdxw/v1',
			'/pricing/categories',
			[
				'methods' =>
					\WP_REST_Server::READABLE,

				'permission_callback' =>
					[
						$this,
						'permission',
					],

				'callback' =>
					[
						$this,
						'get_categories',
					],
			]
		);


		register_rest_route(
			'pdxw/v1',
			'/pricing/categories/(?P<id>\d+)',
			[
				'methods' =>
					\WP_REST_Server::EDITABLE,

				'permission_callback' =>
					[
						$this,
						'permission',
					],

				'callback' =>
					[
						$this,
						'save_category',
					],
			]
		);


		register_rest_route(
			'pdxw/v1',
			'/pricing/categories/(?P<id>\d+)',
			[
				'methods' =>
					\WP_REST_Server::DELETABLE,

				'permission_callback' =>
					[
						$this,
						'permission',
					],

				'callback' =>
					[
						$this,
						'delete_category',
					],
			]
		);


		/*
		|--------------------------------------------------------------------------
		| Print Option Markup Overrides
		|--------------------------------------------------------------------------
		*/

		register_rest_route(
			'pdxw/v1',
			'/pricing/print-options',
			[
				'methods' =>
					\WP_REST_Server::READABLE,

				'permission_callback' =>
					[
						$this,
						'permission',
					],

				'callback' =>
					[
						$this,
						'get_print_options',
					],
			]
		);


		register_rest_route(
			'pdxw/v1',
			'/pricing/print-options/(?P<id>\d+)',
			[
				'methods' =>
					\WP_REST_Server::EDITABLE,

				'permission_callback' =>
					[
						$this,
						'permission',
					],

				'callback' =>
					[
						$this,
						'save_print_option',
					],
			]
		);


		register_rest_route(
			'pdxw/v1',
			'/pricing/print-options/(?P<id>\d+)',
			[
				'methods' =>
					\WP_REST_Server::DELETABLE,

				'permission_callback' =>
					[
						$this,
						'permission',
					],

				'callback' =>
					[
						$this,
						'delete_print_option',
					],
			]
		);


		/*
		|--------------------------------------------------------------------------
		| Manufacturer Discounts
		|--------------------------------------------------------------------------
		*/

		register_rest_route(
			'pdxw/v1',
			'/pricing/manufacturer-discounts',
			[
				'methods' =>
					\WP_REST_Server::READABLE,

				'permission_callback' =>
					[
						$this,
						'permission',
					],

				'callback' =>
					[
						$this,
						'get_manufacturer_discounts',
					],
			]
		);


		register_rest_route(
			'pdxw/v1',
			'/pricing/manufacturer-discounts/(?P<id>\d+)',
			[
				'methods' =>
					\WP_REST_Server::EDITABLE,

				'permission_callback' =>
					[
						$this,
						'permission',
					],

				'callback' =>
					[
						$this,
						'save_manufacturer_discount',
					],
			]
		);


		register_rest_route(
			'pdxw/v1',
			'/pricing/manufacturer-discounts/(?P<id>\d+)',
			[
				'methods' =>
					\WP_REST_Server::DELETABLE,

				'permission_callback' =>
					[
						$this,
						'permission',
					],

				'callback' =>
					[
						$this,
						'delete_manufacturer_discount',
					],
			]
		);


		/*
		|--------------------------------------------------------------------------
		| Supplier Discounts (SKU prefix)
		|--------------------------------------------------------------------------
		|
		| The prefix is sent in the request (not the URL) because it is free
		| text such as "A86-".
		*/

		register_rest_route(
			'pdxw/v1',
			'/pricing/supplier-discounts',
			[
				[
					'methods' =>
						\WP_REST_Server::READABLE,

					'permission_callback' =>
						[
							$this,
							'permission',
						],

					'callback' =>
						[
							$this,
							'get_supplier_discounts',
						],
				],
				[
					'methods' =>
						\WP_REST_Server::CREATABLE,

					'permission_callback' =>
						[
							$this,
							'permission',
						],

					'callback' =>
						[
							$this,
							'save_supplier_discount',
						],
				],
				[
					'methods' =>
						\WP_REST_Server::DELETABLE,

					'permission_callback' =>
						[
							$this,
							'permission',
						],

					'callback' =>
						[
							$this,
							'delete_supplier_discount',
						],
				],
			]
		);


		/*
		|--------------------------------------------------------------------------
		| Print option markups: bulk apply
		|--------------------------------------------------------------------------
		*/

		register_rest_route(
			'pdxw/v1',
			'/pricing/print-options/bulk',
			[
				'methods' =>
					\WP_REST_Server::CREATABLE,

				'permission_callback' =>
					[
						$this,
						'permission',
					],

				'callback' =>
					[
						$this,
						'save_print_options_bulk',
					],
			]
		);


		/*
		|--------------------------------------------------------------------------
		| Catalog price recalculation
		|--------------------------------------------------------------------------
		*/

		register_rest_route(
			'pdxw/v1',
			'/pricing/reprice',
			[
				[
					'methods' =>
						\WP_REST_Server::READABLE,

					'permission_callback' =>
						[
							$this,
							'permission',
						],

					'callback' =>
						[
							$this,
							'get_reprice_status',
						],
				],
				[
					'methods' =>
						\WP_REST_Server::CREATABLE,

					'permission_callback' =>
						[
							$this,
							'permission',
						],

					'callback' =>
						[
							$this,
							'reprice_all',
						],
				],
			]
		);
	}


	public function permission(): bool {

		return current_user_can(
			'manage_woocommerce'
		);
	}


	/**
	 * Validate a markup percentage.
	 *
	 * Returns a WP_Error instead of throwing an exception so REST clients
	 * receive a proper JSON validation response.
	 *
	 * @return float|\WP_Error
	 */
	private function markup(
		\WP_REST_Request $request,
		string $key = 'markup_percent'
	) {

		$value =
			$request->get_param(
				$key
			);

		if (
			! is_numeric( $value )
			|| (float) $value < 0
		) {
			return new \WP_Error(
				'pdxw_invalid_markup',
				sprintf(
					'%s must be a non-negative number.',
					$key
				),
				[
					'status' => 400,
				]
			);
		}

		return (float) $value;
	}


	/**
	 * Validate a discount percentage.
	 *
	 * Unlike a markup, a discount is bounded to 0-100.
	 *
	 * @return float|\WP_Error
	 */
	private function discount_percent(
		\WP_REST_Request $request,
		string $key = 'discount_percent'
	) {

		$value =
			$request->get_param(
				$key
			);

		if (
			! is_numeric( $value )
			|| (float) $value < 0
			|| (float) $value > 100
		) {
			return new \WP_Error(
				'pdxw_invalid_discount',
				sprintf(
					'%s must be a number between 0 and 100.',
					$key
				),
				[
					'status' => 400,
				]
			);
		}

		return (float) $value;
	}


	/*
	|--------------------------------------------------------------------------
	| Default Markups
	|--------------------------------------------------------------------------
	*/

	public function get_defaults(): \WP_REST_Response {

		return new \WP_REST_Response(
			[
				'article_markup' =>
					$this->pricing
						->markup_rules()
						->article_default(),

				'print_price_markup' =>
					$this->pricing
						->markup_rules()
						->print_price_default(),

				'print_fee_markup' =>
					$this->pricing
						->markup_rules()
						->print_fee_default(),
			]
		);
	}


	public function save_defaults(
		\WP_REST_Request $request
	) {

		$data =
			$request->get_json_params();

		if ( ! is_array( $data ) ) {
			$data = [];
		}


		if ( array_key_exists( 'article_markup', $data ) ) {

			$article_markup =
				$this->markup(
					$request,
					'article_markup'
				);

			if (
				is_wp_error(
					$article_markup
				)
			) {
				return $article_markup;
			}

			$previous_article_markup =
				$this->pricing
					->markup_rules()
					->article_default();

			$this->pricing
				->markup_rules()
				->set_article_default(
					$article_markup
				);

			// Every product without a category rule follows the default.
			if (
				abs( $previous_article_markup - $article_markup )
				> 0.00001
			) {

				$this->pricing
					->price_sync()
					->reprice_all();
			}
		}


		if ( array_key_exists( 'print_price_markup', $data ) ) {

			$print_price_markup =
				$this->markup(
					$request,
					'print_price_markup'
				);

			if (
				is_wp_error(
					$print_price_markup
				)
			) {
				return $print_price_markup;
			}

			$this->pricing
				->markup_rules()
				->set_print_price_default(
					$print_price_markup
				);
		}

		if ( array_key_exists( 'print_fee_markup', $data ) ) {

			$print_fee_markup =
				$this->markup(
					$request,
					'print_fee_markup'
				);

			if (
				is_wp_error(
					$print_fee_markup
				)
			) {
				return $print_fee_markup;
			}

			$this->pricing
				->markup_rules()
				->set_print_fee_default(
					$print_fee_markup
				);
		}

		return $this->get_defaults();
	}


	/*
	|--------------------------------------------------------------------------
	| Category Markup Overrides
	|--------------------------------------------------------------------------
	*/

	public function get_categories(): \WP_REST_Response {

		$rules =
			$this->pricing
				->markup_repository()
				->all(
					MarkupRepository::TYPE_CATEGORY
				);

		return new \WP_REST_Response(
			$rules
		);
	}


	public function save_category(
		\WP_REST_Request $request
	) {

		$id =
			absint(
				$request['id']
			);

		$term =
			get_term(
				$id,
				'product_cat'
			);

		if (
			! $term
			|| is_wp_error( $term )
		) {
			return new \WP_Error(
				'pdxw_category_not_found',
				'Category not found.',
				[
					'status' => 404,
				]
			);
		}


		$markup =
			$this->markup(
				$request
			);

		if (
			is_wp_error(
				$markup
			)
		) {
			return $markup;
		}


		$saved =
			$this->pricing
				->markup_repository()
				->save(
					MarkupRepository::TYPE_CATEGORY,
					$id,
					$markup
				);

		if ( ! $saved ) {
			return new \WP_Error(
				'pdxw_category_markup_save_failed',
				'Could not save category markup.',
				[
					'status' => 500,
				]
			);
		}


		$this->pricing
			->price_sync()
			->reprice_category(
				$id
			);

		return new \WP_REST_Response(
			[
				'id' =>
					$id,

				'name' =>
					$term->name,

				'markup_percent' =>
					$markup,
			]
		);
	}


	public function delete_category(
		\WP_REST_Request $request
	) {

		$id =
			absint(
				$request['id']
			);

		$term =
			get_term(
				$id,
				'product_cat'
			);

		if (
			! $term
			|| is_wp_error( $term )
		) {
			return new \WP_Error(
				'pdxw_category_not_found',
				'Category not found.',
				[
					'status' => 404,
				]
			);
		}


		$deleted =
			$this->pricing
				->markup_repository()
				->delete(
					MarkupRepository::TYPE_CATEGORY,
					$id
				);

		if ( ! $deleted ) {
			return new \WP_Error(
				'pdxw_category_markup_delete_failed',
				'Could not delete category markup.',
				[
					'status' => 500,
				]
			);
		}

		$this->pricing
			->price_sync()
			->reprice_category(
				$id
			);


		return new \WP_REST_Response(
			[
				'id'      => $id,
				'deleted' => true,
			]
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Print Option Markup Overrides
	|--------------------------------------------------------------------------
	*/

	public function get_print_options(): \WP_REST_Response {

		return new \WP_REST_Response(
			$this->pricing
				->markup_rules()
				->print_option_overrides()
		);
	}


	public function save_print_option(
		\WP_REST_Request $request
	) {

		$data =
			$request->get_json_params();

		if ( ! is_array( $data ) ) {
			$data = [];
		}

		$id =
			absint(
				$request['id']
			);

		if ( ! $id ) {
			return new \WP_Error(
				'pdxw_print_option_not_found',
				'Print option not found.',
				[
					'status' => 404,
				]
			);
		}


		$price_markup =
			array_key_exists(
				'price_markup_percent',
				$data
			)
				? $this->markup(
					$request,
					'price_markup_percent'
				)
				: (
					array_key_exists(
						'markup_percent',
						$data
					)
						? $this->markup(
							$request,
							'markup_percent'
						)
						: null
				);

		$fee_markup =
			array_key_exists(
				'fee_markup_percent',
				$data
			)
				? $this->markup(
					$request,
					'fee_markup_percent'
				)
				: $price_markup;

		if (
			is_wp_error(
				$price_markup
			)
		) {
			return $price_markup;
		}

		if (
			is_wp_error(
				$fee_markup
			)
		) {
			return $fee_markup;
		}

		$price_saved =
			$this->pricing
				->markup_repository()
				->save(
					MarkupRepository::TYPE_PRINT_OPTION_PRICE,
					$id,
					null !== $price_markup
						? (float) $price_markup
						: $this->pricing
							->markup_rules()
							->print_price_default()
				);

		$fee_saved =
			$this->pricing
				->markup_repository()
				->save(
					MarkupRepository::TYPE_PRINT_OPTION_FEE,
					$id,
					null !== $fee_markup
						? (float) $fee_markup
						: $this->pricing
							->markup_rules()
							->print_fee_default()
				);

		if ( ! $price_saved || ! $fee_saved ) {
			return new \WP_Error(
				'pdxw_print_option_markup_save_failed',
				'Could not save print option markup.',
				[
					'status' => 500,
				]
			);
		}


		return new \WP_REST_Response(
			[
				'id' =>
					$id,

				'price_markup_percent' =>
					null !== $price_markup
						? (float) $price_markup
						: $this->pricing
							->markup_rules()
							->print_price_default(),

				'fee_markup_percent' =>
					null !== $fee_markup
						? (float) $fee_markup
						: $this->pricing
							->markup_rules()
							->print_fee_default(),
			]
		);
	}


	/**
	 * Apply one price / fee markup to many print options at once.
	 *
	 * Body:
	 *
	 *     ids                   int[]  print option IDs
	 *     price_markup_percent  float  required
	 *     fee_markup_percent    float  optional, defaults to the price markup
	 *
	 * Existing overrides of the given options are overwritten.
	 */
	public function save_print_options_bulk(
		\WP_REST_Request $request
	) {

		$data =
			$request->get_json_params();

		if ( ! is_array( $data ) ) {
			$data = [];
		}

		$ids =
			isset( $data['ids'] )
			&& is_array( $data['ids'] )
				? array_values(
					array_unique(
						array_filter(
							array_map(
								'absint',
								$data['ids']
							)
						)
					)
				)
				: [];

		if ( empty( $ids ) ) {
			return new \WP_Error(
				'pdxw_no_print_options',
				'ids must contain at least one print option.',
				[
					'status' => 400,
				]
			);
		}

		if ( count( $ids ) > 10000 ) {
			return new \WP_Error(
				'pdxw_too_many_print_options',
				'A maximum of 10000 print options can be updated at once.',
				[
					'status' => 400,
				]
			);
		}

		$price_markup =
			$this->markup(
				$request,
				'price_markup_percent'
			);

		if ( is_wp_error( $price_markup ) ) {
			return $price_markup;
		}

		$fee_markup =
			array_key_exists(
				'fee_markup_percent',
				$data
			)
			&& '' !== $data['fee_markup_percent']
			&& null !== $data['fee_markup_percent']
				? $this->markup(
					$request,
					'fee_markup_percent'
				)
				: $price_markup;

		if ( is_wp_error( $fee_markup ) ) {
			return $fee_markup;
		}

		$repository =
			$this->pricing
				->markup_repository();

		$price_saved =
			$repository->save_many(
				MarkupRepository::TYPE_PRINT_OPTION_PRICE,
				$ids,
				(float) $price_markup
			);

		$fee_saved =
			$repository->save_many(
				MarkupRepository::TYPE_PRINT_OPTION_FEE,
				$ids,
				(float) $fee_markup
			);

		if ( ! $price_saved || ! $fee_saved ) {
			return new \WP_Error(
				'pdxw_print_option_markup_save_failed',
				'Could not save print option markups.',
				[
					'status' => 500,
				]
			);
		}

		return new \WP_REST_Response(
			[
				'updated'              => count( $ids ),
				'price_markup_percent' => (float) $price_markup,
				'fee_markup_percent'   => (float) $fee_markup,
			]
		);
	}


	public function delete_print_option(
		\WP_REST_Request $request
	) {

		$id =
			absint(
				$request['id']
			);

		if ( ! $id ) {
			return new \WP_Error(
				'pdxw_print_option_not_found',
				'Print option not found.',
				[
					'status' => 404,
				]
			);
		}


		/*
		|--------------------------------------------------------------------------
		| Delete Every Rule Type
		|--------------------------------------------------------------------------
		|
		| Each delete() must run unconditionally — MarkupRepository::delete()
		| returns true even when zero rows matched (wpdb->delete() returns
		| an integer, not false, for "nothing to delete"). Chaining these
		| with || would short-circuit after the first call (almost always
		| TYPE_PRINT_OPTION, which rarely has a row since save_print_option()
		| only ever writes the split PRICE/FEE types), silently leaving the
		| real override rows in place.
		*/

		$deleted_legacy =
			$this->pricing
				->markup_repository()
				->delete(
					MarkupRepository::TYPE_PRINT_OPTION,
					$id
				);

		$deleted_price =
			$this->pricing
				->markup_repository()
				->delete(
					MarkupRepository::TYPE_PRINT_OPTION_PRICE,
					$id
				);

		$deleted_fee =
			$this->pricing
				->markup_repository()
				->delete(
					MarkupRepository::TYPE_PRINT_OPTION_FEE,
					$id
				);

		$deleted =
			$deleted_legacy
			|| $deleted_price
			|| $deleted_fee;

		if ( ! $deleted ) {
			return new \WP_Error(
				'pdxw_print_option_markup_delete_failed',
				'Could not delete print option markup.',
				[
					'status' => 500,
				]
			);
		}


		return new \WP_REST_Response(
			[
				'id'      => $id,
				'deleted' => true,
			]
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Manufacturer Discounts
	|--------------------------------------------------------------------------
	*/

	public function get_manufacturer_discounts(): \WP_REST_Response {

		return new \WP_REST_Response(
			$this->pricing
				->manufacturer_discount()
				->all()
		);
	}


	public function save_manufacturer_discount(
		\WP_REST_Request $request
	) {

		$id =
			absint(
				$request['id']
			);

		$term =
			get_term(
				$id,
				ManufacturerDiscount::TAXONOMY
			);

		if (
			! $term
			|| is_wp_error( $term )
		) {
			return new \WP_Error(
				'pdxw_brand_not_found',
				'Brand not found.',
				[
					'status' => 404,
				]
			);
		}

		$discount =
			$this->discount_percent(
				$request
			);

		if (
			is_wp_error( $discount )
		) {
			return $discount;
		}

		$saved =
			$this->pricing
				->manufacturer_discount()
				->save_for_brand(
					$id,
					$discount
				);

		if ( ! $saved ) {
			return new \WP_Error(
				'pdxw_manufacturer_discount_save_failed',
				'Could not save manufacturer discount.',
				[
					'status' => 500,
				]
			);
		}

		$this->pricing
			->price_sync()
			->reprice_brand(
				$id
			);

		return new \WP_REST_Response(
			[
				'id'               => $id,
				'discount_percent' => $discount,
			]
		);
	}


	public function delete_manufacturer_discount(
		\WP_REST_Request $request
	) {

		$id =
			absint(
				$request['id']
			);

		if ( ! $id ) {
			return new \WP_Error(
				'pdxw_brand_not_found',
				'Brand not found.',
				[
					'status' => 404,
				]
			);
		}

		$deleted =
			$this->pricing
				->manufacturer_discount()
				->delete_for_brand(
					$id
				);

		if ( ! $deleted ) {
			return new \WP_Error(
				'pdxw_manufacturer_discount_delete_failed',
				'Could not delete manufacturer discount.',
				[
					'status' => 500,
				]
			);
		}

		$this->pricing
			->price_sync()
			->reprice_brand(
				$id
			);

		return new \WP_REST_Response(
			[
				'id'      => $id,
				'deleted' => true,
			]
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Supplier Discounts
	|--------------------------------------------------------------------------
	*/

	public function get_supplier_discounts(): \WP_REST_Response {

		return new \WP_REST_Response(
			$this->pricing
				->supplier_discount()
				->all()
		);
	}


	public function save_supplier_discount(
		\WP_REST_Request $request
	) {

		$supplier_discount =
			$this->pricing
				->supplier_discount();

		$sku_prefix =
			$supplier_discount
				->normalize_prefix(
					(string) $request->get_param(
						'sku_prefix'
					)
				);

		if ( '' === $sku_prefix ) {
			return new \WP_Error(
				'pdxw_invalid_sku_prefix',
				'sku_prefix must not be empty.',
				[
					'status' => 400,
				]
			);
		}

		$discount =
			$this->discount_percent(
				$request
			);

		if (
			is_wp_error( $discount )
		) {
			return $discount;
		}

		if (
			! $supplier_discount
				->save(
					$sku_prefix,
					$discount
				)
		) {
			return new \WP_Error(
				'pdxw_supplier_discount_save_failed',
				'Could not save supplier discount.',
				[
					'status' => 500,
				]
			);
		}

		$this->pricing
			->price_sync()
			->reprice_sku_prefix(
				$sku_prefix
			);

		return new \WP_REST_Response(
			[
				'sku_prefix'       => $sku_prefix,
				'discount_percent' => $discount,
			]
		);
	}


	public function delete_supplier_discount(
		\WP_REST_Request $request
	) {

		$supplier_discount =
			$this->pricing
				->supplier_discount();

		$sku_prefix =
			$supplier_discount
				->normalize_prefix(
					(string) $request->get_param(
						'sku_prefix'
					)
				);

		if ( '' === $sku_prefix ) {
			return new \WP_Error(
				'pdxw_invalid_sku_prefix',
				'sku_prefix must not be empty.',
				[
					'status' => 400,
				]
			);
		}

		if (
			! $supplier_discount
				->delete(
					$sku_prefix
				)
		) {
			return new \WP_Error(
				'pdxw_supplier_discount_delete_failed',
				'Could not delete supplier discount.',
				[
					'status' => 500,
				]
			);
		}

		$this->pricing
			->price_sync()
			->reprice_sku_prefix(
				$sku_prefix
			);

		return new \WP_REST_Response(
			[
				'sku_prefix' => $sku_prefix,
				'deleted'    => true,
			]
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Catalog price recalculation
	|--------------------------------------------------------------------------
	*/

	public function get_reprice_status(): \WP_REST_Response {

		return new \WP_REST_Response(
			[
				'pending_jobs' =>
					$this->pricing
						->price_sync()
						->pending_jobs(),
			]
		);
	}


	public function reprice_all(): \WP_REST_Response {

		$products =
			$this->pricing
				->price_sync()
				->reprice_all();

		return new \WP_REST_Response(
			[
				'products'     => $products,
				'pending_jobs' =>
					$this->pricing
						->price_sync()
						->pending_jobs(),
			]
		);
	}
}