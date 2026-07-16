<?php
/**
 * Tests for csme_get_coep_mode().
 *
 * @package ClientSideMediaEverywhere
 */

class Test_Coep_Mode extends WP_UnitTestCase {

	/**
	 * COEP mode is require-corp on Safari.
	 */
	public function test_coep_mode_is_require_corp_on_safari() {
		global $is_safari;
		$is_safari = true;

		$this->assertSame( 'require-corp', csme_get_coep_mode() );
	}

	/**
	 * COEP mode is credentialless on non-Safari (e.g. Firefox).
	 */
	public function test_coep_mode_is_credentialless_on_non_safari() {
		global $is_safari;
		$is_safari = false;

		$this->assertSame( 'credentialless', csme_get_coep_mode() );
	}
}
