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
 * Whether the current request should get COEP/COOP cross-origin isolation.
 *
 * @since 1.2.0
 *
 * @return bool
 */
function csme_should_set_up_cross_origin_isolation() {
	if ( ! csme_should_use_coep_coop() ) {
		return false;
	}

	$screen = get_current_screen();

	if ( ! $screen ) {
		return false;
	}

	if ( ! $screen->is_block_editor() && 'site-editor' !== $screen->id && ! ( 'widgets' === $screen->id && wp_use_widgets_block_editor() ) ) {
		return false;
	}

	// Skip when a third-party page builder overrides the block editor.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['action'] ) && 'edit' !== $_GET['action'] ) {
		return false;
	}

	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return false;
	}

	return user_can( $user_id, 'upload_files' );
}

/**
 * Sets up cross-origin isolation via COEP/COOP on relevant admin screens.
 *
 * Sends the headers, and under require-corp (Safari) also starts the
 * output buffer that adds crossorigin attributes.
 *
 * Hooked at priority 20 so it runs after Gutenberg/core's own hooks.
 */
function csme_set_up_cross_origin_isolation() {
	if ( ! csme_should_set_up_cross_origin_isolation() ) {
		return;
	}

	if ( 'require-corp' === csme_send_coep_coop_headers() ) {
		csme_start_crossorigin_output_buffer();
	}
}

add_action( 'load-post.php', 'csme_set_up_cross_origin_isolation', 20 );
add_action( 'load-post-new.php', 'csme_set_up_cross_origin_isolation', 20 );
add_action( 'load-site-editor.php', 'csme_set_up_cross_origin_isolation', 20 );
add_action( 'load-widgets.php', 'csme_set_up_cross_origin_isolation', 20 );

/**
 * Sends the COOP and COEP headers for cross-origin isolation.
 *
 * @since 1.2.0
 *
 * @link https://web.dev/coop-coep/
 *
 * @return string The COEP mode that was sent: 'require-corp' or 'credentialless'.
 */
function csme_send_coep_coop_headers() {
	$coep = csme_get_coep_mode();

	header( 'Cross-Origin-Opener-Policy: same-origin' );
	header( 'Cross-Origin-Embedder-Policy: ' . $coep );

	return $coep;
}

/**
 * Starts an output buffer that adds crossorigin="anonymous" to cross-origin resources.
 *
 * Only useful under `require-corp` (Safari). That mode blocks every
 * cross-origin resource that does not send `Cross-Origin-Resource-Policy`,
 * and a CORS request via `crossorigin="anonymous"` is the only other way
 * for it to load. Under `credentialless` (Firefox, Chrome < 137) cross-origin
 * resources already load without credentials, and forcing CORS mode would
 * break any of them served without `Access-Control-Allow-Origin`.
 *
 * @since 1.2.0
 */
function csme_start_crossorigin_output_buffer() {
	ob_start(
		function ( $output ) {
			return csme_add_crossorigin_attributes( $output );
		}
	);
}

/**
 * Adds crossorigin="anonymous" to cross-origin resources in an HTML document.
 *
 * Covers IMG (src and srcset), SCRIPT, LINK, AUDIO and VIDEO (src and
 * poster), the AUDIO or VIDEO parent of a cross-origin SOURCE, and the IMG
 * of a PICTURE whose SOURCE holds a cross-origin candidate. The attribute
 * never goes on the SOURCE itself, which has none of its own: the media
 * element governs its source list, and the IMG carries whichever PICTURE
 * candidate the browser selects.
 *
 * The HTML API's tag processor has no tree, so a media element is treated
 * as open until its own closing tag or the first tag that is not SOURCE or
 * TRACK, whichever comes first. The HTML spec requires SOURCE and TRACK
 * children to come before any other content, so this bounds the source
 * list exactly, including when the media element is closed implicitly.
 *
 * Parents are marked in a second forward pass instead of seeking backwards,
 * which keeps the work at two scans in the worst case and never trips the
 * tag processor's seek budget.
 *
 * @since 1.2.0
 *
 * @param string $html HTML input.
 * @return string Modified HTML.
 */
