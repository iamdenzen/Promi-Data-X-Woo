<?php

namespace PromiDataXWoo\Admin;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Tier Pricing meta box for the WooCommerce product editor.
 *
 * Links to the Tier Pricing admin page for the product's SKU and lists every
 * variation (ID, SKU, Woo price) with its own Tier Pricing link.
 *
 * This meta box is read-only.
 */
final class ProductTierPricingBox {

	public const ID =
		'pdxw_tier_pricing_links';

	private bool $initialized = false;


	/**
	 * Register product-editor hooks.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		add_action(
			'add_meta_boxes_product',
			[
				$this,
				'register',
			]
		);
	}


	/**
	 * Register the Tier Pricing meta box.
	 */
	public function register(): void {

		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			return;
		}

		add_meta_box(
			self::ID,
			__(
				'Tier Pricing',
				'promi-data-x-woo'
			),
			[
				$this,
				'render',
			],
			'product',
			'normal',
			'default'
		);
	}


	/**
	 * Tier Pricing page URL for one SKU.
	 */
	private function url(
		string $sku
	): string {

		return add_query_arg(
			[
				'page' => Menu::PRICING_SLUG,
				'sku'  => $sku,
			],
			admin_url( 'admin.php' )
		);
	}


	/**
	 * Render the meta box.
	 */
	public function render(
		WP_Post $post
	): void {

		$product =
			wc_get_product(
				$post->ID
			);

		if ( ! $product instanceof \WC_Product ) {

			echo '<p>' . esc_html__( 'No WooCommerce product was found.', 'promi-data-x-woo' ) . '</p>';

			return;
		}

		$sku =
			trim(
				(string)
				$product->get_sku()
			);

		if ( '' === $sku ) {

			echo '<p>' . esc_html__( 'This product does not have an SKU.', 'promi-data-x-woo' ) . '</p>';

			return;
		}

		printf(
			'<p><a class="button" href="%s" target="_blank" rel="noopener">%s</a></p>',
			esc_url( $this->url( $sku ) ),
			esc_html__( 'View tier pricing', 'promi-data-x-woo' )
		);

		if ( ! $product->is_type( 'variable' ) ) {
			return;
		}

		$variation_ids =
			$product->get_children();

		if ( empty( $variation_ids ) ) {
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'ID', 'promi-data-x-woo' ); ?></th>
					<th><?php esc_html_e( 'SKU', 'promi-data-x-woo' ); ?></th>
					<th><?php esc_html_e( 'Woo Price', 'promi-data-x-woo' ); ?></th>
					<th><?php esc_html_e( 'Tier Pricing', 'promi-data-x-woo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $variation_ids as $variation_id ) :

					$variation =
						wc_get_product(
							$variation_id
						);

					if ( ! $variation ) {
						continue;
					}

					$variation_sku =
						trim(
							(string)
							$variation->get_sku()
						);
					?>
					<tr>
						<td><?php echo esc_html( $variation_id ); ?></td>
						<td><?php echo '' !== $variation_sku ? esc_html( $variation_sku ) : '&mdash;'; ?></td>
						<td><?php echo '' !== $variation->get_price() ? wp_kses_post( wc_price( $variation->get_price() ) ) : '&mdash;'; ?></td>
						<td>
							<?php if ( '' !== $variation_sku ) : ?>
								<a href="<?php echo esc_url( $this->url( $variation_sku ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $variation_sku ); ?></a>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
