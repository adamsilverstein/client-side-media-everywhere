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
	function wp_is_client_side_media_processing_enabled() {
		return true;
	}
}

// Provide Chrome version detection if neither core nor Gutenberg has it.
if ( ! function_exists( 'wp_get_chrome_major_version' ) && ! function_exists( 'gutenberg_get_chrome_major_version' ) ) {
	/**
	 * Stub: detect Chrome major version from the User-Agent header.
	 *
	 * @return int|null Chrome major version or null if not Chrome.
	 */
	function wp_get_chrome_major_version() {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( preg_match( '/Chrome\/(\d+)/', $user_agent, $matches ) ) {
			return (int) $matches[1];
		}
		return null;
	}
}
