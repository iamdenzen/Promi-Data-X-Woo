<?php

namespace PromiDataXWoo\Promi;

use PromiDataXWoo\Core\Database;

defined( 'ABSPATH' ) || exit;

final class Indexer {

	private const DISABLE_AFTER_DAYS = 7;
	private const MINIMUM_FEED_ITEMS = 10;

	private Client $client;
	private Queue $queue;
	private Logger $logger;

	public function __construct(
		Client $client,
		Queue $queue,
		Logger $logger
	) {
		$this->client = $client;
		$this->queue  = $queue;
		$this->logger = $logger;
	}

	public function run(): void {

		if ( Config::is_paused() ) {
			return;
		}

		$this->logger->info( 'Promi index started.' );

		$content = $this->client->get_feed();

		if ( is_wp_error( $content ) ) {

			$this->logger->error(
				'Promi index failed to fetch feed.',
				[
					'error' => $content->get_error_message(),
				]
			);

			return;
		}

		$lines = preg_split(
			'/\r\n|\r|\n/',
			$content
		);

		$lines = array_values(
			array_filter(
				array_map( 'trim', $lines ),
				static fn( $line ) => '' !== $line
			)
		);

		if ( empty( $lines ) ) {

			$this->logger->error(
				'Promi feed contained no usable lines.'
			);

			return;
		}

		/**
		 * Existing Promi feed places category data on line one.
		 *
		 * Catalog will eventually listen to this action.
		 */
		$category_line = array_shift( $lines );

		do_action(
			'pdxw_promi_category_feed',
			$category_line
		);

		/**
		 * Preserve the existing mass-disable protection.
		 */
		if ( count( $lines ) < self::MINIMUM_FEED_ITEMS ) {

			$this->logger->error(
				'Promi feed safety check failed.',
				[
					'items' => count( $lines ),
				]
			);

			return;
		}

		$feed = $this->parse_feed( $lines );

		if ( empty( $feed ) ) {

			$this->logger->error(
				'Promi feed contained no valid product records.'
			);

			return;
		}

		$this->synchronize_index( $feed );

		$this->queue_stale_products();

		$this->logger->info(
			'Promi index completed.',
			[
				'products' => count( $feed ),
			]
		);
	}

	/**
	 * Find an indexed product.
	 */
	public function get( string $sku ): ?object {

		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT *
				FROM ' . Database::table( 'promi_index' ) . '
				WHERE sku = %s
				LIMIT 1',
				$sku
			)
		);
	}

	private function parse_feed( array $lines ): array {

		$feed = [];

		foreach ( $lines as $line ) {

			if ( ! str_contains( $line, '|' ) ) {
				continue;
			}

			[
				$url,
				$hash,
			] = array_map(
				'trim',
				explode( '|', $line, 2 )
			);

			if ( '' === $url || '' === $hash ) {
				continue;
			}

			$url = esc_url_raw( $url );

			if ( ! $url ) {
				continue;
			}

			$path = parse_url(
				$url,
				PHP_URL_PATH
			);

			if ( ! is_string( $path ) ) {
				continue;
			}

			$filename = basename( $path );

			$sku = preg_replace(
				'/\.json$/i',
				'',
				$filename
			);

			$sku = rawurldecode(
				trim( (string) $sku )
			);

			if ( '' === $sku ) {
				continue;
			}

			$feed[ $sku ] = [
				'hash' => $hash,
				'url'  => $url,
			];
		}

		return $feed;
	}

	private function synchronize_index(
		array $feed
	): void {

		global $wpdb;

		$table = Database::table( 'promi_index' );
		$now   = current_time( 'mysql' );

		/**
		 * 30k–50k products is reasonable to map once in memory and avoids
		 * generating one enormous SQL IN(...) statement like the old code.
		 */
		$existing_rows = $wpdb->get_results(
			"SELECT sku, hash, json_url, last_seen
			FROM {$table}",
			ARRAY_A
		);

		$existing = [];

		foreach ( $existing_rows as $row ) {
			$existing[ $row['sku'] ] = $row;
		}

		foreach ( $feed as $sku => $item ) {

			if ( ! isset( $existing[ $sku ] ) ) {

				$wpdb->insert(
					$table,
					[
						'sku'       => $sku,
						'hash'      => $item['hash'],
						'json_url'  => $item['url'],
						'last_seen' => $now,
					]
				);

				$this->queue->enqueue(
					$sku,
					Queue::ACTION_CREATE
				);

				continue;
			}

			$current = $existing[ $sku ];

			$changed = ! hash_equals(
				(string) $current['hash'],
				(string) $item['hash']
			);

			$wpdb->update(
				$table,
				[
					'hash'      => $item['hash'],
					'json_url'  => $item['url'],
					'last_seen' => $now,
				],
				[
					'sku' => $sku,
				]
			);

			if ( $changed ) {

				$this->queue->enqueue(
					$sku,
					Queue::ACTION_UPDATE
				);
			}
		}
	}

	private function queue_stale_products(): void {

		global $wpdb;

		$table = Database::table( 'promi_index' );

		$cutoff = current_datetime()
			->modify(
				'-' . self::DISABLE_AFTER_DAYS . ' days'
			)
			->format( 'Y-m-d H:i:s' );

		$stale_skus = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT sku
				FROM {$table}
				WHERE last_seen IS NOT NULL
				AND last_seen <= %s",
				$cutoff
			)
		);

		if ( empty( $stale_skus ) ) {
			return;
		}

		$queued = [];

		foreach ( $stale_skus as $sku ) {

			if (
				$this->queue->enqueue(
					$sku,
					Queue::ACTION_DISABLE
				)
			) {
				$queued[] = $sku;
			}
		}

		/**
		 * Only remove rows for products whose disable job was actually queued.
		 *
		 * This differs from the old code, which also deleted ignored SKUs.
		 */
		foreach (
			array_chunk( $queued, 500 )
			as $chunk
		) {

			$placeholders = implode(
				',',
				array_fill(
					0,
					count( $chunk ),
					'%s'
				)
			);

			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table}
					WHERE sku IN ({$placeholders})",
					...$chunk
				)
			);
		}
	}
}