<?php
/**
 * Plugin Name: CORS Image Fixture for E2E Tests
 * Description: Serves an image with Access-Control-Allow-Origin so tests have a CORS-enabled cross-origin host.
 *
 * wp-env runs two sites on different ports, so a request from the tests site
 * to the development site is cross-origin. This handler makes the development
 * site a host that offers CORS, which is the case Safari's require-corp mode
 * can rescue. Static files on the same site (no CORS headers at all) are the
 * case it cannot.
 */

add_action(
	'init',
	function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test fixture, no state change.
		if ( ! isset( $_GET['csme_e2e_cors_image'] ) ) {
			return;
		}

		$file = ABSPATH . 'wp-includes/images/media/default.png';

		header( 'Access-Control-Allow-Origin: *' );
		header( 'Content-Type: image/png' );
		header( 'Content-Length: ' . (string) filesize( $file ) );
		header( 'Cache-Control: no-store' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Static fixture.
		exit;
	}
);
