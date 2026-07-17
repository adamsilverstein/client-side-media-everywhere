<?php
/**
 * Client-side media support for the Media Library grid.
 *
 * Extends cross-origin isolation to wp-admin/upload.php (grid mode) so
 * the client-side media pipeline can run there. Core only isolates the
 * block editor screens, so both the COEP/COOP path (Firefox, Safari,
 * Chrome < 137) and the Document-Isolation-Policy path (Chromium 137+)
 * need to be set up by the plugin on this screen.
 *
 * @package ClientSideMediaEverywhere
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the current Media Library mode (grid or list).
 *
 * Replicates the mode resolution in wp-admin/upload.php, which runs
 * after the load-upload.php hook this plugin uses.
 *
 * @since 1.2.0
 *
 * @return string Either 'grid' or 'list'.
 */
function csme_get_media_library_mode() {
	$modes = array( 'grid', 'list' );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['mode'] ) && in_array( sanitize_key( $_GET['mode'] ), $modes, true ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return sanitize_key( $_GET['mode'] );
	}

	$mode = get_user_option( 'media_library_mode', get_current_user_id() );

	return in_array( $mode, $modes, true ) ? $mode : 'grid';
}

/**
 * Sets up cross-origin isolation on the Media Library grid screen.
 *
 * Unlike the block editor screens, core does not isolate upload.php at
 * all, so the DIP path (Chromium 137+) also needs to be started here
 * via core's public output buffer function.
 *
 * Hooked at priority 20 to match the block editor screen hooks.
 *
 * @since 1.2.0
 */
function csme_set_up_media_library_isolation() {
	if ( 'grid' !== csme_get_media_library_mode() ) {
		return;
	}

	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return;
	}

	if ( ! user_can( $user_id, 'upload_files' ) ) {
		return;
	}

	if ( csme_should_use_coep_coop() ) {
		csme_start_coep_coop_output_buffer();
	} elseif ( function_exists( 'wp_start_cross_origin_isolation_output_buffer' ) ) {
		wp_start_cross_origin_isolation_output_buffer();
	} elseif ( function_exists( 'gutenberg_start_cross_origin_isolation_output_buffer' ) ) {
		gutenberg_start_cross_origin_isolation_output_buffer();
	}
}

add_action( 'load-upload.php', 'csme_set_up_media_library_isolation', 20 );
