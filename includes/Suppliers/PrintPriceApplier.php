<?php

namespace PromiDataXWoo\Suppliers;

use PromiDataXWoo\Printing\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Applies supplier print-price data onto existing print options.
 *
 * Options are matched on cx_print_options.supplier_print_code (the
 * supplier's own code, imported from Promi) — never on our Promi SKU.
 * Only purchase prices and setup/handling purchase amounts are written;
 * selling prices, option names and tier structure remain Promi-owned.
 *
 * Which price variant applies to an option is decided by the code's
 * "variant_by" (see Contracts\PrintPriceProvider). Codes the adapter did
 * not return (e.g. logo-size dependent) are left untouched.
 */
final class PrintPriceApplier {

	private Repository $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}


	/**
	 * @param array{codes:array,handling:array<string,float>} $prices Adapter output.
	 * @param string $sku_prefix Source SKU prefix (e.g. "A34-"); print codes are only unique per supplier.
	 *
	 * @return array{matched:int,updated:int,unmatched_codes:int}
	 */
	public function apply( array $prices, string $sku_prefix ): array {

		$codes    = $prices['codes'] ?? [];
		$handling = $prices['handling'] ?? [];

		$matched         = 0;
		$updated         = 0;
		$unmatched_codes = 0;

		foreach ( $codes as $code => $entry ) {

			$options = $this->repository->get_options_by_print_code(
				(string) $code,
				null,
				$sku_prefix
			);

			if ( empty( $options ) ) {

				++$unmatched_codes;

				continue;
			}

			foreach ( $options as $option ) {

				$variant = $this->variant_for( (string) $code, $entry, $option );

				if ( ! $variant ) {
					continue;
				}

				++$matched;

				$option_id = (int) $option->id;

				$changed = $this->repository->apply_supplier_purchase_ladder(
					$option_id,
					$variant['prices']
				);

				if ( null !== $variant['setup'] ) {

					$changed += $this->repository->apply_supplier_setup_purchase(
						$option_id,
						$variant['setup']
					);
				}

				foreach ( $handling as $fee_sku => $amount ) {

					$changed += $this->repository->apply_supplier_handling_purchase(
						$option_id,
						(string) $fee_sku,
						(float) $amount
					);
				}

				if ( $changed > 0 ) {
					++$updated;
				}
			}
		}

		return [
			'matched'         => $matched,
			'updated'         => $updated,
			'unmatched_codes' => $unmatched_codes,
		];
	}


	/**
	 * @return array{setup:?float,prices:array<int,float>}|null
	 */
	private function variant_for( string $code, array $entry, object $option ): ?array {

		$variants = $entry['variants'] ?? [];

		switch ( $entry['variant_by'] ?? 'none' ) {

			case 'colors':
				// A stored 0 means "no explicit colour count": use 1 colour.
				$key = (string) max( 1, (int) ( $option->print_colors ?? 0 ) );
				break;

			case 'range':
				$sku = (string) ( $option->supplier_sku ?? '' );
				$key = str_starts_with( $sku, $code )
					? substr( $sku, strlen( $code ) )
					: '';
				break;

			default:
				$key = '0';
		}

		return $variants[ $key ] ?? null;
	}
}