function csme_add_crossorigin_attributes( $html ) {
	if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $html;
	}

	$site_url = site_url();

	$url_attributes = array(
		'AUDIO'  => array( 'src' ),
		'IMG'    => array( 'src', 'srcset' ),
		'LINK'   => array( 'href' ),
		'SCRIPT' => array( 'src' ),
		'SOURCE' => array( 'src', 'srcset' ),
		'VIDEO'  => array( 'src', 'poster' ),
	);

	$processor = new WP_HTML_Tag_Processor( $html );

	// Ordinal of the last AUDIO or VIDEO opener seen, counting from 1.
	$media_count = 0;
	// Ordinal of the media element whose source list is still open, or 0.
	$open_media = 0;
	// Whether that media element already carries a crossorigin attribute.
	$open_media_marked = false;
	// Ordinals of media elements to mark in the second pass.
	$parents_to_mark = array();
	// Whether the cursor is inside a PICTURE element.
	$in_picture = false;
	// Whether a SOURCE in that PICTURE held a cross-origin candidate.
	$picture_needs_mark = false;

	/*
	 * Enter a media element on its opening tag and reset on its closing tag,
	 * so a source list belongs to exactly one parent. That reset is the only
	 * reason closing tags are visited: without it a SOURCE appearing after
	 * </video> would still look like a child of that video.
	 */
	while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
		$tag      = $processor->get_tag();
		$is_media = 'AUDIO' === $tag || 'VIDEO' === $tag;

		if ( $processor->is_tag_closer() ) {
			if ( $is_media ) {
				$open_media = 0;
			}
			if ( 'PICTURE' === $tag ) {
				$in_picture         = false;
				$picture_needs_mark = false;
			}
			continue;
		}

		if ( 'SOURCE' !== $tag && 'TRACK' !== $tag ) {
			$open_media = 0;
		}

		// A PICTURE holds only SOURCE and IMG, so anything else ends it.
		if ( 'SOURCE' !== $tag && 'IMG' !== $tag ) {
			$in_picture         = false;
			$picture_needs_mark = false;
		}

		if ( 'PICTURE' === $tag ) {
			$in_picture = true;
		}

		if ( $is_media ) {
			++$media_count;
			$open_media        = $media_count;
			$open_media_marked = null !== $processor->get_attribute( 'crossorigin' );
		}

		if ( ! isset( $url_attributes[ $tag ] ) ) {
			continue;
		}

		if ( 'SOURCE' === $tag ) {
			if ( 0 !== $open_media ) {
				if ( $open_media_marked ) {
					continue;
				}
				if ( csme_has_cross_origin_url( $processor, $url_attributes[ $tag ], $site_url ) ) {
					$parents_to_mark[ $open_media ] = true;
					$open_media_marked              = true;
				}
				continue;
			}

			/*
			 * Inside a PICTURE the candidates belong to the IMG that follows
			 * them: the browser applies whichever it picks to that element, so
			 * the IMG is what carries the attribute. A SOURCE with no media or
			 * picture parent has nothing to mark.
			 */
			if ( $in_picture && ! $picture_needs_mark && csme_has_cross_origin_url( $processor, $url_attributes[ $tag ], $site_url ) ) {
				$picture_needs_mark = true;
			}
			continue;
		}

		if ( null !== $processor->get_attribute( 'crossorigin' ) ) {
			continue;
		}

		$needs_mark = csme_has_cross_origin_url( $processor, $url_attributes[ $tag ], $site_url );

		/*
		 * The IMG closes out its PICTURE, whether or not its own fallback is
		 * cross-origin: a same-origin fallback next to a cross-origin AVIF
		 * candidate still needs the attribute for that candidate to load.
		 */
		if ( 'IMG' === $tag && $picture_needs_mark ) {
			$needs_mark         = true;
			$picture_needs_mark = false;
		}

		if ( $needs_mark ) {
			$processor->set_attribute( 'crossorigin', 'anonymous' );
			if ( $is_media ) {
				$open_media_marked = true;
			}
		}
	}

	$html = $processor->get_updated_html();

	if ( empty( $parents_to_mark ) ) {
		return $html;
	}

	$processor   = new WP_HTML_Tag_Processor( $html );
	$media_count = 0;

	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();
		if ( 'AUDIO' !== $tag && 'VIDEO' !== $tag ) {
			continue;
		}
		++$media_count;
		if ( isset( $parents_to_mark[ $media_count ] ) ) {
			$processor->set_attribute( 'crossorigin', 'anonymous' );
		}
	}

	return $processor->get_updated_html();
}

