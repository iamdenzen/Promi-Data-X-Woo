<?php

namespace PromiDataXWoo\Promi;

defined( 'ABSPATH' ) || exit;

final class Client {

	private Logger $logger;

	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Fetch the primary Promi feed.
	 */
	public function get_feed(): string|\WP_Error {

		$url = Config::feed_url();

		if ( ! $url ) {
			return new \WP_Error(
				'pdxw_missing_feed',
				__(
					'Promi feed URL has not been configured.',
					'promi-data-x-woo'
				)
			);
		}

		return $this->get_text(
			$url,
			[
				'timeout' => 60,
			]
		);
	}

	/**
	 * Fetch product JSON.
	 */
	public function get_product( string $url ): array|\WP_Error {

		$body = $this->get_text(
			$url,
			[
				'timeout' => 45,
			]
		);

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		try {

			$data = json_decode(
				$body,
				true,
				512,
				JSON_THROW_ON_ERROR
			);

		} catch ( \JsonException $e ) {

			$this->logger->error(
				'Invalid Promi product JSON.',
				[
					'url'   => $url,
					'error' => $e->getMessage(),
				]
			);

			return new \WP_Error(
				'pdxw_invalid_json',
				__(
					'Promi returned invalid JSON.',
					'promi-data-x-woo'
				)
			);
		}

		if ( ! is_array( $data ) ) {

			return new \WP_Error(
				'pdxw_invalid_product',
				__(
					'Promi product response was invalid.',
					'promi-data-x-woo'
				)
			);
		}

		return $data;
	}

	/**
	 * Fetch a CSV file.
	 */
	public function get_csv( string $url ): string|\WP_Error {

		return $this->get_text(
			$url,
			[
				'timeout' => 60,
			]
		);
	}

	/**
	 * Generic remote text request.
	 *
	 * $url may be a full absolute URL (the configured feed URL itself, or
	 * a legacy cx_promi_index row stored before relative paths existed)
	 * or a path relative to the feed's base URL — see
	 * Config::resolve_promi_url().
	 */
	public function get_text(
		string $url,
		array $args = []
	): string|\WP_Error {

		$url = Config::resolve_promi_url( $url );

		$url = esc_url_raw( $url );

		if ( ! $url ) {

			return new \WP_Error(
				'pdxw_invalid_url',
				__(
					'Invalid Promi URL.',
					'promi-data-x-woo'
				)
			);
		}

		$args = wp_parse_args(
			$args,
			[
				'timeout'     => 30,
				'redirection' => 5,
				'user-agent'  => sprintf(
					'Promi-Data-X-Woo/%s; %s',
					PDXW_VERSION,
					home_url()
				),
			]
		);

		$response = wp_remote_get(
			$url,
			$args
		);

		if ( is_wp_error( $response ) ) {

			$this->logger->error(
				'Promi HTTP request failed.',
				[
					'url'   => $url,
					'error' => $response->get_error_message(),
				]
			);

			return $response;
		}

		$status = wp_remote_retrieve_response_code(
			$response
		);

		if ( $status < 200 || $status >= 300 ) {

			$this->logger->error(
				'Promi HTTP request returned an unexpected status.',
				[
					'url'    => $url,
					'status' => $status,
				]
			);

			return new \WP_Error(
				'pdxw_http_error',
				sprintf(
					__(
						'Promi returned HTTP status %d.',
						'promi-data-x-woo'
					),
					$status
				),
				[
					'status' => $status,
					'url'    => $url,
				]
			);
		}

		$body = wp_remote_retrieve_body(
			$response
		);

		if ( $body === '' ) {

			return new \WP_Error(
				'pdxw_empty_response',
				__(
					'Promi returned an empty response.',
					'promi-data-x-woo'
				)
			);
		}

		return $body;
	}
}