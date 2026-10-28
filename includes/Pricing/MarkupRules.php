<?php

namespace PromiDataXWoo\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Pricing markup business rules.
 *
 * Three price parts, each with its own markup, resolved independently.
 * The most specific level wins:
 *
 * Article price:
 *
 *     product  >  supplier + category  >  supplier (SKU prefix)
 *              >  category (deepest)  >  default
 *
 * Print unit price / print fee (no product-level markup; use a fixed price):
 *
 *     print option  >  supplier + category  >  supplier (SKU prefix)
 *                   >  category (deepest)  >  default
 *
 * "Supplier + category" is ONE markup set for a supplier prefix and a
 * category together. It applies only when the product matches both (the
 * category includes its sub-categories); it is not the sum of the two.
 *
 * Every default is 0% when unset: no markup is applied until configured.
 *
 * A fixed price (FixedPrices) bypasses all of this and is checked by the
 * callers before a markup is resolved.
 *
 * Manufacturer / supplier DISCOUNTS are intentionally NOT handled here
 * (see ManufacturerDiscount and SupplierDiscount); they reduce the cost
 * before the markup is applied.
 *
 * Each resolve_*() method returns:
 *
 *     [
 *         'percent' => float,
 *         'level'   => 'product'|'print_option'|'supplier_category'|'supplier'|'category'|'default',
 *         'label'   => human readable origin, e.g. "Category: USB Sticks",
 *         'target'  => id / prefix of the matching rule ('' for default),
 *     ]
 */
final class MarkupRules {

	public const DEFAULT_ARTICLE_MARKUP =
		0.0;

	public const DEFAULT_PRINT_PRICE_MARKUP =
		0.0;

	public const DEFAULT_PRINT_FEE_MARKUP =
		0.0;

	public const LEVEL_PRODUCT      = 'product';
	public const LEVEL_PRINT_OPTION = 'print_option';
	public const LEVEL_CATEGORY     = 'category';
	public const LEVEL_SUPPLIER     = 'supplier';
	public const LEVEL_SUPPLIER_CATEGORY = 'supplier_category';
	public const LEVEL_DEFAULT      = 'default';

	/**
	 * Manufacturer discount term-meta key.
	 *
	 * Kept here as a shared compatibility constant because
	 * ManufacturerDiscount currently references this constant.
	 */
	public const MANUFACTURER_DISCOUNT_META =
		'pdxw_manufacturer_discount';


	private MarkupRepository $repository;

	private ScopedRuleRepository $scoped;

	/**
	 * What-if defaults used by previews: "part|default|" => percent.
	 *
	 * @var array<string,float>
	 */
	private array $overlay = [];


	public function __construct(
		MarkupRepository $repository,
		?ScopedRuleRepository $scoped = null
	) {
		$this->repository =
			$repository;

		$this->scoped =
			$scoped ?? new ScopedRuleRepository();
	}


	/*
	|--------------------------------------------------------------------------
	| Defaults
	|--------------------------------------------------------------------------
	*/

	public function article_default(): float {

		if ( array_key_exists( 'article|default|', $this->overlay ) ) {
			return max( 0.0, (float) $this->overlay['article|default|'] );
		}

		return max(
			0.0,
			(float) get_option(
				'pdxw_default_article_markup',
				self::DEFAULT_ARTICLE_MARKUP
			)
		);
	}

	public function print_price_default(): float {

		if ( array_key_exists( 'print_price|default|', $this->overlay ) ) {
			return max( 0.0, (float) $this->overlay['print_price|default|'] );
		}

		return max(
			0.0,
			(float) get_option(
				'pdxw_default_print_price_markup',
				self::DEFAULT_PRINT_PRICE_MARKUP
			)
		);
	}

	public function print_fee_default(): float {

		if ( array_key_exists( 'print_fee|default|', $this->overlay ) ) {
			return max( 0.0, (float) $this->overlay['print_fee|default|'] );
		}

		return max(
			0.0,
			(float) get_option(
				'pdxw_default_print_fee_markup',
				self::DEFAULT_PRINT_FEE_MARKUP
			)
		);
	}

	public function set_article_default(
		float $markup
	): void {

		$previous = (float) get_option( 'pdxw_default_article_markup', 0.0 );

		update_option(
			'pdxw_default_article_markup',
			max( 0.0, $markup ),
			false
		);

		if ( abs( $previous - max( 0.0, $markup ) ) > 0.00001 ) {
			History::record_rule_write(
				sprintf( 'article markup, default: %s%% -> %s%%', $previous, max( 0.0, $markup ) ),
				0,
				[ 'part' => 'article', 'level' => 'default', 'old' => $previous, 'new' => max( 0.0, $markup ) ]
			);
		}
	}

