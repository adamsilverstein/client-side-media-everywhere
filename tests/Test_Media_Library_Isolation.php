<?php
/**
 * Tests for csme_set_up_media_library_isolation() and mode resolution.
 *
 * @package ClientSideMediaEverywhere
 */

class Test_Media_Library_Isolation extends WP_UnitTestCase {

	/**
	 * Preserved state for cleanup.
	 *
	 * @var array<string, mixed>
	 */
	private $preserved_state = array();

	/**
	 * Set up before each test: register a stub upload-media script.
	 */
	public function set_up() {
		parent::set_up();

		// Preserve existing wp-upload-media registration if any.
		$this->preserved_state['wp_upload_media'] = wp_scripts()->query( 'wp-upload-media', 'registered' );

		// Isolation is gated on the upload-media package being registered.
		wp_register_script( 'wp-upload-media', 'https://example.org/upload-media.js', array(), '1.0', true );
	}

	/**
	 * Tear down after each test.
	 */
	public function tear_down() {
		remove_all_filters( 'csme_use_coep_coop' );
		unset( $_GET['mode'] );
		unset( $_SERVER['HTTP_USER_AGENT'] );
		wp_set_current_user( 0 );

		// Restore pre-existing wp-upload-media registration or deregister.
		wp_deregister_script( 'wp-upload-media' );
		if ( false !== $this->preserved_state['wp_upload_media'] ) {
			$script = $this->preserved_state['wp_upload_media'];
			wp_register_script(
				'wp-upload-media',
				$script->src,
				$script->deps,
				$script->ver,
				$script->args
			);
		}

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
	 * A non-canonical query string mode is rejected, matching core.
	 *
	 * Core compares the raw value strictly, so `?mode=GRID` falls back
	 * to the user option rather than being normalized to `grid`.
	 */
	public function test_non_canonical_query_string_mode_falls_back_to_user_option() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		update_user_option( $user_id, 'media_library_mode', 'list' );

		$_GET['mode'] = 'GRID';

		$this->assertSame( 'list', csme_get_media_library_mode() );
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
	 * No output buffer starts when the upload-media package is missing.
	 *
	 * Without the package the pipeline cannot run, so no isolation
	 * headers should be sent either.
	 */
	public function test_no_buffer_without_upload_media_package() {
		add_filter( 'csme_use_coep_coop', '__return_true' );
		$_GET['mode'] = 'grid';

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		wp_deregister_script( 'wp-upload-media' );

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

		/*
		 * Core's real wp_start_cross_origin_isolation_output_buffer() (WP 7.1+)
		 * reads the User-Agent and only starts the buffer for Chromium 137+,
		 * so provide one rather than stubbing the version check.
		 */
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

		if ( ! function_exists( 'wp_start_cross_origin_isolation_output_buffer' ) ) {
			// Environments predating WP 7.1: stub the buffer function the
			// plugin dispatches to, honoring the same Chromium check.
			function wp_start_cross_origin_isolation_output_buffer() {
				if ( isset( $_SERVER['HTTP_USER_AGENT'] )
					&& preg_match( '#Chrome/(\d+)#', $_SERVER['HTTP_USER_AGENT'], $matches )
					&& (int) $matches[1] >= 137 ) {
					ob_start();
				}
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
