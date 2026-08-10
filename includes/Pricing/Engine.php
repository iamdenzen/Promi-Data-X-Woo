<?php

namespace PromiDataXWoo\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Unified pricing engine.
 *
 * Pricing modules register callbacks here and the engine executes them in
 * priority order.
 *
 * Example:
 *
 * priority 10
 *     TieredPricing
 *
 * priority 20
 *     Printing Calculator
 *
 * The engine always operates on a per-unit price.
 */
final class Engine {

	/**
	 * Registered callbacks.
	 *
	 * Structure:
	 *
	 * [
	 *     10 => [
	 *         'tier' => callable,
	 *     ],
	 *
	 *     20 => [
	 *         'printing' => callable,
	 *     ],
	 * ]
	 *
	 * @var array<int,array<string,callable>>
	 */
	private array $callbacks = [];


	/**
	 * Register a pricing callback.
	 */
	public function register(
		string $key,
		callable $callback,
		int $priority = 10
	): void {

		$key = sanitize_key(
			$key
		);

		if ( '' === $key ) {
			return;
		}

		$this->callbacks[
			$priority
		][
			$key
		] = $callback;
	}


	/**
	 * Remove a pricing callback.
	 */
	public function unregister(
		string $key,
		?int $priority = null
	): void {

		$key = sanitize_key(
			$key
		);

		if ( '' === $key ) {
			return;
		}


		/*
		|--------------------------------------------------------------------------
		| Exact Priority
		|--------------------------------------------------------------------------
		*/

		if ( null !== $priority ) {

			unset(
				$this->callbacks[
					$priority
				][
					$key
				]
			);

			if (
				isset(
					$this->callbacks[
						$priority
					]
				)
				&& empty(
					$this->callbacks[
						$priority
					]
				)
			) {
				unset(
					$this->callbacks[
						$priority
					]
				);
			}

			return;
		}


		/*
		|--------------------------------------------------------------------------
		| Every Priority
		|--------------------------------------------------------------------------
		*/

		foreach (
			$this->callbacks
				as $registered_priority => $callbacks
		) {

			unset(
				$this->callbacks[
					$registered_priority
				][
					$key
				]
			);

			if (
				empty(
					$this->callbacks[
						$registered_priority
					]
				)
			) {
				unset(
					$this->callbacks[
						$registered_priority
					]
				);
			}
		}
	}


	/**
	 * Calculate a final unit price.
	 *
	 * $base_price should be the raw WooCommerce product/variation price
	 * before our custom pricing layers are applied.
	 *
	 * Expected context:
	 *
	 * [
	 *     'product_id'   => 123,
	 *     'variation_id' => 456,
	 *     'quantity'     => 100,
	 *     'cart_item'    => [...],
	 *     'printing'     => [...],
	 * ]
	 */
	public function calculate(
		float $base_price,
		array $context = []
	): float {

		if ( empty( $this->callbacks ) ) {
			return max(
				0.0,
				$base_price
			);
		}

		ksort(
			$this->callbacks,
			SORT_NUMERIC
		);

		$price = $base_price;


		foreach (
			$this->callbacks as $priority => $callbacks
		) {

			foreach (
				$callbacks as $key => $callback
			) {

				/*
				 * Keep the previous value available so a broken callback
				 * cannot accidentally corrupt every WooCommerce cart total.
				 */
				$previous =
					$price;

				try {

					$result =
						call_user_func(
							$callback,
							$price,
							$context
						);

				} catch ( \Throwable $e ) {

					/*
					 * Pricing must fail safely.
					 *
					 * The caller can attach logging through this action
					 * without coupling Engine to Promi or Core loggers.
					 */
					do_action(
						'pdxw_pricing_callback_error',
						$key,
						$priority,
						$e,
						$context
					);

					$price =
						$previous;

					continue;
				}


				if (
					! is_numeric(
						$result
					)
				) {

					do_action(
						'pdxw_pricing_callback_invalid_result',
						$key,
						$priority,
						$result,
						$context
					);

					$price =
						$previous;

					continue;
				}


				$price = max(
					0.0,
					(float) $result
				);
			}
		}


		return $price;
	}


	/**
	 * Check whether a callback is registered.
	 */
	public function has(
		string $key
	): bool {

		$key = sanitize_key(
			$key
		);

		if ( '' === $key ) {
			return false;
		}

		foreach (
			$this->callbacks as $callbacks
		) {

			if (
				array_key_exists(
					$key,
					$callbacks
				)
			) {
				return true;
			}
		}

		return false;
	}


	/**
	 * Return registered callback keys grouped by priority.
	 *
	 * Useful for diagnostics without exposing callable internals.
	 */
	public function registered(): array {

		if ( empty( $this->callbacks ) ) {
			return [];
		}

		$registered = [];

		$callbacks =
			$this->callbacks;

		ksort(
			$callbacks,
			SORT_NUMERIC
		);

		foreach (
			$callbacks as $priority => $group
		) {

			$registered[
				$priority
			] = array_keys(
				$group
			);
		}

		return $registered;
	}
}