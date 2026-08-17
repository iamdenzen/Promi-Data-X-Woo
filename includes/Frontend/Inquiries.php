<?php

namespace PromiDataXWoo\Frontend;

use PromiDataXWoo\Core\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Price-on-request inquiries ("Angebot anfordern").
 *
 * A price-on-request product hides Add to Cart and shows this form instead
 * (see templates/frontend/add-to-cart.php). Submissions are stored in
 * cx_inquiries and the site admin is emailed a summary.
 *
 * Static utility class, matching Promi\IgnoreRules — used directly by both
 * the frontend submission endpoint (Frontend\Ajax) and the admin list page
 * (Admin\InquiriesPage / Admin\Ajax) without requiring DI wiring through
 * every module constructor in between.
 */
final class Inquiries {

	public const STATUS_NEW     = 'new';
	public const STATUS_READ    = 'read';
	public const STATUS_REPLIED = 'replied';
	public const STATUS_CLOSED  = 'closed';

	private const MAX_MESSAGE_LENGTH = 5000;


	/*
	|--------------------------------------------------------------------------
	| Submission
	|--------------------------------------------------------------------------
	*/

	/**
	 * Validate, store, and notify the site admin about a new inquiry.
	 *
	 * @param array<string,mixed> $data
	 * @return int|\WP_Error New inquiry ID, or a WP_Error on validation
	 *                       failure.
	 */
	public static function submit( array $data ) {

		$name = sanitize_text_field(
			trim( (string) ( $data['name'] ?? '' ) )
		);

		$email = sanitize_email(
			trim( (string) ( $data['email'] ?? '' ) )
		);

		$phone = sanitize_text_field(
			trim( (string) ( $data['phone'] ?? '' ) )
		);

		$message = sanitize_textarea_field(
			trim( (string) ( $data['message'] ?? '' ) )
		);


		if ( '' === $name ) {

			return new \WP_Error(
				'pdxw_inquiry_missing_name',
				__( 'Please enter your name.', 'promi-data-x-woo' )
			);
		}

		if ( '' === $email || ! is_email( $email ) ) {

			return new \WP_Error(
				'pdxw_inquiry_invalid_email',
				__( 'Please enter a valid email address.', 'promi-data-x-woo' )
			);
		}


		$message = mb_substr(
			$message,
			0,
			self::MAX_MESSAGE_LENGTH
		);


		$product_id   = absint( $data['product_id'] ?? 0 );
		$variation_id = absint( $data['variation_id'] ?? 0 );
		$quantity     = max( 0, absint( $data['quantity'] ?? 0 ) );


		$selections       = $data['selections'] ?? null;
		$selections_json =
			is_array( $selections ) && ! empty( $selections )
				? wp_json_encode( $selections )
				: null;


		global $wpdb;

		$table = Database::table( 'inquiries' );

		$inserted = $wpdb->insert(
			$table,
			[
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'quantity'     => $quantity,
				'name'         => $name,
				'email'        => $email,
				'phone'        => $phone,
				'message'      => $message,
				'selections'   => $selections_json,
				'status'       => self::STATUS_NEW,
				'ip_address'   => self::client_ip(),
				'created_at'   => current_time( 'mysql' ),
			]
		);

		if ( ! $inserted ) {

			return new \WP_Error(
				'pdxw_inquiry_save_failed',
				__( 'Your request could not be saved. Please try again.', 'promi-data-x-woo' )
			);
		}

		$id = (int) $wpdb->insert_id;

		/*
		 * Notify from the values already validated/sanitized above rather
		 * than re-reading the row we just inserted — this endpoint can
		 * see bursty traffic (e.g. a marketing email driving quote
		 * requests), so avoid a redundant SELECT on every submission.
		 */
		self::notify_admin(
			[
				'product_id' => $product_id,
				'name'       => $name,
				'email'      => $email,
				'phone'      => $phone,
				'quantity'   => $quantity,
				'message'    => $message,
			]
		);

		return $id;
	}


	/*
	|--------------------------------------------------------------------------
	| Retrieval
	|--------------------------------------------------------------------------
	*/

	public static function get( int $id ): ?object {

		global $wpdb;

		$table = Database::table( 'inquiries' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
				$id
			)
		);

