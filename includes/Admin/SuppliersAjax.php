<?php

namespace PromiDataXWoo\Admin;

use PromiDataXWoo\Suppliers\Suppliers;

defined( 'ABSPATH' ) || exit;

/**
 * Admin AJAX controller for supplier source management.
 *
 * Owns request-level responsibility only (capability/nonce checks,
 * sanitization, JSON responses). Persistence lives in
 * Suppliers\SourceRepository; fetching/matching/applying lives in
 * Suppliers\Sync.
 */
final class SuppliersAjax {

	public const ACTION_SAVE_SOURCE =
		'pdxw_supplier_save_source';

	public const ACTION_DELETE_SOURCE =
		'pdxw_supplier_delete_source';

	public const ACTION_RUN_SOURCE_NOW =
		'pdxw_supplier_run_source_now';


	private Suppliers $suppliers;

	private bool $initialized = false;

	public function __construct( Suppliers $suppliers ) {
		$this->suppliers = $suppliers;
	}


	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		add_action( 'wp_ajax_' . self::ACTION_SAVE_SOURCE, [ $this, 'save_source' ] );
		add_action( 'wp_ajax_' . self::ACTION_DELETE_SOURCE, [ $this, 'delete_source' ] );
		add_action( 'wp_ajax_' . self::ACTION_RUN_SOURCE_NOW, [ $this, 'run_source_now' ] );

		do_action( 'pdxw_admin_suppliers_ajax_init', $this );
	}


	/**
	 * Create or update a supplier source.
	 */
	public function save_source(): void {

		$this->authorize();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		$sku_prefix = $this->request_string( 'sku_prefix' );

		if ( '' === $sku_prefix ) {

			wp_send_json_error(
				[ 'message' => __( 'A SKU prefix is required.', 'promi-data-x-woo' ) ],
				400
			);
		}

		$endpoint_url = $this->request_string( 'endpoint_url' );

		if ( '' !== $endpoint_url && ! wp_http_validate_url( $endpoint_url ) ) {

			wp_send_json_error(
				[ 'message' => __( 'Please enter a valid endpoint URL.', 'promi-data-x-woo' ) ],
				400
			);
		}

		$price_endpoint_url = $this->request_string( 'price_endpoint_url' );

		if ( '' !== $price_endpoint_url && ! wp_http_validate_url( $price_endpoint_url ) ) {

			wp_send_json_error(
				[ 'message' => __( 'Please enter a valid price feed URL.', 'promi-data-x-woo' ) ],
				400
			);
		}

		$data = [
			'name'                  => $this->request_string( 'name' ),
			'sku_prefix'            => $sku_prefix,
			'adapter_key'           => sanitize_key( $this->request_string( 'adapter_key' ) ),
			'enabled'               => $this->request_bool( 'enabled' ) ? 1 : 0,
			'endpoint_url'          => esc_url_raw( $endpoint_url ),
			'price_endpoint_url'    => esc_url_raw( $price_endpoint_url ),
			'credential'            => $this->request_raw_string( 'credential' ),
			'sync_interval_minutes' => max( 5, $this->request_int( 'sync_interval_minutes', 60 ) ),
		];

		if ( $id ) {

			$saved = $this->suppliers->sources()->update( $id, $data );

		} else {

			$id    = $this->suppliers->sources()->create( $data );
			$saved = (bool) $id;
		}

		if ( ! $saved ) {

			wp_send_json_error(
				[ 'message' => __( 'The supplier source could not be saved. Check that the SKU prefix is not already used by another source.', 'promi-data-x-woo' ) ],
				500
			);
		}

		wp_send_json_success(
			[
				'message' => __( 'Supplier source saved.', 'promi-data-x-woo' ),
				'id'      => $id,
			]
		);
	}


	public function delete_source(): void {

		$this->authorize();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( ! $id || ! $this->suppliers->sources()->delete( $id ) ) {

			wp_send_json_error(
				[ 'message' => __( 'The supplier source could not be deleted.', 'promi-data-x-woo' ) ],
				500
			);
		}

		wp_send_json_success(
			[ 'message' => __( 'Supplier source deleted.', 'promi-data-x-woo' ) ]
		);
	}


	public function run_source_now(): void {

		$this->authorize();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( ! $id ) {

			wp_send_json_error(
				[ 'message' => __( 'Please provide a valid source ID.', 'promi-data-x-woo' ) ],
				400
			);
		}

		try {

			$result = $this->suppliers->run_source_now( $id );

		} catch ( \Throwable $e ) {

			$this->suppliers->logger()->error(
				$e->getMessage(),
				[ 'exception' => get_class( $e ) ]
			);

			wp_send_json_error(
				[ 'message' => __( 'The supplier sync could not be run.', 'promi-data-x-woo' ) ],
				500
			);
		}

		if ( empty( $result['found'] ) ) {

			wp_send_json_error(
				[ 'message' => __( 'Supplier source not found.', 'promi-data-x-woo' ) ],
				404
			);
		}

		wp_send_json_success(
			[
				'message' => $result['message'] ?? __( 'Done.', 'promi-data-x-woo' ),
				'matched' => $result['matched'] ?? 0,
				'updated' => $result['updated'] ?? 0,
				'status'  => $result['status'] ?? '',
			]
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Helpers
	|--------------------------------------------------------------------------
	*/

	private function authorize(): void {

		if ( ! current_user_can( Menu::CAPABILITY ) ) {

			wp_send_json_error(
				[ 'message' => __( 'You do not have permission to manage Promi-Data X Woo.', 'promi-data-x-woo' ) ],
				403
			);
		}

		check_ajax_referer( Ajax::NONCE_ACTION, Ajax::NONCE_FIELD );
	}


	private function request_string( string $key, string $default = '' ): string {

		if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
			return $default;
		}

		return trim( sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) );
	}


	/**
	 * Like request_string(), but without sanitize_text_field() stripping
	 * characters an API key/secret might legitimately contain.
	 */
	private function request_raw_string( string $key, string $default = '' ): string {

		if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
			return $default;
		}

		return trim( (string) wp_unslash( (string) $_POST[ $key ] ) );
	}


	private function request_int( string $key, int $default = 0 ): int {

		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}

		return absint( $_POST[ $key ] );
	}


	private function request_bool( string $key ): bool {

		if ( ! isset( $_POST[ $key ] ) ) {
			return false;
		}

		$value = wp_unslash( $_POST[ $key ] );

		return in_array( $value, [ '1', 1, true, 'true' ], true );
	}


	public function is_initialized(): bool {
		return $this->initialized;
	}
}
