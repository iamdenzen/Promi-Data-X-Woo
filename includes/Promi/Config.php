<?php

namespace PromiDataXWoo\Promi;

defined( 'ABSPATH' ) || exit;

final class Config {

	public const FEED_OPTION  = 'cx_promi_feed';
	public const BATCH_OPTION = 'cx_promi_batch';
	public const PAUSE_OPTION = 'cx_promi_pause_cron';

	public const NOTIFICATION_EMAILS_OPTION = 'cx_promi_notification_emails';

	public static function feed_url(): string {

		$url = get_option( self::FEED_OPTION, '' );

		return is_string( $url )
			? esc_url_raw( $url )
			: '';
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