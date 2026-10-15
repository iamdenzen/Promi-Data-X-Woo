<?php

namespace PromiDataXWoo\Pricing;

use PromiDataXWoo\Core\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Supplier discount service.
 *
 * A supplier discount is configured against a SKU prefix, for example
 * "A86-", and is applied to every product whose SKU starts with that prefix.
 *
 * It is a percentage that converts Promi's RecommendedSellingPrice into an
 * effective cost, exactly like the manufacturer discount does.
 *
 * Priority:
 *
 *     Manufacturer Discount (product_brand)
 *         ↓ only when the brand has no discount configured
 *     Supplier Discount (SKU prefix, longest prefix wins)
 *         ↓
 *     no discount
 *
 * The priority itself is applied by CostCalculator. This class only stores
 * and resolves supplier discounts.
 */
final class SupplierDiscount {

	/**
	 * Per-request cache of enabled rules, longest prefix first.
	 *
	 * @var array<int,array{prefix:string,discount:float}>|null
	 */
	private ?array $rules = null;


	/*
	|--------------------------------------------------------------------------
	| Listing
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return every stored supplier discount, for the admin UI.
	 *
	 * @return array<int,array{sku_prefix:string,discount_percent:float}>
	 */
	public function all(): array {

		global $wpdb;

		$table =
			Database::table(
				'supplier_discounts'
			);

		$rows =
			$wpdb->get_results(
				"SELECT sku_prefix, discount_percent
				FROM {$table}
				WHERE enabled = 1
				ORDER BY sku_prefix ASC",
				ARRAY_A
			);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$result = [];

		foreach ( $rows as $row ) {

			$result[] = [

				'sku_prefix' =>
					(string) $row['sku_prefix'],

				'discount_percent' =>
					$this->normalize(
						$row['discount_percent']
					),
			];
		}

		return $result;
	}


	/*
	|--------------------------------------------------------------------------
	| Resolution
	|--------------------------------------------------------------------------
	*/

	/**
	 * Get the supplier discount for a SKU.
	 *
	 * The longest matching prefix wins. Matching is case-insensitive.
	 *
	 * Returns 0 when no prefix matches.
	 */
	public function get_for_sku(
		string $sku
	): float {

		$match =
			$this->match(
				$sku
			);

		return null === $match
			? 0.0
			: $match['discount'];
	}


	/**
	 * Get the supplier discount for a product (matched on its SKU).
	 */
	public function get_for_product(
		int $product_id
	): float {

		return $this->get_for_sku(
			$this->product_sku(
				$product_id
			)
		);
	}


	/**
	 * Whether a SKU matches a configured supplier discount.
	 *
	 * Distinguishes "no rule" from "a rule set to 0%".
	 */
	public function has_for_sku(
		string $sku
	): bool {

		return null !== $this->match(
			$sku
		);
	}


	/**
	 * Return the prefix matched by a SKU, or null.
	 */
	public function matched_prefix(
		string $sku
	): ?string {

		$match =
			$this->match(
				$sku
			);

		return null === $match
			? null
			: $match['prefix'];
	}


	/*
	|--------------------------------------------------------------------------
	| Persistence
	|--------------------------------------------------------------------------
	*/

	/**
	 * Create or update a supplier discount.
	 */
	public function save(
		string $sku_prefix,
		float $discount
	): bool {

		$sku_prefix =
			$this->normalize_prefix(
				$sku_prefix
			);

		if ( '' === $sku_prefix ) {
			return false;
		}

		global $wpdb;

		$table =
			Database::table(
				'supplier_discounts'
			);

		$now =
			current_time(
				'mysql',
				true
			);

		$result =
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table}
					(
						sku_prefix,
						discount_percent,
						enabled,
						created_at,
						updated_at
					)
					VALUES
					(
						%s,
						%f,
						1,
						%s,
						%s
					)
					ON DUPLICATE KEY UPDATE
						discount_percent = VALUES(discount_percent),
						enabled = 1,
						updated_at = VALUES(updated_at)",
					$sku_prefix,
					$this->normalize(
						$discount
					),
					$now,
					$now
				)
			);

		$this->rules = null;

		return false !== $result;
	}


	/**
	 * Delete a supplier discount.
	 */
	public function delete(
		string $sku_prefix
	): bool {

		$sku_prefix =
			$this->normalize_prefix(
				$sku_prefix
			);

		if ( '' === $sku_prefix ) {
			return false;
		}

		global $wpdb;

		$result =
			$wpdb->delete(
				Database::table(
					'supplier_discounts'
				),
				[
					'sku_prefix' =>
						$sku_prefix,
				],
				[
					'%s',
				]
			);

		$this->rules = null;

		return false !== $result;
	}


	/**
	 * Normalise a SKU prefix as entered in the admin UI.
	 *
	 * Prefixes are stored upper-case so the unique key is effectively
	 * case-insensitive and matching stays cheap.
	 */
	public function normalize_prefix(
		string $sku_prefix
	): string {

		return strtoupper(
			trim(
				sanitize_text_field(
					$sku_prefix
				)
			)
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Internals
	|--------------------------------------------------------------------------
	*/

	/**
	 * @return array{prefix:string,discount:float}|null
	 */
	private function match(
		string $sku
	): ?array {

		$sku =
			strtoupper(
				trim(
					$sku
				)
			);

		if ( '' === $sku ) {
			return null;
		}

		foreach ( $this->rules() as $rule ) {

			if (
				str_starts_with(
					$sku,
					$rule['prefix']
				)
			) {
				return $rule;
			}
		}

		return null;
	}


	/**
	 * Enabled rules, longest prefix first, loaded once per request.
	 *
	 * @return array<int,array{prefix:string,discount:float}>
	 */
	private function rules(): array {

		if ( null !== $this->rules ) {
			return $this->rules;
		}

		global $wpdb;

		$table =
			Database::table(
				'supplier_discounts'
			);

		$rows =
			$wpdb->get_results(
				"SELECT sku_prefix, discount_percent
				FROM {$table}
				WHERE enabled = 1",
				ARRAY_A
			);

		$rules = [];

		if ( is_array( $rows ) ) {

			foreach ( $rows as $row ) {

				$prefix =
					strtoupper(
						(string) $row['sku_prefix']
					);

				if ( '' === $prefix ) {
					continue;
				}

				$rules[] = [

					'prefix' =>
						$prefix,

					'discount' =>
						$this->normalize(
							$row['discount_percent']
						),
				];
			}
		}

		usort(
			$rules,
			static fn ( array $a, array $b ): int =>
				strlen( $b['prefix'] ) <=> strlen( $a['prefix'] )
		);

		$this->rules = $rules;

		return $this->rules;
	}


	private function product_sku(
		int $product_id
	): string {

		$product_id =
			absint(
				$product_id
			);

		if ( ! $product_id ) {
			return '';
		}

		$sku =
			get_post_meta(
				$product_id,
				'_sku',
				true
			);

		return is_string( $sku )
			? $sku
			: '';
	}


	/**
	 * Normalise a percentage to 0 - 100.
	 */
	private function normalize(
		$value
	): float {

		if ( ! is_numeric( $value ) ) {
			return 0.0;
		}

		return min(
			100.0,
			max(
				0.0,
				(float) $value
			)
		);
	}
}
