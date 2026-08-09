<?php

namespace PromiDataXWoo\Admin;

use PromiDataXWoo\Catalog\Catalog;
use PromiDataXWoo\Core\Database;
use PromiDataXWoo\Printing\Printing;
use PromiDataXWoo\Promi\Promi;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce product-list administration.
 *
 * Rebuilds useful product-management diagnostics previously spread across
 * cx-promi and cx-print.
 *
 * Product list additions:
 *
 * - Promi Status column.
 * - Promi Disabled view.
 * - Variations Problem view.
 * - Need Images view.
 * - No Tiers view.
 * - Print Option filter.
 *
 * This class owns admin-query presentation only.
 *
 * Promi synchronization remains inside Promi.
 * Pricing storage remains inside Pricing.
 * Printing storage remains inside Printing.
 */
final class ProductAdmin {

	/*
	|--------------------------------------------------------------------------
	| Existing Operational Meta
	|--------------------------------------------------------------------------
	|
	| These keys carry real imported-product state, so they remain useful even
	| though the old plugin classes are being removed.
	*/

	public const DISABLED_META =
		'cx_promi_disabled_product';

	public const NEED_IMAGES_META =
		'_cx_need_to_sync_images';


	/*
	|--------------------------------------------------------------------------
	| Product List Query Arguments
	|--------------------------------------------------------------------------
	|
	| These are new PDXW request parameters.
	|
	| We do not need to retain the old cx_* URL query arguments because they
	| are transient admin navigation, not persisted business data.
	*/

	public const DISABLED_FILTER =
		'pdxw_promi_disabled';

	public const NO_VARIATIONS_FILTER =
		'pdxw_variable_no_variations';

	public const NEED_IMAGES_FILTER =
		'pdxw_need_images';

	public const NO_TIERS_FILTER =
		'pdxw_no_tiers';

	public const PRINT_OPTION_FILTER =
		'pdxw_print_option';


	/*
	|--------------------------------------------------------------------------
	| Internal Query Flags
	|--------------------------------------------------------------------------
	*/

	private const QUERY_NO_VARIATIONS =
		'pdxw_query_no_variations';

	private const QUERY_NO_TIERS =
		'pdxw_query_no_tiers';


	private Catalog $catalog;

	private Printing $printing;

	private Promi $promi;

	private bool $initialized = false;


	public function __construct(
		Catalog $catalog,
		Printing $printing,
		Promi $promi
	) {
		$this->catalog  = $catalog;
		$this->printing = $printing;
		$this->promi    = $promi;
	}


	/**
	 * Register WooCommerce product-list integrations.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;


		/*
		|--------------------------------------------------------------------------
		| Promi Status Column
		|--------------------------------------------------------------------------
		*/

		add_filter(
			'manage_edit-product_columns',
			[
				$this,
				'columns',
			],
			20
		);

		add_action(
			'manage_product_posts_custom_column',
			[
				$this,
				'render_column',
			],
			10,
			2
		);


		/*
		|--------------------------------------------------------------------------
		| Product Diagnostic Views
		|--------------------------------------------------------------------------
		*/

		add_filter(
			'views_edit-product',
			[
				$this,
				'views',
			]
		);


		/*
		|--------------------------------------------------------------------------
		| Print Option Filter
		|--------------------------------------------------------------------------
		*/

		add_action(
			'restrict_manage_posts',
			[
				$this,
				'render_print_option_filter',
			],
			20,
			2
		);


		/*
		|--------------------------------------------------------------------------
		| Product Query
		|--------------------------------------------------------------------------
		*/

		add_action(
			'pre_get_posts',
			[
				$this,
				'filter_products',
			],
			20
		);


		/*
		|--------------------------------------------------------------------------
		| SQL Diagnostics
		|--------------------------------------------------------------------------
		|
		| Two diagnostics are much cheaper as NOT EXISTS SQL conditions:
		|
		| - variable products without child variations
		| - products without any tier records
		*/

