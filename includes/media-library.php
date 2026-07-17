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

	/*
	 * Match core: compare the raw value against the whitelist, so inputs
	 * core rejects (e.g. `?mode=GRID`) fall back to the user option here
	 * too. The strict whitelist comparison doubles as sanitization.
	 */
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$requested = isset( $_GET['mode'] ) ? wp_unslash( $_GET['mode'] ) : '';
	if ( in_array( $requested, $modes, true ) ) {
		return $requested;
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

/**
 * Enqueues the client-side upload integration on the Media Library grid.
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
function csme_enqueue_media_library_scripts( $hook_suffix ) {
	if ( 'upload.php' !== $hook_suffix ) {
		return;
	}

	if ( 'grid' !== csme_get_media_library_mode() ) {
		return;
	}

	// Core 7.1+ (or Gutenberg) must have registered the upload-media package.
	if ( ! wp_script_is( 'wp-upload-media', 'registered' ) ) {
		return;
	}

	wp_enqueue_script(
		'csme-media-library-upload',
		CSME_PLUGIN_URL . 'js/media-library-upload.js',
		array(
			'media-views',
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

	wp_add_inline_script(
		'csme-media-library-upload',
		'window.csmeMediaLibrarySettings = ' . wp_json_encode( csme_get_media_library_settings() ) . ';',
		'before'
	);
}

add_action( 'admin_enqueue_scripts', 'csme_enqueue_media_library_scripts' );

/**
 * Builds the settings passed to the client-side media pipeline.
 *
 * These mirror the values the block editor consumes for the same
 * pipeline: the REST index (image sizes and the big-image threshold)
 * and get_block_editor_settings() (max upload size and allowed mime
 * types), plus the image encoding filters.
 *
 * @since 1.2.0
 *
 * @return array{
 *     maxUploadFileSize:int,
 *     allowedMimeTypes:array<string,string>,
 *     allImageSizes:array<string,array<string,mixed>>,
 *     bigImageSizeThreshold:int,
 *     imageStripMeta:bool,
 *     imageMaxBitDepth:int
 * } Settings for the client-side pipeline.
 */
function csme_get_media_library_settings() {
	/** This filter is documented in wp-admin/includes/image.php */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	$big_image_size_threshold = (int) apply_filters( 'big_image_size_threshold', 2560, array( 0, 0 ), '', 0 );

	/** This filter is documented in wp-includes/class-wp-image-editor-imagick.php */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	$image_strip_meta = (bool) apply_filters( 'image_strip_meta', true );

	/** This filter is documented in wp-includes/class-wp-image-editor-imagick.php */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	$image_max_bit_depth = (int) apply_filters( 'image_max_bit_depth', 16, 16 );

	return array(
		'maxUploadFileSize'     => wp_max_upload_size(),
		'allowedMimeTypes'      => get_allowed_mime_types(),
		'allImageSizes'         => wp_get_registered_image_subsizes(),
		'bigImageSizeThreshold' => $big_image_size_threshold,
		'imageStripMeta'        => $image_strip_meta,
		'imageMaxBitDepth'      => $image_max_bit_depth,
	);
}
