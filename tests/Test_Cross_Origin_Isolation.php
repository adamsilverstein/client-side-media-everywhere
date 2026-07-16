<?php
/**
 * Tests for csme_set_up_cross_origin_isolation() guard checks.
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

		// Should not start an output buffer.
		$ob_level_before = ob_get_level();
		csme_set_up_cross_origin_isolation();
		$ob_level_after = ob_get_level();

		$this->assertSame( $ob_level_before, $ob_level_after );
	}

	/**
	 * Returns early when no screen is set.
	 */
	public function test_returns_early_when_no_screen() {
		add_filter( 'csme_use_coep_coop', '__return_true' );

		// Ensure no screen is set.
		$GLOBALS['current_screen'] = null;

		$ob_level_before = ob_get_level();
		csme_set_up_cross_origin_isolation();
		$ob_level_after = ob_get_level();

		$this->assertSame( $ob_level_before, $ob_level_after );
	}

	/**
	 * Returns early when screen is not a block editor.
	 */
	public function test_returns_early_when_not_block_editor() {
		add_filter( 'csme_use_coep_coop', '__return_true' );

		// Set up a non-editor screen.
		set_current_screen( 'options-general' );

		$ob_level_before = ob_get_level();
		csme_set_up_cross_origin_isolation();
		$ob_level_after = ob_get_level();

		$this->assertSame( $ob_level_before, $ob_level_after );
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

		$ob_level_before = ob_get_level();
		csme_set_up_cross_origin_isolation();
		$ob_level_after = ob_get_level();

		$this->assertSame( $ob_level_before, $ob_level_after );
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

		$ob_level_before = ob_get_level();
		csme_set_up_cross_origin_isolation();
		$ob_level_after = ob_get_level();

		$this->assertSame( $ob_level_before, $ob_level_after );
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

		$ob_level_before = ob_get_level();
		csme_set_up_cross_origin_isolation();
		$ob_level_after = ob_get_level();

		$this->assertSame( $ob_level_before, $ob_level_after );
	}

	/**
	 * Proceeds (starts output buffer) when all conditions are met.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_starts_output_buffer_when_all_conditions_met() {
		add_filter( 'csme_use_coep_coop', '__return_true' );

		// Set up a post edit screen.
		set_current_screen( 'post' );
		$_GET['action'] = 'edit';

		// Create an editor (has upload_files cap).
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$ob_level_before = ob_get_level();
		csme_set_up_cross_origin_isolation();
		$ob_level_after = ob_get_level();

		$this->assertSame( $ob_level_before + 1, $ob_level_after );

		// Clean up the output buffer without invoking the callback
		// (which would call header() and fail since output already started).
		while ( ob_get_level() > $ob_level_before ) {
			ob_end_clean();
		}
	}
}
