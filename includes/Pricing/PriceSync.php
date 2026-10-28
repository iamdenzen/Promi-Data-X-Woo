<?php

namespace PromiDataXWoo\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the stored WooCommerce catalog price in step with the pricing rules.
 *
 * Why this exists:
 *
 * The product page, cart and checkout always price live through
 * CostCalculator. The catalog (grid, slider, search, price filter and price
 * sort) cannot: it reads the stored WooCommerce price, and the filter / sort
 * run in SQL over many products. That stored price used to be written only
 * when a product was imported, so changing a markup or a discount left it
 * stale.
 *
 * This service re-calculates and stores it whenever a rule changes:
 *
 *     category markup / default markup / manufacturer discount /
 *     supplier discount / product category or brand assignment
 *             ↓
 *     scope (product IDs)
 *             ↓
 *     small scope  → processed immediately
 *     large scope  → Action Scheduler chunks (background)
 *             ↓
 *     TieredPricing::refresh_woocommerce_price() per variation
 *
 * It also stores a small report on each product, used by the
 * "Unmarked Products" admin page:
 *
 *     _pdxw_price_source      purchase_price | recommended_selling_price
 *     _pdxw_markup_applied    article markup % in effect
 *     _pdxw_discount_applied  manufacturer / supplier discount % in effect
 *     _pdxw_discount_source   none | manufacturer | supplier
 *     _pdxw_cost              cost at the lowest tier
 *     _pdxw_selling_price     customer price at the lowest tier
 *     _pdxw_unmarked          '1' when the customer pays exactly the Promi
 *                             price (no markup and no discount), else '0'
 */
final class PriceSync {

	/**
	 * Async hook that reprices one chunk of product IDs.
	 */
	public const ACTION =
		'pdxw_reprice_chunk';

	/**
	 * Action Scheduler group.
	 */
	public const GROUP =
		'pdxw-pricing';

	/**
	 * Products per background job.
	 */
	public const CHUNK_SIZE =
		50;

	/**
	 * Scopes up to this size run immediately instead of being queued.
	 */
	public const INLINE_LIMIT =
		10;

	public const META_SOURCE =
		'_pdxw_price_source';

	public const META_MARKUP =
		'_pdxw_markup_applied';

	public const META_DISCOUNT =
		'_pdxw_discount_applied';

	public const META_DISCOUNT_SOURCE =
		'_pdxw_discount_source';

	public const META_COST =
		'_pdxw_cost';

	public const META_SELLING =
		'_pdxw_selling_price';

	public const META_UNMARKED =
		'_pdxw_unmarked';


	private PriceRepository $repository;

	private TieredPricing $tiers;

	private CostCalculator $costs;

	private bool $initialized = false;

	/**
	 * Product IDs whose category / brand changed during this request.
	 *
	 * @var array<int,true>
	 */
	private array $dirty = [];


	public function __construct(
		PriceRepository $repository,
		TieredPricing $tiers,
		CostCalculator $costs
	) {

		$this->repository =
			$repository;

		$this->tiers =
			$tiers;

		$this->costs =
			$costs;
	}


	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		add_action(
			self::ACTION,
			[
				$this,
				'handle_chunk',
			]
		);

		add_action(
			'set_object_terms',
			[
				$this,
				'on_terms_changed',
			],
			10,
			6
		);

