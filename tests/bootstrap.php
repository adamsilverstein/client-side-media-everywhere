<?php
/**
 * PHPUnit bootstrap file for the Client-Side Media Everywhere plugin.
 *
 * @package ClientSideMediaEverywhere
 */

// Define plugin constants.
define( 'CSME_VERSION', '1.1.0' );
define( 'CSME_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'CSME_PLUGIN_URL', 'http://example.org/wp-content/plugins/client-side-media-everywhere/' );

// Determine the WP tests directory.
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php. Have you run install-wp-tests.sh?" . PHP_EOL;
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin's cross-origin isolation include.
 *
 * The main plugin file has early-return guards, so we load the include directly.
 */
function _manually_load_plugin() {
	require_once CSME_PLUGIN_DIR . 'includes/cross-origin-isolation.php';
	require_once CSME_PLUGIN_DIR . 'includes/media-library.php';
	require_once CSME_PLUGIN_DIR . 'includes/media-new.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
