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
		unset( $_SERVER['HTTP_USER_AGENT'] );
		parent::tear_down();
	}

	/**
	 * Makes the Chromium version lookup report the given major version.
	 *
	 * WordPress 7.1 and newer define wp_get_chromium_major_version(), which
	 * reads the user agent; older versions get a stub instead.
	 *
	 * @param int $version Major Chromium version to report.
	 */
	private function set_chromium_version( $version ) {
		if ( function_exists( 'wp_get_chromium_major_version' ) ) {
			$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/' . $version . '.0.0.0 Safari/537.36';
			return;
		}

		eval( 'function wp_get_chromium_major_version() { return ' . (int) $version . '; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
	}

	/**
	 * Returns true when no Chromium version function exists (Firefox/Safari).
	 */
	public function test_returns_true_when_no_chromium_version_function() {
		// Without a Chromium user agent the version lookup returns null.
		$this->assertTrue( csme_should_use_coep_coop() );
	}

	/**
	 * Returns false when Chromium version is 137+ (DIP is used).
	 */
	public function test_returns_false_when_chromium_137_or_higher() {
		$this->set_chromium_version( 140 );

		$this->assertFalse( csme_should_use_coep_coop() );
	}

	/**
	 * Returns true when Chromium version is below 137 (no DIP support).
	 */
	public function test_returns_true_when_chromium_below_137() {
		$this->set_chromium_version( 130 );

		$this->assertTrue( csme_should_use_coep_coop() );
	}

	/**
	 * Falls back to the Gutenberg plugin's version function when core's is absent.
	 */
	public function test_falls_back_to_gutenberg_version_function() {
		if ( function_exists( 'wp_get_chromium_major_version' ) ) {
			$this->markTestSkipped( 'Core defines wp_get_chromium_major_version(), so the Gutenberg fallback is never reached.' );
		}

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
