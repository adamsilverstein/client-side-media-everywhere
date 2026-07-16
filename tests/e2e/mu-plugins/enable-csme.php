<?php
/**
 * Plugin Name: Enable Client-Side Media for E2E Tests
 * Description: Stubs required functions so the CSME plugin loads in the test environment.
 */

if ( ! function_exists( 'wp_is_client_side_media_processing_enabled' ) ) {
	/**
	 * Stub: always enable client-side media processing.
	 *
	 * @return bool
	 */
	function wp_is_client_side_media_processing_enabled() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Deliberate stub of the core function.
		return true;
	}
}

// Provide Chromium version detection if neither core (7.1+) nor Gutenberg has it.
if ( ! function_exists( 'wp_get_chromium_major_version' ) && ! function_exists( 'gutenberg_get_chromium_major_version' ) ) {
	/**
	 * Stub: detect Chromium major version from the User-Agent header.
	 *
	 * Mirrors core's wp_get_chromium_major_version() for environments
	 * running a WordPress version that predates it.
	 *
	 * @return int|null Chromium major version or null if not a Chromium browser.
	 */
	function wp_get_chromium_major_version() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Deliberate stub of the core function.
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( preg_match( '/Chrome\/(\d+)/', $user_agent, $matches ) ) {
			return (int) $matches[1];
		}
		return null;
	}
}