	public function set_print_price_default(
		float $markup
	): void {

		$previous = (float) get_option( 'pdxw_default_print_price_markup', 0.0 );

		update_option(
			'pdxw_default_print_price_markup',
			max( 0.0, $markup ),
			false
		);

		if ( abs( $previous - max( 0.0, $markup ) ) > 0.00001 ) {
			History::record_rule_write(
				sprintf( 'print_price markup, default: %s%% -> %s%%', $previous, max( 0.0, $markup ) ),
				0,
				[ 'part' => 'print_price', 'level' => 'default', 'old' => $previous, 'new' => max( 0.0, $markup ) ]
			);
		}
	}

	public function set_print_fee_default(
		float $markup
	): void {

		$previous = (float) get_option( 'pdxw_default_print_fee_markup', 0.0 );

		update_option(
			'pdxw_default_print_fee_markup',
			max( 0.0, $markup ),
			false
		);

		if ( abs( $previous - max( 0.0, $markup ) ) > 0.00001 ) {
			History::record_rule_write(
				sprintf( 'print_fee markup, default: %s%% -> %s%%', $previous, max( 0.0, $markup ) ),
				0,
				[ 'part' => 'print_fee', 'level' => 'default', 'old' => $previous, 'new' => max( 0.0, $markup ) ]
			);
		}
	}


	/*
	|--------------------------------------------------------------------------
	| Article Markup
	|--------------------------------------------------------------------------
	*/

	/**
	 * Resolve the article markup and the level that supplied it.
	 *
	 * @return array{percent:float,level:string,label:string,target:string}
	 */
	public function resolve_article(
		int $product_id
	): array {

		$product_id = absint( $product_id );

		if ( $product_id ) {

			$product_rule =
				$this->scoped->get(
					ScopedRuleRepository::PART_ARTICLE,
					ScopedRuleRepository::LEVEL_PRODUCT,
					(string) $product_id
				);

			if ( null !== $product_rule ) {
				return $this->result(
					$product_rule,
					self::LEVEL_PRODUCT,
					'Product #' . $product_id,
					(string) $product_id
				);
			}

			$combined =
				$this->combined_match(
					ScopedRuleRepository::PART_ARTICLE,
					$product_id
				);

			if ( $combined ) {
				return $combined;
			}

			$supplier =
				$this->scoped->match_supplier(
					ScopedRuleRepository::PART_ARTICLE,
					$this->sku( $product_id )
				);

			if ( $supplier ) {
				return $this->supplier_result( $supplier );
			}

			$category = $this->best_category(
				$product_id,
				fn( int $term_id ) =>
					$this->repository->get(
						MarkupRepository::TYPE_CATEGORY,
						$term_id
					)
			);

			if ( $category ) {
				return $this->category_result( $category );
			}
		}

		return $this->result(
			$this->article_default(),
			self::LEVEL_DEFAULT,
			'Default',
			''
		);
	}

	/**
	 * Return the applicable article markup for a product.
	 */
	public function article_markup(
		int $product_id
	): float {

		return $this->resolve_article( $product_id )['percent'];
	}


	/*
	|--------------------------------------------------------------------------
	| Print price / print fee markup
	|--------------------------------------------------------------------------
	*/

	/**
	 * @return array{percent:float,level:string,label:string,target:string}
	 */
	public function resolve_print_price(
		int $print_option_id,
		int $product_id = 0
	): array {

		return $this->resolve_print(
			ScopedRuleRepository::PART_PRINT_PRICE,
			MarkupRepository::TYPE_PRINT_OPTION_PRICE,
			$print_option_id,
			$product_id,
			$this->print_price_default()
		);
	}

	/**
	 * @return array{percent:float,level:string,label:string,target:string}
	 */
	public function resolve_print_fee(
		int $print_option_id,
		int $product_id = 0
	): array {

		return $this->resolve_print(
			ScopedRuleRepository::PART_PRINT_FEE,
			MarkupRepository::TYPE_PRINT_OPTION_FEE,
			$print_option_id,
			$product_id,
			$this->print_fee_default()
		);
	}

	/**
	 * Return the print-price markup for a print option (and product).
	 */
	public function print_price_markup(
		int $print_option_id,
		int $product_id = 0
	): float {

		return $this->resolve_print_price(
			$print_option_id,
			$product_id
		)['percent'];
	}

	/**
	 * Return the setup/fee markup for a print option (and product).
	 */
	public function print_fee_markup(
		int $print_option_id,
		int $product_id = 0
	): float {

		return $this->resolve_print_fee(
			$print_option_id,
			$product_id
		)['percent'];
	}


