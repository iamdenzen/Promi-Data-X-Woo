<?php

namespace PromiDataXWoo\Promi;

defined( 'ABSPATH' ) || exit;

final class Config {

	public const FEED_OPTION  = 'cx_promi_feed';
	public const BATCH_OPTION = 'cx_promi_batch';
	public const PAUSE_OPTION = 'cx_promi_pause_cron';

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
}