<?php

namespace PromiDataXWoo\Mcp\Tools;

use PromiDataXWoo\Pricing\ChangeSet;

defined( 'ABSPATH' ) || exit;

/**
 * Locks or unlocks products.
 */
final class LockProductsTool extends AbstractPricingTool {

	private bool $locked;

	public function __construct( bool $locked ) {
		$this->locked = $locked;
	}

	public function name(): string {
		return $this->locked ? 'pdxw_lock_products' : 'pdxw_unlock_products';
	}

	public function description(): string {

		if ( $this->locked ) {
			return 'Locks products. A locked product keeps its current prices (article tiers, print unit prices, print fees) through every markup change, recalculation, Promi import and supplier sync: the current prices are frozen as fixed prices. Pass product_id or product_ids (bulk). Applied immediately unless apply=false. Admin only.';
		}

		return 'Unlocks products: the frozen prices from the lock are removed and the product follows Promi data + markup rules again (fixed prices set by hand stay). Pass product_id or product_ids (bulk). Admin only.';
	}

	public function input_schema(): array {

		return [
			'type'       => 'object',
			'properties' => [
				'product_id'  => [
					'type'        => 'integer',
					'description' => 'One product id.',
				],
				'product_ids' => [
					'type'        => 'array',
					'description' => 'Bulk: product ids.',
					'items'       => [ 'type' => 'integer' ],
				],
				'apply'       => [
					'type'        => 'boolean',
					'description' => 'true (default) saves immediately; false only validates.',
				],
			],
		];
	}

	public function execute( array $arguments = [] ): array {

		$this->boot();

		$ids = isset( $arguments['product_ids'] ) && is_array( $arguments['product_ids'] )
			? $arguments['product_ids']
			: [ $arguments['product_id'] ?? 0 ];

		$locked = $this->locked;

		$changes = array_map(
			static fn( $id ) => [
				'type'       => 'lock',
				'product_id' => $id,
				'locked'     => $locked,
			],
			$ids
		);

		return $this->respond( ChangeSet::run( $changes, $this->apply_flag( $arguments ) ) );
	}
}