		add_action(
			'shutdown',
			[
				$this,
				'flush_dirty',
			]
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Scopes
	|--------------------------------------------------------------------------
	*/

	/**
	 * Reprice every product that has tier data.
	 *
	 * Returns the number of products scheduled.
	 */
	public function reprice_all(): int {

		$count = 0;
		$after = 0;

		do {

			$ids =
				$this->repository
					->get_product_ids(
						$after,
						self::CHUNK_SIZE * 10
					);

			if ( empty( $ids ) ) {
				break;
			}

			$after =
				(int) end( $ids );

			$count += count( $ids );

			// Walking the whole catalog is always a background job.
			foreach (
				array_chunk(
					$ids,
					self::CHUNK_SIZE
				) as $chunk
			) {
				$this->enqueue(
					$chunk
				);
			}

		} while ( true );

		return $count;
	}


	/**
	 * Reprice every product in a product category and its descendants.
	 */
	public function reprice_category(
		int $term_id
	): int {

		return $this->reprice_categories(
			[
				$term_id,
			]
		);
	}


	/**
	 * Reprice every product in any of the given categories (and their
	 * descendants), scheduling each product only once.
	 *
	 * @param int[] $term_ids
	 */
	public function reprice_categories(
		array $term_ids
	): int {

		$all_terms = [];

		foreach (
			array_filter(
				array_map(
					'absint',
					$term_ids
				)
			) as $term_id
		) {

			$all_terms[] =
				$term_id;

			$children =
				get_term_children(
					$term_id,
					'product_cat'
				);

			if ( is_array( $children ) ) {

				$all_terms =
					array_merge(
						$all_terms,
						$children
					);
			}
		}

		$all_terms =
			array_values(
				array_unique(
					array_map(
						'absint',
						$all_terms
					)
				)
			);

		if ( empty( $all_terms ) ) {
			return 0;
		}

		$ids =
			get_objects_in_term(
				$all_terms,
				'product_cat'
			);

		return $this->schedule(
			is_wp_error( $ids )
				? []
				: $ids
		);
	}


	/**
	 * Reprice every product of a brand (manufacturer).
	 */
	public function reprice_brand(
		int $term_id
	): int {

		$ids =
			get_objects_in_term(
				absint( $term_id ),
				ManufacturerDiscount::TAXONOMY
			);

		return $this->schedule(
			is_wp_error( $ids )
				? []
				: $ids
		);
	}


	/**
	 * Reprice every product whose SKU starts with a prefix.
	 */
	public function reprice_sku_prefix(
		string $prefix
	): int {

		$prefix =
			trim(
				$prefix
			);

		if ( '' === $prefix ) {
			return 0;
		}

		global $wpdb;

		$ids =
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} m
						ON m.post_id = p.ID
						AND m.meta_key = '_sku'
					WHERE p.post_type = 'product'
						AND m.meta_value LIKE %s",
					$wpdb->esc_like( $prefix ) . '%'
				)
			);

		return $this->schedule(
			is_array( $ids )
				? $ids
				: []
		);
	}


	/**
	 * Reprice a set of products: immediately when small, otherwise in the
	 * background.
	 *
	 * Returns the number of products in the scope.
	 */
	public function schedule(
		array $product_ids
	): int {

		$ids =
			array_values(
				array_unique(
					array_filter(
						array_map(
							'absint',
							$product_ids
						)
					)
				)
			);

		if ( empty( $ids ) ) {
			return 0;
		}

		if ( count( $ids ) <= self::INLINE_LIMIT ) {

			$this->process(
				$ids
			);

			return count( $ids );
		}

		foreach (
			array_chunk(
				$ids,
				self::CHUNK_SIZE
			) as $chunk
		) {
			$this->enqueue(
				$chunk
			);
		}

		return count( $ids );
	}


	/*
	|--------------------------------------------------------------------------
	| Hooks
	|--------------------------------------------------------------------------
	*/

	/**
	 * Background job entry point.
	 */
	public function handle_chunk(
		$product_ids
	): void {

		if ( ! is_array( $product_ids ) ) {
			return;
		}

		$this->process(
			array_map(
				'absint',
				$product_ids
			)
		);
	}


	/**
	 * Remember products whose category or brand assignment changed.
	 *
	 * Collected and handled once on shutdown, so bulk edits and imports do
	 * not trigger one run per taxonomy write.
	 */
	public function on_terms_changed(
		$object_id,
		$terms,
		$tt_ids,
		$taxonomy,
		$append = false,
		$old_tt_ids = []
	): void {

		if (
			'product_cat' !== $taxonomy
			&& ManufacturerDiscount::TAXONOMY !== $taxonomy
		) {
			return;
		}

		if (
			'product' !== get_post_type(
				(int) $object_id
			)
		) {
			return;
		}

		if (
			is_array( $tt_ids )
			&& is_array( $old_tt_ids )
		) {

			$new = array_map( 'intval', $tt_ids );
			$old = array_map( 'intval', $old_tt_ids );

			sort( $new );
			sort( $old );

			if ( $new === $old ) {
				return;
			}
		}

		$this->dirty[
			(int) $object_id
		] = true;
	}


	public function flush_dirty(): void {

		if ( empty( $this->dirty ) ) {
			return;
		}

		$ids =
			array_keys(
				$this->dirty
			);

		$this->dirty = [];

		$this->schedule(
			$ids
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Processing
	|--------------------------------------------------------------------------
	*/

	/**
	 * Reprice products now.
	 */
	public function process(
		array $product_ids
	): void {

		foreach ( $product_ids as $product_id ) {

			$product_id =
				absint(
					$product_id
				);

			if ( $product_id ) {

				$this->reprice_product(
					$product_id
				);
			}
		}
	}


	/**
	 * Reprice one product: every variation's stored WooCommerce price plus
	 * the report meta used by the admin.
	 */
	public function reprice_product(
		int $product_id,
		bool $force = false
	): void {

		// A locked product keeps its stored price in every recalculation.
		if (
			! $force
			&& PriceLocks::is_locked( $product_id )
		) {
			return;
		}

		$variation_ids =
			$this->repository
				->get_variation_ids(
					$product_id
				);

		if ( empty( $variation_ids ) ) {

			$this->store_report(
				$product_id,
				null
			);

			return;
		}

		foreach ( $variation_ids as $variation_id ) {

			$this->tiers
				->refresh_woocommerce_price(
					$product_id,
					$variation_id
				);
		}

		$this->store_report(
			$product_id,
			$this->report_result(
				$product_id,
				$variation_ids
			)
		);

		wc_delete_product_transients(
			$product_id
		);
	}


	/**
	 * Pending background jobs (for the admin notice).
	 */
	public function pending_jobs(): int {

		if (
			! function_exists( 'as_get_scheduled_actions' )
		) {
			return 0;
		}

		$ids =
			as_get_scheduled_actions(
				[
					'hook'     => self::ACTION,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				],
				'ids'
			);

		return is_array( $ids )
			? count( $ids )
			: 0;
	}


	/*
	|--------------------------------------------------------------------------
	| Internals
	|--------------------------------------------------------------------------
	*/

	private function enqueue(
		array $chunk
	): void {

		if (
			function_exists( 'as_enqueue_async_action' )
		) {

			as_enqueue_async_action(
				self::ACTION,
				[
					$chunk,
				],
				self::GROUP
			);

			return;
		}

		// WooCommerce always ships Action Scheduler; this is only a guard.
		wp_schedule_single_event(
			time() + 5,
			self::ACTION,
			[
				$chunk,
			]
		);
	}


	/**
	 * Calculation result at the lowest tier of the first variation (or the
	 * product-level target) that can be priced.
	 */
	private function report_result(
		int $product_id,
		array $variation_ids
	): ?array {

		foreach ( $variation_ids as $variation_id ) {

			$quantities =
				$this->repository
					->get_target_quantities(
						$product_id,
						$variation_id
					);

			if ( empty( $quantities ) ) {
				continue;
			}

			$result =
				$this->costs
					->calculate(
						$product_id,
						$variation_id,
						(int) min( $quantities )
					);

			if (
				CostCalculator::STATUS_PRICED
				=== ( $result['status'] ?? '' )
			) {
				return $result;
			}
		}

		return null;
	}


	/**
	 * Persist the admin report. A null result (price on request / no tiers)
	 * clears it so the product is never reported as unmarked.
	 */
	private function store_report(
		int $product_id,
		?array $result
	): void {

		$keys = [
			self::META_SOURCE,
			self::META_MARKUP,
			self::META_DISCOUNT,
			self::META_DISCOUNT_SOURCE,
			self::META_COST,
			self::META_SELLING,
		];

		if ( null === $result ) {

			foreach ( $keys as $key ) {

				delete_post_meta(
					$product_id,
					$key
				);
			}

			update_post_meta(
				$product_id,
				self::META_UNMARKED,
				'0'
			);

			return;
		}

		$markup =
			(float) ( $result['article_markup'] ?? 0 );

		$discount =
			(float) ( $result['manufacturer_discount'] ?? 0 );

		update_post_meta(
			$product_id,
			self::META_SOURCE,
			(string) ( $result['source'] ?? '' )
		);

		update_post_meta(
			$product_id,
			self::META_MARKUP,
			$markup
		);

		update_post_meta(
			$product_id,
			self::META_DISCOUNT,
			$discount
		);

		update_post_meta(
			$product_id,
			self::META_DISCOUNT_SOURCE,
			(string) ( $result['discount_source'] ?? 'none' )
		);

		update_post_meta(
			$product_id,
			self::META_COST,
			(float) ( $result['cost'] ?? 0 )
		);

		update_post_meta(
			$product_id,
			self::META_SELLING,
			(float) ( $result['article_price'] ?? 0 )
		);

		update_post_meta(
			$product_id,
			self::META_UNMARKED,
			(
				$markup <= 0.0
				&& $discount <= 0.0
			)
				? '1'
				: '0'
		);
	}
}
