<?php

namespace PromiDataXWoo\Pricing;

use InvalidArgumentException;
use PromiDataXWoo\Core\Database;
use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Tier-price repository.
 *
 * Owns all direct access to the cx_tier_prices table.
 *
 * Supports:
 *
 * - Product-level tiers.
 * - Variation-level tiers.
 * - Selling prices.
 * - Purchase prices.
 * - Quantity-tier resolution.
 * - Parent-product fallback.
 * - Atomic tier replacement.
 * - Product/variation cleanup.
 *
 * Business rules and Promi transformation do not belong here.
 */
final class PriceRepository {

	/**
	 * Return the tier-pricing table.
	 */
	private function table(): string {

		return Database::table(
			'tier_prices'
		);
	}


	/**
	 * Return WordPress database instance.
	 */
	private function db(): \wpdb {

		global $wpdb;

		return $wpdb;
	}


	/*
	|--------------------------------------------------------------------------
	| Exact Tier Reads
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return all raw tiers for one exact product/variation target.
	 *
	 * No parent fallback is performed here.
	 */
	public function get(
		int $product_id,
		int $variation_id = 0
	): array {

		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );

		if ( ! $product_id ) {
			return [];
		}

		$db = $this->db();

		return $db->get_results(
			$db->prepare(
				'SELECT
					id,
					product_id,
					variation_id,
					qty,
					price,
					purchase_price
				FROM ' . $this->table() . '
				WHERE product_id = %d
					AND variation_id = %d
				ORDER BY qty ASC',
				$product_id,
				$variation_id
			)
		);
	}


	/**
	 * Return one exact quantity tier.
	 *
	 * No quantity approximation or parent fallback is performed.
	 */
	public function find(
		int $product_id,
		int $variation_id,
		int $quantity
	): ?object {

		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );
		$quantity     = max(
			1,
			absint( $quantity )
		);

		if ( ! $product_id ) {
			return null;
		}

		$db = $this->db();

		$row = $db->get_row(
			$db->prepare(
				'SELECT
					id,
					product_id,
					variation_id,
					qty,
					price,
					purchase_price
				FROM ' . $this->table() . '
				WHERE product_id = %d
					AND variation_id = %d
					AND qty = %d
				LIMIT 1',
				$product_id,
				$variation_id,
				$quantity
			)
		);

		return $row ?: null;
	}


	/*
	|--------------------------------------------------------------------------
	| Selling Price Reads
	|--------------------------------------------------------------------------
	*/

	/**
	 * Resolve the applicable selling price.
	 *
	 * Resolution order:
	 *
	 * 1. Variation tier where qty <= requested qty, highest qty wins.
	 * 2. If requested quantity is below every variation tier, first
	 *    variation tier.
	 * 3. If the variation has no selling tiers, repeat against parent
	 *    variation_id = 0.
	 *
	 * This preserves the existing CX Tiered Pricing behavior.
	 */
	public function get_applicable_selling_price(
		int $product_id,
		int $variation_id,
		int $quantity
	): ?float {

		return $this->get_applicable_price(
			$product_id,
			$variation_id,
			$quantity,
			'price'
		);
	}


	/**
	 * Return selling tiers.
	 *
	 * Variation tiers are preferred. If none exist, parent-level tiers are
	 * returned.
	 */
	public function get_selling_tiers(
		int $product_id,
		int $variation_id = 0
	): array {

		return $this->get_price_tiers(
			$product_id,
			$variation_id,
			'price'
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Purchase Price Reads
	|--------------------------------------------------------------------------
	*/

	/**
	 * Resolve the applicable purchase price.
	 *
	 * Purchase prices use exactly the same tier-resolution algorithm as
	 * selling prices, but NULL purchase-price rows are ignored.
	 */
	public function get_applicable_purchase_price(
		int $product_id,
		int $variation_id,
		int $quantity
	): ?float {

		return $this->get_applicable_price(
			$product_id,
			$variation_id,
			$quantity,
			'purchase_price'
		);
	}


	/**
	 * Return purchase-price tiers.
	 *
	 * Rows with NULL purchase prices are excluded.
	 */
	public function get_purchase_tiers(
		int $product_id,
		int $variation_id = 0
	): array {

		return $this->get_price_tiers(
			$product_id,
			$variation_id,
			'purchase_price'
		);
	}


	/**
	 * Return exact purchase prices keyed by quantity.
	 *
	 * This is useful when selling tiers are replaced independently and their
	 * existing buying prices need to be preserved.
	 *
	 * [
	 *     1   => 4.25,
	 *     100 => 3.80,
	 * ]
	 */
	public function get_purchase_prices_by_quantity(
		int $product_id,
		int $variation_id = 0
	): array {

		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );

		if ( ! $product_id ) {
			return [];
		}

		$db = $this->db();

		$rows = $db->get_results(
			$db->prepare(
				'SELECT
					qty,
					purchase_price
				FROM ' . $this->table() . '
				WHERE product_id = %d
					AND variation_id = %d
					AND purchase_price IS NOT NULL
				ORDER BY qty ASC',
				$product_id,
				$variation_id
			),
			ARRAY_A
		);

		$prices = [];

		foreach ( $rows as $row ) {

			if (
				! isset(
					$row['qty'],
					$row['purchase_price']
				)
			) {
				continue;
			}

			$prices[
				(int) $row['qty']
			] = (float) $row['purchase_price'];
		}

		return $prices;
	}


	/*
	|--------------------------------------------------------------------------
	| Quantity Reads
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return unique tier quantities.
	 *
	 * When variation_id is provided, only that variation is queried.
	 *
	 * When variation_id is NULL, quantities across the entire product and
	 * all of its variations are returned.
	 *
	 * This preserves the distinction used by the existing tiered-pricing
	 * frontend.
	 */
	public function get_quantities(
		int $product_id,
		?int $variation_id = null
	): array {

		$product_id = absint(
			$product_id
		);

		if ( ! $product_id ) {
			return [];
		}

		$db = $this->db();

		if ( null !== $variation_id ) {

			$rows = $db->get_col(
				$db->prepare(
					'SELECT DISTINCT qty
					FROM ' . $this->table() . '
					WHERE product_id = %d
						AND variation_id = %d
					ORDER BY qty ASC',
					$product_id,
					absint( $variation_id )
				)
			);

		} else {

			$rows = $db->get_col(
				$db->prepare(
					'SELECT DISTINCT qty
					FROM ' . $this->table() . '
					WHERE product_id = %d
					ORDER BY qty ASC',
					$product_id
				)
			);
		}

		return array_map(
			'intval',
			$rows
		);
	}


	/**
	 * Return exact quantities for one target.
	 *
	 * Unlike get_quantities(), this method always requires an exact
	 * variation_id and never aggregates across variations.
	 */
	public function get_target_quantities(
		int $product_id,
		int $variation_id = 0
	): array {

		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );

		if ( ! $product_id ) {
			return [];
		}

		$db = $this->db();

		return array_map(
			'intval',
			$db->get_col(
				$db->prepare(
					'SELECT qty
					FROM ' . $this->table() . '
					WHERE product_id = %d
						AND variation_id = %d
					ORDER BY qty ASC',
					$product_id,
					$variation_id
				)
			)
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Writes
	|--------------------------------------------------------------------------
	*/

	/**
	 * Atomically replace every tier for one product/variation target.
	 *
	 * Expected normalized structure:
	 *
	 * [
	 *     [
	 *         'qty'            => 1,
	 *         'price'          => 10.00,
	 *         'purchase_price' => 5.00,
	 *     ],
	 * ]
	 *
	 * The caller is responsible for validating and normalizing the tiers.
	 */
	public function replace(
		int $product_id,
		int $variation_id,
		array $tiers
	): bool {

		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );

		if ( ! $product_id ) {
			return false;
		}

		$db = $this->db();

		$db->query(
			'START TRANSACTION'
		);

		try {

			$deleted = $db->delete(
				$this->table(),
				[
					'product_id' =>
						$product_id,

					'variation_id' =>
						$variation_id,
				],
				[
					'%d',
					'%d',
				]
			);

			if ( false === $deleted ) {

				throw new RuntimeException(
					$db->last_error
						?: 'Could not delete existing tier prices.'
				);
			}

			if ( ! empty( $tiers ) ) {

				$this->insert_rows(
					$product_id,
					$variation_id,
					$tiers
				);
			}

			$db->query(
				'COMMIT'
			);

		} catch ( Throwable $e ) {

			$db->query(
				'ROLLBACK'
			);

			throw $e;
		}

		return true;
	}


	/**
	 * Replace selling tiers while preserving existing purchase prices at
	 * matching quantities.
	 *
	 * This mirrors the existing CX Tiered Pricing behavior and allows admin
	 * selling-price edits without silently deleting purchasing data.
	 *
	 * Expected tiers:
	 *
	 * [
	 *     [
	 *         'qty'   => 1,
	 *         'price' => 10.00,
	 *     ],
	 * ]
	 */
	public function replace_selling(
		int $product_id,
		int $variation_id,
		array $tiers
	): bool {

		$purchase_prices =
			$this->get_purchase_prices_by_quantity(
				$product_id,
				$variation_id
			);

		$combined = [];

		foreach ( $tiers as $tier ) {

			$qty = absint(
				$tier['qty']
				?? 0
			);

			if ( ! $qty ) {
				continue;
			}

			$combined[] = [
				'qty' =>
					$qty,

				'price' =>
					$tier['price']
					?? null,

				'purchase_price' =>
					$purchase_prices[
						$qty
					] ?? null,
			];
		}

		return $this->replace(
			$product_id,
			$variation_id,
			$combined
		);
	}


	/**
	 * Replace purchase prices without changing existing selling tiers.
	 *
	 * Purchase tiers may only exist for quantities already represented by
	 * selling tiers because both values occupy the same database row.
	 *
	 * Expected input:
	 *
	 * [
	 *     1   => 5.00,
	 *     100 => 4.25,
	 * ]
	 */
	public function replace_purchase(
		int $product_id,
		int $variation_id,
		array $prices_by_quantity
	): bool {

		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );

		if ( ! $product_id ) {
			return false;
		}

		$valid_quantities =
			$this->get_target_quantities(
				$product_id,
				$variation_id
			);

		if (
			empty( $valid_quantities )
			&& ! empty( $prices_by_quantity )
		) {
			return false;
		}

		$valid_map = array_fill_keys(
			$valid_quantities,
			true
		);

		$db = $this->db();

		$db->query(
			'START TRANSACTION'
		);

		try {

			/*
			 * First clear the existing purchase side while leaving the
			 * selling side untouched.
			 */
			$result = $db->query(
				$db->prepare(
					'UPDATE ' . $this->table() . '
					SET purchase_price = NULL
					WHERE product_id = %d
						AND variation_id = %d',
					$product_id,
					$variation_id
				)
			);

			if ( false === $result ) {

				throw new RuntimeException(
					$db->last_error
						?: 'Could not clear purchase tier prices.'
				);
			}

			foreach (
				$prices_by_quantity
					as $quantity => $price
			) {

				$quantity = absint(
					$quantity
				);

				if (
					! $quantity
					|| ! isset(
						$valid_map[
							$quantity
						]
					)
					|| null === $price
				) {
					continue;
				}

				$result = $db->query(
					$db->prepare(
						'UPDATE ' . $this->table() . '
						SET purchase_price = %f
						WHERE product_id = %d
							AND variation_id = %d
							AND qty = %d',
						(float) $price,
						$product_id,
						$variation_id,
						$quantity
					)
				);

				if ( false === $result ) {

					throw new RuntimeException(
						$db->last_error
							?: 'Could not update purchase tier price.'
					);
				}
			}

			$db->query(
				'COMMIT'
			);

		} catch ( Throwable $e ) {

			$db->query(
				'ROLLBACK'
			);

			throw $e;
		}

		return true;
	}


	/**
	 * Insert normalized tier rows.
	 */
	private function insert_rows(
		int $product_id,
		int $variation_id,
		array $tiers
	): void {

		if ( empty( $tiers ) ) {
			return;
		}

		$db = $this->db();

		$values = [];

		$params = [];

		foreach ( $tiers as $tier ) {

			$qty = absint(
				$tier['qty']
					?? 0
			);

			$price =
				$tier['price']
				?? null;

			$purchase_price =
				$tier['purchase_price']
				?? null;

			/*
			 * Input should already be normalized by TieredPricing.
			 * These checks protect the repository against malformed direct
			 * calls without duplicating the full business-normalization
			 * layer.
			 */
			if (
				! $qty
				|| ! is_numeric(
					$price
				)
			) {
				continue;
			}

			if (
				null === $purchase_price
				|| ''
					=== (string)
						$purchase_price
			) {

				$values[] =
					'(%d, %d, %d, %f, NULL)';

				$params[] =
					$product_id;

				$params[] =
					$variation_id;

				$params[] =
					$qty;

				$params[] =
					(float) $price;

				continue;
			}

			if (
				! is_numeric(
					$purchase_price
				)
			) {
				$purchase_price = null;
			}

			if ( null === $purchase_price ) {

				$values[] =
					'(%d, %d, %d, %f, NULL)';

				$params[] =
					$product_id;

				$params[] =
					$variation_id;

				$params[] =
					$qty;

				$params[] =
					(float) $price;

				continue;
			}

			$values[] =
				'(%d, %d, %d, %f, %f)';

			$params[] =
				$product_id;

			$params[] =
				$variation_id;

			$params[] =
				$qty;

			$params[] =
				(float) $price;

			$params[] =
				(float) $purchase_price;
		}

		if ( empty( $values ) ) {
			return;
		}

		$sql =
			'INSERT INTO '
			. $this->table()
			. '
			(
				product_id,
				variation_id,
				qty,
				price,
				purchase_price
			)
			VALUES '
			. implode(
				', ',
				$values
			);

		$result = $db->query(
			$db->prepare(
				$sql,
				...$params
			)
		);

		if ( false === $result ) {

			throw new RuntimeException(
				$db->last_error
					?: 'Could not insert tier prices.'
			);
		}
	}


	/*
	|--------------------------------------------------------------------------
	| Deletes
	|--------------------------------------------------------------------------
	*/

	/**
	 * Delete one exact product/variation price set.
	 */
	public function delete(
		int $product_id,
		int $variation_id = 0
	): int {

		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );

		if ( ! $product_id ) {
			return 0;
		}

		$deleted = $this->db()->delete(
			$this->table(),
			[
				'product_id' =>
					$product_id,

				'variation_id' =>
					$variation_id,
			],
			[
				'%d',
				'%d',
			]
		);

		return false === $deleted
			? 0
			: (int) $deleted;
	}


	/**
	 * Delete all tiers belonging to a parent product.
	 *
	 * This includes every variation because each row stores the parent
	 * product_id.
	 */
	public function delete_by_product(
		int $product_id
	): int {

		$product_id = absint(
			$product_id
		);

		if ( ! $product_id ) {
			return 0;
		}

		$deleted = $this->db()->delete(
			$this->table(),
			[
				'product_id' =>
					$product_id,
			],
			[
				'%d',
			]
		);

		return false === $deleted
			? 0
			: (int) $deleted;
	}


	/**
	 * Delete tiers belonging to one variation.
	 */
	public function delete_by_variation(
		int $variation_id
	): int {

		$variation_id = absint(
			$variation_id
		);

		if ( ! $variation_id ) {
			return 0;
		}

		$deleted = $this->db()->delete(
			$this->table(),
			[
				'variation_id' =>
					$variation_id,
			],
			[
				'%d',
			]
		);

		return false === $deleted
			? 0
			: (int) $deleted;
	}


	/*
	|--------------------------------------------------------------------------
	| Shared Price Resolution
	|--------------------------------------------------------------------------
	*/

	/**
	 * Resolve one price column for a quantity.
	 */
	private function get_applicable_price(
		int $product_id,
		int $variation_id,
		int $quantity,
		string $column
	): ?float {

		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );
		$quantity     = max(
			1,
			absint( $quantity )
		);

		$column =
			$this->validate_price_column(
				$column
			);

		if ( ! $product_id ) {
			return null;
		}


		/*
		|--------------------------------------------------------------------------
		| Variation
		|--------------------------------------------------------------------------
		*/

		if ( $variation_id ) {

			$price =
				$this->find_price_for_quantity(
					$product_id,
					$variation_id,
					$quantity,
					$column
				);

			if ( null !== $price ) {
				return $price;
			}
		}


		/*
		|--------------------------------------------------------------------------
		| Parent Fallback
		|--------------------------------------------------------------------------
		*/

		return $this->find_price_for_quantity(
			$product_id,
			0,
			$quantity,
			$column
		);
	}


	/**
	 * Resolve a price against one exact target.
	 *
	 * First:
	 *
	 *     highest tier <= requested quantity
	 *
	 * If no tier is low enough:
	 *
	 *     first available tier
	 *
	 * This is intentional and mirrors the current XSImpress implementation.
	 */
	private function find_price_for_quantity(
		int $product_id,
		int $variation_id,
		int $quantity,
		string $column
	): ?float {

		$db = $this->db();


		/*
		|--------------------------------------------------------------------------
		| Highest Tier <= Quantity
		|--------------------------------------------------------------------------
		*/

		$price = $db->get_var(
			$db->prepare(
				'SELECT ' . $column . '
				FROM ' . $this->table() . '
				WHERE product_id = %d
					AND variation_id = %d
					AND qty <= %d
					AND ' . $column . ' IS NOT NULL
				ORDER BY qty DESC
				LIMIT 1',
				$product_id,
				$variation_id,
				$quantity
			)
		);

		if ( null !== $price ) {
			return (float) $price;
		}


		/*
		|--------------------------------------------------------------------------
		| First Available Tier
		|--------------------------------------------------------------------------
		|
		| This matters when the requested quantity is below the first stored
		| quantity tier.
		*/

		$price = $db->get_var(
			$db->prepare(
				'SELECT ' . $column . '
				FROM ' . $this->table() . '
				WHERE product_id = %d
					AND variation_id = %d
					AND ' . $column . ' IS NOT NULL
				ORDER BY qty ASC
				LIMIT 1',
				$product_id,
				$variation_id
			)
		);

		return null !== $price
			? (float) $price
			: null;
	}


	/**
	 * Return all tiers for one price column.
	 *
	 * Variation rows are preferred as a complete set.
	 *
	 * We intentionally do not merge variation and parent tiers together.
	 * If the variation has any usable tiers, those tiers define the
	 * variation's price structure.
	 */
	private function get_price_tiers(
		int $product_id,
		int $variation_id,
		string $column
	): array {

		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );

		$column =
			$this->validate_price_column(
				$column
			);

		if ( ! $product_id ) {
			return [];
		}

		$db = $this->db();


		/*
		|--------------------------------------------------------------------------
		| Variation
		|--------------------------------------------------------------------------
		*/

		if ( $variation_id ) {

			$tiers = $db->get_results(
				$db->prepare(
					'SELECT
						qty,
						' . $column . ' AS price
					FROM ' . $this->table() . '
					WHERE product_id = %d
						AND variation_id = %d
						AND ' . $column . ' IS NOT NULL
					ORDER BY qty ASC',
					$product_id,
					$variation_id
				),
				ARRAY_A
			);

			if ( ! empty( $tiers ) ) {

				return $this->cast_price_tiers(
					$tiers
				);
			}
		}


		/*
		|--------------------------------------------------------------------------
		| Parent
		|--------------------------------------------------------------------------
		*/

		$tiers = $db->get_results(
			$db->prepare(
				'SELECT
					qty,
					' . $column . ' AS price
				FROM ' . $this->table() . '
				WHERE product_id = %d
					AND variation_id = 0
					AND ' . $column . ' IS NOT NULL
				ORDER BY qty ASC',
				$product_id
			),
			ARRAY_A
		);

		return $this->cast_price_tiers(
			$tiers
		);
	}


	/**
	 * Normalize database price rows into typed values.
	 */
	private function cast_price_tiers(
		array $tiers
	): array {

		$result = [];

		foreach ( $tiers as $tier ) {

			if (
				! isset(
					$tier['qty'],
					$tier['price']
				)
			) {
				continue;
			}

			$result[] = [
				'qty' =>
					(int) $tier['qty'],

				'price' =>
					(float) $tier['price'],
			];
		}

		return $result;
	}


	/**
	 * Whitelist a dynamic price-column identifier.
	 *
	 * SQL identifiers cannot be passed through $wpdb->prepare(), so every
	 * dynamic column must pass this whitelist first.
	 */
	private function validate_price_column(
		string $column
	): string {

		if (
			! in_array(
				$column,
				[
					'price',
					'purchase_price',
				],
				true
			)
		) {

			throw new InvalidArgumentException(
				'Invalid tier price column.'
			);
		}

		return $column;
	}
}