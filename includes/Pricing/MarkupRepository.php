<?php

namespace PromiDataXWoo\Pricing;

use PromiDataXWoo\Core\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Storage layer for article / finishing markup rules.
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

		return false !== $result;
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