		add_filter(
			'posts_where',
			[
				$this,
				'filter_where',
			],
			20,
			2
		);


		do_action(
			'pdxw_admin_product_init',
			$this
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Columns
	|--------------------------------------------------------------------------
	*/

	/**
	 * Insert the Promi Status column after the WooCommerce product name.
	 */
	public function columns(
		array $columns
	): array {

		$result = [];


		foreach (
			$columns as $key => $label
		) {

			$result[
				$key
			] = $label;


			if ( 'name' === $key ) {

				$result[
					'pdxw_promi_status'
				] =
					__(
						'Promi Status',
						'promi-data-x-woo'
					);
			}
		}


		return $result;
	}


	/**
	 * Render one custom WooCommerce product column.
	 */
	public function render_column(
		string $column,
		int $post_id
	): void {

		if (
			'pdxw_promi_status'
			!== $column
		) {
			return;
		}


		$disabled =
			(bool)
			get_post_meta(
				$post_id,
				self::DISABLED_META,
				true
			);


		if ( $disabled ) {

			printf(
				'<span class="pdxw-promi-status pdxw-promi-status--disabled">%s</span>',
				esc_html__(
					'Disabled',
					'promi-data-x-woo'
				)
			);

			return;
		}


		printf(
			'<span class="pdxw-promi-status pdxw-promi-status--enabled">%s</span>',
			esc_html__(
				'Enabled',
				'promi-data-x-woo'
			)
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Product Views
	|--------------------------------------------------------------------------
	*/

	/**
	 * Add PDXW diagnostic links above the WooCommerce product table.
	 */
	public function views(
		array $views
	): array {

		$views[
			'pdxw_promi_disabled'
		] =
			$this->view_link(
				__(
					'Promi Disabled',
					'promi-data-x-woo'
				),
				self::DISABLED_FILTER,
				$this->count_disabled()
			);


		$views[
			'pdxw_variable_no_variations'
		] =
			$this->view_link(
				__(
					'Variations Problem',
					'promi-data-x-woo'
				),
				self::NO_VARIATIONS_FILTER,
				$this->count_variable_without_variations()
			);


		$views[
			'pdxw_need_images'
		] =
			$this->view_link(
				__(
					'Need Images',
					'promi-data-x-woo'
				),
				self::NEED_IMAGES_FILTER,
				$this->count_need_images()
			);


		$views[
			'pdxw_no_tiers'
		] =
			$this->view_link(
				__(
					'No Tiers',
					'promi-data-x-woo'
				),
				self::NO_TIERS_FILTER,
				$this->count_no_tiers()
			);


		return $views;
	}


	/**
	 * Render one WooCommerce product-list view link.
	 */
	private function view_link(
		string $label,
		string $filter,
		int $count
	): string {

		$url =
			add_query_arg(
				[
					'post_type' =>
						'product',

					$filter =>
						'1',
				],
				admin_url(
					'edit.php'
				)
			);


		$current =
			$this->request_flag(
				$filter
			);


		return sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url(
				$url
			),
			$current
				? ' class="current" aria-current="page"'
				: '',
			esc_html(
				$label
			),
			esc_html(
				number_format_i18n(
					$count
				)
			)
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Diagnostic Counts
	|--------------------------------------------------------------------------
	*/

	/**
	 * Count Promi-disabled WooCommerce products.
	 */
	private function count_disabled(): int {

		global $wpdb;


		$sql =
			$wpdb->prepare(
				"
				SELECT COUNT(DISTINCT p.ID)

				FROM {$wpdb->posts} p

				INNER JOIN {$wpdb->postmeta} pm
					ON pm.post_id = p.ID

				WHERE p.post_type = %s
					AND p.post_status NOT IN ('trash', 'auto-draft')
					AND pm.meta_key = %s
					AND pm.meta_value != ''
				",
				'product',
				self::DISABLED_META
			);


		return (int)
			$wpdb->get_var(
				$sql
			);
	}


	/**
	 * Count variable products with no non-trashed child variations.
	 */
	private function count_variable_without_variations(): int {

		global $wpdb;


		$sql = "
			SELECT COUNT(DISTINCT p.ID)

			FROM {$wpdb->posts} p

			INNER JOIN {$wpdb->term_relationships} tr
				ON tr.object_id = p.ID

			INNER JOIN {$wpdb->term_taxonomy} tt
				ON tt.term_taxonomy_id = tr.term_taxonomy_id
				AND tt.taxonomy = 'product_type'

			INNER JOIN {$wpdb->terms} t
				ON t.term_id = tt.term_id
				AND t.slug = 'variable'

			WHERE p.post_type = 'product'
				AND p.post_status NOT IN ('trash', 'auto-draft')

				AND NOT EXISTS (
					SELECT 1

					FROM {$wpdb->posts} variation

					WHERE variation.post_parent = p.ID
						AND variation.post_type = 'product_variation'
						AND variation.post_status NOT IN ('trash', 'auto-draft')
				)
		";


		return (int)
			$wpdb->get_var(
				$sql
			);
	}


	/**
	 * Count products still waiting for deferred Promi image synchronization.
	 */
	private function count_need_images(): int {

		global $wpdb;


		$sql =
			$wpdb->prepare(
				"
				SELECT COUNT(DISTINCT p.ID)

				FROM {$wpdb->posts} p

				INNER JOIN {$wpdb->postmeta} pm
					ON pm.post_id = p.ID

				WHERE p.post_type = %s
					AND p.post_status NOT IN ('trash', 'auto-draft')
					AND pm.meta_key = %s
				",
				'product',
				self::NEED_IMAGES_META
			);


		return (int)
			$wpdb->get_var(
				$sql
			);
	}


	/**
	 * Count products with no rows at all in the tier-pricing table.
	 *
	 * This intentionally preserves the existing diagnostic's semantics:
	 * a parent counts as having tiers if any cx_tier_prices row references
	 * its product_id, including variation-tier rows.
	 */
	private function count_no_tiers(): int {

		global $wpdb;


		$tier_table =
			Database::table(
				'tier_prices'
			);


		$sql = "
			SELECT COUNT(p.ID)

			FROM {$wpdb->posts} p

			WHERE p.post_type = 'product'
				AND p.post_status NOT IN ('trash', 'auto-draft')

				AND NOT EXISTS (
					SELECT 1

					FROM {$tier_table} tp

					WHERE tp.product_id = p.ID
				)
		";


		return (int)
			$wpdb->get_var(
				$sql
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Print Option Filter UI
	|--------------------------------------------------------------------------
	*/

	/**
	 * Add a Print Option dropdown to the WooCommerce product table.
	 *
	 * This replaces CX_Print_Admin::render_print_option_filter().
	 */
	public function render_print_option_filter(
		string $post_type = '',
		string $which = ''
	): void {

		if (
			'product'
			!== $post_type
		) {
			return;
		}


		$selected =
			$this->request_int(
				self::PRINT_OPTION_FILTER
			);


		$options =
			$this->printing
				->options()
				->all();


		if ( empty( $options ) ) {
			return;
		}
		?>

		<label
			class="screen-reader-text"
			for="pdxw-print-option-filter"
		>
			<?php
			echo esc_html__(
				'Filter by print option',
				'promi-data-x-woo'
			);
			?>
		</label>


		<select
			id="pdxw-print-option-filter"
			name="<?php echo esc_attr(
				self::PRINT_OPTION_FILTER
			); ?>"
		>

			<option value="">
				<?php
				echo esc_html__(
					'All Print Options',
					'promi-data-x-woo'
				);
				?>
			</option>


			<?php foreach ( $options as $option ) : ?>

				<?php
				if (
					! is_object(
						$option
					)
					|| empty(
						$option->id
					)
				) {
					continue;
				}


				$name =
					trim(
						(string) (
							$option->name
							?? ''
						)
					);


				$sku =
					trim(
						(string) (
							$option->sku
							?? ''
						)
					);


				$label =
					$name;


				if (
					'' !== $sku
					&& $sku !== $name
				) {

					$label .=
						' — '
						. $sku;
				}
				?>

				<option
					value="<?php echo esc_attr(
						(int) $option->id
					); ?>"
					<?php
					selected(
						$selected,
						(int) $option->id
					);
					?>
				>
					<?php
					echo esc_html(
						$label
					);
					?>
				</option>

			<?php endforeach; ?>

		</select>

		<?php
	}


	/*
	|--------------------------------------------------------------------------
	| Product Query Filtering
	|--------------------------------------------------------------------------
	*/

	/**
	 * Apply active PDXW filters to WooCommerce's main product query.
	 */
	public function filter_products(
		WP_Query $query
	): void {

		if (
			! $this->is_product_list_query(
				$query
			)
		) {
			return;
		}


		/*
		|--------------------------------------------------------------------------
		| Promi Disabled
		|--------------------------------------------------------------------------
		*/

		if (
			$this->request_flag(
				self::DISABLED_FILTER
			)
		) {

			$meta_query =
				(array)
				$query->get(
					'meta_query'
				);


			$meta_query[] = [
				'key' =>
					self::DISABLED_META,

				'value' =>
					'',

				'compare' =>
					'!=',
			];


			$query->set(
				'meta_query',
				$meta_query
			);
		}


		/*
		|--------------------------------------------------------------------------
		| Need Images
		|--------------------------------------------------------------------------
		*/

		if (
			$this->request_flag(
				self::NEED_IMAGES_FILTER
			)
		) {

			$meta_query =
				(array)
				$query->get(
					'meta_query'
				);


			$meta_query[] = [
				'key' =>
					self::NEED_IMAGES_META,

				'compare' =>
					'EXISTS',
			];


			$query->set(
				'meta_query',
				$meta_query
			);
		}


		/*
		|--------------------------------------------------------------------------
		| Variable Product With No Variations
		|--------------------------------------------------------------------------
		*/

		if (
			$this->request_flag(
				self::NO_VARIATIONS_FILTER
			)
		) {

			$tax_query =
				(array)
				$query->get(
					'tax_query'
				);


			$tax_query[] = [
				'taxonomy' =>
					'product_type',

				'field' =>
					'slug',

				'terms' => [
					'variable',
				],

				'operator' =>
					'IN',
			];


			$query->set(
				'tax_query',
				$tax_query
			);


			$query->set(
				self::QUERY_NO_VARIATIONS,
				true
			);
		}


		/*
		|--------------------------------------------------------------------------
		| No Tier Pricing
		|--------------------------------------------------------------------------
		*/

		if (
			$this->request_flag(
				self::NO_TIERS_FILTER
			)
		) {

			$query->set(
				self::QUERY_NO_TIERS,
				true
			);
		}


		/*
		|--------------------------------------------------------------------------
		| Print Option
		|--------------------------------------------------------------------------
		*/

		$print_option_id =
			$this->request_int(
				self::PRINT_OPTION_FILTER
			);


		if ( $print_option_id ) {

			$this->apply_print_option_filter(
				$query,
				$print_option_id
			);
		}
	}


	/**
	 * Apply the print-option relation filter.
	 *
	 * The existing CX Print implementation first resolves distinct parent
	 * product IDs from cx_print_relation, then limits WP_Query using
	 * post__in. Preserve that behavior.
	 */
	private function apply_print_option_filter(
		WP_Query $query,
		int $print_option_id
	): void {

		global $wpdb;


		$relation_table =
			Database::table(
				'print_relation'
			);


		$product_ids =
			$wpdb->get_col(
				$wpdb->prepare(
					"
					SELECT DISTINCT product_id

					FROM {$relation_table}

					WHERE print_option_id = %d
					",
					$print_option_id
				)
			);


		$product_ids =
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


		if ( empty( $product_ids ) ) {

			$product_ids = [
				0,
			];
		}


		/*
		 * Respect any post__in constraint already applied by WooCommerce or
		 * another plugin rather than silently replacing it.
		 */
		$existing =
			array_filter(
				array_map(
					'absint',
					(array)
						$query->get(
							'post__in'
						)
				)
			);


		if ( ! empty( $existing ) ) {

			$product_ids =
				array_values(
					array_intersect(
						$existing,
						$product_ids
					)
				);


			if ( empty( $product_ids ) ) {

				$product_ids = [
					0,
				];
			}
		}


		$query->set(
			'post__in',
			$product_ids
		);
	}


	/*
	|--------------------------------------------------------------------------
	| SQL Conditions
	|--------------------------------------------------------------------------
	*/

	/**
	 * Append SQL required by diagnostic views.
	 */
	public function filter_where(
		string $where,
		WP_Query $query
	): string {

		if (
			! is_admin()
			|| ! $query->is_main_query()
		) {
			return $where;
		}


		global $wpdb;


		/*
		|--------------------------------------------------------------------------
		| Variable Without Variations
		|--------------------------------------------------------------------------
		*/

		if (
			$query->get(
				self::QUERY_NO_VARIATIONS
			)
		) {

			$where .= "
				AND NOT EXISTS (
					SELECT 1

					FROM {$wpdb->posts} pdxw_variation

					WHERE pdxw_variation.post_parent = {$wpdb->posts}.ID
						AND pdxw_variation.post_type = 'product_variation'
						AND pdxw_variation.post_status NOT IN ('trash', 'auto-draft')
				)
			";
		}


		/*
		|--------------------------------------------------------------------------
		| No Tier Prices
		|--------------------------------------------------------------------------
		*/

		if (
			$query->get(
				self::QUERY_NO_TIERS
			)
		) {

			$tier_table =
				Database::table(
					'tier_prices'
				);


			$where .= "
				AND NOT EXISTS (
					SELECT 1

					FROM {$tier_table} pdxw_tp

					WHERE pdxw_tp.product_id = {$wpdb->posts}.ID
				)
			";
		}


		return $where;
	}


	/*
	|--------------------------------------------------------------------------
	| Query Detection
	|--------------------------------------------------------------------------
	*/

	/**
	 * Determine whether this is the main WooCommerce Products screen query.
	 */
	private function is_product_list_query(
		WP_Query $query
	): bool {

		if (
			! is_admin()
			|| ! $query->is_main_query()
		) {
			return false;
		}


		global $pagenow;


		if (
			'edit.php'
			!== $pagenow
		) {
			return false;
		}


		return 'product'
			=== (
				$query->get(
					'post_type'
				)
				?? ''
			);
	}


	/*
	|--------------------------------------------------------------------------
	| Request Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Determine whether an admin filter flag is enabled.
	 */
	private function request_flag(
		string $key
	): bool {

		if (
			! isset(
				$_GET[
					$key
				]
			)
			|| is_array(
				$_GET[
					$key
				]
			)
		) {
			return false;
		}


		return '1'
			=== sanitize_text_field(
				wp_unslash(
					(string)
					$_GET[
						$key
					]
				)
			);
	}


	/**
	 * Return one positive integer query argument.
	 */
	private function request_int(
		string $key
	): int {

		if (
			! isset(
				$_GET[
					$key
				]
			)
			|| is_array(
				$_GET[
					$key
				]
			)
		) {
			return 0;
		}


		return absint(
			wp_unslash(
				$_GET[
					$key
				]
			)
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Accessors
	|--------------------------------------------------------------------------
	*/

	public function catalog(): Catalog {
		return $this->catalog;
	}


	public function printing(): Printing {
		return $this->printing;
	}


	public function promi(): Promi {
		return $this->promi;
	}


	/*
	|--------------------------------------------------------------------------
	| State
	|--------------------------------------------------------------------------
	*/

	public function is_initialized(): bool {
		return $this->initialized;
	}
}
