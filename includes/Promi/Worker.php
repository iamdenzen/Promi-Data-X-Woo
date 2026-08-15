<?php

namespace PromiDataXWoo\Promi;

defined( 'ABSPATH' ) || exit;

final class Worker {

	private Queue $queue;
	private Indexer $indexer;
	private Client $client;
	private Logger $logger;
	private ProductSync $product_sync;
	private Notifier $notifier;

	public function __construct(
		Queue $queue,
		Indexer $indexer,
		Client $client,
		Logger $logger,
		ProductSync $product_sync,
		Notifier $notifier
	) {
		$this->queue        = $queue;
		$this->indexer      = $indexer;
		$this->client       = $client;
		$this->logger       = $logger;
		$this->product_sync = $product_sync;
		$this->notifier     = $notifier;
	}

	public function run(): void {

		if ( Config::is_paused() ) {
			return;
		}

		$jobs = $this->queue->claim(
			Config::batch_size()
		);

		if ( empty( $jobs ) ) {
			return;
		}

		$this->logger->info(
			'Promi worker claimed jobs.',
			[
				'count' => count( $jobs ),
			]
		);

		foreach ( $jobs as $job ) {
			$this->process( $job );
		}
	}

	public function process(
		object $job
	): bool {

		$this->logger->info(
			'Processing Promi job.',
			[
				'id'      => (int) $job->id,
				'sku'     => $job->sku,
				'action'  => $job->action,
				'attempt' => (int) $job->attempts,
			]
		);

		try {

			/**
			 * Disable does NOT require an index record.
			 *
			 * This fixes the bug in the existing implementation.
			 */
			if (
				Queue::ACTION_DISABLE
				=== $job->action
			) {

				$this->product_sync->disable(
					$job->sku
				);

				$this->queue->complete(
					(int) $job->id
				);

				return true;
			}

			$index = $this->indexer->get(
				$job->sku
			);

			if ( ! $index ) {

				throw new \RuntimeException(
					sprintf(
						'Promi index record not found for %s.',
						$job->sku
					)
				);
			}

			if ( empty( $index->json_url ) ) {

				throw new \RuntimeException(
					sprintf(
						'Promi JSON URL missing for %s.',
						$job->sku
					)
				);
			}

			$data = $this->client->get_product(
				$index->json_url
			);

			if ( is_wp_error( $data ) ) {

				throw new \RuntimeException(
					$data->get_error_message()
				);
			}

			$this->product_sync->sync(
				$data,
				$job->action
			);

			$this->queue->complete(
				(int) $job->id
			);

			$this->logger->info(
				'Promi job completed.',
				[
					'id'  => (int) $job->id,
					'sku' => $job->sku,
				]
			);

			return true;

		} catch ( \Throwable $e ) {

			$permanent = $this->queue->fail(
				$job,
				$e->getMessage()
			);

			$this->logger->error(
				'Promi job failed.',
				[
					'id'      => (int) $job->id,
					'sku'     => $job->sku,
					'action'  => $job->action,
					'error'   => $e->getMessage(),
					'attempt' => (int) $job->attempts,
				]
			);

			/*
			 * Only notify on the final, permanent failure — not on every
			 * transient retry attempt — to avoid alert spam for jobs that
			 * recover on their own.
			 */
			if ( $permanent ) {

				$this->notifier->notify_error(
					sprintf(
						'Promi job permanently failed for SKU %s (action: %s) after %d attempts.',
						$job->sku,
						$job->action,
						(int) $job->attempts
					),
					[
						'id'    => (int) $job->id,
						'sku'   => $job->sku,
						'action' => $job->action,
						'error' => $e->getMessage(),
					]
				);
			}

			return false;
		}
	}
}