<?php

namespace PromiDataXWoo\Promi;

defined( 'ABSPATH' ) || exit;

final class Config {

	public const FEED_OPTION  = 'cx_promi_feed';
	public const BATCH_OPTION = 'cx_promi_batch';
	public const PAUSE_OPTION = 'cx_promi_pause_cron';

	public const NOTIFICATION_EMAILS_OPTION = 'cx_promi_notification_emails';

	/**
	 * Trailing path Promi appends to the configured feed URL, e.g.
	 *
	 *     https://promi-dl.de/Profiles/Live/{guid}/import/import.txt
	 *
	 * Every per-product JSON URL in the feed shares the same base
	 * (".../{guid}/") that precedes this suffix.
	 */
	private const FEED_PATH_SUFFIX = 'Import/Import.txt';

	public static function feed_url(): string {

		$url = get_option( self::FEED_OPTION, '' );

		return is_string( $url )
			? esc_url_raw( $url )
			: '';
	}

	/**
	 * The feed URL with its trailing "import/import.txt" removed and
	 * exactly one trailing slash, e.g.
	 *
	 *     https://promi-dl.de/Profiles/Live/{guid}/
	 *
	 * Every per-product JSON URL Promi returns is relative to this base.
	 * Returns '' when no feed URL is configured, or when the configured
	 * URL doesn't end with the expected suffix (so callers never silently
	 * build a URL against the wrong host).
	 */
	public static function promi_base_url(): string {

		$url = self::feed_url();

		if ( '' === $url ) {
			return '';
		}

		if ( ! str_ends_with( $url, self::FEED_PATH_SUFFIX ) ) {
			return '';
		}

		$url = substr(
			$url,
			0,
			-strlen( self::FEED_PATH_SUFFIX )
		);

		return rtrim( $url, '/' ) . '/';
	}

	/**
	 * Convert a full Promi JSON URL into a path relative to
	 * promi_base_url(), for compact storage in cx_promi_index, e.g.
	 *
	 *     https://promi-dl.de/Profiles/Live/{guid}/A36/A36-MO1001b.json
	 *         → /A36/A36-MO1001b.json
	 *
	 * Falls back to returning $url unchanged when it doesn't start with
	 * the configured base — this must never silently corrupt a URL that
	 * doesn't match what we expected.
	 *
	 * $base_url can be passed in when the caller already has it (e.g. a
	 * loop processing many items) to avoid recomputing it per call.
	 */
	public static function relative_json_path(
		string $url,
		?string $base_url = null
	): string {

		$base_url ??= self::promi_base_url();

		if (
			'' === $base_url
			|| 0 !== stripos( $url, $base_url )
		) {
			return $url;
		}

		return '/' . ltrim(
			substr( $url, strlen( $base_url ) ),
			'/'
		);
	}

	/**
	 * Resolve a Promi URL into a full, directly fetchable/clickable URL.
	 *
	 * Accepts either:
	 *
	 * - an already-absolute URL (legacy cx_promi_index rows stored before
	 *   relative_json_path() existed, or any URL outside the feed's own
	 *   domain) — returned unchanged.
	 * - a path relative to promi_base_url(), e.g. "/A36/A36-MO1001b.json"
	 *   — expanded against the configured feed's base URL.
	 */
	public static function resolve_promi_url( string $url ): string {

		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}

		$base_url = self::promi_base_url();

		if ( '' === $base_url ) {
			return $url;
		}

		return $base_url . ltrim( $url, '/' );
	}

	public static function set_feed_url( string $url ): bool {

		return update_option(
			self::FEED_OPTION,
			esc_url_raw( $url )
		);
	}

	public static function batch_size(): int {

		$batch = (int) get_option(
			self::BATCH_OPTION,
			10
		);

		return max( 1, min( 100, $batch ) );
	}

	public static function set_batch_size( int $batch ): bool {

		$batch = max( 1, min( 100, $batch ) );

		return update_option(
			self::BATCH_OPTION,
			$batch
		);
	}

	public static function is_paused(): bool {
		return (bool) get_option( self::PAUSE_OPTION, false );
	}

	public static function pause(): void {
		update_option( self::PAUSE_OPTION, true, false );
	}

	public static function resume(): void {
		delete_option( self::PAUSE_OPTION );
	}


	/**
	 * Return every configured notification recipient email address.
	 *
	 * @return array<int,string>
	 */
	public static function notification_emails(): array {

		$emails = get_option(
			self::NOTIFICATION_EMAILS_OPTION,
			[]
		);

		if ( ! is_array( $emails ) ) {
			return [];
		}

		return array_values(
			array_filter(
				array_map(
					'sanitize_email',
					$emails
				),
				'is_email'
			)
		);
	}

	/**
	 * Save the notification recipient list.
	 *
	 * @param array<int,string> $emails
	 */
	public static function set_notification_emails( array $emails ): bool {

		$emails = array_values(
			array_unique(
				array_filter(
					array_map(
						'sanitize_email',
						$emails
					),
					'is_email'
				)
			)
		);

		return update_option(
			self::NOTIFICATION_EMAILS_OPTION,
			$emails,
			false
		);
	}
}