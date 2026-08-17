<?php

namespace PromiDataXWoo\Frontend;

use PromiDataXWoo\Pricing\Pricing;
use PromiDataXWoo\Printing\Printing;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;

defined( 'ABSPATH' ) || exit;

/**
 * Product frontend shortcodes.
 *
 * Replaces the shortcode/template responsibilities previously contained
 * inside CX_Product.
 *
 * Existing shortcode names are intentionally preserved because they may
 * already be embedded in:
 *
 * - WordPress content.
 * - Elementor layouts.
 * - Divi layouts.
 * - Theme templates.
 *
 * Shortcodes:
 *
 * [cx_product_varattr_dropdown]
 * [cx_product_price_table]
 * [cx_product_printers]
 * [cx_addtocart_form]
 * [cx_product_configurator]
 *
 * Business data comes from ProductData, Pricing and Printing.
 * HTML remains inside templates wherever practical.
 */
final class Shortcodes {

	private ProductData $product_data;

	private Pricing $pricing;

	private Printing $printing;

	private bool $initialized = false;


	public function __construct(
		ProductData $product_data,
		Pricing $pricing,
		Printing $printing
	) {
		$this->product_data = $product_data;
		$this->pricing      = $pricing;
		$this->printing     = $printing;
	}


	/**
	 * Register frontend shortcodes.
	 */
	public function init(): void {

		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;


		add_shortcode(
			'cx_product_varattr_dropdown',
			[
				$this,
				'render_varattr_dropdowns',
			]
		);


		add_shortcode(
			'cx_product_price_table',
			[
				$this,
				'render_price_table',
			]
		);


		add_shortcode(
			'cx_product_printers',
			[
				$this,
				'render_printer_grid',
			]
		);


		add_shortcode(
			'cx_addtocart_form',
			[
				$this,
				'render_add_to_cart',
			]
		);


		add_shortcode(
			'cx_product_configurator',
			[
				$this,
				'render_configurator',
			]
		);


		do_action(
			'pdxw_frontend_shortcodes_init',
			$this
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Shortcodes
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render variation attribute dropdowns.
	 */
	public function render_varattr_dropdowns(
		array|string $atts = []
	): string {

		$context =
			$this->resolve_product(
				$atts
			);

		if ( ! $context ) {
			return '';
		}

		$product =
			$context[
				'product'
			];

		if (
			! $product instanceof WC_Product_Variable
		) {
			return '';
		}

		return $this->variation_attribute_dropdowns(
			$product->get_id()
		);
	}


	/**
	 * Render tier-price table.
	 */
	public function render_price_table(
		array|string $atts = []
	): string {

		$context =
			$this->resolve_product(
				$atts
			);

		if ( ! $context ) {
			return '';
		}

		if (
			$this->is_price_on_request_product(
				$context['product'],
				$context['product_id']
			)
		) {
			return '';
		}

		return $this->render_template(
			'price-table.php',
			$this->template_context(
				$context
			)
		);
	}


	/**
	 * Render available print-position grid.
	 */
	public function render_printer_grid(
		array|string $atts = []
	): string {

		$context =
			$this->resolve_product(
				$atts
			);

		if ( ! $context ) {
			return '';
		}

		$product_id =
			$context[
				'product_id'
			];

		/*
		 * The previous template called:
		 *
		 *     CX_Print::get_unique_positions( $product_id )
		 *
		 * The rebuilt Printing domain exposes the same concept through
		 * Positions::get_unique().
		 */
		$context[
			'positions'
		] =
			$this->printing
				->positions()
				->get_unique(
					$product_id
				);

		return $this->render_template(
			'printers-grid.php',
			$this->template_context(
				$context
			)
		);
	}


	/**
	 * Render the main product add-to-cart configurator.
	 */
	public function render_add_to_cart(
		array|string $atts = []
	): string {

		$context =
			$this->resolve_product(
				$atts
			);

		if ( ! $context ) {
			return '';
		}

		$product =
			$context[
				'product'
			];

		$product_id =
			$context[
				'product_id'
			];


		/*
		|--------------------------------------------------------------------------
		| Quantity Rules
		|--------------------------------------------------------------------------
		*/

		$context[
			'min_order_qty'
		] =
			$this->product_data
				->minimum_order_quantity(
					$product
				);

		$context[
			'qty_increments'
		] =
			$this->product_data
				->quantity_increment(
					$product
				);


		/*
		|--------------------------------------------------------------------------
		| Printing Availability
		|--------------------------------------------------------------------------
		|
		| The old add-to-cart template checked whether any positions existed
		| before rendering its "Werbeanbringung" controls.
		*/

		$context[
			'positions'
		] =
			$this->printing
				->positions()
				->get_by_product(
					$product_id
				);


		/*
		|--------------------------------------------------------------------------
		| Price on Request
		|--------------------------------------------------------------------------
		*/

		$context['is_price_on_request'] =
			$this->is_price_on_request_product(
				$product,
				$product_id
			);


		return $this->render_template(
			'add-to-cart.php',
			$this->template_context(
				$context
			)
		);
	}


	/**
	 * Render the multi-step product configurator.
	 */
	public function render_configurator(
		array|string $atts = []
	): string {

		$context =
			$this->resolve_product(
				$atts
			);

		if ( ! $context ) {
			return '';
		}

		$product_id =
			$context[
				'product_id'
			];

		if (
			$this->is_price_on_request_product(
				$context['product'],
				$product_id
			)
		) {
			return '';
		}


		/*
		 * Existing configurator determines whether step 03
		 * "Werbeanbringung" exists by checking all product positions.
		 */
		$context[
			'positions'
		] =
			$this->printing
				->positions()
				->get_by_product(
					$product_id
				);


		return $this->render_template(
			'configurator.php',
			$this->template_context(
				$context
			)
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Product Resolution
	|--------------------------------------------------------------------------
	*/

	/**
	 * Resolve the shortcode's WooCommerce product.
	 *
	 * Existing shortcode contract:
	 *
	 *     [cx_addtocart_form]
	 *
	 * or:
	 *
	 *     [cx_addtocart_form product_id="123"]
	 */
	private function resolve_product(
		array|string $atts
	): ?array {

		$atts =
			shortcode_atts(
				[
					'product_id' =>
						'',
				],
				is_array( $atts )
					? $atts
					: [],
				''
			);

		$product_id =
			absint(
				$atts[
					'product_id'
				] ?? 0
			);

		$product = null;


		/*
		|--------------------------------------------------------------------------
		| Explicit Product
		|--------------------------------------------------------------------------
		*/

		if ( $product_id ) {

			$product =
				wc_get_product(
					$product_id
				);

		} else {

			/*
			|--------------------------------------------------------------------------
			| Current WooCommerce Product
			|--------------------------------------------------------------------------
			|
			| The old implementation relied directly on global $product.
			| Retain that behavior but add a queried-object fallback.
			*/

			global $product;

			if (
				$product instanceof WC_Product
			) {
				$product_id =
					$product->get_id();
			}


			if ( ! $product_id ) {

				$product_id =
					absint(
						get_queried_object_id()
					);

				if ( $product_id ) {

					$product =
						wc_get_product(
							$product_id
						);
				}
			}
		}


		if (
			! $product instanceof WC_Product
		) {
			return null;
		}

		return [
			'product_id' =>
				$product->get_id(),

			'product' =>
				$product,
		];
	}


	/**
	 * Determine whether a product is entirely price-on-request.
	 *
	 * For variable products: only true when EVERY variation is POR. If at
	 * least one variation has a price the shopper can still add to cart;
	 * per-variation state is handled by JS after a selection is made.
	 *
	 * For simple products: checks the single product directly.
	 */
	private function is_price_on_request_product(
		WC_Product $product,
		int $product_id
	): bool {

		if ( $product instanceof WC_Product_Variable ) {

			$children = $product->get_children();

			if ( empty( $children ) ) {
				return false;
			}

			foreach ( $children as $child_id ) {

				if (
					! $this->product_data->is_price_on_request(
						$product_id,
						absint( $child_id )
					)
				) {
					return false;
				}
			}

			return true;
		}

		return $this->product_data->is_price_on_request(
			$product_id,
			0
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Variation Attribute Dropdowns
	|--------------------------------------------------------------------------
	*/

	/**
	 * Generate select dropdowns for all variable-product attributes.
	 *
	 * This preserves the markup contract consumed by the existing
	 * events/attributes.js frontend code.
	 */
	public function variation_attribute_dropdowns(
		int $product_id
	): string {

		$product =
			wc_get_product(
				absint(
					$product_id
				)
			);

		if (
			! $product instanceof WC_Product_Variable
		) {
			return '';
		}

		$attributes =
			$this->product_data
				->variation_attributes(
					$product_id
				);

		if ( empty( $attributes ) ) {
			return '';
		}


		ob_start();

		?>
		<div class="cx-variation-attributes">
			<?php foreach ( $attributes as $taxonomy => $attribute ) : ?>

				<?php
				$attribute_label =
					(string) (
						$attribute[
							'label'
						] ?? ''
					);

				$select_name =
					'attribute_'
					. sanitize_title(
						$taxonomy
					);

				$select_id =
					'cx-'
					. sanitize_title(
						$taxonomy
					);

				$display_label =
					'Größe' === $attribute_label
						? 'auswählen Größe'
						: $attribute_label;
				?>

				<div class="cx-variation-attribute">

					<label for="<?php echo esc_attr( $select_id ); ?>">
						<?php echo esc_html( $display_label ); ?>:
					</label>

					<select
						name="attributes[<?php echo esc_attr( $select_name ); ?>]"
						id="<?php echo esc_attr( $select_id ); ?>"
						class="cx-attribute-select"
						data-name="<?php echo esc_attr( $attribute_label ); ?>"
					>
						<option value="">
							<?php
							echo esc_html(
								sprintf(
									__(
										'%s auswählen',
										'woocommerce'
									),
									ucwords(
										$attribute_label
									)
								)
							);
							?>
						</option>

						<?php
						foreach (
							$attribute[
								'options'
							] ?? [] as $option
						) :
							?>

							<option
								value="<?php echo esc_attr(
									$option[
										'value'
									] ?? ''
								); ?>"
							>
								<?php echo esc_html(
									$option[
										'label'
									] ?? ''
								); ?>
							</option>

						<?php endforeach; ?>

					</select>

				</div>

			<?php endforeach; ?>
		</div>
		<?php

		return (string)
			ob_get_clean();
	}


	/*
	|--------------------------------------------------------------------------
	| Variation Attribute Radios
	|--------------------------------------------------------------------------
	*/

	/**
	 * Generate the image/radio attribute selector used by the existing
	 * add-to-cart and configurator templates.
	 */
	public function variation_attribute_radios(
		int $product_id,
		string $prefix = 'cxatc'
	): string {

		$product =
			wc_get_product(
				absint(
					$product_id
				)
			);

		if (
			! $product instanceof WC_Product_Variable
		) {
			return '';
		}

		$attributes =
			$this->product_data
				->variation_attributes(
					$product_id
				);

		if ( empty( $attributes ) ) {
			return '';
		}

		$matrix =
			$this->product_data
				->variation_matrix(
					$product
				);

		$prefix =
			sanitize_html_class(
				$prefix
			);


		ob_start();

		?>
		<div
			class="cx-variation-attributes cx-radio-attributes"
			data-variation-matrix="<?php echo esc_attr( wp_json_encode( $matrix ) ); ?>"
		>

			<?php foreach ( $attributes as $taxonomy => $attribute ) : ?>

				<?php
				$label =
					(string) (
						$attribute[
							'label'
						] ?? ''
					);

				$input_name =
					'attributes[attribute_'
					. sanitize_title(
						$taxonomy
					)
					. ']';
				?>

				<div class="cx-variation-attribute">

					<div class="cx-radio-label">
						<?php echo esc_html( $label ); ?>
					</div>

					<div class="cxatc-row cxatc-error cxatc-variation-error">
						<?php
						echo esc_html(
							sprintf(
								'Bitte wählen Sie zuerst eine %s aus:',
								$label
							)
						);
						?>
					</div>

					<div class="cx-select-img">

						<?php
						foreach (
							$attribute[
								'options'
							] ?? [] as $option
						) :

							$value =
								(string) (
									$option[
										'value'
									] ?? ''
								);

							$option_label =
								(string) (
									$option[
										'label'
									] ?? $value
								);

							$term_id =
								absint(
									$option[
										'term_id'
									] ?? 0
								);

							$hex =
								(string) (
									$option[
										'hex'
									] ?? '#000000'
								);

							$image =
								(string) (
									$option[
										'image'
									] ?? ''
								);

							$input_id =
								$prefix
								. '-attr-'
								. (
									$term_id
										?: sanitize_title(
											$value
										)
								);
							?>

							<div
								class="cx-option"
								style="--bg-hex-code:<?php echo esc_attr( $hex ); ?>;"
							>

								<input
									type="radio"
									name="<?php echo esc_attr( $input_name ); ?>"
									value="<?php echo esc_attr( $value ); ?>"
									id="<?php echo esc_attr( $input_id ); ?>"
									class="cx-attribute-input"
								>

								<label for="<?php echo esc_attr( $input_id ); ?>">

									<?php
									/*
									 * Preserve the old behavior:
									 * size choices do not show thumbnails.
									 */
									if (
										$image
										&& 'pa_groesse'
											!== $taxonomy
									) :
										?>

										<span class="cx-option-image">
											<img
												src="<?php echo esc_url( $image ); ?>"
												alt="<?php echo esc_attr( $option_label ); ?>"
												loading="lazy"
												decoding="async"
											>
										</span>

									<?php endif; ?>

									<span class="cx-option-label">
										<?php echo esc_html( $option_label ); ?>
									</span>

								</label>

							</div>

						<?php endforeach; ?>

					</div>

				</div>

			<?php endforeach; ?>

		</div>
		<?php

		return (string)
			ob_get_clean();
	}


	/*
	|--------------------------------------------------------------------------
	| Variation Dropdown
	|--------------------------------------------------------------------------
	*/

	/**
	 * Generate the variation dropdown currently used inside price-table.php.
	 */
	public function variation_dropdown(
		int $product_id,
		string $input_name = 'child_variations',
		string $input_id = '',
		int|string $selected_value = ''
	): string {

		$product =
			wc_get_product(
				absint(
					$product_id
				)
			);

		if (
			! $product instanceof WC_Product_Variable
		) {
			return '';
		}

		if ( '' === $input_id ) {
			$input_id =
				$input_name;
		}

		$variation_ids =
			$this->product_data
				->variation_ids(
					$product
				);


		ob_start();

		?>
		<select
			name="<?php echo esc_attr( $input_name ); ?>"
			id="<?php echo esc_attr( $input_id ); ?>"
		>

			<option value="">
				Option auswählen
			</option>

			<?php foreach ( $variation_ids as $variation_id ) : ?>

				<?php
				$variation =
					wc_get_product(
						$variation_id
					);

				if (
					! $variation instanceof WC_Product_Variation
				) {
					continue;
				}

				$data_attributes = [];

				foreach (
					$variation->get_attributes()
						as $attribute_name => $attribute_value
				) {

					$attribute_name =
						str_replace(
							'pa_',
							'',
							$attribute_name
						);

					$data_attributes[
						$attribute_name
					] =
						$attribute_value;
				}
				?>

				<option
					value="<?php echo esc_attr( $variation_id ); ?>"
					<?php
					foreach (
						$data_attributes as $name => $value
					) :
						?>
						data-<?php echo esc_attr( sanitize_key( $name ) ); ?>="<?php echo esc_attr( $value ); ?>"
					<?php endforeach; ?>
					<?php
					echo selected(
						(string) $selected_value,
						(string) $variation_id,
						false
					);
					?>
				>
					<?php echo esc_html(
						$variation->get_name()
					); ?>
				</option>

			<?php endforeach; ?>

		</select>
		<?php

		return (string)
			ob_get_clean();
	}


	/*
	|--------------------------------------------------------------------------
	| Backward Template Helper Names
	|--------------------------------------------------------------------------
	|
	| The existing template files call methods with these names through
	| $this. Keeping these small aliases lets us migrate/review the templates
	| one at a time instead of requiring an all-at-once rewrite.
	|--------------------------------------------------------------------------
	*/

	public function get_variation_attribute_dropdowns(
		int $product_id
	): string {

		return $this->variation_attribute_dropdowns(
			$product_id
		);
	}


	public function get_variation_attribute_radios(
		int $product_id,
		string $prefix = 'cxatc'
	): string {

		return $this->variation_attribute_radios(
			$product_id,
			$prefix
		);
	}


	public function get_variation_dropdown(
		int $product_id,
		string $input_name = 'child_variations',
		string $input_id = '',
		int|string $selected_value = ''
	): string {

		return $this->variation_dropdown(
			$product_id,
			$input_name,
			$input_id,
			$selected_value
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Template Rendering
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render one frontend template.
	 *
	 * Template files live at:
	 *
	 *     templates/frontend/
	 *
	 * rather than inside the Frontend class.
	 */
	private function render_template(
		string $template,
		array $context = []
	): string {

		$template =
			basename(
				$template
			);

		$path =
			PDXW_TEMPLATES_PATH
			. 'frontend/'
			. $template;

		if (
			! is_readable(
				$path
			)
		) {
			return '';
		}


		/*
		 * Variables are intentionally extracted for clean PHP templates:
		 *
		 * $product
		 * $product_id
		 * $positions
		 * $min_order_qty
		 * $qty_increments
		 *
		 * etc.
		 */
		if ( ! empty( $context ) ) {

			extract(
				$context,
				EXTR_SKIP
			);
		}


		ob_start();

		include $path;

		return (string)
			ob_get_clean();
	}


	/**
	 * Add shared services to every template context.
	 *
	 * New templates should prefer these variables instead of reaching back
	 * into the Shortcodes class for business-domain services.
	 */
	private function template_context(
		array $context
	): array {

		$context[
			'product_data'
		] =
			$this->product_data;

		$context[
			'pricing'
		] =
			$this->pricing;

		$context[
			'printing'
		] =
			$this->printing;

		$context[
			'shortcodes'
		] =
			$this;

		return $context;
	}


	/*
	|--------------------------------------------------------------------------
	| Services
	|--------------------------------------------------------------------------
	*/

	public function product_data(): ProductData {
		return $this->product_data;
	}


	public function pricing(): Pricing {
		return $this->pricing;
	}


	public function printing(): Printing {
		return $this->printing;
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
