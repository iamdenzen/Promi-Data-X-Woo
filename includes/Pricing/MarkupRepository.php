<?php

namespace PromiDataXWoo\Pricing;

use PromiDataXWoo\Core\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Storage layer for article / print-option markup rules.
 *
 * SQL lives here.
 *
 * Business decisions such as category inheritance belong in MarkupRules.
 */
final class MarkupRepository {

	public const TYPE_CATEGORY =
		'category';

	public const TYPE_PRINT_OPTION =
		'print_option';

	public const TYPE_PRINT_OPTION_PRICE =
		'print_option_price';

	public const TYPE_PRINT_OPTION_FEE =
		'print_option_fee';


	/**
	 * In-memory what-if rules used by previews: "type|target_id" => percent,
	 * or null for a removed rule. Never written to the database.
	 *
	 * @var array<string,?float>
	 */
	private array $overlay = [];


	public function with_overlay( array $overlay ): self {

		$clone          = clone $this;
		$clone->overlay = $overlay + $this->overlay; // new entries win

		return $clone;
	}


	public function get(
		string $type,
		int $target_id
	): ?float {

		$type =
			$this->normalize_type(
				$type
			);

		$target_id =
			absint(
				$target_id
			);

		if (
			'' === $type
			|| ! $target_id
		) {
			return null;
		}

		$overlay_key = "{$type}|{$target_id}";

		if ( array_key_exists( $overlay_key, $this->overlay ) ) {
			return null === $this->overlay[ $overlay_key ]
				? null
				: max( 0.0, (float) $this->overlay[ $overlay_key ] );
		}

		global $wpdb;

		$table =
			Database::table(
				'pricing_markup_rules'
			);

		$value =
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT markup_percent
					FROM {$table}
					WHERE rule_type = %s
						AND target_id = %d
						AND enabled = 1
					LIMIT 1",
					$type,
					$target_id
				)
			);

		if (
			null === $value
			|| ! is_numeric( $value )
		) {
			return null;
		}

		return max(
			0.0,
			(float) $value
		);
	}


	/**
	 * Save one markup rule.
	 */
	public function save(
		string $type,
		int $target_id,
		float $markup_percent
	): bool {

		$type =
			$this->normalize_type(
				$type
			);

		$target_id =
			absint(
				$target_id
			);

		$markup_percent =
			max(
				0.0,
				$markup_percent
			);

		if (
			'' === $type
			|| ! $target_id
		) {
			return false;
		}

		global $wpdb;

		$table =
			Database::table(
				'pricing_markup_rules'
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
						rule_type,
						target_id,
						markup_percent,
						enabled,
						created_at,
						updated_at
					)
					VALUES
					(
						%s,
						%d,
						%f,
						1,
						%s,
						%s
					)
					ON DUPLICATE KEY UPDATE
						markup_percent = VALUES(markup_percent),
						enabled = 1,
						updated_at = VALUES(updated_at)",
					$type,
					$target_id,
					$markup_percent,
					$now,
					$now
				)
			);

		if ( false !== $result ) {
			History::record_rule_write(
				sprintf( '%s markup, target %d: set to %s%%', $type, $target_id, rtrim( rtrim( number_format( $markup_percent, 4, '.', '' ), '0' ), '.' ) ),
				0,
				[ 'type' => $type, 'target' => $target_id, 'percent' => $markup_percent ]
			);
		}

		return false !== $result;
	}


	/**
	 * Save the same markup for many targets at once.
	 *
	 * Equivalent to calling save() for every ID, but written as chunked
	 * multi-row INSERT ... ON DUPLICATE KEY UPDATE statements so that
	 * thousands of rules cost a handful of queries.
	 *
	 * @param int[] $target_ids
	 */
	public function save_many(
		string $type,
		array $target_ids,
		float $markup_percent
	): bool {

		$type =
			$this->normalize_type(
				$type
			);

		$target_ids =
			array_values(
				array_unique(
					array_filter(
						array_map(
							'absint',
							$target_ids
						)
					)
				)
			);

		if (
			'' === $type
			|| empty( $target_ids )
		) {
			return false;
		}

		$markup_percent =
			max(
				0.0,
				$markup_percent
			);

		global $wpdb;

		$table =
			Database::table(
				'pricing_markup_rules'
			);

		$now =
			current_time(
				'mysql',
				true
			);

		foreach (
			array_chunk(
				$target_ids,
				500
			) as $chunk
		) {

			$placeholders = [];
			$values       = [];

			foreach ( $chunk as $target_id ) {

				$placeholders[] =
					'(%s, %d, %f, 1, %s, %s)';

				array_push(
					$values,
					$type,
					$target_id,
					$markup_percent,
					$now,
					$now
				);
			}

			$result =
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$table}
						(
							rule_type,
							target_id,
							markup_percent,
							enabled,
							created_at,
							updated_at
						)
						VALUES "
						. implode( ', ', $placeholders )
						. "
						ON DUPLICATE KEY UPDATE
							markup_percent = VALUES(markup_percent),
							enabled = 1,
							updated_at = VALUES(updated_at)",
						$values
					)
				);

			if ( false === $result ) {
				return false;
			}
		}

		History::record_rule_write(
			sprintf( '%s markup: set to %s%% for %d targets', $type, rtrim( rtrim( number_format( $markup_percent, 4, '.', '' ), '0' ), '.' ), count( $target_ids ) ),
			0,
			[ 'type' => $type, 'targets' => array_slice( $target_ids, 0, 200 ), 'percent' => $markup_percent ]
		);

		return true;
	}


	/**
	 * Delete one rule.
	 */
	public function delete(
		string $type,
		int $target_id
	): bool {

		$type =
			$this->normalize_type(
				$type
			);

		$target_id =
			absint(
				$target_id
			);

		if (
			'' === $type
			|| ! $target_id
		) {
			return false;
		}

		global $wpdb;

		$result =
			$wpdb->delete(
				Database::table(
					'pricing_markup_rules'
				),
				[
					'rule_type' =>
						$type,

					'target_id' =>
						$target_id,
				],
				[
					'%s',
					'%d',
				]
			);

		if ( false !== $result ) {
			History::record_rule_write(
				sprintf( '%s markup, target %d: removed', $type, $target_id ),
				0,
				[ 'type' => $type, 'target' => $target_id ]
			);
		}

		return false !== $result;
	}


	/**
	 * Return all rules of one type.
	 */
	public function all(
		string $type
	): array {

		$type =
			$this->normalize_type(
				$type
			);

		if ( '' === $type ) {
			return [];
		}

		global $wpdb;

		$table =
			Database::table(
				'pricing_markup_rules'
			);

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					id,
					rule_type,
					target_id,
					markup_percent,
					enabled,
					created_at,
					updated_at
				FROM {$table}
				WHERE rule_type = %s
				ORDER BY target_id ASC",
				$type
			),
			ARRAY_A
		);
	}


	private function normalize_type(
		string $type
	): string {

		$type =
			sanitize_key(
				$type
			);

		return in_array(
			$type,
			[
				self::TYPE_CATEGORY,
				self::TYPE_PRINT_OPTION,
				self::TYPE_PRINT_OPTION_PRICE,
				self::TYPE_PRINT_OPTION_FEE,
			],
			true
		)
			? $type
			: '';
	}
}