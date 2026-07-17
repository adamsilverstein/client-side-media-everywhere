<?php
/**
 * Tests for csme_set_up_media_library_isolation() and mode resolution.
 *
 * @package ClientSideMediaEverywhere
 */

class Test_Media_Library_Isolation extends WP_UnitTestCase {

	/**
	 * Tear down after each test.
	 */
	public function tear_down() {
		remove_all_filters( 'csme_use_coep_coop' );
		unset( $_GET['mode'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Grid mode is the default when nothing is set.
	 */
	public function test_mode_defaults_to_grid() {
		$this->assertSame( 'grid', csme_get_media_library_mode() );
	}

	/**
	 * Mode is read from the query string when valid.
	 */
	public function test_mode_from_query_string() {
		$_GET['mode'] = 'list';
		$this->assertSame( 'list', csme_get_media_library_mode() );
	}

	/**
	 * An invalid query string mode falls back to the default.
	 */
	public function test_invalid_query_string_mode_falls_back_to_grid() {
		$_GET['mode'] = 'bogus';
		$this->assertSame( 'grid', csme_get_media_library_mode() );
	}

	/**
	 * Mode is read from the user option when no query string is present.
	 */
	public function test_mode_from_user_option() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		update_user_option( $user_id, 'media_library_mode', 'list' );

		$this->assertSame( 'list', csme_get_media_library_mode() );
	}

	/**
	 * No output buffer starts in list mode.
	 */
	public function test_no_buffer_in_list_mode() {
		add_filter( 'csme_use_coep_coop', '__return_true' );
		$_GET['mode'] = 'list';

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$ob_level_before = ob_get_level();
		csme_set_up_media_library_isolation();
		$ob_level_after = ob_get_level();

		$this->assertSame( $ob_level_before, $ob_level_after );
	}

	/**
	 * No output buffer starts when no user is logged in.
	 */
	public function test_no_buffer_when_logged_out() {
		add_filter( 'csme_use_coep_coop', '__return_true' );
		$_GET['mode'] = 'grid';

		wp_set_current_user( 0 );

		$ob_level_before = ob_get_level();
		csme_set_up_media_library_isolation();
		$ob_level_after = ob_get_level();

		$this->assertSame( $ob_level_before, $ob_level_after );
	}

	/**
	 * No output buffer starts when the user cannot upload files.
	 */
	public function test_no_buffer_when_user_cannot_upload() {
		add_filter( 'csme_use_coep_coop', '__return_true' );
		$_GET['mode'] = 'grid';

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$ob_level_before = ob_get_level();
		csme_set_up_media_library_isolation();
		$ob_level_after = ob_get_level();

		$this->assertSame( $ob_level_before, $ob_level_after );
	}

	/**
	 * The COEP/COOP output buffer starts in grid mode for a capable user.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_starts_coep_coop_buffer_in_grid_mode() {
		add_filter( 'csme_use_coep_coop', '__return_true' );
		$_GET['mode'] = 'grid';

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$ob_level_before = ob_get_level();
		csme_set_up_media_library_isolation();
		$ob_level_after = ob_get_level();

		$this->assertSame( $ob_level_before + 1, $ob_level_after );

		while ( ob_get_level() > $ob_level_before ) {
			ob_end_clean();
		}
	}

	/**
	 * The DIP output buffer starts in grid mode when Chromium 137+ is detected.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_starts_dip_buffer_for_chromium() {
		// Force the DIP path: COEP/COOP disabled and a modern Chromium version.
		add_filter( 'csme_use_coep_coop', '__return_false' );

		if ( ! function_exists( 'wp_get_chromium_major_version' ) ) {
			function wp_get_chromium_major_version() {
				return 140;
			}
		}
		if ( ! function_exists( 'wp_start_cross_origin_isolation_output_buffer' ) ) {
			function wp_start_cross_origin_isolation_output_buffer() {
				ob_start();
			}
		}

		$_GET['mode'] = 'grid';

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$ob_level_before = ob_get_level();
		csme_set_up_media_library_isolation();
		$ob_level_after = ob_get_level();

		$this->assertSame( $ob_level_before + 1, $ob_level_after );

		while ( ob_get_level() > $ob_level_before ) {
			ob_end_clean();
		}
	}

	/**
	 * No buffer starts on the DIP path when core's buffer function is absent.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_no_buffer_when_no_dip_function_available() {
		add_filter( 'csme_use_coep_coop', '__return_false' );
		$_GET['mode'] = 'grid';

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Neither core nor Gutenberg buffer functions exist in the base test env,
		// and COEP/COOP is filtered off, so nothing should start a buffer.
		if ( function_exists( 'wp_start_cross_origin_isolation_output_buffer' )
			|| function_exists( 'gutenberg_start_cross_origin_isolation_output_buffer' ) ) {
			$this->markTestSkipped( 'A DIP buffer function is defined in this environment.' );
		}

		$ob_level_before = ob_get_level();
		csme_set_up_media_library_isolation();
		$ob_level_after = ob_get_level();

		$this->assertSame( $ob_level_before, $ob_level_after );
	}
}
