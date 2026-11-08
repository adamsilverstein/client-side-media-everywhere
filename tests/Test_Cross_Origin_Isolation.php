<?php
/**
 * Tests for the cross-origin isolation guard checks and set-up.
 *
 * @package ClientSideMediaEverywhere
 */

class Test_Cross_Origin_Isolation extends WP_UnitTestCase {

	/**
	 * Tear down after each test.
	 */
	public function tear_down() {
		remove_all_filters( 'csme_use_coep_coop' );
		unset( $_GET['action'] );
		$GLOBALS['current_screen'] = null;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Returns early when csme_should_use_coep_coop() returns false.
	 */
	public function test_returns_early_when_should_use_coep_coop_is_false() {
		add_filter( 'csme_use_coep_coop', '__return_false' );

		$this->assertFalse( csme_should_set_up_cross_origin_isolation() );
	}

	/**
	 * Returns early when no screen is set.
	 */
	public function test_returns_early_when_no_screen() {
		add_filter( 'csme_use_coep_coop', '__return_true' );

		// Ensure no screen is set.
		$GLOBALS['current_screen'] = null;

		$this->assertFalse( csme_should_set_up_cross_origin_isolation() );
	}

	/**
	 * Returns early when screen is not a block editor.
	 */
	public function test_returns_early_when_not_block_editor() {
		add_filter( 'csme_use_coep_coop', '__return_true' );

		// Set up a non-editor screen.
		set_current_screen( 'options-general' );

		$this->assertFalse( csme_should_set_up_cross_origin_isolation() );
	}

	/**
	 * Returns early when $_GET['action'] is not 'edit' (third-party editor skip).
	 */
	public function test_returns_early_when_action_is_not_edit() {
		add_filter( 'csme_use_coep_coop', '__return_true' );

		// Set up a post edit screen.
		set_current_screen( 'post' );

		// Simulate a third-party page builder action.
		$_GET['action'] = 'elementor';

		$this->assertFalse( csme_should_set_up_cross_origin_isolation() );
	}

	/**
	 * Returns early when no user is logged in.
	 */
	public function test_returns_early_when_no_user_logged_in() {
		add_filter( 'csme_use_coep_coop', '__return_true' );

		// Set up a post edit screen.
		set_current_screen( 'post' );
		$_GET['action'] = 'edit';

		// Ensure no user is logged in.
		wp_set_current_user( 0 );

		$this->assertFalse( csme_should_set_up_cross_origin_isolation() );
	}

	/**
	 * Returns early when user has no upload_files capability.
	 */
	public function test_returns_early_when_user_cannot_upload() {
		add_filter( 'csme_use_coep_coop', '__return_true' );

		// Set up a post edit screen.
		set_current_screen( 'post' );
		$_GET['action'] = 'edit';

		// Create a subscriber (no upload_files cap).
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$this->assertFalse( csme_should_set_up_cross_origin_isolation() );
	}

	/**
	 * Sends the headers without buffering under credentialless (Firefox),
	 * where no crossorigin attributes are needed.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_sends_headers_without_buffer_under_credentialless() {
		global $is_safari;
		$is_safari = false;

		add_filter( 'csme_use_coep_coop', '__return_true' );
		set_current_screen( 'post' );
		$_GET['action'] = 'edit';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$ob_level_before = ob_get_level();

		$this->assertTrue( csme_should_set_up_cross_origin_isolation() );
		csme_set_up_cross_origin_isolation();
		$this->assertSame( $ob_level_before, ob_get_level(), 'No output buffer should be started under credentialless.' );
	}

	/**
	 * Sends the headers and starts the crossorigin output buffer under
	 * require-corp (Safari).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_starts_output_buffer_under_require_corp() {
		global $is_safari;
		$is_safari = true;

		add_filter( 'csme_use_coep_coop', '__return_true' );
		set_current_screen( 'post' );
		$_GET['action'] = 'edit';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$ob_level_before = ob_get_level();

		$this->assertTrue( csme_should_set_up_cross_origin_isolation() );
		csme_set_up_cross_origin_isolation();
		$this->assertSame( $ob_level_before + 1, ob_get_level(), 'The crossorigin output buffer should be started under require-corp.' );

		while ( ob_get_level() > $ob_level_before ) {
			ob_end_clean();
		}
	}
}
