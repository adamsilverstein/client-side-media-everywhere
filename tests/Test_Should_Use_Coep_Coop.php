<?php
/**
 * Tests for csme_should_use_coep_coop().
 *
 * @package ClientSideMediaEverywhere
 */

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Test_Should_Use_Coep_Coop extends WP_UnitTestCase {

	/**
	 * Tear down after each test.
	 */
	public function tear_down() {
		remove_all_filters( 'csme_use_coep_coop' );
		parent::tear_down();
	}

	/**
	 * Returns true when no Chromium version function exists (Firefox/Safari).
	 */
	public function test_returns_true_when_no_chromium_version_function() {
		// Neither wp_get_chromium_major_version nor gutenberg_get_chromium_major_version
		// should exist in the test environment by default.
		$this->assertTrue( csme_should_use_coep_coop() );
	}

	/**
	 * Returns false when Chromium version is 137+ (DIP is used).
	 */
	public function test_returns_false_when_chromium_137_or_higher() {
		if ( ! function_exists( 'wp_get_chromium_major_version' ) ) {
			function wp_get_chromium_major_version() {
				return 140;
			}
		}

		$this->assertFalse( csme_should_use_coep_coop() );
	}

	/**
	 * Returns true when Chromium version is below 137 (no DIP support).
	 */
	public function test_returns_true_when_chromium_below_137() {
		if ( ! function_exists( 'wp_get_chromium_major_version' ) ) {
			function wp_get_chromium_major_version() {
				return 130;
			}
		}

		$this->assertTrue( csme_should_use_coep_coop() );
	}

	/**
	 * Falls back to the Gutenberg plugin's version function when core's is absent.
	 */
	public function test_falls_back_to_gutenberg_version_function() {
		if ( ! function_exists( 'gutenberg_get_chromium_major_version' ) ) {
			function gutenberg_get_chromium_major_version() {
				return 140;
			}
		}

		$this->assertFalse( csme_should_use_coep_coop() );
	}

	/**
	 * Filter can override the return value to false.
	 */
	public function test_filter_can_override_to_false() {
		add_filter( 'csme_use_coep_coop', '__return_false' );

		$this->assertFalse( csme_should_use_coep_coop() );
	}

	/**
	 * Filter can override the return value to true.
	 */
	public function test_filter_can_override_to_true() {
		add_filter( 'csme_use_coep_coop', '__return_true' );

		$this->assertTrue( csme_should_use_coep_coop() );
	}
}
