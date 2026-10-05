<?php

namespace PromiDataXWoo\Suppliers;

use PromiDataXWoo\Printing\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Applies supplier print-price data onto existing print options.
 *
 * Options are matched on cx_print_options.supplier_print_code (the
 * supplier's own code, imported from Promi) — never on our Promi SKU.
 * Only purchase prices and setup purchase amounts are written; selling
 * prices, option names and tier structure remain Promi-owned.
 *
 * Colour-dependent codes use the variant for the option's print_colors
 * (a stored 0 means "no explicit colour count" and uses the 1-colour
 * variant). Codes the adapter did not return (e.g. logo-size dependent)
 * are left untouched.
 */
final class PrintPriceApplier {

	private Repository $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}


	/**
	 * @param array<string,array{
	 *     colors_dependent:bool,
	 *     variants:array<int,array{setup:?float,prices:array<int,float>}>
	 * }> $codes
	 *
	 * @param string $sku_prefix Source SKU prefix (e.g. "A34-"); print codes are only unique per supplier.
	 *
	 * @return array{matched:int,updated:int,unmatched_codes:int}
	 */
	public function apply( array $codes, string $sku_prefix ): array {

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

				$variant = $this->variant_for( $entry, $option );

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
	private function variant_for( array $entry, object $option ): ?array {

		$variants = $entry['variants'] ?? [];

		if ( empty( $entry['colors_dependent'] ) ) {
			return $variants[0] ?? null;
		}

		$colors = max( 1, (int) ( $option->print_colors ?? 0 ) );

		return $variants[ $colors ] ?? null;
	}
}
