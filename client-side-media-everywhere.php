<?php
/**
 * Plugin Name: Client-Side Media Everywhere
 * Plugin URI:  https://github.com/adamsilverstein/client-side-media-everywhere
 * Description: Enables client-side media processing on Firefox and Safari via COEP/COOP cross-origin isolation headers.
 * Version:     1.1.0
 * Requires at least: 6.8
 * Requires PHP: 7.4
 * Author:      Adam Silverstein
 * Author URI:  https://developer.wordpress.org
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: client-side-media-everywhere
 *
 * @package ClientSideMediaEverywhere
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CSME_VERSION', '1.1.0' );
define( 'CSME_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CSME_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Initializes the plugin once all plugins are loaded.
 *
 * Deferred to plugins_loaded so the Gutenberg plugin's functions are
 * available regardless of plugin load order.
 */
function csme_init() {
	// Bail if client-side media processing is not enabled.
	if ( function_exists( 'wp_is_client_side_media_processing_enabled' ) ) {
		if ( ! wp_is_client_side_media_processing_enabled() ) {
			return;
		}
	} elseif ( function_exists( 'gutenberg_is_client_side_media_processing_enabled' ) ) {
		if ( ! gutenberg_is_client_side_media_processing_enabled() ) {
			return;
		}
	} else {
		// Neither core 7.1+ nor Gutenberg with the feature - nothing to do.
		return;
	}

	require_once CSME_PLUGIN_DIR . 'includes/cross-origin-isolation.php';
	require_once CSME_PLUGIN_DIR . 'includes/media-library.php';
	require_once CSME_PLUGIN_DIR . 'includes/media-new.php';
}
add_action( 'plugins_loaded', 'csme_init' );
