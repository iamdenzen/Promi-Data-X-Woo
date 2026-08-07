<?php

namespace PromiDataXWoo\Promi;

defined( 'ABSPATH' ) || exit;

final class Logger {

	private static ?float $start_time = null;

	public function debug(
		string $message,
		array $context = []
	): void {
		$this->write( 'DEBUG', $message, $context );
	}

	public function info(
		string $message,
		array $context = []
	): void {
		$this->write( 'INFO', $message, $context );
	}

	public function warning(
		string $message,
		array $context = []
	): void {
		$this->write( 'WARNING', $message, $context );
	}

	public function error(
		string $message,
		array $context = []
	): void {
		$this->write( 'ERROR', $message, $context );
	}

	public function log(
		string $message,
		array $context = []
	): void {
		$this->info( $message, $context );
	}

	private function write(
		string $level,
		string $message,
		array $context
	): void {

		if ( self::$start_time === null ) {
			self::$start_time = microtime( true );
		}

		$elapsed = round(
			( microtime( true ) - self::$start_time ) * 1000,
			2
		);

		$file = $this->log_file();

		if ( ! $file ) {
			return;
		}

		if ( $context ) {
			$message .= ' ' . wp_json_encode(
				$context,
				JSON_UNESCAPED_SLASHES
				| JSON_UNESCAPED_UNICODE
			);
		}

		$line = sprintf(
			"[%s] [%s] [%sms] %s%s",
			current_time( 'mysql' ),
			$level,
			$elapsed,
			$message,
			PHP_EOL
		);

		file_put_contents(
			$file,
			$line,
			FILE_APPEND | LOCK_EX
		);
	}

	private function log_file(): ?string {

		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return null;
		}

		/*
		 * Preserve current log directory for migration compatibility.
		 */
		$directory = trailingslashit(
			$uploads['basedir']
		) . 'cx-promi-logs';

		if (
			! is_dir( $directory )
			&& ! wp_mkdir_p( $directory )
		) {
			return null;
		}

		return trailingslashit( $directory )
			. 'promi-sync-'
			. current_time( 'Y-m-d' )
			. '.log';
	}
}