<?php

namespace PromiDataXWoo\Suppliers;

use PromiDataXWoo\Catalog\Catalog;
use PromiDataXWoo\Suppliers\Contracts\PrintPriceProvider;
use PromiDataXWoo\Suppliers\Contracts\SupplierAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Runs one supplier source end to end: resolve its adapter, fetch and
 * normalize its data, match SKUs against existing WooCommerce products,
 * and apply the allow-listed fields.
 *
 * Nothing is ever created here — only SKUs that already exist as
 * WooCommerce products/variations (imported by Promi) are touched.
 */
final class Sync {

	private AdapterRegistry $adapters;

	private ProductApplier $applier;

	private Catalog $catalog;

	private SourceRepository $sources;

	private Logger $logger;

	private ?PrintPriceApplier $print_prices;

	public function __construct(
		AdapterRegistry $adapters,
		ProductApplier $applier,
		Catalog $catalog,
		SourceRepository $sources,
		Logger $logger,
		?PrintPriceApplier $print_prices = null
	) {
		$this->adapters     = $adapters;
		$this->applier      = $applier;
		$this->catalog      = $catalog;
		$this->sources      = $sources;
		$this->logger       = $logger;
		$this->print_prices = $print_prices;
	}


	/**
	 * @return array{status:string,message:string,matched:int,updated:int}
	 */
	public function run( object $source ): array {

		$id          = (int) ( $source->id ?? 0 );
		$adapter_key = trim( (string) ( $source->adapter_key ?? '' ) );

		$this->logger->info(
			'Supplier sync started.',
			[
				'source'  => $source->name ?? $id,
				'adapter' => $adapter_key,
			]
		);

		$adapter = $this->adapters->resolve( $adapter_key );

		if ( ! $adapter ) {

			$message = __( 'No adapter is configured for this source.', 'promi-data-x-woo' );

			$this->sources->record_run( $id, 'error', $message, 0, 0 );

			return [
				'status'  => 'error',
				'message' => $message,
				'matched' => 0,
				'updated' => 0,
			];
		}

		$print_note = $this->sync_print_prices( $adapter, $source );

		$rows = $adapter->fetch( $source, $this->catalog );

		if ( is_wp_error( $rows ) ) {

			$message = $rows->get_error_message();

			$this->logger->error(
				'Supplier sync failed.',
				[
					'source' => $id,
					'error'  => $message,
				]
			);

			$this->sources->record_run( $id, 'error', $message, 0, 0 );

			return [
				'status'  => 'error',
				'message' => $message,
				'matched' => 0,
				'updated' => 0,
			];
		}

		if ( empty( $rows ) ) {

			$message = __( 'No matching SKUs were found in the feed.', 'promi-data-x-woo' ) . $print_note;

			$this->sources->record_run( $id, 'success', $message, 0, 0 );

			return [
				'status'  => 'success',
				'message' => $message,
				'matched' => 0,
				'updated' => 0,
			];
		}

		$product_ids = $this->catalog
			->products()
			->ids_by_skus( array_keys( $rows ) );

		$matched = 0;
		$updated = 0;

		foreach ( $product_ids as $sku => $product_id ) {

			$data = $rows[ $sku ] ?? null;

			if ( ! $data ) {
				continue;
			}

			$matched++;

			if ( $this->applier->apply( $product_id, $data ) ) {
				$updated++;
			}
		}

		$message = sprintf(
			/* translators: 1: matched product count, 2: updated product count. */
			__( 'Matched %1$d product(s)/variation(s), updated %2$d.', 'promi-data-x-woo' ),
			$matched,
			$updated
		) . $print_note;

		$this->sources->record_run( $id, 'success', $message, $matched, $updated );

		$this->logger->info(
			'Supplier sync completed.',
			[
				'source'  => $id,
				'matched' => $matched,
				'updated' => $updated,
			]
		);

		return [
			'status'  => 'success',
			'message' => $message,
			'matched' => $matched,
			'updated' => $updated,
		];
	}


	/**
	 * Apply the supplier's print-price feed onto print options, when the
	 * adapter supports it and a print price URL is configured.
	 *
	 * Failures are logged and reported in the returned note but never abort
	 * the stock/price sync, and vice versa.
	 *
	 * @return string Message suffix (empty when not applicable).
	 */
	private function sync_print_prices( SupplierAdapter $adapter, object $source ): string {

		if (
			! $adapter instanceof PrintPriceProvider
			|| ! $this->print_prices
			|| '' === trim( (string) ( $source->print_price_endpoint_url ?? '' ) )
		) {
			return '';
		}

		$codes = $adapter->fetch_print_prices( $source );

		if ( is_wp_error( $codes ) ) {

			$this->logger->error(
				'Supplier print price sync failed.',
				[
					'source' => (int) ( $source->id ?? 0 ),
					'error'  => $codes->get_error_message(),
				]
			);

			return ' ' . sprintf(
				/* translators: %s: error message. */
				__( 'Print prices failed: %s', 'promi-data-x-woo' ),
				$codes->get_error_message()
			);
		}

		$result = $this->print_prices->apply(
			$codes,
			(string) ( $source->sku_prefix ?? '' )
		);

		$this->logger->info(
			'Supplier print price sync completed.',
			[
				'source' => (int) ( $source->id ?? 0 ),
			] + $result
		);

		return ' ' . sprintf(
			/* translators: 1: matched print option count, 2: updated print option count. */
			__( 'Print options: matched %1$d, updated %2$d.', 'promi-data-x-woo' ),
			$result['matched'],
			$result['updated']
		);
	}
}
