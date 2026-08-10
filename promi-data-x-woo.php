<?php
/**
 * Plugin Name:       Promi-Data X Woo
 * Plugin URI:        https://github.com/iamdenzen/Promi-Data-X-Woo
 * Description:       Promi-Data X Woo is a WordPress plugin that integrates Promi-Data with WooCommerce, providing enhanced data management and analytics capabilities for your online store.
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      8.0
 * Author:            Creatricx
 * Author URI:        https://github.com/iamdenzen
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/iamdenzen/Promi-Data-X-Woo
 * Text Domain:       promi-data-x-woo
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * --------------------------------------------------------------------------
 * Plugin Constants
 * --------------------------------------------------------------------------
 */

define( 'PDXW_VERSION', '1.0.1' );

define( 'PDXW_FILE', __FILE__ );
define( 'PDXW_BASENAME', plugin_basename( __FILE__ ) );

define( 'PDXW_PATH', plugin_dir_path( __FILE__ ) );
define( 'PDXW_URL', plugin_dir_url( __FILE__ ) );

define( 'PDXW_INCLUDES_PATH', PDXW_PATH . 'includes/' );
define( 'PDXW_ASSETS_PATH', PDXW_PATH . 'assets/' );
define( 'PDXW_TEMPLATES_PATH', PDXW_PATH . 'templates/' );

define( 'PDXW_ASSETS_URL', PDXW_URL . 'assets/' );


/**
 * --------------------------------------------------------------------------
 * Autoloader
 * --------------------------------------------------------------------------
 *
 * Namespace:
 *
 * PromiDataXWoo\Core\Plugin
 *
 * becomes:
 *
 * includes/Core/Plugin.php
 *
 * This allows us to keep the entire plugin modular without requiring every
 * class manually from this bootstrap file.
 */

spl_autoload_register(
	static function ( $class ) {

		$prefix = 'PromiDataXWoo\\';

		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );

		$file = PDXW_INCLUDES_PATH
			. str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class )
			. '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);


/**
 * --------------------------------------------------------------------------
 * Activation
 * --------------------------------------------------------------------------
 */

function pdxw_activate(): void {

	if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
		wp_die(
			esc_html__(
				'Promi-Data X Woo requires PHP 8.0 or newer.',
				'promi-data-x-woo'
			)
		);
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		wp_die(
			esc_html__(
				'Promi-Data X Woo requires WooCommerce to be installed and active.',
				'promi-data-x-woo'
			)
		);
	}

	PromiDataXWoo\Core\Activator::activate();
}

register_activation_hook( __FILE__, 'pdxw_activate' );


/**
 * --------------------------------------------------------------------------
 * Deactivation
 * --------------------------------------------------------------------------
 */

function pdxw_deactivate(): void {

	PromiDataXWoo\Core\Deactivator::deactivate();
}

register_deactivation_hook( __FILE__, 'pdxw_deactivate' );


/**
 * --------------------------------------------------------------------------
 * Boot Plugin
 * --------------------------------------------------------------------------
 */

function pdxw_boot(): void {

	/**
	 * WooCommerce is the foundation of this plugin.
	 *
	 * "Requires Plugins: woocommerce" handles this on modern WordPress
	 * versions, but we still guard against WooCommerce being unavailable.
	 */
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	PromiDataXWoo\Core\Plugin::instance()->boot();
}

add_action( 'plugins_loaded', 'pdxw_boot', 20 );


/**
 * --------------------------------------------------------------------------
 * Plugin Accessor
 * --------------------------------------------------------------------------
 *
 * Provides a convenient global entry point where needed:
 *
 * pdxw()->pricing()
 * pdxw()->promi()
 * pdxw()->printing()
 *
 * Most internal code should still use dependency injection rather than
 * relying on this function everywhere.
 */

function pdxw(): ?PromiDataXWoo\Core\Plugin {

	if ( ! class_exists( 'PromiDataXWoo\Core\Plugin' ) ) {
		return null;
	}

	return PromiDataXWoo\Core\Plugin::instance();
}