	/**
	 * @return array{percent:float,level:string,label:string,target:string}
	 */
	private function resolve_print(
		string $part,
		string $option_type,
		int $print_option_id,
		int $product_id,
		float $default
	): array {

		$print_option_id = absint( $print_option_id );
		$product_id      = absint( $product_id );

		if ( $print_option_id ) {

			$option_rule =
				$this->repository->get( $option_type, $print_option_id );

			if ( null === $option_rule ) {
				$option_rule =
					$this->repository->get(
						MarkupRepository::TYPE_PRINT_OPTION,
						$print_option_id
					);
			}

			if ( null !== $option_rule ) {
				return $this->result(
					$option_rule,
					self::LEVEL_PRINT_OPTION,
					'Print option #' . $print_option_id,
					(string) $print_option_id
				);
			}
		}

		if ( $product_id ) {

			$combined =
				$this->combined_match( $part, $product_id );

			if ( $combined ) {
				return $combined;
			}

			$supplier =
				$this->scoped->match_supplier(
					$part,
					$this->sku( $product_id )
				);

			if ( $supplier ) {
				return $this->supplier_result( $supplier );
			}

			$category = $this->best_category(
				$product_id,
				fn( int $term_id ) =>
					$this->scoped->get(
						$part,
						ScopedRuleRepository::LEVEL_CATEGORY,
						(string) $term_id
					)
			);

			if ( $category ) {
				return $this->category_result( $category );
			}
		}

		return $this->result(
			$default,
			self::LEVEL_DEFAULT,
			'Default',
			''
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Category resolution
	|--------------------------------------------------------------------------
	*/

	/**
	 * Supplier + category rule for a product, if both parts match.
	 *
	 * The SKU must start with the rule's prefix and the product must be in
	 * the rule's category or one of its sub-categories. With several
	 * matches the longest prefix wins, then the deepest category, then the
	 * lowest term id.
	 *
	 * @return array{percent:float,level:string,label:string,target:string}|null
	 */
	private function combined_match(
		string $part,
		int $product_id
	): ?array {

		$rules = $this->scoped->combined_rules( $part );

		if ( empty( $rules ) ) {
			return null;
		}

		$sku = strtoupper( trim( $this->sku( $product_id ) ) );

		if ( '' === $sku ) {
			return null;
		}

		$own = wp_get_post_terms(
			$product_id,
			'product_cat',
			[ 'fields' => 'ids' ]
		);

		if ( is_wp_error( $own ) || empty( $own ) ) {
			return null;
		}

		// Every category the product belongs to, including ancestors.
		$in_tree = [];

		foreach ( $own as $term_id ) {

			$term_id = absint( $term_id );

			$in_tree[ $term_id ] = true;

			foreach ( get_ancestors( $term_id, 'product_cat' ) as $ancestor ) {
				$in_tree[ absint( $ancestor ) ] = true;
			}
		}

		$best     = null;
		$best_key = null;

		foreach ( $rules as $rule ) {

			if (
				! isset( $in_tree[ $rule['term_id'] ] )
				|| ! str_starts_with( $sku, $rule['prefix'] )
			) {
				continue;
			}

			$depth = count( get_ancestors( $rule['term_id'], 'product_cat' ) ) + 1;

			$key = [ strlen( $rule['prefix'] ), $depth, -$rule['term_id'] ];

			if ( null === $best_key || $key > $best_key ) {
				$best_key = $key;
				$best     = $rule;
			}
		}

		if ( ! $best ) {
			return null;
		}

		$term = get_term( $best['term_id'], 'product_cat' );

		$name = $term && ! is_wp_error( $term )
			? $term->name
			: '#' . $best['term_id'];

		return $this->result(
			$best['markup'],
			self::LEVEL_SUPPLIER_CATEGORY,
			'Supplier + category: ' . $best['prefix'] . ' + ' . $name,
			$best['prefix'] . ':' . $best['term_id']
		);
	}


	/**
	 * Find the rule on the deepest category of the product.
	 *
	 * Depth is the term's absolute depth in the category tree, so rules on
	 * different branches of a multi-category product compare correctly.
	 * On equal depth the lowest term id wins, which keeps results stable.
	 *
	 * @param callable(int):?float $lookup
	 * @return array{term_id:int,markup:float,depth:int}|null
	 */
	private function best_category(
		int $product_id,
		callable $lookup
	): ?array {

		$category_ids =
			wp_get_post_terms(
				$product_id,
				'product_cat',
				[ 'fields' => 'ids' ]
			);

		if ( is_wp_error( $category_ids ) || empty( $category_ids ) ) {
			return null;
		}

		sort( $category_ids );

		$best = null;

		foreach ( $category_ids as $category_id ) {

			$category_id = absint( $category_id );

			if ( ! $category_id ) {
				continue;
			}

			$chain = array_merge(
				[ $category_id ],
				array_map(
					'absint',
					get_ancestors( $category_id, 'product_cat' )
				)
			);

			$chain_size = count( $chain );

			// The first rule found walking upwards is the most specific.
			foreach ( $chain as $index => $term_id ) {

				if ( ! $term_id ) {
					continue;
				}

				$markup = $lookup( $term_id );

				if ( null === $markup ) {
					continue;
				}

				$depth = $chain_size - $index;

				if ( null === $best || $depth > $best['depth'] ) {
					$best = [
						'term_id' => $term_id,
						'markup'  => (float) $markup,
						'depth'   => $depth,
					];
				}

				break;
			}
		}

		return $best;
	}

	private function category_result( array $category ): array {

		$term = get_term( $category['term_id'], 'product_cat' );

		$name = $term && ! is_wp_error( $term )
			? $term->name
			: '#' . $category['term_id'];

		return $this->result(
			$category['markup'],
			self::LEVEL_CATEGORY,
			'Category: ' . $name,
			(string) $category['term_id']
		);
	}

	private function supplier_result( array $rule ): array {

		return $this->result(
			$rule['markup'],
			self::LEVEL_SUPPLIER,
			'Supplier: ' . $rule['prefix'],
			$rule['prefix']
		);
	}

	private function result(
		float $percent,
		string $level,
		string $label,
		string $target
	): array {

		return [
			'percent' => max( 0.0, $percent ),
			'level'   => $level,
			'label'   => $label,
			'target'  => $target,
		];
	}

	private function sku( int $product_id ): string {

		return (string) get_post_meta( $product_id, '_sku', true );
	}


	/*
	|--------------------------------------------------------------------------
	| Listings
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return all print-option markup overrides, merged by print option.
	 *
	 * Each row:
	 *
	 * [
	 *     'id'                    => 12,
	 *     'price_markup_percent'  => 25.0,
	 *     'fee_markup_percent'    => 30.0,
	 * ]
	 */
	public function print_option_overrides(): array {

		$price_rules =
			$this->repository
				->all( MarkupRepository::TYPE_PRINT_OPTION_PRICE );

		$fee_rules =
			$this->repository
				->all( MarkupRepository::TYPE_PRINT_OPTION_FEE );

		$rows = [];

		foreach ( $price_rules as $rule ) {

			$id = absint( $rule['target_id'] ?? 0 );

			if ( ! $id ) {
				continue;
			}

			$rows[ $id ]['id'] = $id;

			$rows[ $id ]['price_markup_percent'] =
				(float) ( $rule['markup_percent'] ?? 0 );
		}

		foreach ( $fee_rules as $rule ) {

			$id = absint( $rule['target_id'] ?? 0 );

			if ( ! $id ) {
				continue;
			}

			$rows[ $id ]['id'] = $id;

			$rows[ $id ]['fee_markup_percent'] =
				(float) ( $rule['markup_percent'] ?? 0 );
		}

		ksort( $rows );

		return array_values( $rows );
	}

	/**
	 * Every active markup rule at every level and part, in one list.
	 *
	 * @return array<int,array{part:string,level:string,target:string,label:string,markup_percent:float}>
	 */
	public function all_rules(): array {

		$rules = [
			[
				'part'           => 'article',
				'level'          => self::LEVEL_DEFAULT,
				'target'         => '',
				'label'          => 'Default',
				'markup_percent' => $this->article_default(),
			],
			[
				'part'           => 'print_price',
				'level'          => self::LEVEL_DEFAULT,
				'target'         => '',
				'label'          => 'Default',
				'markup_percent' => $this->print_price_default(),
			],
			[
				'part'           => 'print_fee',
				'level'          => self::LEVEL_DEFAULT,
				'target'         => '',
				'label'          => 'Default',
				'markup_percent' => $this->print_fee_default(),
			],
		];

		foreach ( $this->scoped->all() as $row ) {

			$label = $row['level'] . ': ' . $row['target_key'];

			if ( ScopedRuleRepository::LEVEL_CATEGORY === $row['level'] ) {
				$term  = get_term( (int) $row['target_key'], 'product_cat' );
				$label = 'Category: ' . ( $term && ! is_wp_error( $term ) ? $term->name : '#' . $row['target_key'] );
			} elseif ( ScopedRuleRepository::LEVEL_SUPPLIER === $row['level'] ) {
				$label = 'Supplier: ' . $row['target_key'];
			} elseif ( ScopedRuleRepository::LEVEL_SUPPLIER_CATEGORY === $row['level'] ) {
				[ $prefix, $term_id ] = array_pad( explode( ':', $row['target_key'], 2 ), 2, '0' );
				$term  = get_term( (int) $term_id, 'product_cat' );
				$label = 'Supplier + category: ' . $prefix . ' + ' . ( $term && ! is_wp_error( $term ) ? $term->name : '#' . $term_id );
			} else {
				$label = 'Product #' . $row['target_key'];
			}

			$rules[] = [
				'part'           => $row['part'],
				'level'          => $row['level'],
				'target'         => $row['target_key'],
				'label'          => $label,
				'markup_percent' => $row['markup_percent'],
			];
		}

		foreach (
			$this->repository->all( MarkupRepository::TYPE_CATEGORY ) as $row
		) {
			$term = get_term( (int) $row['target_id'], 'product_cat' );

			$rules[] = [
				'part'           => 'article',
				'level'          => self::LEVEL_CATEGORY,
				'target'         => (string) $row['target_id'],
				'label'          => 'Category: ' . ( $term && ! is_wp_error( $term ) ? $term->name : '#' . $row['target_id'] ),
				'markup_percent' => (float) $row['markup_percent'],
			];
		}

		foreach (
			[
				MarkupRepository::TYPE_PRINT_OPTION_PRICE => 'print_price',
				MarkupRepository::TYPE_PRINT_OPTION_FEE   => 'print_fee',
			] as $type => $part
		) {
			foreach ( $this->repository->all( $type ) as $row ) {
				$rules[] = [
					'part'           => $part,
					'level'          => self::LEVEL_PRINT_OPTION,
					'target'         => (string) $row['target_id'],
					'label'          => 'Print option #' . $row['target_id'],
					'markup_percent' => (float) $row['markup_percent'],
				];
			}
		}

		return $rules;
	}


	/*
	|--------------------------------------------------------------------------
	| What-if (preview)
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return a copy of these rules with proposed changes applied in memory.
	 *
	 * Nothing is written. Each change:
	 *
	 *     [ 'part'    => article|print_price|print_fee,
	 *       'level'   => default|supplier|category|print_option|product,
	 *       'target'  => id / prefix ('' for default),
	 *       'percent' => float, or null to remove the rule ]
	 *
	 * @param array<int,array<string,mixed>> $changes
	 */
	public function with_changes( array $changes ): self {

		$legacy = [];
		$scoped = [];
		$clone  = clone $this;

		foreach ( $changes as $change ) {

			$part    = (string) ( $change['part'] ?? '' );
			$level   = (string) ( $change['level'] ?? '' );
			$target  = (string) ( $change['target'] ?? '' );
			$percent = array_key_exists( 'percent', $change ) && null !== $change['percent']
				? max( 0.0, (float) $change['percent'] )
				: null;

			if ( self::LEVEL_DEFAULT === $level ) {

				// A removed default falls back to 0%.
				$clone->overlay[ "{$part}|default|" ] = $percent ?? 0.0;

			} elseif (
				ScopedRuleRepository::PART_ARTICLE === $part
				&& self::LEVEL_CATEGORY === $level
			) {

				$legacy[ MarkupRepository::TYPE_CATEGORY . "|{$target}" ] = $percent;

			} elseif ( self::LEVEL_PRINT_OPTION === $level ) {

				$type = ScopedRuleRepository::PART_PRINT_PRICE === $part
					? MarkupRepository::TYPE_PRINT_OPTION_PRICE
					: MarkupRepository::TYPE_PRINT_OPTION_FEE;

				$legacy[ "{$type}|{$target}" ] = $percent;

			} else {

				$key = in_array( $level, [ self::LEVEL_SUPPLIER, self::LEVEL_SUPPLIER_CATEGORY ], true )
					? strtoupper( $target )
					: $target;

				$scoped[ "{$part}|{$level}|{$key}" ] = $percent;
			}
		}

		$clone->repository = $this->repository->with_overlay( $legacy );
		$clone->scoped     = $this->scoped->with_overlay( $scoped );

		return $clone;
	}


	/*
	|--------------------------------------------------------------------------
	| Repository
	|--------------------------------------------------------------------------
	*/

	public function repository(): MarkupRepository {

		return $this->repository;
	}

	public function scoped(): ScopedRuleRepository {

		return $this->scoped;
	}
}
