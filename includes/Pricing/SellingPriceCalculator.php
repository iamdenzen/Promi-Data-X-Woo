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
 *     cost × (1 + markup / 100)  → rounded ONCE to cents
 *
 * Printing / decoration:
 *
 *     cost × (1 + print price markup / 100)  → rounded ONCE to cents
 *
 * Setup:
 *
 *     marked-up amount (unrounded) → whole euro (ceil)
 *                                  → price ending in 9 (e.g. 57.902 → 58 → 59,
 *                                    see round_setup())
 *
 * Ongoing printing / decoration:
 *
 *     marked-up amount → rounded ONCE to cents
 *
 * Rounding policy: every customer-facing unit price is a 2-decimal amount,
 * rounded exactly once, here. Everything downstream (line totals, cart,
 * order) multiplies that rounded unit price by the quantity and never
 * rounds a unit price again. See round_money().
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
	 * The result is the customer-facing unit price, rounded once to cents.
	 */
	public function article(
		float $cost,
		float $markup_percent
	): float {

		return $this->round_money(
			$this->apply_markup(
				$cost,
				$markup_percent
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
	 * Rounded once to cents.
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

		return $this->round_money(
			$this->apply_markup(
				$cost,
				$markup_percent
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
	 *     print fee markup
	 *         ↓
	 *     round up to a whole euro
	 *         ↓
	 *     map to the price ending in 9 (see round_setup())
	 *
	 * Example:
	 *
	 *     €44.54 × 1.30 = €57.902
	 *
	 *     €57.902 → €58 → result = €59
	 */
	public function setup(
		float $cost,
		float $markup_percent
	): float {

		// Unrounded on purpose: setup rounding works on the raw marked-up amount.
		$marked_up =
			$this->apply_markup(
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
	 * Ongoing printing follows the print price / print fee markup and is
	 * rounded once to cents (never to a whole euro / "9" ending — that is for
	 * setup only).
	 *
	 * Example:
	 *
	 *     €0.83 × 1.25 = €1.0375
	 *
	 *     result = €1.04
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
	 * Calculate a marked-up amount, rounded once to cents.
	 *
	 * This is useful for any decoration/printing amount which is subject to
	 * print markup but is not a setup fee.
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
	| Money Rounding
	|--------------------------------------------------------------------------
	*/

	/**
	 * Round a monetary amount to cents (half up).
	 *
	 * The single rounding rule for the whole store. Use it for unit prices
	 * (once, when they are produced), line totals (unit × quantity), tax and
	 * sums of already-rounded lines. A tiny epsilon absorbs binary floating
	 * point noise (e.g. 1.005 stored as 1.00499999…) so half-cent values
	 * round up as a customer would expect.
	 */
	public static function round_money(
		float $amount
	): float {

		return round(
			$amount + ( $amount >= 0 ? 1.0E-9 : -1.0E-9 ),
			2,
			PHP_ROUND_HALF_UP
		);
	}


	/**
	 * Cost × (1 + markup / 100), unrounded. Internal building block.
	 */
	private function apply_markup(
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
	| Setup Rounding
	|--------------------------------------------------------------------------
	*/

	/**
	 * Round a setup amount to a price ending in 9.
	 *
	 * The amount is first rounded up to a whole euro (ceil). That whole euro
	 * n is then mapped to the 9-ending price of its decade:
	 *
	 *     n = 10k + 1 ... 10k + 10   →   10k + 9
	 *
	 * Examples:
	 *
	 *     55.23 → 56 → 59
	 *     57.902 → 58 → 59
	 *     59.00 → 59
	 *     60.00 → 60 → 59   (a whole euro ending in 0 goes DOWN by 1)
	 *     59.10 → 60 → 59
	 *     60.50 → 61 → 69
	 *
	 * This is the intended business rule (price endings of 9), not ordinary
	 * rounding to the nearest euro. Note the one case where the result is
	 * below the marked-up amount: amounts that round up to a multiple of 10.
	 */
	public function round_setup(
		float $amount
	): float {

		$amount =
			$this->normalize_amount(
				$amount
			);

		if ( 0.0 === $amount ) {
			return 0.0;
		}

		$rounded =
			(float) ceil(
				$amount
			);

		if ( $rounded <= 0 ) {
			return 0.0;
		}

		$last_digit =
			(int) fmod(
				$rounded,
				10
			);

		if ( 9 === $last_digit ) {
			return $rounded;
		}

		return (float) (
			(
				(int) floor(
					( $rounded + 9 ) / 10
				)
				* 10
			)
			- 1
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
