<?php
/**
 * Tests for the plugin settings registration.
 *
 * @package ClientSideMediaExperiments
 */

class Test_Settings extends WP_UnitTestCase {

	/**
	 * Tear down after each test.
	 */
	public function tear_down() {
		delete_option( 'csme_enabled' );
		remove_all_filters( 'csme_use_coep_coop' );
		parent::tear_down();
	}

	/**
	 * The csme_enabled option defaults to 1 regardless of browser.
	 *
	 * Browser (DIP) detection happens at runtime in csme_should_use_coep_coop(),
	 * so the persisted option default must not depend on the User-Agent.
	 */
	public function test_enabled_option_defaults_to_1() {
		csme_register_settings();

		$registered = get_registered_settings();

		$this->assertArrayHasKey( 'csme_enabled', $registered );
		$this->assertSame( 1, $registered['csme_enabled']['default'] );
	}

	/**
	 * csme_maybe_disable_coep_coop() returns false when the option is off.
	 */
	public function test_option_off_disables_coep_coop() {
		update_option( 'csme_enabled', 0 );

		$this->assertFalse( csme_should_use_coep_coop() );
	}

	/**
	 * csme_maybe_disable_coep_coop() passes the value through when the option is on.
	 */
	public function test_option_on_keeps_coep_coop() {
		update_option( 'csme_enabled', 1 );

		$this->assertTrue( csme_should_use_coep_coop() );
	}
}