/**
 * Whether any of the given attributes on the current tag holds a cross-origin URL.
 *
 * A `srcset` attribute is split into its candidates, each of which is a URL
 * optionally followed by a descriptor.
 *
 * @since 1.2.0
 *
 * @param WP_HTML_Tag_Processor $processor  Processor positioned on a tag.
 * @param string[]              $attributes Attribute names holding URLs.
 * @param string                $site_url   The site URL.
 * @return bool Whether a cross-origin URL was found.
 */
function csme_has_cross_origin_url( $processor, $attributes, $site_url ) {
	foreach ( $attributes as $attribute ) {
		$value = $processor->get_attribute( $attribute );
		if ( ! is_string( $value ) || '' === $value ) {
			continue;
		}

		$urls = array( $value );
		if ( 'srcset' === $attribute ) {
			$urls = array();
			foreach ( explode( ',', $value ) as $candidate ) {
				$candidate = trim( $candidate );
				if ( '' !== $candidate ) {
					$parts  = preg_split( '/\s+/', $candidate );
					$urls[] = $parts[0];
				}
			}
		}

		foreach ( $urls as $url ) {
			if ( csme_is_cross_origin_url( $url, $site_url ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Returns the origin of a URL: scheme, host, and non-default port.
 *
 * A URL with no host, such as a root-relative, data, or blob URL, has no
 * origin of its own and returns an empty string.
 *
 * @since 1.2.0
 *
 * @param string $url            URL to read.
 * @param string $default_scheme Scheme to assume when the URL omits one,
 *                               as protocol-relative URLs do.
 * @return string The origin, or an empty string when the URL has no host.
 */
function csme_get_url_origin( $url, $default_scheme = '' ) {
	$parts = wp_parse_url( $url );

	if ( empty( $parts['host'] ) ) {
		return '';
	}

	$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : $default_scheme;
	$host   = strtolower( $parts['host'] );
	$port   = isset( $parts['port'] ) ? (int) $parts['port'] : 0;

	// An explicit default port is the same origin as no port at all.
	if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
		$port = 0;
	}

	return $scheme . '://' . $host . ( $port > 0 ? ':' . $port : '' );
}

/**
 * Whether a URL points to a different origin than the site.
 *
 * Compares parsed origins rather than matching the site URL as a string
 * prefix. A prefix match reads https://example.com.cdn.net as same-origin
 * for a site at https://example.com, and misses a differing port, both of
 * which would leave a cross-origin resource unmarked and blocked under
 * require-corp. It also reads every URL outside the install directory as
 * cross-origin on a site in a subdirectory.
 *
 * URLs with no host of their own are same-origin: root-relative URLs (a
 * single leading slash) resolve against the site, and data or blob URLs
 * are not fetched across the network. Protocol-relative URLs (a double
 * leading slash) inherit the site's scheme, so only the host and port
 * decide.
 *
 * @since 1.1.0
 *
 * @param string $url      URL to check.
 * @param string $site_url The site URL.
 * @return bool Whether the URL is cross-origin.
 */
function csme_is_cross_origin_url( $url, $site_url ) {
	$site_parts  = wp_parse_url( $site_url );
	$site_scheme = isset( $site_parts['scheme'] ) ? strtolower( $site_parts['scheme'] ) : '';

	$origin = csme_get_url_origin( $url, $site_scheme );

	if ( '' === $origin ) {
		return false;
	}

	return csme_get_url_origin( $site_url ) !== $origin;
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

	if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php', 'site-editor.php', 'widgets.php' ), true ) ) {
		return;
	}

	wp_enqueue_script(
		'csme-cross-origin-isolation-coep',
		CSME_PLUGIN_URL . 'js/cross-origin-isolation-coep.js',
		array( 'wp-block-editor', 'wp-element', 'wp-hooks', 'wp-compose' ),
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
