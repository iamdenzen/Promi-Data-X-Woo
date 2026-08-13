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

				'finishing_markup' =>
					$this->pricing
						->markup_rules()
						->finishing_default(),
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

			$this->pricing
				->markup_rules()
				->set_article_default(
					$article_markup
				);
		}


		if ( array_key_exists( 'finishing_markup', $data ) ) {

			$finishing_markup =
				$this->markup(
					$request,
					'finishing_markup'
				);

			if (
				is_wp_error(
					$finishing_markup
				)
			) {
				return $finishing_markup;
			}

			$this->pricing
				->markup_rules()
				->set_finishing_default(
					$finishing_markup
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
				->markup_repository()
				->all(
					MarkupRepository::TYPE_PRINT_OPTION
				)
		);
	}


	public function save_print_option(
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
					MarkupRepository::TYPE_PRINT_OPTION,
					$id,
					$markup
				);

		if ( ! $saved ) {
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

				'markup_percent' =>
					$markup,
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


		$deleted =
			$this->pricing
				->markup_repository()
				->delete(
					MarkupRepository::TYPE_PRINT_OPTION,
					$id
				);

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
}