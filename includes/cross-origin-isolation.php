<?php
/**
 * Cross-origin isolation via COEP/COOP headers.
 *
 * Restores COEP/COOP-based cross-origin isolation for browsers that
 * do not support Document-Isolation-Policy (Firefox, Safari, and
 * Chrome < 137).
 *
 * @package ClientSideMediaExperiments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether to use COEP/COOP headers for cross-origin isolation.
 *
 * Returns true only when Document-Isolation-Policy is NOT being used,
 * i.e. on non-Chromium browsers or Chrome < 137.
 *
 * @return bool
 */
function csme_should_use_coep_coop() {
	$chromium_version = null;

	if ( function_exists( 'wp_get_chromium_major_version' ) ) {
		$chromium_version = wp_get_chromium_major_version();
	} elseif ( function_exists( 'gutenberg_get_chromium_major_version' ) ) {
		$chromium_version = gutenberg_get_chromium_major_version();
	}

	// DIP is used on Chromium 137+. Only use COEP/COOP when DIP is NOT active.
	$use_dip = null !== $chromium_version && $chromium_version >= 137;

	/**
	 * Filters whether to use COEP/COOP for cross-origin isolation.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $use_coep_coop Whether COEP/COOP should be used.
	 */
	return (bool) apply_filters( 'csme_use_coep_coop', ! $use_dip );
}

/**
 * Sets up cross-origin isolation via COEP/COOP on relevant admin screens.
 *
 * Hooked at priority 20 so it runs after Gutenberg/core's own hooks.
 */
function csme_set_up_cross_origin_isolation() {
	if ( ! csme_should_use_coep_coop() ) {
		return;
	}

	$screen = get_current_screen();

	if ( ! $screen ) {
		return;
	}

	if ( ! $screen->is_block_editor() && 'site-editor' !== $screen->id && ! ( 'widgets' === $screen->id && wp_use_widgets_block_editor() ) ) {
		return;
	}

	// Skip when a third-party page builder overrides the block editor.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['action'] ) && 'edit' !== $_GET['action'] ) {
		return;
	}

	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return;
	}

	if ( ! user_can( $user_id, 'upload_files' ) ) {
		return;
	}

	csme_start_coep_coop_output_buffer();
}

add_action( 'load-post.php', 'csme_set_up_cross_origin_isolation', 20 );
add_action( 'load-post-new.php', 'csme_set_up_cross_origin_isolation', 20 );
add_action( 'load-site-editor.php', 'csme_set_up_cross_origin_isolation', 20 );
add_action( 'load-widgets.php', 'csme_set_up_cross_origin_isolation', 20 );

/**
 * Starts an output buffer that sends COEP/COOP headers and adds crossorigin attributes.
 *
 * @link https://web.dev/coop-coep/
 */
function csme_start_coep_coop_output_buffer() {
	global $is_safari;

	ob_start(
		function ( $output ) use ( $is_safari ) {
			$coep = $is_safari ? 'require-corp' : 'credentialless';
			header( 'Cross-Origin-Opener-Policy: same-origin' );
			header( 'Cross-Origin-Embedder-Policy: ' . $coep );

			if ( function_exists( 'wp_add_crossorigin_attributes' ) ) {
				return wp_add_crossorigin_attributes( $output );
			} elseif ( function_exists( 'gutenberg_add_crossorigin_attributes' ) ) {
				return gutenberg_add_crossorigin_attributes( $output );
			}

			return $output;
		}
	);
}

/**
 * Adds a JS flag so the client-side script knows COEP/COOP isolation is active.
 */
function csme_set_js_flag() {
	if ( ! csme_should_use_coep_coop() ) {
		return;
	}

	wp_add_inline_script( 'wp-block-editor', 'window.__coepCoopIsolation = true', 'before' );
}

add_action( 'admin_init', 'csme_set_js_flag' );

/**
 * Enqueues the COEP/COOP cross-origin isolation JavaScript.
 *
 * @param string $hook_suffix The current admin page hook suffix.
 */
function csme_enqueue_scripts( $hook_suffix ) {
	if ( ! csme_should_use_coep_coop() ) {
		return;
	}

	if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php', 'site-editor.php', 'widgets.php' ), true ) ) {
		return;
	}

	wp_enqueue_script(
		'csme-cross-origin-isolation-coep',
		CSME_PLUGIN_URL . 'js/cross-origin-isolation-coep.js',
		array( 'wp-block-editor', 'wp-hooks', 'wp-compose' ),
		CSME_VERSION,
		true
	);
}

add_action( 'admin_enqueue_scripts', 'csme_enqueue_scripts' );
