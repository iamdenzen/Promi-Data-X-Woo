<?php

defined( 'ABSPATH' ) || exit;

/**
 * Product print-position grid.
 *
 * Available context:
 *
 * @var \WC_Product                         $product
 * @var int                                 $product_id
 * @var array                               $positions
 * @var \PromiDataXWoo\Frontend\ProductData $product_data
 * @var \PromiDataXWoo\Pricing\Pricing      $pricing
 * @var \PromiDataXWoo\Printing\Printing    $printing
 * @var \PromiDataXWoo\Frontend\Shortcodes  $shortcodes
 *
 * Shortcodes.php already resolves:
 *
 *     $printing
 *         ->positions()
 *         ->get_unique( $product_id )
 *
 * so this template contains presentation only.
 */

if (
	! isset( $product )
	|| ! $product instanceof \WC_Product
) {
	return;
}

$product_id =
	absint(
		$product_id
		?: $product->get_id()
	);

$positions =
	isset( $positions )
	&& is_array( $positions )
		? $positions
		: [];


/*
|--------------------------------------------------------------------------
| No Printing Available
|--------------------------------------------------------------------------
|
| Preserve the existing XSImpress storefront behavior.
|
| When the product has no print positions, the old template hides:
|
|     #werbeanbringung
|     #produktkonfigurator
|
| as well as links pointing to those page anchors.
|
| These IDs belong to the surrounding product-page layout, not this
| shortcode itself, so retaining this behavior prevents empty sections
| appearing for non-printable products.
*/

if ( empty( $positions ) ) :
	?>

	<style>
		#werbeanbringung,
		a[href="#werbeanbringung"],
		#produktkonfigurator,
		a[href="#produktkonfigurator"] {
			display: none !important;
			visibility: hidden;
			opacity: 0;
			position: absolute;
			height: 1px;
			width: 1px;
			overflow: hidden;
		}
	</style>

	<?php
endif;
?>


<div class="cx-printers-grid">

	<?php foreach ( $positions as $position ) : ?>

		<?php
		if (
			! is_object( $position )
			|| empty( $position->id )
		) {
			continue;
		}


		/*
		|--------------------------------------------------------------------------
		| Position Image
		|--------------------------------------------------------------------------
		*/

		$image_id =
			absint(
				$position->image
				?? 0
			);

		$image = '';

		if ( $image_id ) {

			$image =
				wp_get_attachment_image(
					$image_id,
					'medium',
					false,
					[
						'class' =>
							'cx-printers-grid-image',

						'loading' =>
							'lazy',

						'decoding' =>
							'async',
					]
				);
		}


		/*
		|--------------------------------------------------------------------------
		| Position Label
		|--------------------------------------------------------------------------
		*/

		$label =
			trim(
				(string) (
					$position->position_label
					?? ''
				)
			);

		if (
			'' === $label
			&& isset(
				$position->position_code
			)
		) {
			$label =
				(string)
					$position->position_code;
		}
		?>


		<div
			class="cx-printers-grid-item"
			data-position-id="<?php echo esc_attr(
				(int) $position->id
			); ?>"
		>

			<?php
			if ( '' !== $image ) {

				echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>


			<?php if ( '' !== $label ) : ?>

				<div class="cx-printers-grid-label">
					<?php echo esc_html( $label ); ?>
				</div>

			<?php endif; ?>

		</div>

	<?php endforeach; ?>

</div>


<style>
	.cx-printers-grid {
		display: grid;
		gap: 20px;
		grid-template-columns:
			repeat(
				5,
				minmax(0, 1fr)
			);
	}


	.cx-printers-grid
	.cx-printers-grid-item {
		border: 1px solid #ccc;
		border-radius: 4px;

		text-align: center;

		padding: 10px;

		font-size: 12px;
		line-height: 1.2em;

		min-width: 0;
	}


	.cx-printers-grid
	.cx-printers-grid-item
	img {
		height: 90px;
		width: 100%;

		object-fit: contain;

		margin-bottom: 10px;
	}


	.cx-printers-grid-label {
		overflow-wrap: anywhere;
	}


	@media (max-width: 1024px) {

		.cx-printers-grid {
			grid-template-columns:
				repeat(
					3,
					minmax(0, 1fr)
				);
		}
	}


	@media (max-width: 768px) {

		.cx-printers-grid {
			grid-template-columns:
				repeat(
					2,
					minmax(0, 1fr)
				);
		}
	}


	@media (max-width: 480px) {

		.cx-printers-grid {
			grid-template-columns: 1fr;
		}
	}
</style>
