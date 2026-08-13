<?php

namespace PromiDataXWoo\Printing;

use InvalidArgumentException;
use PromiDataXWoo\Core\Database;
use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Printing data repository.
 *
 * Owns all direct database access for:
 *
 * - Print positions
 * - Print options
 * - Position <-> option relations
 * - Print tier prices
 * - Print fees
 *
 * Business rules and Promi transformation logic do not belong here.
 */
final class Repository {

	private const OPTIONS_CACHE          = 'cx_print_options_all';
	private const DROPDOWN_OPTIONS_CACHE = 'cx_print_dropdown_options_all';


	/*
	|--------------------------------------------------------------------------
	| Helpers
	|--------------------------------------------------------------------------
	*/

	private function db(): \wpdb {
		global $wpdb;

		return $wpdb;
	}


	private function table( string $name ): string {

		$map = [
			'positions' => 'print_positions',
			'options'   => 'print_options',
			'prices'    => 'print_prices',
			'fees'      => 'print_fees',
			'relation'  => 'print_relation',
		];

		if ( ! isset( $map[ $name ] ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'Unknown printing table: %s',
					$name
				)
			);
		}

		return Database::table(
			$map[ $name ]
		);
	}


	private function clear_option_cache(): void {

		delete_transient(
			self::OPTIONS_CACHE
		);

		delete_transient(
			self::DROPDOWN_OPTIONS_CACHE
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Positions
	|--------------------------------------------------------------------------
	*/

	public function insert_position(
		int $product_id,
		array $data
	): int {

		$db = $this->db();

		$result = $db->insert(
			$this->table( 'positions' ),
			[
				'product_id'     => absint( $product_id ),
				'variation_id'   => absint(
					$data['variation_id'] ?? 0
				),
				'position_code'  => sanitize_key(
					$data['position_code'] ?? ''
				),
				'position_label' => sanitize_text_field(
					$data['position_label'] ?? ''
				),
				'area'           => sanitize_text_field(
					$data['area'] ?? ''
				),
				'image'          => absint(
					$data['image'] ?? 0
				),
			],
			[
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
				'%d',
			]
		);

		if ( false === $result ) {
			return 0;
		}

		return (int) $db->insert_id;
	}


	public function update_position(
		int $position_id,
		array $data
	): bool {

		$result = $this->db()->update(
			$this->table( 'positions' ),
			[
				'position_label' => sanitize_text_field(
					$data['position_label'] ?? ''
				),
				'area'           => sanitize_text_field(
					$data['area'] ?? ''
				),
				'image'          => absint(
					$data['image'] ?? 0
				),
			],
			[
				'id' => absint( $position_id ),
			],
			[
				'%s',
				'%s',
				'%d',
			],
			[
				'%d',
			]
		);

		return false !== $result;
	}


	public function delete_position(
		int $position_id
	): bool {

		$position_id = absint(
			$position_id
		);

		if ( ! $position_id ) {
			return false;
		}

		$db = $this->db();

		$db->delete(
			$this->table( 'relation' ),
			[
				'print_position_id' => $position_id,
			],
			[
				'%d',
			]
		);

		return false !== $db->delete(
			$this->table( 'positions' ),
			[
				'id' => $position_id,
			],
			[
				'%d',
			]
		);
	}


	public function delete_positions_by_product(
		int $product_id
	): int {

		$product_id = absint(
			$product_id
		);

		if ( ! $product_id ) {
			return 0;
		}

		$db = $this->db();

		$position_ids = $db->get_col(
			$db->prepare(
				'SELECT id
				FROM ' . $this->table( 'positions' ) . '
				WHERE product_id = %d',
				$product_id
			)
		);

		$this->delete_relations_for_position_ids(
			$position_ids
		);

		$deleted = $db->delete(
			$this->table( 'positions' ),
			[
				'product_id' => $product_id,
			],
			[
				'%d',
			]
		);

		return false === $deleted
			? 0
			: (int) $deleted;
	}


	public function delete_positions_by_variation(
		int $variation_id
	): int {

		$variation_id = absint(
			$variation_id
		);

		if ( ! $variation_id ) {
			return 0;
		}

		$db = $this->db();

		$position_ids = $db->get_col(
			$db->prepare(
				'SELECT id
				FROM ' . $this->table( 'positions' ) . '
				WHERE variation_id = %d',
				$variation_id
			)
		);

		$this->delete_relations_for_position_ids(
			$position_ids
		);

		$deleted = $db->delete(
			$this->table( 'positions' ),
			[
				'variation_id' => $variation_id,
			],
			[
				'%d',
			]
		);

		return false === $deleted
			? 0
			: (int) $deleted;
	}


	public function delete_positions_by_product_variation(
		int $product_id,
		int $variation_id
	): int {

		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );

		if ( ! $product_id ) {
			return 0;
		}

		$db = $this->db();

		$position_ids = $db->get_col(
			$db->prepare(
				'SELECT id
				FROM ' . $this->table( 'positions' ) . '
				WHERE product_id = %d
				AND variation_id = %d',
				$product_id,
				$variation_id
			)
		);

		$this->delete_relations_for_position_ids(
			$position_ids
		);

		$deleted = $db->delete(
			$this->table( 'positions' ),
			[
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
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


	private function delete_relations_for_position_ids(
		array $position_ids
	): void {

		$position_ids = array_values(
			array_filter(
				array_map(
					'absint',
					$position_ids
				)
			)
		);

		if ( empty( $position_ids ) ) {
			return;
		}

		$db = $this->db();

		$placeholders = implode(
			',',
			array_fill(
				0,
				count( $position_ids ),
				'%d'
			)
		);

		$db->query(
			$db->prepare(
				'DELETE FROM '
				. $this->table( 'relation' )
				. " WHERE print_position_id IN ({$placeholders})",
				...$position_ids
			)
		);
	}


	public function get_position(
		int $position_id
	): ?object {

		$row = $this->db()->get_row(
			$this->db()->prepare(
				'SELECT *
				FROM ' . $this->table( 'positions' ) . '
				WHERE id = %d
				LIMIT 1',
				absint( $position_id )
			)
		);

		return $row ?: null;
	}


	public function get_positions(
		int $product_id,
		int $variation_id = 0
	): array {

		return $this->db()->get_results(
			$this->db()->prepare(
				'SELECT *
				FROM ' . $this->table( 'positions' ) . '
				WHERE product_id = %d
				AND variation_id = %d
				ORDER BY id ASC',
				absint( $product_id ),
				absint( $variation_id )
			)
		);
	}


	public function get_positions_by_product(
		int $product_id
	): array {

		return $this->db()->get_results(
			$this->db()->prepare(
				'SELECT *
				FROM ' . $this->table( 'positions' ) . '
				WHERE product_id = %d
				ORDER BY id ASC',
				absint( $product_id )
			)
		);
	}


	public function find_position(
		int $product_id,
		int $variation_id,
		string $position_code
	): ?object {

		$row = $this->db()->get_row(
			$this->db()->prepare(
				'SELECT *
				FROM ' . $this->table( 'positions' ) . '
				WHERE product_id = %d
				AND variation_id = %d
				AND position_code = %s
				LIMIT 1',
				absint( $product_id ),
				absint( $variation_id ),
				sanitize_key( $position_code )
			)
		);

		return $row ?: null;
	}


	public function get_unique_positions(
		int $product_id
	): array {

		return $this->db()->get_results(
			$this->db()->prepare(
				'SELECT
					position_code,
					MAX(position_label) AS position_label,
					MAX(image) AS image
				FROM ' . $this->table( 'positions' ) . '
				WHERE product_id = %d
				GROUP BY position_code
				ORDER BY position_label ASC',
				absint( $product_id )
			)
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Options
	|--------------------------------------------------------------------------
	*/

	public function insert_option(
		array $data
	): int {

		$db = $this->db();

		$result = $db->insert(
			$this->table( 'options' ),
			[
				'sku' => sanitize_text_field(
					$data['sku'] ?? ''
				),
				'name' => sanitize_text_field(
					$data['name'] ?? ''
				),
				'min_order_qty' => max(
					1,
					absint(
						$data['min_order_qty'] ?? 1
					)
				),
				'max_colors' => absint(
					$data['max_colors'] ?? 0
				),
			],
			[
				'%s',
				'%s',
				'%d',
				'%d',
			]
		);

		if ( false === $result ) {
			return 0;
		}

		$this->clear_option_cache();

		return (int) $db->insert_id;
	}


	public function update_option(
		int $option_id,
		array $data
	): bool {

		$result = $this->db()->update(
			$this->table( 'options' ),
			[
				'name' => sanitize_text_field(
					$data['name'] ?? ''
				),
				'min_order_qty' => max(
					1,
					absint(
						$data['min_order_qty'] ?? 1
					)
				),
				'max_colors' => absint(
					$data['max_colors'] ?? 0
				),
			],
			[
				'id' => absint( $option_id ),
			],
			[
				'%s',
				'%d',
				'%d',
			],
			[
				'%d',
			]
		);

		$this->clear_option_cache();

		return false !== $result;
	}


	public function delete_option(
		int $option_id
	): bool {

		$option_id = absint(
			$option_id
		);

		if ( ! $option_id ) {
			return false;
		}

		$db = $this->db();

		$db->query( 'START TRANSACTION' );

		try {

			$db->delete(
				$this->table( 'relation' ),
				[
					'print_option_id' => $option_id,
				],
				[
					'%d',
				]
			);

			$db->delete(
				$this->table( 'prices' ),
				[
					'print_option_id' => $option_id,
				],
				[
					'%d',
				]
			);

			$db->delete(
				$this->table( 'fees' ),
				[
					'print_option_id' => $option_id,
				],
				[
					'%d',
				]
			);

			$result = $db->delete(
				$this->table( 'options' ),
				[
					'id' => $option_id,
				],
				[
					'%d',
				]
			);

			if ( false === $result ) {
				throw new RuntimeException(
					$db->last_error
					?: 'Could not delete print option.'
				);
			}

			$db->query( 'COMMIT' );

		} catch ( Throwable $e ) {

			$db->query( 'ROLLBACK' );

			throw $e;
		}

		$this->clear_option_cache();

		return true;
	}


	public function get_option(
		int $option_id
	): ?object {

		$row = $this->db()->get_row(
			$this->db()->prepare(
				'SELECT *
				FROM ' . $this->table( 'options' ) . '
				WHERE id = %d
				LIMIT 1',
				absint( $option_id )
			)
		);

		return $row ?: null;
	}


	public function find_option_by_sku(
		string $sku
	): ?object {

		$row = $this->db()->get_row(
			$this->db()->prepare(
				'SELECT *
				FROM ' . $this->table( 'options' ) . '
				WHERE sku = %s
				LIMIT 1',
				sanitize_text_field( $sku )
			)
		);

		return $row ?: null;
	}


	public function get_options(
		bool $use_cache = true
	): array {

		if ( $use_cache ) {

			$cached = get_transient(
				self::OPTIONS_CACHE
			);

			if ( false !== $cached ) {
				return is_array( $cached )
					? $cached
					: [];
			}
		}

		$results = $this->db()->get_results(
			'SELECT
				id,
				name,
				sku,
				min_order_qty,
				max_colors
			FROM ' . $this->table( 'options' ) . '
			ORDER BY name ASC'
		);

		if ( $use_cache ) {
			set_transient(
				self::OPTIONS_CACHE,
				$results,
				DAY_IN_SECONDS
			);
		}

		return $results;
	}


	public function get_dropdown_options(
		bool $use_cache = true
	): array {

		if ( $use_cache ) {

			$cached = get_transient(
				self::DROPDOWN_OPTIONS_CACHE
			);

			if ( false !== $cached ) {
				return is_array( $cached )
					? $cached
					: [];
			}
		}

		$results = $this->db()->get_col(
			'SELECT DISTINCT name
			FROM ' . $this->table( 'options' ) . "
			WHERE name != ''
			ORDER BY name ASC"
		);

		if ( $use_cache ) {
			set_transient(
				self::DROPDOWN_OPTIONS_CACHE,
				$results,
				DAY_IN_SECONDS
			);
		}

		return $results;
	}


	public function get_options_by_ids(
		array $option_ids
	): array {

		$option_ids = array_values(
			array_unique(
				array_filter(
					array_map(
						'absint',
						$option_ids
					)
				)
			)
		);

		if ( empty( $option_ids ) ) {
			return [];
		}

		$placeholders = implode(
			',',
			array_fill(
				0,
				count( $option_ids ),
				'%d'
			)
		);

		return $this->db()->get_results(
			$this->db()->prepare(
				'SELECT *
				FROM '
				. $this->table( 'options' )
				. " WHERE id IN ({$placeholders})",
				...$option_ids
			)
		);
	}


	public function get_option_ids_by_skus(
		array $skus
	): array {

		$skus = array_values(
			array_unique(
				array_filter(
					array_map(
						'sanitize_text_field',
						$skus
					)
				)
			)
		);

		if ( empty( $skus ) ) {
			return [];
		}

		$placeholders = implode(
			',',
			array_fill(
				0,
				count( $skus ),
				'%s'
			)
		);

		$rows = $this->db()->get_results(
			$this->db()->prepare(
				'SELECT id, sku
				FROM '
				. $this->table( 'options' )
				. " WHERE sku IN ({$placeholders})",
				...$skus
			)
		);

		$map = [];

		foreach ( $rows as $row ) {
			$map[ $row->sku ] = (int) $row->id;
		}

		return $map;
	}


	public function get_options_paginated(
		int $page = 1,
		int $per_page = 50,
		string $sku_search = ''
	): array {

		$db = $this->db();

		$page       = max( 1, $page );
		$per_page   = max( 1, $per_page );
		$sku_search = sanitize_text_field(
			$sku_search
		);

		$offset = ( $page - 1 )
			* $per_page;

		$sql = 'SELECT *
			FROM '
			. $this->table( 'options' );

		$args = [];

		if ( '' !== $sku_search ) {

			$sql .= ' WHERE sku LIKE %s';

			$args[] = '%'
				. $db->esc_like( $sku_search )
				. '%';
		}

		$sql .= ' ORDER BY id ASC
			LIMIT %d OFFSET %d';

		$args[] = $per_page;
		$args[] = $offset;

		return $db->get_results(
			$db->prepare(
				$sql,
				...$args
			)
		);
	}


	public function count_options(
		string $sku_search = ''
	): int {

		$db = $this->db();

		$sku_search = sanitize_text_field(
			$sku_search
		);

		$sql = 'SELECT COUNT(*)
			FROM '
			. $this->table( 'options' );

		if ( '' === $sku_search ) {
			return (int) $db->get_var(
				$sql
			);
		}

		return (int) $db->get_var(
			$db->prepare(
				$sql . ' WHERE sku LIKE %s',
				'%'
				. $db->esc_like( $sku_search )
				. '%'
			)
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Relations
	|--------------------------------------------------------------------------
	*/

	public function assign_option_to_position(
		int $product_id,
		int $variation_id,
		int $position_id,
		int $option_id
	): bool {

		$result = $this->db()->query(
			$this->db()->prepare(
				'INSERT IGNORE INTO '
				. $this->table( 'relation' ) . '
				(
					product_id,
					variation_id,
					print_option_id,
					print_position_id
				)
				VALUES (%d, %d, %d, %d)',
				absint( $product_id ),
				absint( $variation_id ),
				absint( $option_id ),
				absint( $position_id )
			)
		);

		return false !== $result;
	}


	public function remove_option_from_position(
		int $product_id,
		int $variation_id,
		int $position_id,
		int $option_id
	): bool {

		$result = $this->db()->delete(
			$this->table( 'relation' ),
			[
				'product_id'        => absint( $product_id ),
				'variation_id'      => absint( $variation_id ),
				'print_position_id' => absint( $position_id ),
				'print_option_id'   => absint( $option_id ),
			],
			[
				'%d',
				'%d',
				'%d',
				'%d',
			]
		);

		return false !== $result;
	}


	public function replace_position_options(
		int $product_id,
		int $variation_id,
		int $position_id,
		array $option_ids
	): void {

		$db = $this->db();

		$db->delete(
			$this->table( 'relation' ),
			[
				'product_id'        => absint( $product_id ),
				'variation_id'      => absint( $variation_id ),
				'print_position_id' => absint( $position_id ),
			],
			[
				'%d',
				'%d',
				'%d',
			]
		);

		foreach (
			array_unique(
				array_map(
					'absint',
					$option_ids
				)
			) as $option_id
		) {

			if ( ! $option_id ) {
				continue;
			}

			$this->assign_option_to_position(
				$product_id,
				$variation_id,
				$position_id,
				$option_id
			);
		}
	}


	public function bulk_assign_options_to_positions(
		array $rows
	): bool {

		if ( empty( $rows ) ) {
			return true;
		}

		$values = [];
		$params = [];

		foreach ( $rows as $row ) {

			$product_id = absint(
				$row['product_id'] ?? 0
			);

			$variation_id = absint(
				$row['variation_id'] ?? 0
			);

			$option_id = absint(
				$row['print_option_id'] ?? 0
			);

			$position_id = absint(
				$row['print_position_id'] ?? 0
			);

			if (
				! $product_id
				|| ! $option_id
				|| ! $position_id
			) {
				continue;
			}

			$values[] = '(%d, %d, %d, %d)';

			$params[] = $product_id;
			$params[] = $variation_id;
			$params[] = $option_id;
			$params[] = $position_id;
		}

		if ( empty( $values ) ) {
			return true;
		}

		$result = $this->db()->query(
			$this->db()->prepare(
				'INSERT IGNORE INTO '
				. $this->table( 'relation' )
				. '
				(
					product_id,
					variation_id,
					print_option_id,
					print_position_id
				)
				VALUES '
				. implode( ',', $values ),
				...$params
			)
		);

		return false !== $result;
	}


	public function get_options_by_position(
		int $product_id,
		int $variation_id,
		int $position_id
	): array {

		return $this->db()->get_results(
			$this->db()->prepare(
				'SELECT o.*
				FROM '
				. $this->table( 'relation' )
				. ' r
				INNER JOIN '
				. $this->table( 'options' )
				. ' o
					ON o.id = r.print_option_id
				WHERE r.product_id = %d
				AND r.variation_id = %d
				AND r.print_position_id = %d
				ORDER BY o.name ASC',
				absint( $product_id ),
				absint( $variation_id ),
				absint( $position_id )
			)
		);
	}


	public function selection_is_valid(
		int $product_or_variation_id,
		int $position_id,
		int $option_id
	): bool {

		$count = $this->db()->get_var(
			$this->db()->prepare(
				'SELECT COUNT(*)
				FROM '
				. $this->table( 'relation' )
				. '
				WHERE (
					product_id = %d
					OR variation_id = %d
				)
				AND print_position_id = %d
				AND print_option_id = %d',
				absint( $product_or_variation_id ),
				absint( $product_or_variation_id ),
				absint( $position_id ),
				absint( $option_id )
			)
		);

		return (int) $count > 0;
	}


	/*
	|--------------------------------------------------------------------------
	| Prices
	|--------------------------------------------------------------------------
	*/

	public function get_prices(
		int $option_id
	): array {

		return $this->db()->get_results(
			$this->db()->prepare(
				'SELECT
					min_qty,
					price,
					purchase_price
				FROM '
				. $this->table( 'prices' )
				. '
				WHERE print_option_id = %d
				ORDER BY min_qty ASC',
				absint( $option_id )
			)
		);
	}


	public function get_selling_prices(
		int $option_id
	): array {

		return $this->db()->get_results(
			$this->db()->prepare(
				'SELECT
					min_qty,
					price
				FROM '
				. $this->table( 'prices' )
				. '
				WHERE print_option_id = %d
				ORDER BY min_qty ASC',
				absint( $option_id )
			)
		);
	}


	public function get_purchase_prices(
		int $option_id
	): array {

		return $this->db()->get_results(
			$this->db()->prepare(
				'SELECT
					min_qty,
					purchase_price AS price
				FROM '
				. $this->table( 'prices' )
				. '
				WHERE print_option_id = %d
				AND purchase_price IS NOT NULL
				ORDER BY min_qty ASC',
				absint( $option_id )
			)
		);
	}


	public function get_applicable_selling_price(
		int $option_id,
		int $qty
	): ?float {

		return $this->get_applicable_price(
			$option_id,
			$qty,
			'price'
		);
	}


	public function get_applicable_purchase_price_row(
		int $option_id,
		int $quantity
	): ?object {

		$option_id =
			absint(
				$option_id
			);

		$quantity =
			max(
				1,
				absint(
					$quantity
				)
			);

		if ( ! $option_id ) {
			return null;
		}

		global $wpdb;

		$table =
			$this->table(
				'prices'
			);

		$row =
			$wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						id,
						print_option_id,
						min_qty,
						price,
						purchase_price
					FROM {$table}
					WHERE print_option_id = %d
						AND min_qty <= %d
					ORDER BY min_qty DESC
					LIMIT 1",
					$option_id,
					$quantity
				)
			);

		if ( $row ) {
			return $row;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					id,
					print_option_id,
					min_qty,
					price,
					purchase_price
				FROM {$table}
				WHERE print_option_id = %d
				ORDER BY min_qty ASC
				LIMIT 1",
				$option_id
			)
		);
	}


	public function get_applicable_purchase_price(
		int $option_id,
		int $qty
	): ?float {

		return $this->get_applicable_price(
			$option_id,
			$qty,
			'purchase_price'
		);
	}


	private function get_applicable_price(
		int $option_id,
		int $qty,
		string $column
	): ?float {

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
				'Invalid print price column.'
			);
		}

		$value = $this->db()->get_var(
			$this->db()->prepare(
				"SELECT {$column}
				FROM "
				. $this->table( 'prices' )
				. "
				WHERE print_option_id = %d
				AND min_qty <= %d
				AND {$column} IS NOT NULL
				ORDER BY min_qty DESC
				LIMIT 1",
				absint( $option_id ),
				max( 1, absint( $qty ) )
			)
		);

		return null === $value
			? null
			: (float) $value;
	}


	public function replace_prices(
		int $option_id,
		array $tiers
	): bool {

		$option_id = absint(
			$option_id
		);

		if ( ! $option_id ) {
			return false;
		}

		$normalized = [];

		foreach ( $tiers as $tier ) {

			if ( ! empty( $tier['OnRequest'] ) ) {
				continue;
			}

			$qty = absint(
				$tier['Quantity']
				?? $tier['min_qty']
				?? 0
			);

			$price = $this->normalize_optional_price(
				$tier['Price']
				?? $tier['price']
				?? null
			);

			$purchase_price =
				$this->normalize_optional_price(
					$tier['PurchasePrice']
						?? $tier['purchase_price']
						?? null
				);

			if (
				$qty <= 0
				|| null === $price
				|| $price <= 0
			) {
				continue;
			}

			$normalized[ $qty ] = [
				'min_qty'        => $qty,
				'price'          => $price,
				'purchase_price' => $purchase_price,
			];
		}

		ksort(
			$normalized,
			SORT_NUMERIC
		);

		$db    = $this->db();
		$table = $this->table( 'prices' );

		$db->query( 'START TRANSACTION' );

		try {

			$db->delete(
				$table,
				[
					'print_option_id' => $option_id,
				],
				[
					'%d',
				]
			);

			if ( ! empty( $normalized ) ) {

				$values = [];
				$params = [];

				foreach ( $normalized as $tier ) {

					if (
						null === $tier['purchase_price']
					) {

						$values[] =
							'(%d, %d, %f, NULL)';

						$params[] = $option_id;
						$params[] = $tier['min_qty'];
						$params[] = $tier['price'];

						continue;
					}

					$values[] =
						'(%d, %d, %f, %f)';

					$params[] = $option_id;
					$params[] = $tier['min_qty'];
					$params[] = $tier['price'];
					$params[] =
						$tier['purchase_price'];
				}

				$result = $db->query(
					$db->prepare(
						"INSERT INTO {$table}
						(
							print_option_id,
							min_qty,
							price,
							purchase_price
						)
						VALUES "
						. implode(
							',',
							$values
						),
						...$params
					)
				);

				if ( false === $result ) {
					throw new RuntimeException(
						$db->last_error
						?: 'Could not insert print prices.'
					);
				}
			}

			$db->query( 'COMMIT' );

		} catch ( Throwable $e ) {

			$db->query( 'ROLLBACK' );

			throw $e;
		}

		return true;
	}


	/*
	|--------------------------------------------------------------------------
	| Fees
	|--------------------------------------------------------------------------
	*/

	public function get_fees(
		int $option_id
	): array {

		return $this->db()->get_results(
			$this->db()->prepare(
				'SELECT
					fee_label,
					fee_type,
					calculation,
					calculation_type,
					calculation_amount,
					requirement,
					amount,
					purchase_amount
				FROM '
				. $this->table( 'fees' )
				. '
				WHERE print_option_id = %d
				ORDER BY id ASC',
				absint( $option_id )
			)
		);
	}


	public function replace_fees(
		int $option_id,
		array $fees
	): bool {

		$option_id = absint(
			$option_id
		);

		if ( ! $option_id ) {
			return false;
		}

		$db    = $this->db();
		$table = $this->table( 'fees' );

		$db->query( 'START TRANSACTION' );

		try {

			$db->delete(
				$table,
				[
					'print_option_id' => $option_id,
				],
				[
					'%d',
				]
			);

			$values = [];
			$params = [];

			foreach ( $fees as $fee ) {

				$requirement =
					$this->normalize_requirement(
						$fee['requirement']
							?? null
					);

				$purchase_amount =
					$this->normalize_optional_price(
						$fee['purchase_amount']
							?? null
					);

				$requirement_placeholder =
					null === $requirement
						? 'NULL'
						: '%s';

				$purchase_placeholder =
					null === $purchase_amount
						? 'NULL'
						: '%f';

				$values[] = "(
					%d,
					%s,
					%s,
					%s,
					%s,
					%f,
					{$requirement_placeholder},
					%f,
					{$purchase_placeholder}
				)";

				$params[] = $option_id;

				$params[] = sanitize_text_field(
					$fee['label']
						?? $fee['fee_label']
						?? ''
				);

				$params[] = sanitize_text_field(
					$fee['type']
						?? $fee['fee_type']
						?? ''
				);

				$params[] = sanitize_text_field(
					$fee['calculation']
						?? ''
				);

				$params[] = sanitize_text_field(
					$fee['calculation_type']
						?? ''
				);

				$params[] = (float) (
					$fee['calculation_amount']
						?? 0
				);

				if ( null !== $requirement ) {
					$params[] = $requirement;
				}

				$params[] = (float) (
					$fee['amount']
						?? 0
				);

				if (
					null !== $purchase_amount
				) {
					$params[] =
						$purchase_amount;
				}
			}

			if ( ! empty( $values ) ) {

				$result = $db->query(
					$db->prepare(
						"INSERT INTO {$table}
						(
							print_option_id,
							fee_label,
							fee_type,
							calculation,
							calculation_type,
							calculation_amount,
							requirement,
							amount,
							purchase_amount
						)
						VALUES "
						. implode(
							',',
							$values
						),
						...$params
					)
				);

				if ( false === $result ) {
					throw new RuntimeException(
						$db->last_error
						?: 'Could not insert print fees.'
					);
				}
			}

			$db->query( 'COMMIT' );

		} catch ( Throwable $e ) {

			$db->query( 'ROLLBACK' );

			throw $e;
		}

		return true;
	}


	/*
	|--------------------------------------------------------------------------
	| Full configuration
	|--------------------------------------------------------------------------
	*/

	public function get_product_config(
		int $product_id,
		int $variation_id = 0
	): array {

		$positions = $this->get_positions(
			$product_id,
			$variation_id
		);

		$config = [];

		foreach ( $positions as $position ) {

			$options =
				$this->get_options_by_position(
					$product_id,
					$variation_id,
					(int) $position->id
				);

			$config[ $position->id ] = [
				'label'   =>
					$position->position_label,

				'code'    =>
					$position->position_code,

				'area'    =>
					$position->area,

				'image'   =>
					wp_get_attachment_url(
						(int) $position->image
					),

				'options' => [],
			];

			foreach ( $options as $option ) {

				$config[ $position->id ]
					['options']
					[ $option->id ] = [

						'name' =>
							$option->name,

						'sku' =>
							$option->sku,

						'min_order_qty' =>
							(int) $option
								->min_order_qty,

						'max_colors' =>
							(int) $option
								->max_colors,

						'prices' =>
							$this->get_prices(
								(int) $option->id
							),

						'fees' =>
							$this->get_fees(
								(int) $option->id
							),
					];
			}
		}

		return $config;
	}


	/*
	|--------------------------------------------------------------------------
	| Normalization
	|--------------------------------------------------------------------------
	*/

	private function normalize_requirement(
		mixed $requirement
	): ?string {

		if (
			null === $requirement
			|| '' === $requirement
			|| [] === $requirement
		) {
			return null;
		}

		if ( is_array( $requirement ) ) {
			return wp_json_encode(
				$requirement
			);
		}

		if ( ! is_string( $requirement ) ) {
			return null;
		}

		json_decode(
			$requirement,
			true
		);

		if (
			JSON_ERROR_NONE
			!== json_last_error()
		) {
			return null;
		}

		return $requirement;
	}


	private function normalize_optional_price(
		mixed $value
	): ?float {

		if (
			null === $value
			|| '' === trim(
				(string) $value
			)
		) {
			return null;
		}

		$value = wc_format_decimal(
			$value,
			4
		);

		if (
			'' === $value
			|| ! is_numeric( $value )
		) {
			return null;
		}

		return (float) $value;
	}
}