<?php

namespace PromiDataXWoo\Promi;

use PromiDataXWoo\Core\Database;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Promi image synchronization.
 *
 * Product data is imported first and images are processed asynchronously.
 *
 * This is intentional because image downloading, normalization, sideloading,
 * thumbnail generation and WebP conversion are considerably more expensive
 * than normal product synchronization.
 */
final class ImageSync {

	private const BATCH_SIZE = 5;

	private const LOCK_KEY = 'pdxw_promi_image_sync_lock';

	private const LOCK_TTL = 55;

	private const IMAGE_SYNC_META = '_cx_need_to_sync_images';

	private const DISABLED_META = 'cx_promi_disabled_product';

	private const FILENAME_META = '_promi_filename';

	/**
	 * Only these image formats are accepted for auxiliary Promi images.
	 */
	private const ALLOWED_EXTENSIONS = [
		'jpg',
		'jpeg',
		'png',
		'webp',
		'gif',
	];

	private Client $client;

	private Logger $logger;

	/**
	 * Controls intermediate-size generation while product images
	 * are being imported.
	 */
	private bool $importing_product_image = false;

	/**
	 * Controls intermediate-size generation for printing/category images.
	 */
	private bool $importing_auxiliary_image = false;

	/**
	 * Avoid duplicate downloads during one request.
	 *
	 * @var array<string,int>
	 */
	private array $url_cache = [];


	public function __construct(
		Client $client,
		Logger $logger
	) {
		$this->client = $client;
		$this->logger = $logger;
	}


