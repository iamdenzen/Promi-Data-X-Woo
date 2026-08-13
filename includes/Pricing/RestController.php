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
	}


	public function permission(): bool {

		return current_user_can(
			'manage_woocommerce'
		);
	}


	private function markup(
		\WP_REST_Request $request
	): float {

		$value =
			$request->get_param(
				'markup_percent'
			);

		if (
			! is_numeric( $value )
			|| (float) $value < 0
		) {
			throw new \InvalidArgumentException(
				'markup_percent must be a non-negative number.'
			);
		}

		return (float) $value;
	}


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
	): \WP_REST_Response {

		$data =
			$request->get_json_params();

		if ( isset( $data['article_markup'] ) ) {

			$this->pricing
				->markup_rules()
				->set_article_default(
					(float) $data['article_markup']
				);
		}

		if ( isset( $data['finishing_markup'] ) ) {

			$this->pricing
				->markup_rules()
				->set_finishing_default(
					(float) $data['finishing_markup']
				);
		}

		return $this->get_defaults();
	}


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
	): \WP_REST_Response {

		$id =
			absint(
				$request['id']
			);

		if (
			! get_term(
				$id,
				'product_cat'
			)
		) {
			return new \WP_REST_Response(
				[
					'message' =>
						'Category not found.',
				],
				404
			);
		}

		$this->pricing
			->markup_repository()
			->save(
				MarkupRepository::TYPE_CATEGORY,
				$id,
				$this->markup(
					$request
				)
			);

		return new \WP_REST_Response(
			[
				'id' =>
					$id,

				'markup_percent' =>
					$this->pricing
						->markup_rules()
						->article_markup(
							0
						),
			]
		);
	}


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
	): \WP_REST_Response {

		$id =
			absint(
				$request['id']
			);

		if ( ! $id ) {
			return new \WP_REST_Response(
				[
					'message' =>
						'Print option not found.',
				],
				404
			);
		}

		$this->pricing
			->markup_repository()
			->save(
				MarkupRepository::TYPE_PRINT_OPTION,
				$id,
				$this->markup(
					$request
				)
			);

		return new \WP_REST_Response(
			[
				'id' =>
					$id,

				'markup_percent' =>
					$this->pricing
						->markup_rules()
						->finishing_markup(
							$id
						),
			]
		);
	}
}