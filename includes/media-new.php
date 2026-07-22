<?php
/**
 * Client-side media support for the "Add New Media File" screen.
 *
 * Extends cross-origin isolation to wp-admin/media-new.php so the
 * client-side media pipeline can run there. The screen is also where
 * the Media Library's list mode sends users to upload (its "Add New
 * Media File" button links here), so together with the grid integration
 * this covers the whole Media Library. Core only isolates the block
 * editor screens, so both the COEP/COOP path (Firefox, Safari,
 * Chrome < 137) and the Document-Isolation-Policy path (Chromium 137+)
 * need to be set up by the plugin on this screen.
 *
 * @package ClientSideMediaEverywhere
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets up cross-origin isolation on the "Add New Media File" screen.
 *
 * Unlike the block editor screens, core does not isolate media-new.php
 * at all, so the DIP path (Chromium 137+) also needs to be started here
 * via core's public output buffer function.
 *
 * Hooked at priority 20 to match the block editor screen hooks.
 *
 * @since 1.2.0
 */
function csme_set_up_media_new_isolation() {
	/*
	 * The screen itself dies without upload_files, but this hook fires
	 * before that check runs, so gate here too.
	 */
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return;
	}

	if ( ! user_can( $user_id, 'upload_files' ) ) {
		return;
	}

	/*
	 * Without the upload-media package (core 7.1+ or Gutenberg) the
	 * pipeline cannot run, so skip the isolation headers: COEP can break
	 * third-party iframes on the screen for no benefit. Default scripts
	 * register on the first wp_scripts() call, so this is reliable here.
	 */
	if ( ! wp_script_is( 'wp-upload-media', 'registered' ) ) {
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

add_action( 'load-media-new.php', 'csme_set_up_media_new_isolation', 20 );

/**
 * Enqueues the client-side upload integration on the "Add New Media File"
 * screen.
 *
 * Wanted under both the DIP and the COEP/COOP isolation paths, so this is
 * not gated on csme_should_use_coep_coop(). The script itself no-ops when
 * the browser lacks client-side media support, leaving classic plupload
 * untouched.
 *
 * @since 1.2.0
 *
 * @param string $hook_suffix The current admin page hook suffix.
 */
function csme_enqueue_media_new_scripts( $hook_suffix ) {
	if ( 'media-new.php' !== $hook_suffix ) {
		return;
	}

	// Core 7.1+ (or Gutenberg) must have registered the upload-media package.
	if ( ! wp_script_is( 'wp-upload-media', 'registered' ) ) {
		return;
	}

	wp_enqueue_script(
		'csme-media-new-upload',
		CSME_PLUGIN_URL . 'js/media-new-upload.js',
		array(
			'plupload-handlers',
			'wp-upload-media',
			'wp-media-utils',
			'wp-api-fetch',
			'wp-data',
			'wp-element',
			'wp-i18n',
		),
		CSME_VERSION,
		true
	);

	// The pipeline settings are identical to the grid's.
	wp_add_inline_script(
		'csme-media-new-upload',
		'window.csmeMediaNewSettings = ' . wp_json_encode( csme_get_media_library_settings() ) . ';',
		'before'
	);
}

add_action( 'admin_enqueue_scripts', 'csme_enqueue_media_new_scripts' );
