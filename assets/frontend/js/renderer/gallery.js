window.CX = window.CX || {};


/**
 * Display the gallery belonging to a selected variation.
 *
 * Promi variation galleries are rendered elsewhere on the product page
 * using IDs in the form:
 *
 *     #gallery-variation-123
 */
CX.renderGallery = function (
	variationId
) {

	variationId =
		CX.utils.int(
			variationId
		);

	if (!variationId) {
		return;
	}


	const $gallery =
		$(
			`#gallery-variation-${variationId}`
		);


	if (!$gallery.length) {
		return;
	}


	$(".et_pb_wc_images .woocommerce-product-gallery")
		.hide();


	$(".et_pb_wc_images .variation-gallery")
		.hide();


	$gallery.show();
};


/*
|--------------------------------------------------------------------------
| Initial Gallery Cleanup
|--------------------------------------------------------------------------
|
| Preserve the current storefront behavior where generated variation
| galleries are hidden after the original WooCommerce gallery has finished
| initializing.
*/

window.setTimeout(
	() => {

		jQuery(
			".et_pb_wc_images .variation-gallery"
		)
			.hide();
	},
	2000
);
