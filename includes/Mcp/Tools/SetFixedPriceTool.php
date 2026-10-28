<?php

namespace PromiDataXWoo\Mcp\Tools;

use PromiDataXWoo\Pricing\ChangeSet;

defined( 'ABSPATH' ) || exit;

/**
 * Sets or removes fixed prices. With $remove true the price is always null.
 */
final class SetFixedPriceTool extends AbstractPricingTool {

	private bool $remove;

	public function __construct( bool $remove = false ) {
		$this->remove = $remove;
	}

	public function name(): string {
		return $this->remove ? 'pdxw_remove_fixed_price' : 'pdxw_set_fixed_price';
	}

	public function description(): string {

		if ( $this->remove ) {
			return 'Removes fixed prices of a product so its prices follow Promi data + markup again. Give qty to remove one tier, or omit qty to remove the whole ladder of that kind (and ref_id). Single or bulk via "prices". Admin only.';
		}

		return 'Sets a static (fixed) price on top of Promi data for one product tier. A fixed price is final: no discount, markup or rounding is applied to it, and imports never change it. kind: article = product price tier (qty = tier quantity); print_price = print unit price tier (ref_id = print option id, qty = tier quantity); print_fee = print fee (ref_id = fee id; the price is per fee unit, colors/quantity multipliers still apply). variation_id 0 = every variation. To replace a whole tier ladder, send every tier; tiers of a kind that has any fixed row REPLACE the Promi ladder for that variation. Single or bulk via "prices". Applied immediately unless apply=false (preview only). Admin only.';
	}

	public function input_schema(): array {

		$properties = [
			'product_id'   => [
				'type'        => 'integer',
				'description' => 'Parent product id.',
			],
			'variation_id' => [
				'type'        => 'integer',
				'description' => 'Variation id; 0 or omitted = all variations of the product.',
			],
			'kind'         => [
				'type'        => 'string',
				'enum'        => [ 'article', 'print_price', 'print_fee' ],
			],
			'ref_id'       => [
				'type'        => 'integer',
				'description' => 'Print option id (print_price) or fee id (print_fee). Not used for article.',
			],
			'qty'          => [
				'type'        => 'integer',
				'description' => 'Tier quantity (article / print_price). Omitted for print_fee.',
			],
		];

		if ( ! $this->remove ) {
			$properties['price'] = [
				'type'        => 'number',
				'description' => 'Final customer price (>= 0).',
			];
		}

		return [
			'type'       => 'object',
			'properties' => $properties + [
				'prices' => [
					'type'        => 'array',
					'description' => 'Bulk: several entries with the same fields (max 500).',
					'items'       => [
						'type'       => 'object',
						'required'   => [ 'product_id', 'kind' ],
						'properties' => $properties,
					],
				],
				'apply'  => [
					'type'        => 'boolean',
					'description' => 'true (default) saves immediately; false returns the preview only.',
				],
			],
		];
	}

	public function execute( array $arguments = [] ): array {

		$this->boot();

		$remove = $this->remove;

		$changes = array_map(
			static function ( $row ) use ( $remove ): array {

				$row = [ 'type' => 'fixed_price' ] + $row;

				if ( $remove ) {
					$row['price'] = null;
				}

				return $row;
			},
			$this->items(
				$arguments,
				'prices',
				[ 'product_id', 'variation_id', 'kind', 'ref_id', 'qty', 'price' ]
			)
		);

		return $this->respond( ChangeSet::run( $changes, $this->apply_flag( $arguments ) ) );
	}
}
