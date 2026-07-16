<?php
/**
 * Tests for csme_start_coep_coop_output_buffer() header output.
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

		csme_start_coep_coop_output_buffer();
		echo 'test output';
		ob_end_flush();

		$headers = $this->get_sent_headers();
		$this->assertContains( 'Cross-Origin-Opener-Policy: same-origin', $headers );
	}

	/**
	 * COEP header is require-corp on Safari.
	 */
	public function test_coep_header_is_require_corp_on_safari() {
		global $is_safari;
		$is_safari = true;

		csme_start_coep_coop_output_buffer();
		echo 'test output';
		ob_end_flush();

		$headers = $this->get_sent_headers();
		$this->assertContains( 'Cross-Origin-Embedder-Policy: require-corp', $headers );
	}

	/**
	 * COEP header is credentialless on non-Safari (e.g. Firefox).
	 */
	public function test_coep_header_is_credentialless_on_non_safari() {
		global $is_safari;
		$is_safari = false;

		csme_start_coep_coop_output_buffer();
		echo 'test output';
		ob_end_flush();

		$headers = $this->get_sent_headers();
		$this->assertContains( 'Cross-Origin-Embedder-Policy: credentialless', $headers );
	}

	/**
	 * Cross-origin images get crossorigin="anonymous" under require-corp (Safari).
	 */
	public function test_images_get_crossorigin_on_safari() {
		global $is_safari;
		$is_safari = true;

		ob_start();
		csme_start_coep_coop_output_buffer();
		echo '<img src="https://external.example.com/a.jpg">';
		ob_end_flush();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'crossorigin="anonymous"', $output );
	}

	/**
	 * Images are left alone under credentialless (Firefox), where forcing
	 * CORS mode would break images from servers without CORS headers.
	 */
	public function test_images_not_modified_on_non_safari() {
		global $is_safari;
		$is_safari = false;

		ob_start();
		csme_start_coep_coop_output_buffer();
		echo '<img src="https://external.example.com/a.jpg">';
		ob_end_flush();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'crossorigin', $output );
	}

	/**
	 * Helper to get sent headers as an array.
	 *
	 * @return array
	 */
	private function get_sent_headers() {
		if ( function_exists( 'xdebug_get_headers' ) ) {
			return xdebug_get_headers();
		}

		return headers_list();
	}
}
