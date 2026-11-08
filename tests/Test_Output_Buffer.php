<?php
/**
 * Tests for the COEP/COOP headers and the require-corp output buffer.
 *
 * @package ClientSideMediaEverywhere
 */

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Test_Output_Buffer extends WP_UnitTestCase {

	/**
	 * COOP header is always same-origin.
	 */
	public function test_coop_header_is_same_origin() {
		global $is_safari;
		$is_safari = false;

		csme_send_coep_coop_headers();

		$headers = $this->get_sent_headers();
		$this->assertContains( 'Cross-Origin-Opener-Policy: same-origin', $headers );
	}

	/**
	 * COEP header is require-corp on Safari.
	 */
	public function test_coep_header_is_require_corp_on_safari() {
		global $is_safari;
		$is_safari = true;

		$this->assertSame( 'require-corp', csme_send_coep_coop_headers() );

		$headers = $this->get_sent_headers();
		$this->assertContains( 'Cross-Origin-Embedder-Policy: require-corp', $headers );
	}

	/**
	 * COEP header is credentialless on non-Safari (e.g. Firefox).
	 */
	public function test_coep_header_is_credentialless_on_non_safari() {
		global $is_safari;
		$is_safari = false;

		$this->assertSame( 'credentialless', csme_send_coep_coop_headers() );

		$headers = $this->get_sent_headers();
		$this->assertContains( 'Cross-Origin-Embedder-Policy: credentialless', $headers );
	}

	/**
	 * The require-corp output buffer adds crossorigin="anonymous" to
	 * cross-origin resources of every kind.
	 */
	public function test_output_buffer_adds_crossorigin() {
		ob_start();
		csme_start_crossorigin_output_buffer();
		echo '<img src="https://external.example.com/a.jpg"><script src="https://external.example.com/a.js"></script><video><source src="https://external.example.com/a.mp4"></video>';
		ob_end_flush();
		$output = ob_get_clean();

		$this->assertSame( 3, substr_count( $output, 'crossorigin="anonymous"' ) );
		$this->assertStringContainsString( '<source src="https://external.example.com/a.mp4">', $output, 'SOURCE elements must not receive the attribute themselves.' );
	}

	/**
	 * Helper to get sent headers as an array.
	 *
	 * headers_list() always returns an empty array under the CLI SAPI, so
	 * without Xdebug sent headers cannot be observed and the test is skipped.
	 *
	 * @return array
	 */
	private function get_sent_headers() {
		if ( ! function_exists( 'xdebug_get_headers' ) ) {
			$this->markTestSkipped( 'Xdebug is required to inspect sent headers under the CLI SAPI.' );
		}

		return xdebug_get_headers();
	}
}
