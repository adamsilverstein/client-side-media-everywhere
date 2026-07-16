<?php
/**
 * Plugin Name: Force DIP Mode for E2E Tests
 * Description: Allows tests to simulate Document-Isolation-Policy mode via query parameter.
 */

/**
 * Disables COEP/COOP when the csme_force_dip query parameter is set.
 *
 * This simulates the behavior of Chrome 137+ where DIP takes over
 * and COEP/COOP headers should not be sent.
 *
 * Uses two mechanisms for robustness:
 * 1. Removes CSME plugin action hooks after plugins load (belt).
 * 2. Filters csme_use_coep_coop to return false (suspenders).
 */

/**
 * Checks whether DIP mode is being forced via query parameter.
 *
 * @return bool
 */
function csme_e2e_is_forcing_dip() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	return isset( $_GET['csme_force_dip'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['csme_force_dip'] ) );
}

/**
 * Removes CSME cross-origin isolation hooks when DIP mode is forced.
 *
 * Runs on plugins_loaded at priority 20: the plugin registers its hooks
 * inside csme_init() on plugins_loaded (default priority 10), and since
 * mu-plugins load before regular plugins, a priority-10 callback here
 * would run before those hooks exist and remove nothing.
 */
function csme_e2e_remove_coep_coop_hooks() {
	if ( ! csme_e2e_is_forcing_dip() ) {
		return;
	}

	$hooks = array( 'load-post.php', 'load-post-new.php', 'load-site-editor.php', 'load-widgets.php' );
	foreach ( $hooks as $hook ) {
		remove_action( $hook, 'csme_set_up_cross_origin_isolation', 20 );
	}

	remove_action( 'admin_init', 'csme_set_js_flag' );
	remove_action( 'admin_enqueue_scripts', 'csme_enqueue_scripts' );
}
add_action( 'plugins_loaded', 'csme_e2e_remove_coep_coop_hooks', 20 );

/**
 * Filters csme_use_coep_coop to disable COEP/COOP when DIP mode is forced.
 *
 * @param bool $use_coep_coop Whether COEP/COOP should be used.
 * @return bool Filtered value.
 */
function csme_e2e_force_dip_filter( $use_coep_coop ) {
	if ( csme_e2e_is_forcing_dip() ) {
		return false;
	}
	return $use_coep_coop;
}
add_filter( 'csme_use_coep_coop', 'csme_e2e_force_dip_filter', 1 );
