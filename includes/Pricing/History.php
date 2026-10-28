<?php

namespace PromiDataXWoo\Pricing;

use PromiDataXWoo\Core\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Audit log of pricing changes: date, user, what changed, products affected.
 *
 * The entry point (REST, MCP, admin) is announced once per request with
 * set_source(); the actor is the logged-in user, or "MCP" for MCP calls.
 */
final class History {

	public const SOURCE_ADMIN = 'admin';
	public const SOURCE_REST  = 'rest';
	public const SOURCE_MCP   = 'mcp';

	private static string $source = self::SOURCE_ADMIN;
	private static string $actor  = '';


	public static function set_source( string $source, string $actor = '' ): void {

		self::$source = in_array(
			$source,
			[ self::SOURCE_ADMIN, self::SOURCE_REST, self::SOURCE_MCP ],
			true
		) ? $source : self::SOURCE_ADMIN;

		self::$actor = $actor;
	}

	/**
	 * While true, rule writes are not logged one by one (ChangeSet::apply
	 * writes a single entry for the whole change set).
	 */
	private static bool $suppressed = false;

	public static function suppress( bool $suppressed ): void {

		self::$suppressed = $suppressed;
	}

	/**
	 * Log a direct rule write (existing admin UI / REST saves).
	 *
	 * @param array<string,mixed> $details
	 */
	public static function record_rule_write(
		string $summary,
		int $affected_count = 0,
		array $details = []
	): void {

		if ( self::$suppressed ) {
			return;
		}

		self::record( 'markup_saved', $summary, $affected_count, $details );
	}

	public static function source(): string {

		return self::$source;
	}


	/**
	 * Name stored for the current actor.
	 */
	public static function actor(): string {

		if ( '' !== self::$actor ) {
			return self::$actor;
		}

		if ( self::SOURCE_MCP === self::$source ) {
			return 'MCP';
		}

		$user = wp_get_current_user();

		return $user && $user->exists()
			? (string) $user->user_login
			: 'system';
	}


	/**
	 * @param array<string,mixed> $details
	 */
	public static function record(
		string $action,
		string $summary,
		int $affected_count = 0,
		array $details = []
	): int {

		global $wpdb;

		$wpdb->insert(
			Database::table( 'price_history' ),
			[
				'created_at'     => current_time( 'mysql', true ),
				'user_id'        => get_current_user_id(),
				'actor'          => mb_substr( self::actor(), 0, 100 ),
				'source'         => self::$source,
				'action'         => mb_substr( $action, 0, 40 ),
				'summary'        => mb_substr( $summary, 0, 500 ),
				'affected_count' => max( 0, $affected_count ),
				'details'        => $details ? wp_json_encode( $details ) : null,
			],
			[ '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}


	/**
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public static function list(
		int $limit = 50,
		int $offset = 0,
		string $action = '',
		string $source = ''
	): array {

		global $wpdb;

		$table = Database::table( 'price_history' );

		$where  = [ '1=1' ];
		$values = [];

		if ( '' !== $action ) {
			$where[]  = 'action = %s';
			$values[] = $action;
		}

		if ( '' !== $source ) {
			$where[]  = 'source = %s';
			$values[] = $source;
		}

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var(
			$values ? $wpdb->prepare( $count_sql, $values ) : $count_sql
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, user_id, actor, source, action, summary, affected_count, details
				FROM {$table}
				WHERE {$where_sql}
				ORDER BY id DESC
				LIMIT %d OFFSET %d",
				array_merge( $values, [ max( 1, min( 500, $limit ) ), max( 0, $offset ) ] )
			),
			ARRAY_A
		);

		$items = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {

			$row['id']             = (int) $row['id'];
			$row['user_id']        = (int) $row['user_id'];
			$row['affected_count'] = (int) $row['affected_count'];
			$row['details']        = $row['details']
				? json_decode( (string) $row['details'], true )
				: null;

			$items[] = $row;
		}

		return [
			'items' => $items,
			'total' => $total,
		];
	}
}
