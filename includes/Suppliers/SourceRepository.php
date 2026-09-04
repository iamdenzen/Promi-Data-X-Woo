<?php

namespace PromiDataXWoo\Suppliers;

use PromiDataXWoo\Core\Database;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD access to cx_supplier_sources.
 *
 * Owns direct database access only. Field-value sanitization of admin
 * input belongs to the admin AJAX layer; fetching/mapping/applying feed
 * data belongs to Sync, the per-supplier adapters, and ProductApplier.
 */
final class SourceRepository {

	/**
	 * Columns a source row is allowed to set through create()/update().
	 */
	private const WRITABLE_COLUMNS = [
		'name',
		'sku_prefix',
		'adapter_key',
		'enabled',
		'endpoint_url',
		'credential',
		'sync_interval_minutes',
	];


	private function table(): string {
		return Database::table( 'supplier_sources' );
	}


	/**
	 * Return every configured source.
	 *
	 * @return array<int,object>
	 */
	public function all(): array {

		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT * FROM ' . $this->table() . ' ORDER BY name ASC, id ASC'
		);

		return is_array( $rows ) ? $rows : [];
	}


	public function find( int $id ): ?object {

		global $wpdb;

		$id = absint( $id );

		if ( ! $id ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE id = %d LIMIT 1',
				$id
			)
		);

		return $row ?: null;
	}


	/**
	 * Create a new source.
	 *
	 * @param array<string,mixed> $data
	 * @return int New source ID, or 0 on failure.
	 */
	public function create( array $data ): int {

		global $wpdb;

		$data = $this->filter_writable( $data );

		if ( empty( $data['sku_prefix'] ) ) {
			return 0;
		}

		$data['created_at'] = current_time( 'mysql' );
		$data['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->insert( $this->table(), $data );

		return false === $result ? 0 : (int) $wpdb->insert_id;
	}


	/**
	 * Update an existing source.
	 *
	 * @param array<string,mixed> $data
	 */
	public function update( int $id, array $data ): bool {

		global $wpdb;

		$id = absint( $id );

		if ( ! $id ) {
			return false;
		}

		$data = $this->filter_writable( $data );

		if ( empty( $data ) ) {
			return false;
		}

		$data['updated_at'] = current_time( 'mysql' );

		return false !== $wpdb->update(
			$this->table(),
			$data,
			[ 'id' => $id ]
		);
	}


	public function delete( int $id ): bool {

		global $wpdb;

		$id = absint( $id );

		if ( ! $id ) {
			return false;
		}

		return false !== $wpdb->delete(
			$this->table(),
			[ 'id' => $id ],
			[ '%d' ]
		);
	}


	/**
	 * Return every enabled source whose sync interval has elapsed.
	 *
	 * @return array<int,object>
	 */
	public function due(): array {

		global $wpdb;

		$table = $this->table();
		$now   = current_time( 'mysql' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM {$table}
				WHERE enabled = 1
				AND adapter_key != ''
				AND (
					last_synced_at IS NULL
					OR last_synced_at <= DATE_SUB(%s, INTERVAL sync_interval_minutes MINUTE)
				)",
				$now
			)
		);

		return is_array( $rows ) ? $rows : [];
	}


	/**
	 * Record the outcome of a run against one source.
	 */
	public function record_run(
		int $id,
		string $status,
		string $message,
		int $matched,
		int $updated
	): void {

		global $wpdb;

		$id = absint( $id );

		if ( ! $id ) {
			return;
		}

		$wpdb->update(
			$this->table(),
			[
				'last_synced_at' => current_time( 'mysql' ),
				'last_status'    => sanitize_key( $status ),
				'last_message'   => $message,
				'last_matched'   => max( 0, $matched ),
				'last_updated'   => max( 0, $updated ),
				'updated_at'     => current_time( 'mysql' ),
			],
			[ 'id' => $id ]
		);
	}


	/**
	 * Restrict an incoming data array to known writable columns.
	 */
	private function filter_writable( array $data ): array {

		return array_intersect_key(
			$data,
			array_flip( self::WRITABLE_COLUMNS )
		);
	}
}
