<?php

namespace PromiDataXWoo\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Selling-price calculation utilities.
 *
 * This class contains the mathematical selling-price rules only.
 *
 * Article:
 *
 *     cost × (1 + markup / 100)
 *
 * Printing / decoration:
 *
 *     cost × (1 + finishing markup / 100)
 *
 * Setup:
 *
 *     marked-up amount → nearest whole euro
 *
 * Ongoing printing / decoration:
 *
 *     marked-up amount → NO rounding
 *
 * This class intentionally does not:
 *
 * - read database tables,
 * - resolve category rules,
 * - resolve manufacturer discounts,
 * - resolve quantity tiers,
 * - calculate WooCommerce cart prices.
 *
 * Those responsibilities belong to the surrounding Pricing services.
 */
final class SellingPriceCalculator {

	/*
	|--------------------------------------------------------------------------
	| Article
	|--------------------------------------------------------------------------
	*/

	/**
	 * Calculate an article selling price.
	 *
	 * Example:
	 *
	 *     cost = €4.00
	 *     markup = 25%
	 *
	 *     result = €5.00
	 *
	 * No rounding is performed here.
	 *
	 * The final WooCommerce price may subsequently be formatted according
	 * to WooCommerce's configured decimal settings, but the commercial
	 * calculation itself remains unrounded.
	 */
	public function article(
		float $cost,
		float $markup_percent
	): float {

		$cost =
			$this->normalize_amount(
				$cost
			);


		$markup_percent =
			$this->normalize_markup(
				$markup_percent
			);


		return $cost
			* (
				1
				+ (
					$markup_percent
					/ 100
				)
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Finishing / Decoration
	|--------------------------------------------------------------------------
	*/

	/**
	 * Calculate a finishing / decoration selling price.
	 *
	 * No rounding is performed.
	 *
	 * Example:
	 *
	 *     print cost = €0.80
	 *     markup = 30%
	 *
	 *     result = €1.04
	 */
	public function finishing(
		float $cost,
		float $markup_percent
	): float {

		$cost =
			$this->normalize_amount(
				$cost
			);


		$markup_percent =
			$this->normalize_markup(
				$markup_percent
			);


		return $cost
			* (
				1
				+ (
					$markup_percent
					/ 100
				)
			);
	}


	/**
	 * Calculate a setup cost.
	 *
	 * The commercial rule is:
	 *
	 *     raw setup cost
	 *         ↓
	 *     finishing markup
	 *         ↓
	 *     nearest whole euro
	 *
	 * Example:
	 *
	 *     €44.54 × 1.30 = €57.902
	 *
	 *     result = €58
	 */
	public function setup(
		float $cost,
		float $markup_percent
	): float {

		$marked_up =
			$this->finishing(
				$cost,
				$markup_percent
			);


		return $this->round_setup(
			$marked_up
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Ongoing Printing / Decoration
	|--------------------------------------------------------------------------
	*/

	/**
	 * Calculate an ongoing print / decoration amount.
	 *
	 * Ongoing printing follows the finishing markup rule but is NOT rounded.
	 *
	 * Example:
	 *
	 *     €0.83 × 1.25 = €1.0375
	 *
	 *     result = €1.0375
	 *
	 * The amount is intentionally left at calculation precision.
	 */
	public function ongoing(
		float $cost,
		float $markup_percent
	): float {

		return $this->finishing(
			$cost,
			$markup_percent
		);
	}


	/**
	 * Calculate a marked-up amount without rounding.
	 *
	 * This is useful for any decoration/printing amount which is subject to
	 * finishing markup but is not a setup fee.
	 */
	public function marked_up(
		float $cost,
		float $markup_percent
	): float {

		return $this->finishing(
			$cost,
			$markup_percent
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Setup Rounding
	|--------------------------------------------------------------------------
	*/

	/**
	 * Round a setup amount to the nearest whole euro.
	 *
	 * Examples:
	 *
	 *     55.23 → 55
	 *     55.49 → 55
	 *     55.50 → 56
	 *     55.67 → 56
	 *
	 * PHP's default round() behavior is used because the business
	 * requirement is ordinary nearest-euro rounding.
	 */
	public function round_setup(
		float $amount
	): float {

		return (float) round(
			$amount
		);
	}


	/**
	 * Named alias for setup rounding.
	 *
	 * Kept as a separate public method for callers that want the business
	 * rule expressed explicitly as "round setup cost".
	 */
	public function round_setup_cost(
		float $amount
	): float {

		return $this->round_setup(
			$amount
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Normalization
	|--------------------------------------------------------------------------
	*/

	/**
	 * Normalize a monetary amount.
	 *
	 * Negative costs are never meaningful for our commercial pricing
	 * calculation, so they are treated as zero.
	 */
	private function normalize_amount(
		float $amount
	): float {

		return max(
			0.0,
			$amount
		);
	}


	/**
	 * Normalize a markup percentage.
	 *
	 * Markups cannot be negative.
	 *
	 * There is intentionally no upper limit here. The business requirement
	 * says markups must be adjustable, and a future API/UI may legitimately
	 * configure a markup above 100%.
	 */
	private function normalize_markup(
		float $markup_percent
	): float {

		return max(
			0.0,
			$markup_percent
		);
	}
}
