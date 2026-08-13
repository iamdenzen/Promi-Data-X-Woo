<?php

namespace PromiDataXWoo\Admin;

use PromiDataXWoo\Pricing\MarkupRepository;
use PromiDataXWoo\Pricing\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Pricing markups administration page.
 *
 * Renders a React-driven admin UI backed by the REST endpoints registered
 * in Pricing\RestController:
 *
 *     GET/PUT  pdxw/v1/pricing/defaults
 *     GET      pdxw/v1/pricing/categories
 *     PUT      pdxw/v1/pricing/categories/{id}
 *     GET      pdxw/v1/pricing/print-options
 *     PUT      pdxw/v1/pricing/print-options/{id}
 *
 * Business logic stays in Pricing\MarkupRules and Pricing\MarkupRepository.
 */
final class MarkupPage {

	private Pricing $pricing;

	private bool $initialized = false;


	public function __construct(
		Pricing $pricing
	) {
		$this->pricing = $pricing;
	}


	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		add_action(
			'admin_enqueue_scripts',
			[
				$this,
				'enqueue_assets',
			]
		);
	}


	/**
	 * Enqueue page-specific assets when viewing the Markups page.
	 */
	public function enqueue_assets(
		string $hook
	): void {

		if (
			false === strpos(
				$hook,
				Menu::MARKUP_SLUG
			)
		) {
			return;
		}

		wp_enqueue_script(
			'wp-api-fetch'
		);
	}


	/**
	 * Render the Pricing Markups admin page.
	 */
	public function render(): void {

		if (
			! current_user_can(
				Menu::CAPABILITY
			)
		) {
			wp_die(
				esc_html__(
					'You do not have permission to access this page.',
					'promi-data-x-woo'
				)
			);
		}

		$article_markup = $this->pricing
			->markup_rules()
			->article_default();

		$finishing_markup = $this->pricing
			->markup_rules()
			->finishing_default();

		$category_rules = $this->pricing
			->markup_repository()
			->all( MarkupRepository::TYPE_CATEGORY );

		$print_option_rules = $this->pricing
			->markup_repository()
			->all( MarkupRepository::TYPE_PRINT_OPTION );

		$api_root = esc_url_raw( rest_url( 'pdxw/v1' ) );
		$nonce    = wp_create_nonce( 'wp_rest' );

		?>
		<div class="wrap">

			<h1><?php echo esc_html__( 'Pricing Markups', 'promi-data-x-woo' ); ?></h1>

			<p class="description">
				<?php echo esc_html__( 'Configure markup percentages applied to Promi purchase prices when calculating selling prices.', 'promi-data-x-woo' ); ?>
			</p>

			<hr class="wp-header-end">

			<div id="pdxw-markup-notices"></div>


			<?php
			/*
			|--------------------------------------------------------------------------
			| Default Markups
			|--------------------------------------------------------------------------
			*/
			?>

			<h2><?php echo esc_html__( 'Default Markups', 'promi-data-x-woo' ); ?></h2>

			<p class="description">
				<?php echo esc_html__( 'Applied when no category or print-option specific rule matches.', 'promi-data-x-woo' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tbody>

					<tr>
						<th scope="row">
							<label for="pdxw-article-markup">
								<?php echo esc_html__( 'Article Markup (%)', 'promi-data-x-woo' ); ?>
							</label>
						</th>
						<td>
							<input
								type="number"
								id="pdxw-article-markup"
								class="small-text"
								value="<?php echo esc_attr( $article_markup ); ?>"
								min="0"
								step="0.01"
							>
							<p class="description">
								<?php echo esc_html__( 'Default markup applied to article purchase prices.', 'promi-data-x-woo' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="pdxw-finishing-markup">
								<?php echo esc_html__( 'Finishing Markup (%)', 'promi-data-x-woo' ); ?>
							</label>
						</th>
						<td>
							<input
								type="number"
								id="pdxw-finishing-markup"
								class="small-text"
								value="<?php echo esc_attr( $finishing_markup ); ?>"
								min="0"
								step="0.01"
							>
							<p class="description">
								<?php echo esc_html__( 'Default markup applied to print/finishing option purchase prices.', 'promi-data-x-woo' ); ?>
							</p>
						</td>
					</tr>

				</tbody>
			</table>

			<p>
				<button
					type="button"
					id="pdxw-save-defaults"
					class="button button-primary"
				>
					<?php echo esc_html__( 'Save Default Markups', 'promi-data-x-woo' ); ?>
				</button>
			</p>


			<?php
			/*
			|--------------------------------------------------------------------------
			| Category Markup Overrides
			|--------------------------------------------------------------------------
			*/
			?>

			<hr>

			<h2><?php echo esc_html__( 'Category Markup Overrides', 'promi-data-x-woo' ); ?></h2>

			<p class="description">
				<?php echo esc_html__( 'Set a specific markup for a product category. Overrides the default article markup for products in that category.', 'promi-data-x-woo' ); ?>
			</p>

			<?php if ( ! empty( $category_rules ) ) : ?>

				<table class="wp-list-table widefat fixed striped" id="pdxw-category-rules">

					<thead>
						<tr>
							<th><?php echo esc_html__( 'Category', 'promi-data-x-woo' ); ?></th>
							<th><?php echo esc_html__( 'Markup (%)', 'promi-data-x-woo' ); ?></th>
							<th><?php echo esc_html__( 'Actions', 'promi-data-x-woo' ); ?></th>
						</tr>
					</thead>

					<tbody>
						<?php foreach ( $category_rules as $rule ) :
							$term = get_term( absint( $rule['target_id'] ?? 0 ), 'product_cat' );
							$term_name = $term instanceof \WP_Term
								? $term->name
								: sprintf( __( 'Category #%d', 'promi-data-x-woo' ), $rule['target_id'] );
							?>

							<tr data-rule-type="category" data-rule-id="<?php echo esc_attr( $rule['target_id'] ?? 0 ); ?>">
								<td><?php echo esc_html( $term_name ); ?></td>
								<td>
									<input
										type="number"
										class="small-text pdxw-rule-markup"
										value="<?php echo esc_attr( $rule['markup_percent'] ?? 0 ); ?>"
										min="0"
										step="0.01"
									>
								</td>
								<td>
									<button
										type="button"
										class="button button-small pdxw-save-rule"
									>
										<?php echo esc_html__( 'Save', 'promi-data-x-woo' ); ?>
									</button>
								</td>
							</tr>

						<?php endforeach; ?>
					</tbody>

				</table>

			<?php else : ?>

				<p><?php echo esc_html__( 'No category markup rules configured.', 'promi-data-x-woo' ); ?></p>

			<?php endif; ?>


			<?php
			/*
			|--------------------------------------------------------------------------
			| Print Option Markup Overrides
			|--------------------------------------------------------------------------
			*/
			?>

			<hr>

			<h2><?php echo esc_html__( 'Print Option Markup Overrides', 'promi-data-x-woo' ); ?></h2>

			<p class="description">
				<?php echo esc_html__( 'Set a specific markup for a print option. Overrides the default finishing markup for that option.', 'promi-data-x-woo' ); ?>
			</p>

			<?php if ( ! empty( $print_option_rules ) ) : ?>

				<table class="wp-list-table widefat fixed striped" id="pdxw-print-option-rules">

					<thead>
						<tr>
							<th><?php echo esc_html__( 'Print Option', 'promi-data-x-woo' ); ?></th>
							<th><?php echo esc_html__( 'Markup (%)', 'promi-data-x-woo' ); ?></th>
							<th><?php echo esc_html__( 'Actions', 'promi-data-x-woo' ); ?></th>
						</tr>
					</thead>

					<tbody>
						<?php foreach ( $print_option_rules as $rule ) : ?>

							<tr data-rule-type="<?php echo esc_attr( MarkupRepository::TYPE_PRINT_OPTION ); ?>" data-rule-id="<?php echo esc_attr( $rule['target_id'] ?? 0 ); ?>">
								<td><?php echo esc_html( sprintf( __( 'Print Option #%d', 'promi-data-x-woo' ), $rule['target_id'] ) ); ?></td>
								<td>
									<input
										type="number"
										class="small-text pdxw-rule-markup"
										value="<?php echo esc_attr( $rule['markup_percent'] ?? 0 ); ?>"
										min="0"
										step="0.01"
									>
								</td>
								<td>
									<button
										type="button"
										class="button button-small pdxw-save-rule"
									>
										<?php echo esc_html__( 'Save', 'promi-data-x-woo' ); ?>
									</button>
								</td>
							</tr>

						<?php endforeach; ?>
					</tbody>

				</table>

			<?php else : ?>

				<p><?php echo esc_html__( 'No print option markup rules configured.', 'promi-data-x-woo' ); ?></p>

			<?php endif; ?>

		</div>


		<script>
		(function () {

			const apiRoot  = <?php echo wp_json_encode( $api_root ); ?>;
			const nonce    = <?php echo wp_json_encode( $nonce ); ?>;
			const notices  = document.getElementById( 'pdxw-markup-notices' );


			function showNotice( message, type ) {
				notices.innerHTML = '<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>';
				setTimeout( function () { notices.innerHTML = ''; }, 4000 );
			}


			function apiFetch( path, method, body ) {
				return fetch( apiRoot + path, {
					method:  method || 'GET',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce':   nonce,
					},
					body: body ? JSON.stringify( body ) : undefined,
				} ).then( function ( res ) {
					return res.json();
				} );
			}


			document.getElementById( 'pdxw-save-defaults' ).addEventListener( 'click', function () {
				const article  = parseFloat( document.getElementById( 'pdxw-article-markup' ).value );
				const finishing = parseFloat( document.getElementById( 'pdxw-finishing-markup' ).value );

				if ( isNaN( article ) || isNaN( finishing ) ) {
					showNotice( <?php echo wp_json_encode( __( 'Please enter valid numbers.', 'promi-data-x-woo' ) ); ?>, 'error' );
					return;
				}

				apiFetch( '/pricing/defaults', 'POST', {
					article_markup:   article,
					finishing_markup: finishing,
				} ).then( function () {
					showNotice( <?php echo wp_json_encode( __( 'Default markups saved.', 'promi-data-x-woo' ) ); ?>, 'success' );
				} ).catch( function () {
					showNotice( <?php echo wp_json_encode( __( 'Error saving defaults.', 'promi-data-x-woo' ) ); ?>, 'error' );
				} );
			} );


			document.querySelectorAll( '.pdxw-save-rule' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					const row    = btn.closest( 'tr' );
					const type   = row.dataset.ruleType;
					const id     = row.dataset.ruleId;
					const markup = parseFloat( row.querySelector( '.pdxw-rule-markup' ).value );

					if ( isNaN( markup ) ) {
						showNotice( <?php echo wp_json_encode( __( 'Please enter a valid markup.', 'promi-data-x-woo' ) ); ?>, 'error' );
						return;
					}

					const catType = <?php echo wp_json_encode( MarkupRepository::TYPE_CATEGORY ); ?>;
					const path = type === catType
						? '/pricing/categories/' + id
						: '/pricing/print-options/' + id;

					apiFetch( path, 'POST', { markup_percent: markup } ).then( function () {
						showNotice( <?php echo wp_json_encode( __( 'Markup saved.', 'promi-data-x-woo' ) ); ?>, 'success' );
					} ).catch( function () {
						showNotice( <?php echo wp_json_encode( __( 'Error saving markup.', 'promi-data-x-woo' ) ); ?>, 'error' );
					} );
				} );
			} );

		})();
		</script>
		<?php
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
