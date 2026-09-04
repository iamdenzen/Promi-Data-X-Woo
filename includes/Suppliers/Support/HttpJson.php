<?php

namespace PromiDataXWoo\Suppliers\Support;

use PromiDataXWoo\Suppliers\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Shared GET/POST + JSON-decode helper used internally by supplier
 * adapters.
 *
 * Deliberately minimal: every adapter still builds its own headers/body,
 * since auth shape and request method differ per supplier. This only
 * removes the repetitive wp_remote_request()/json_decode()/error-handling
 * boilerplate.
 */
final class HttpJson {

	private Logger $logger;

	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}


	/**
	 * @param array<string,string> $headers
	 * @return array|\WP_Error
	 */
	public function get( string $url, array $headers = [] ): array|\WP_Error {

		return $this->request( 'GET', $url, $headers );
	}


	/**
	 * @param array<string,string> $headers
	 * @return array|\WP_Error
	 */
	public function post( string $url, array $headers, array $body ): array|\WP_Error {

		$headers['Content-Type'] = 'application/json';

		return $this->request(
			'POST',
			$url,
			$headers,
			(string) wp_json_encode( $body )
		);
	}


	/**
	 * @param array<string,string> $headers
	 * @return array|\WP_Error
	 */
	private function request(
		string $method,
		string $url,
		array $headers,
		?string $body = null
	): array|\WP_Error {

		$url = esc_url_raw( trim( $url ) );

		if ( '' === $url ) {

			return new \WP_Error(
				'pdxw_supplier_invalid_url',
				__( 'Invalid supplier endpoint URL.', 'promi-data-x-woo' )
			);
		}

		$args = [
			'method'      => $method,
			'timeout'     => 60,
			'redirection' => 5,
			'headers'     => $headers,
			'user-agent'  => sprintf(
				'Promi-Data-X-Woo-Supplier/%s; %s',
				PDXW_VERSION,
				home_url()
			),
		];

		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {

			$this->logger->error(
				'Supplier HTTP request failed.',
				[
					'method' => $method,
					'url'    => $url,
					'error'  => $response->get_error_message(),
				]
			);

			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status >= 300 ) {

			$this->logger->error(
				'Supplier HTTP request returned an unexpected status.',
				[
					'method' => $method,
					'url'    => $url,
					'status' => $status,
				]
			);

			return new \WP_Error(
				'pdxw_supplier_http_error',
				sprintf(
					__( 'Supplier request returned HTTP status %d.', 'promi-data-x-woo' ),
					$status
				)
			);
		}

		$raw = wp_remote_retrieve_body( $response );

		if ( '' === $raw ) {

			return new \WP_Error(
				'pdxw_supplier_empty_response',
				__( 'Supplier returned an empty response.', 'promi-data-x-woo' )
			);
		}

		try {

			$data = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );

		} catch ( \JsonException $e ) {

			$this->logger->error(
				'Invalid supplier JSON.',
				[
					'url'   => $url,
					'error' => $e->getMessage(),
				]
			);

			return new \WP_Error(
				'pdxw_supplier_invalid_json',
				__( 'Supplier returned invalid JSON.', 'promi-data-x-woo' )
			);
		}

		if ( ! is_array( $data ) ) {

			return new \WP_Error(
				'pdxw_supplier_invalid_response',
				__( 'Supplier response was not a JSON object or array.', 'promi-data-x-woo' )
			);
		}

		return $data;
	}
}