	/**
	 * Register image-related WordPress hooks.
	 */
	public function init(): void {

		add_filter(
			'intermediate_image_sizes_advanced',
			[ $this, 'filter_image_sizes' ],
			10,
			3
		);

		add_filter(
			'wp_editor_set_quality',
			[ $this, 'image_quality' ]
		);

		add_filter(
			'jpeg_quality',
			[ $this, 'image_quality' ]
		);

		add_filter(
			'image_editor_output_format',
			[ $this, 'output_formats' ]
		);

		/**
		 * Printing uses this when Promi supplies an image for a
		 * print position.
		 */
		add_filter(
			'pdxw_print_position_image_id',
			[ $this, 'import_print_position_image' ],
			10,
			4
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Cron Worker
	|--------------------------------------------------------------------------
	*/

	/**
	 * Process a batch of products waiting for image synchronization.
	 */
	public function run(): void {

		if ( Config::is_paused() ) {
			return;
		}

		if ( get_transient( self::LOCK_KEY ) ) {

			$this->logger->info(
				'Promi image sync skipped because another worker holds the lock.'
			);

			return;
		}

		set_transient(
			self::LOCK_KEY,
			1,
			self::LOCK_TTL
		);

		try {

			$product_ids =
				$this->pending_product_ids(
					self::BATCH_SIZE
				);

			if ( empty( $product_ids ) ) {
				return;
			}

			$this->logger->info(
				'Promi image sync picked products.',
				[
					'count' => count( $product_ids ),
				]
			);

			$sku_map =
				$this->product_sku_map(
					$product_ids
				);

			if ( empty( $sku_map ) ) {
				return;
			}

			$index_rows =
				$this->index_rows(
					array_keys( $sku_map )
				);

			if ( empty( $index_rows ) ) {

				$this->logger->warning(
					'No Promi index records found for pending image products.',
					[
						'skus' =>
							array_keys(
								$sku_map
							),
					]
				);

				return;
			}

			foreach ( $index_rows as $row ) {

				$product_id =
					$sku_map[
						$row->sku
					] ?? 0;

				if (
					! $product_id
					|| empty( $row->json_url )
				) {
					continue;
				}

				$this->process_product(
					$product_id,
					(string) $row->sku,
					(string) $row->json_url
				);
			}

		} finally {

			delete_transient(
				self::LOCK_KEY
			);

			$this->logger->info(
				'Promi image sync lock released.'
			);
		}
	}


	/**
	 * Process images for one product.
	 */
	private function process_product(
		int $product_id,
		string $sku,
		string $json_url
	): void {

		$data = $this->client
			->get_product(
				$json_url
			);

		if ( is_wp_error( $data ) ) {

			$this->logger->error(
				'Could not retrieve Promi product data for image synchronization.',
				[
					'product_id' =>
						$product_id,

					'sku' =>
						$sku,

					'error' =>
						$data
							->get_error_message(),
				]
			);

			return;
		}

		try {

			$this->sync_product_images(
				$product_id,
				$data
			);

		} catch ( \Throwable $e ) {

			$this->logger->error(
				'Promi product image synchronization failed.',
				[
					'product_id' =>
						$product_id,

					'sku' =>
						$sku,

					'error' =>
						$e->getMessage(),
				]
			);

			return;
		}


		/*
		|--------------------------------------------------------------------------
		| Image Job Complete
		|--------------------------------------------------------------------------
		|
		| Preserve the existing workflow:
		|
		| Promi import
		|      ↓
		| product remains draft
		|      ↓
		| images imported asynchronously
		|      ↓
		| sync flag removed
		|      ↓
		| product published
		*/

		delete_post_meta(
			$product_id,
			self::IMAGE_SYNC_META
		);

		$result = wp_update_post(
			[
				'ID' =>
					$product_id,

				'post_status' =>
					'publish',
			],
			true
		);

		if ( is_wp_error( $result ) ) {

			$this->logger->error(
				'Promi images synchronized but product could not be published.',
				[
					'product_id' =>
						$product_id,

					'error' =>
						$result
							->get_error_message(),
				]
			);

			return;
		}

		$this->logger->info(
			'Promi product images synchronized and product published.',
			[
				'product_id' =>
					$product_id,

				'sku' =>
					$sku,
			]
		);

		do_action(
			'pdxw_promi_images_synced',
			$product_id,
			$data
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Product Image Synchronization
	|--------------------------------------------------------------------------
	*/

	/**
	 * Synchronize parent and variation images.
	 */
	public function sync_product_images(
		int $parent_id,
		array $data
	): void {

		$parent_id = absint(
			$parent_id
		);

		if ( ! $parent_id ) {
			return;
		}

		$map = $this->build_image_map(
			$data
		);

		if ( empty( $map['all'] ) ) {

			$this->logger->warning(
				'Promi product contains no usable images.',
				[
					'product_id' =>
						$parent_id,
				]
			);

			return;
		}

		$parent_title =
			get_the_title(
				$parent_id
			);

		$attachments = [];

		$this->importing_product_image = true;

		try {

			foreach (
				$map['all']
					as $filename => $image
			) {

				$attachment_id =
					$this->get_or_create_image(
						$filename,
						$image['url'],
						$image['desc'],
						$parent_id,
						$parent_title
					);

				if ( $attachment_id ) {

					$attachments[
						$filename
					] = $attachment_id;
				}
			}

		} finally {

			$this->importing_product_image =
				false;
		}


		if ( empty( $attachments ) ) {

			throw new \RuntimeException(
				sprintf(
					'No Promi images could be imported for product %d.',
					$parent_id
				)
			);
		}


		/*
		|--------------------------------------------------------------------------
		| Parent Featured Image + Gallery
		|--------------------------------------------------------------------------
		|
		| Preserve existing behavior:
		|
		| The parent receives all unique child images in Promi traversal
		| order. The first becomes featured; the remaining images form the
		| WooCommerce gallery.
		*/

		$parent_images =
			array_values(
				$attachments
			);

		$featured_id =
			array_shift(
				$parent_images
			);

		if ( $featured_id ) {

			set_post_thumbnail(
				$parent_id,
				$featured_id
			);
		}

		update_post_meta(
			$parent_id,
			'_product_image_gallery',
			implode(
				',',
				array_filter(
					array_map(
						'absint',
						$parent_images
					)
				)
			)
		);


		/*
		|--------------------------------------------------------------------------
		| Variation Images
		|--------------------------------------------------------------------------
		*/

		$this->logger->info(
			'Assigning Promi variation images.',
			[
				'product_id' =>
					$parent_id,

				'variations' =>
					count(
						$map['variations']
					),
			]
		);

		foreach (
			$map['variations']
				as $sku => $filenames
		) {

			$variation_id =
				wc_get_product_id_by_sku(
					$sku
				);

			if ( ! $variation_id ) {
				continue;
			}

			$image_ids = [];

			foreach (
				$filenames as $filename
			) {

				if (
					isset(
						$attachments[
							$filename
						]
					)
				) {
					$image_ids[] =
						(int)
							$attachments[
								$filename
							];
				}
			}

			$image_ids =
				array_values(
					array_unique(
						array_filter(
							$image_ids
						)
					)
				);

			if ( empty( $image_ids ) ) {
				continue;
			}

			$variation_featured =
				array_shift(
					$image_ids
				);

			if ( $variation_featured ) {

				set_post_thumbnail(
					$variation_id,
					$variation_featured
				);
			}

			/**
			 * WooCommerce itself only has one standard variation image,
			 * but the existing XSImpress frontend uses this gallery meta
			 * for variation-specific galleries.
			 */
			update_post_meta(
				$variation_id,
				'_product_image_gallery',
				implode(
					',',
					$image_ids
				)
			);
		}
	}


	/**
	 * Build the same image structure used by the existing importer.
	 *
	 * [
	 *     'all' => [
	 *         filename => [
	 *             'url'  => ...,
	 *             'desc' => ...,
	 *         ],
	 *     ],
	 *
	 *     'variations' => [
	 *         sku => [
	 *             filename,
	 *             filename,
	 *         ],
	 *     ],
	 * ]
	 */
	private function build_image_map(
		array $data
	): array {

		$all = [];

		$variations = [];

		$children =
			$data['ChildProducts']
			?? [];

		if ( ! is_array( $children ) ) {

			return [
				'all' =>
					[],

				'variations' =>
					[],
			];
		}

		foreach ( $children as $child ) {

			if ( ! is_array( $child ) ) {
				continue;
			}

			$sku = sanitize_text_field(
				$child['Sku']
				?? ''
			);

			if ( '' === $sku ) {
				continue;
			}

			$details =
				$child[
					'ProductDetails'
				]['de']
				?? [];


			/*
			|--------------------------------------------------------------------------
			| Main Image
			|--------------------------------------------------------------------------
			*/

			$main =
				$details['Image']
				?? null;

			$this->add_image_to_map(
				$all,
				$variations,
				$sku,
				$main
			);


			/*
			|--------------------------------------------------------------------------
			| Gallery Images
			|--------------------------------------------------------------------------
			*/

			$gallery =
				$details[
					'MediaGalleryImages'
				] ?? [];

			if ( ! is_array( $gallery ) ) {
				continue;
			}

			foreach (
				$gallery as $image
			) {

				$this->add_image_to_map(
					$all,
					$variations,
					$sku,
					$image
				);
			}
		}

		$this->logger->info(
			'Calculated Promi image map.',
			[
				'images' =>
					count( $all ),

				'variations' =>
					count( $variations ),
			]
		);

		return [
			'all' =>
				$all,

			'variations' =>
				$variations,
		];
	}


	private function add_image_to_map(
		array &$all,
		array &$variations,
		string $sku,
		mixed $image
	): void {

		if (
			! is_array( $image )
			|| empty( $image['FileName'] )
			|| empty( $image['Url'] )
		) {
			return;
		}

		$filename =
			sanitize_file_name(
				$image['FileName']
			);

		$url =
			esc_url_raw(
				$image['Url']
			);

		if (
			'' === $filename
			|| '' === $url
		) {
			return;
		}

		/*
		 * Global array is keyed by filename, preserving the existing
		 * deduplication behavior.
		 */
		$all[ $filename ] = [
			'url' =>
				$url,

			'desc' =>
				sanitize_text_field(
					$image['Description']
					?? ''
				),
		];

		$variations[
			$sku
		][] = $filename;
	}


	/*
	|--------------------------------------------------------------------------
	| Auxiliary Images
	|--------------------------------------------------------------------------
	*/

	/**
	 * Import a print-position image.
	 *
	 * Hooked to pdxw_print_position_image_id.
	 */
	public function import_print_position_image(
		int $attachment_id,
		string $remote_url,
		string $description,
		int $parent_id
	): int {

		$result =
			$this->import_auxiliary_image(
				$remote_url,
				$description,
				$parent_id,
				get_the_title(
					$parent_id
				)
			);

		return is_wp_error( $result )
			? 0
			: $result;
	}


	/**
	 * Import an auxiliary Promi image.
	 *
	 * Used for:
	 * - print positions
	 * - printer images
	 * - category images
	 *
	 * The service is generic enough for those consumers without knowing
	 * their business domain.
	 */
	public function import_auxiliary_image(
		string $remote_url,
		string $description = '',
		int $parent_id = 0,
		string $parent_title = ''
	): int|WP_Error {

		if ( '' === trim( $remote_url ) ) {

			return new WP_Error(
				'pdxw_empty_image_url',
				__(
					'An empty image URL was provided.',
					'promi-data-x-woo'
				)
			);
		}

		$url = esc_url_raw(
			$remote_url
		);

		if ( ! $url ) {

			return new WP_Error(
				'pdxw_invalid_image_url',
				__(
					'The Promi image URL is invalid.',
					'promi-data-x-woo'
				)
			);
		}

		$path = wp_parse_url(
			$url,
			PHP_URL_PATH
		);

		if ( ! is_string( $path ) ) {

			return new WP_Error(
				'pdxw_invalid_image_path',
				__(
					'The Promi image URL does not contain a valid path.',
					'promi-data-x-woo'
				)
			);
		}

		$filename =
			sanitize_file_name(
				basename( $path )
			);

		$extension =
			strtolower(
				pathinfo(
					$filename,
					PATHINFO_EXTENSION
				)
			);

		if (
			! in_array(
				$extension,
				self::ALLOWED_EXTENSIONS,
				true
			)
		) {

			return new WP_Error(
				'pdxw_invalid_image_format',
				__(
					'This image format is not allowed.',
					'promi-data-x-woo'
				)
			);
		}

		$this->importing_auxiliary_image =
			true;

		try {

			return $this->get_or_create_image(
				$filename,
				$url,
				$description,
				$parent_id,
				$parent_title
			);

		} finally {

			$this->importing_auxiliary_image =
				false;
		}
	}


	/*
	|--------------------------------------------------------------------------
	| Download / Deduplication
	|--------------------------------------------------------------------------
	*/

	/**
	 * Get an existing Promi attachment or download it.
	 *
	 * Filename remains the persistent deduplication identifier because this
	 * is how existing XSImpress media is already indexed.
	 */
	public function get_or_create_image(
		string $filename,
		string $url,
		string $description = '',
		int $parent_id = 0,
		string $parent_title = ''
	): int {

		$filename =
			sanitize_file_name(
				$filename
			);

		$url = esc_url_raw(
			$url
		);

		if (
			'' === $filename
			|| '' === $url
		) {
			return 0;
		}


		/*
		|--------------------------------------------------------------------------
		| In-request URL cache
		|--------------------------------------------------------------------------
		*/

		if (
			isset(
				$this->url_cache[
					$url
				]
			)
		) {

			return $this->url_cache[
				$url
			];
		}


		/*
		|--------------------------------------------------------------------------
		| Persistent filename deduplication
		|--------------------------------------------------------------------------
		*/

		$existing =
			$this->find_by_filename(
				$filename
			);

		if ( $existing ) {

			$this->update_attachment_meta(
				$existing,
				$description,
				$parent_title
			);

			$this->url_cache[
				$url
			] = $existing;

			return $existing;
		}


		/*
		|--------------------------------------------------------------------------
		| Download
		|--------------------------------------------------------------------------
		*/

		$this->load_media_dependencies();

		$temp_file =
			download_url(
				$url,
				45
			);

		if ( is_wp_error( $temp_file ) ) {

			$this->logger->error(
				'Promi image download failed.',
				[
					'url' =>
						$url,

					'error' =>
						$temp_file
							->get_error_message(),
				]
			);

			return 0;
		}

		/*
		 * normalize_image() writes back to the same temporary path.
		 */
		$temp_file =
			$this->normalize_image(
				$temp_file
			);

		$file = [
			'name' =>
				$filename,

			'tmp_name' =>
				$temp_file,
		];

		$attachment_id =
			media_handle_sideload(
				$file,
				$parent_id
			);

		if ( is_wp_error( $attachment_id ) ) {

			/*
			 * media_handle_sideload() normally removes/moves the temporary
			 * file, but clean it up defensively when it remains.
			 */
			if ( file_exists( $temp_file ) ) {
				@unlink( $temp_file );
			}

			$this->logger->error(
				'Promi image sideload failed.',
				[
					'filename' =>
						$filename,

					'url' =>
						$url,

					'error' =>
						$attachment_id
							->get_error_message(),
				]
			);

			return 0;
		}

		$attachment_id =
			absint(
				$attachment_id
			);

		update_post_meta(
			$attachment_id,
			self::FILENAME_META,
			$filename
		);

		$this->update_attachment_meta(
			$attachment_id,
			$description,
			$parent_title
		);

		$this->url_cache[
			$url
		] = $attachment_id;

		return $attachment_id;
	}


	/**
	 * Locate an existing Promi attachment using its original filename.
	 */
	private function find_by_filename(
		string $filename
	): int {

		global $wpdb;

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id
				FROM {$wpdb->postmeta}
				WHERE meta_key = %s
					AND meta_value = %s
				LIMIT 1",
				self::FILENAME_META,
				$filename
			)
		);

		return absint(
			$id
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Attachment Metadata
	|--------------------------------------------------------------------------
	*/

	private function update_attachment_meta(
		int $attachment_id,
		string $description,
		string $parent_title
	): void {

		$attachment_id =
			absint(
				$attachment_id
			);

		if ( ! $attachment_id ) {
			return;
		}

		if ( '' !== $parent_title ) {

			wp_update_post(
				[
					'ID' =>
						$attachment_id,

					'post_title' =>
						sanitize_text_field(
							$parent_title
						),
				]
			);
		}

		$alt = '' !== trim( $description )
			? sanitize_text_field(
				$description
			)
			: sanitize_text_field(
				$parent_title
			);

		update_post_meta(
			$attachment_id,
			'_wp_attachment_image_alt',
			$alt
		);
	}


	/*
	|--------------------------------------------------------------------------
	| Image Processing
	|--------------------------------------------------------------------------
	*/

	/**
	 * Reduce generated image sizes while importing Promi images.
	 */
	public function filter_image_sizes(
		array $sizes,
		array $metadata,
		int $attachment_id
	): array {

		if ( $this->importing_auxiliary_image ) {

			$allowed = [
				'thumbnail',
				'medium',
			];

			return array_intersect_key(
				$sizes,
				array_flip(
					$allowed
				)
			);
		}

		if ( $this->importing_product_image ) {

			$allowed = [
				'thumbnail',
				'large',
				'woocommerce_thumbnail',
				'woocommerce_single',
				'woocommerce_gallery_thumbnail',
			];

			return array_intersect_key(
				$sizes,
				array_flip(
					$allowed
				)
			);
		}

		return $sizes;
	}


	/**
	 * Preserve existing 82% image quality.
	 */
	public function image_quality(
		int $quality
	): int {

		return 82;
	}


	/**
	 * Preserve existing JPEG → WebP generation.
	 */
	public function output_formats(
		array $formats
	): array {

		$formats['image/jpeg'] =
			'image/webp';

		return $formats;
	}


	/**
	 * Normalize downloaded images before WordPress sideloading.
	 *
	 * Existing rules:
	 *
	 * - auto-orient
	 * - max dimension 1200px
	 * - extreme ratio > 2.5 gets centered on square white canvas
	 * - output as JPEG
	 * - quality 82
	 * - metadata stripped
	 */
	private function normalize_image(
		string $file_path
	): string {

		if (
			! class_exists( 'Imagick' )
			|| ! file_exists( $file_path )
		) {
			return $file_path;
		}

		try {

			$image =
				new \Imagick(
					$file_path
				);

			$image->autoOrient();

			$width =
				$image
					->getImageWidth();

			$height =
				$image
					->getImageHeight();

			if (
				$width <= 0
				|| $height <= 0
			) {

				$image->clear();
				$image->destroy();

				return $file_path;
			}

			$max_dimension = 1200;

			if (
				$width > $max_dimension
				|| $height > $max_dimension
			) {

				$image->thumbnailImage(
					$max_dimension,
					$max_dimension,
					true
				);

				$width =
					$image
						->getImageWidth();

				$height =
					$image
						->getImageHeight();
			}


			/*
			|--------------------------------------------------------------------------
			| Extreme Aspect Ratio
			|--------------------------------------------------------------------------
			*/

			$ratio = max(
				$width / $height,
				$height / $width
			);

			if ( $ratio > 2.5 ) {

				$size = max(
					$width,
					$height
				);

				$canvas =
					new \Imagick();

				$canvas->newImage(
					$size,
					$size,
					new \ImagickPixel(
						'white'
					)
				);

				$canvas->setImageFormat(
					'jpeg'
				);

				$x = (int) (
					( $size - $width )
					/ 2
				);

				$y = (int) (
					( $size - $height )
					/ 2
				);

				$canvas->compositeImage(
					$image,
					\Imagick::COMPOSITE_OVER,
					$x,
					$y
				);

				$image->clear();
				$image->destroy();

				$image = $canvas;
			}


			$image->setImageFormat(
				'jpeg'
			);

			$image->setImageCompression(
				\Imagick::COMPRESSION_JPEG
			);

			$image->setImageCompressionQuality(
				82
			);

			$image->stripImage();

			$image->writeImage(
				$file_path
			);

			$image->clear();
			$image->destroy();

		} catch ( \Throwable $e ) {

			$this->logger->warning(
				'Promi image normalization failed; original image will be used.',
				[
					'error' =>
						$e->getMessage(),
				]
			);
		}

		return $file_path;
	}


	/*
	|--------------------------------------------------------------------------
	| Pending Product Queries
	|--------------------------------------------------------------------------
	*/

	/**
	 * Find draft products waiting for image synchronization.
	 *
	 * This deliberately uses the same conditions as the existing plugin:
	 *
	 * - product
	 * - draft
	 * - _cx_need_to_sync_images = 1
	 * - not Promi-disabled
	 */
	private function pending_product_ids(
		int $limit
	): array {

		global $wpdb;

		$limit = max(
			1,
			absint( $limit )
		);

		return array_map(
			'absint',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT p.ID
					FROM {$wpdb->posts} p

					INNER JOIN {$wpdb->postmeta} sync_pm
						ON sync_pm.post_id = p.ID
						AND sync_pm.meta_key = %s
						AND sync_pm.meta_value = %s

					WHERE p.post_type = %s
						AND p.post_status = %s

						AND NOT EXISTS (
							SELECT 1
							FROM {$wpdb->postmeta} disabled_pm
							WHERE disabled_pm.post_id = p.ID
								AND disabled_pm.meta_key = %s
								AND disabled_pm.meta_value != ''
						)

					ORDER BY p.ID ASC

					LIMIT %d",
					self::IMAGE_SYNC_META,
					'1',
					'product',
					'draft',
					self::DISABLED_META,
					$limit
				)
			)
		);
	}


	/**
	 * Build SKU => product ID map.
	 */
	private function product_sku_map(
		array $product_ids
	): array {

		$map = [];

		foreach ( $product_ids as $product_id ) {

			$product_id =
				absint(
					$product_id
				);

			if ( ! $product_id ) {
				continue;
			}

			$sku = get_post_meta(
				$product_id,
				'_sku',
				true
			);

			$sku =
				sanitize_text_field(
					$sku
				);

			if ( $sku ) {
				$map[ $sku ] =
					$product_id;
			}
		}

		return $map;
	}


	/**
	 * Load Promi index rows for the requested SKUs.
	 */
	private function index_rows(
		array $skus
	): array {

		global $wpdb;

		$skus =
			array_values(
				array_unique(
					array_filter(
						array_map(
							'sanitize_text_field',
							$skus
						)
					)
				)
			);

		if ( empty( $skus ) ) {
			return [];
		}

		$placeholders =
			implode(
				',',
				array_fill(
					0,
					count( $skus ),
					'%s'
				)
			);

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT sku, json_url
				FROM '
				. Database::table(
					'promi_index'
				)
				. "
				WHERE sku IN ({$placeholders})",
				...$skus
			)
		);
	}


	/*
	|--------------------------------------------------------------------------
	| WordPress Media Dependencies
	|--------------------------------------------------------------------------
	*/

	private function load_media_dependencies(): void {

		require_once ABSPATH
			. 'wp-admin/includes/file.php';

		require_once ABSPATH
			. 'wp-admin/includes/media.php';

		require_once ABSPATH
			. 'wp-admin/includes/image.php';
	}
}