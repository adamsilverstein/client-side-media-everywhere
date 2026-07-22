<?php
/**
 * Cross-origin isolation via COEP/COOP headers.
 *
 * Restores COEP/COOP-based cross-origin isolation for browsers that
 * do not support Document-Isolation-Policy (Firefox, Safari, and
 * Chrome < 137).
 *
 * @package ClientSideMediaEverywhere
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
 * Returns the COEP mode to use for cross-origin isolation.
 *
 * Safari does not support `credentialless`, so it gets `require-corp`.
 * All other browsers get `credentialless`, which does not require
 * cross-origin resources to opt in via CORS.
 *
 * @return string Either 'require-corp' or 'credentialless'.
 */
function csme_get_coep_mode() {
	global $is_safari;

	return $is_safari ? 'require-corp' : 'credentialless';
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
	ob_start(
		function ( $output ) {
			$coep = csme_get_coep_mode();
			header( 'Cross-Origin-Opener-Policy: same-origin' );
			header( 'Cross-Origin-Embedder-Policy: ' . $coep );

			// Let core/Gutenberg handle AUDIO, LINK, SCRIPT, VIDEO, SOURCE.
			if ( function_exists( 'wp_add_crossorigin_attributes' ) ) {
				$output = wp_add_crossorigin_attributes( $output );
			} elseif ( function_exists( 'gutenberg_add_crossorigin_attributes' ) ) {
				$output = gutenberg_add_crossorigin_attributes( $output );
			}

			/*
			 * Under require-corp (Safari), cross-origin images without CORP
			 * headers are blocked, and a CORS request via
			 * crossorigin="anonymous" is their only chance to load. Under
			 * credentialless (Firefox), no-cors images already load fine,
			 * and forcing CORS mode would break images from servers that
			 * do not send Access-Control-Allow-Origin.
			 */
			if ( 'require-corp' === $coep ) {
				$output = csme_add_crossorigin_to_images( $output );
			}

			return $output;
		}
	);
}

/**
 * Adds crossorigin="anonymous" to cross-origin IMG tags.
 *
 * Core/Gutenberg removed IMG from the elements receiving crossorigin
 * attributes (see Gutenberg#76618). Under require-corp isolation images
 * still need the attribute, so this plugin adds it back for IMG only.
 *
 * @since 1.1.0
 *
 * @param string $html HTML input.
 * @return string Modified HTML.
 */
function csme_add_crossorigin_to_images( $html ) {
	if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $html;
	}

	$site_url = site_url();

	$processor = new WP_HTML_Tag_Processor( $html );

	while ( $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
		if ( null !== $processor->get_attribute( 'crossorigin' ) ) {
			continue;
		}

		$urls = array();

		$src = $processor->get_attribute( 'src' );
		if ( is_string( $src ) ) {
			$urls[] = $src;
		}

		// Each srcset candidate is a URL optionally followed by a descriptor.
		$srcset = $processor->get_attribute( 'srcset' );
		if ( is_string( $srcset ) ) {
			foreach ( explode( ',', $srcset ) as $candidate ) {
				$candidate = trim( $candidate );
				if ( '' !== $candidate ) {
					$parts  = preg_split( '/\s+/', $candidate );
					$urls[] = $parts[0];
				}
			}
		}

		foreach ( $urls as $url ) {
			if ( csme_is_cross_origin_url( $url, $site_url ) ) {
				$processor->set_attribute( 'crossorigin', 'anonymous' );
				break;
			}
		}
	}

	return $processor->get_updated_html();
}

/**
 * Whether a URL points to a different origin than the site.
 *
 * Root-relative URLs (a single leading slash) are same-origin;
 * protocol-relative URLs (double leading slash) are treated as
 * cross-origin, unlike core's check, which misclassifies them.
 *
 * @since 1.1.0
 *
 * @param string $url      URL to check.
 * @param string $site_url The site URL.
 * @return bool Whether the URL is cross-origin.
 */
function csme_is_cross_origin_url( $url, $site_url ) {
	$is_root_relative = str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' );

	return ! str_starts_with( $url, $site_url ) && ! $is_root_relative;
}

/**
 * Enqueues the COEP/COOP cross-origin isolation JavaScript.
 *
 * @param string $hook_suffix The current admin page hook suffix.
 */
function csme_enqueue_scripts( $hook_suffix ) {
	if ( ! csme_should_use_coep_coop() ) {
		return;
	}

	$is_media_screen = in_array( $hook_suffix, array( 'upload.php', 'media-new.php' ), true );

	if ( 'upload.php' === $hook_suffix ) {
		// Only the grid mode has an uploader; list mode uploads happen on
		// media-new.php (the list view's "Add New Media File" links there).
		if ( 'grid' !== csme_get_media_library_mode() ) {
			return;
		}
	}

	if ( $is_media_screen ) {
		// No isolation headers are sent on these screens without the
		// upload-media package (see csme_set_up_media_library_isolation()
		// and csme_set_up_media_new_isolation()), so the observer script
		// would be dead weight.
		if ( ! wp_script_is( 'wp-upload-media', 'registered' ) ) {
			return;
		}
	} elseif ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php', 'site-editor.php', 'widgets.php' ), true ) ) {
		return;
	}

	/*
	 * The MutationObserver portion of the script is dependency-free and
	 * its block editor section self-guards, so the media screens do
	 * not need the block editor scripts dragged onto the page.
	 */
	$dependencies = $is_media_screen
		? array()
		: array( 'wp-block-editor', 'wp-element', 'wp-hooks', 'wp-compose' );

	wp_enqueue_script(
		'csme-cross-origin-isolation-coep',
		CSME_PLUGIN_URL . 'js/cross-origin-isolation-coep.js',
		$dependencies,
		CSME_VERSION,
		true
	);

	// Flag so the script knows COEP/COOP isolation (not DIP) is active,
	// and which COEP mode is in effect (require-corp vs credentialless).
	wp_add_inline_script(
		'csme-cross-origin-isolation-coep',
		'window.__coepCoopIsolation = true; window.__coepMode = ' . wp_json_encode( csme_get_coep_mode() ) . ';',
		'before'
	);
}

add_action( 'admin_enqueue_scripts', 'csme_enqueue_scripts' );
