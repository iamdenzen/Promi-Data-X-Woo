<?php

namespace PromiDataXWoo\Promi;

defined( 'ABSPATH' ) || exit;

/**
 * Sends email notifications about Promi import events.
 *
 * Recipients are configured under Promi-Data X Woo > Notifications
 * (Config::notification_emails()). If none are configured, every
 * notify_*() call below is a silent no-op.
 */
final class Notifier {

	/**
	 * Notify configured recipients about an import error.
	 *
	 * @param array<string,mixed> $context
	 */
	public function notify_error(
		string $summary,
		array $context = []
	): void {

		$body = $summary;

		if ( ! empty( $context ) ) {

			$body .= "\n\n" . $this->format_context( $context );
		}

		$this->send(
			sprintf(
				/* translators: %s: site name. */
				__( '[%s] Promi import error', 'promi-data-x-woo' ),
				$this->site_name()
			),
			$body
		);
	}


	/**
	 * Notify configured recipients about newly queued import work.
	 *
	 * @param array<int,string> $created
	 * @param array<int,string> $updated
	 * @param array<int,string> $disabled
	 */
	public function notify_queue_summary(
		array $created,
		array $updated,
		array $disabled
	): void {

		if (
			empty( $created )
			&& empty( $updated )
			&& empty( $disabled )
		) {
			return;
		}

		$lines   = [];
		$lines[] = sprintf(
			/* translators: %d: number of queued items. */
			__( 'The Promi feed index just queued %d work item(s):', 'promi-data-x-woo' ),
			count( $created ) + count( $updated ) + count( $disabled )
		);
		$lines[] = '';
		$lines[] = $this->summary_line( __( 'Create', 'promi-data-x-woo' ), $created );
		$lines[] = $this->summary_line( __( 'Update', 'promi-data-x-woo' ), $updated );
		$lines[] = $this->summary_line( __( 'Disable', 'promi-data-x-woo' ), $disabled );

		$this->send(
			sprintf(
				/* translators: %s: site name. */
				__( '[%s] Promi queue updated', 'promi-data-x-woo' ),
				$this->site_name()
			),
			implode( "\n", $lines )
		);
	}


	/**
	 * Notify configured recipients that an index run found nothing to do.
	 */
	public function notify_no_changes(
		int $feed_count
	): void {

		$this->send(
			sprintf(
				/* translators: %s: site name. */
				__( '[%s] Promi index: no changes detected', 'promi-data-x-woo' ),
				$this->site_name()
			),
			sprintf(
				/* translators: %d: number of products checked. */
				__(
					'The Promi feed index ran successfully and checked %d product(s), but none required creating, updating, or disabling. This can be expected, but if you were expecting changes it may be worth checking that the feed itself is updating.',
					'promi-data-x-woo'
				),
				$feed_count
			)
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Formatting
	|--------------------------------------------------------------------------
	*/

	private function summary_line(
		string $label,
		array $skus
	): string {

		if ( empty( $skus ) ) {

			return sprintf(
				'%s: %s',
				$label,
				__( 'none', 'promi-data-x-woo' )
			);
		}

		$shown = array_slice( $skus, 0, 25 );

		$line = sprintf(
			'%s (%d): %s',
			$label,
			count( $skus ),
			implode( ', ', $shown )
		);

		if ( count( $skus ) > count( $shown ) ) {

			$line .= ' ' . sprintf(
				/* translators: %d: number of additional items not shown. */
				__( '... and %d more', 'promi-data-x-woo' ),
				count( $skus ) - count( $shown )
			);
		}

		return $line;
	}


	/**
	 * @param array<string,mixed> $context
	 */
	private function format_context(
		array $context
	): string {

		$lines = [];

		foreach ( $context as $key => $value ) {

			$lines[] = sprintf(
				'%s: %s',
				$key,
				is_scalar( $value )
					? (string) $value
					: wp_json_encode( $value )
			);
		}

		return implode( "\n", $lines );
	}


	private function site_name(): string {

		return wp_strip_all_tags(
			get_bloginfo( 'name' )
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Delivery
	|--------------------------------------------------------------------------
	*/

	private function send(
		string $subject,
		string $body
	): void {

		$recipients = Config::notification_emails();

		if ( empty( $recipients ) ) {
			return;
		}

		wp_mail(
			$recipients,
			$subject,
			$body
		);
	}
}