		return $row instanceof \stdClass
			? $row
			: null;
	}


	/**
	 * Paginated/searchable inquiry listing for the admin page.
	 *
	 * @return array{rows:array,total:int}
	 */
	public static function paged(
		string $search,
		string $status,
		string $orderby,
		string $order,
		int $page,
		int $per_page
	): array {

		global $wpdb;

		$table = Database::table( 'inquiries' );

		$where  = [];
		$params = [];

		if ( '' !== $search ) {

			$like = '%' . $wpdb->esc_like( $search ) . '%';

			$where[]  = '(name LIKE %s OR email LIKE %s OR message LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( '' !== $status && self::is_valid_status( $status ) ) {

			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$allowed_orderby = [
			'id',
			'name',
			'email',
			'status',
			'created_at',
			'product_id',
		];

		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'created_at';
		}

		$order = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';

		$where_sql = $where
			? ' WHERE ' . implode( ' AND ', $where )
			: '';

		$count_sql = "SELECT COUNT(*) FROM {$table}{$where_sql}";

		$total = $params
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
			: (int) $wpdb->get_var( $count_sql );

		$page     = max( 1, $page );
		$per_page = max( 1, $per_page );
		$offset   = ( $page - 1 ) * $per_page;

		$data_sql = "SELECT * FROM {$table}{$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		$data_params   = $params;
		$data_params[] = $per_page;
		$data_params[] = $offset;

		$rows = $wpdb->get_results(
			$wpdb->prepare( $data_sql, ...$data_params ),
			ARRAY_A
		);

		return [
			'rows'  => is_array( $rows ) ? $rows : [],
			'total' => $total,
		];
	}


	/**
	 * Return inquiry counts grouped by status.
	 *
	 * @return array<string,int>
	 */
	public static function counts_by_status(): array {

		global $wpdb;

		$table = Database::table( 'inquiries' );

		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status",
			ARRAY_A
		);

		$result = [];

		foreach ( $rows as $row ) {

			$result[ sanitize_key( $row['status'] ?? '' ) ] =
				(int) ( $row['total'] ?? 0 );
		}

		return $result;
	}


	/**
	 * Count new (unread) inquiries. Useful for an admin-menu badge.
	 */
	public static function new_count(): int {

		global $wpdb;

		$table = Database::table( 'inquiries' );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = %s",
				self::STATUS_NEW
			)
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Mutation
	|--------------------------------------------------------------------------
	*/

	public static function update_status(
		int $id,
		string $status
	): bool {

		if ( ! self::is_valid_status( $status ) ) {
			return false;
		}

		global $wpdb;

		$table = Database::table( 'inquiries' );

		return false !== $wpdb->update(
			$table,
			[
				'status' => $status,
			],
			[
				'id' => $id,
			]
		);
	}


	public static function delete( int $id ): bool {

		global $wpdb;

		$table = Database::table( 'inquiries' );

		return false !== $wpdb->delete(
			$table,
			[
				'id' => $id,
			],
			[
				'%d',
			]
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Statuses
	|--------------------------------------------------------------------------
	*/

	/**
	 * @return array<int,string>
	 */
	public static function statuses(): array {

		return [
			self::STATUS_NEW,
			self::STATUS_READ,
			self::STATUS_REPLIED,
			self::STATUS_CLOSED,
		];
	}


	public static function is_valid_status( string $status ): bool {

		return in_array(
			$status,
			self::statuses(),
			true
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Notification
	|--------------------------------------------------------------------------
	*/

	/**
	 * Email the site admin about a new inquiry.
	 *
	 * This intentionally always targets the site's admin_email rather than
	 * the configurable Promi import notification list (Promi\Config) —
	 * inquiries are a separate, customer-facing concern from Promi import
	 * health.
	 *
	 * Takes the already-sanitized submission fields directly (not a
	 * re-fetched DB row) — every field notify_admin() needs was already
	 * validated in submit() before the insert.
	 *
	 * @param array{product_id:int,name:string,email:string,phone:string,quantity:int,message:string} $inquiry
	 */
	private static function notify_admin( array $inquiry ): void {

		$to = get_option( 'admin_email' );

		if ( ! $to || ! is_email( $to ) ) {
			return;
		}

		$product_line = '';

		if ( ! empty( $inquiry['product_id'] ) ) {

			$product = function_exists( 'wc_get_product' )
				? wc_get_product( (int) $inquiry['product_id'] )
				: null;

			if ( $product ) {

				$product_line = sprintf(
					"%s\n%s\n\n",
					$product->get_name(),
					(string) get_edit_post_link( (int) $inquiry['product_id'], '' )
				);
			}
		}

		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] New price inquiry', 'promi-data-x-woo' ),
			wp_strip_all_tags( get_bloginfo( 'name' ) )
		);

		$body = sprintf(
			"%s%s: %s\n%s: %s\n%s: %s\n%s: %d\n\n%s:\n%s\n",
			$product_line,
			__( 'Name', 'promi-data-x-woo' ),
			$inquiry['name'],
			__( 'Email', 'promi-data-x-woo' ),
			$inquiry['email'],
			__( 'Phone', 'promi-data-x-woo' ),
			$inquiry['phone'] ? $inquiry['phone'] : '—',
			__( 'Quantity', 'promi-data-x-woo' ),
			(int) $inquiry['quantity'],
			__( 'Message', 'promi-data-x-woo' ),
			$inquiry['message'] ? $inquiry['message'] : '—'
		);

		wp_mail(
			$to,
			$subject,
			$body
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Utility
	|--------------------------------------------------------------------------
	*/

	private static function client_ip(): ?string {

		$ip = $_SERVER['REMOTE_ADDR'] ?? null;

		if ( ! is_string( $ip ) ) {
			return null;
		}

		return sanitize_text_field( $ip );
	}
}
