<?php
/**
 * Tests for csme_set_up_media_new_isolation().
 *
 * @package ClientSideMediaEverywhere
 */

class Test_Media_New_Isolation extends WP_UnitTestCase {

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
	 * The isolation setup is hooked on load-media-new.php.
	 */
	public function test_hooked_on_load_media_new() {
		$this->assertSame( 20, has_action( 'load-media-new.php', 'csme_set_up_media_new_isolation' ) );
	}

	/**
	 * No output buffer starts when no user is logged in.
	 */
	public function test_no_buffer_when_logged_out() {
		add_filter( 'csme_use_coep_coop', '__return_true' );

		wp_set_current_user( 0 );

		$ob_level_before = ob_get_level();
		csme_set_up_media_new_isolation();
		$ob_level_after = ob_get_level();

		$this->assertSame( $ob_level_before, $ob_level_after );
	}

	/**
	 * No output buffer starts when the user cannot upload files.
	 */
	public function test_no_buffer_when_user_cannot_upload() {
		add_filter( 'csme_use_coep_coop', '__return_true' );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$ob_level_before = ob_get_level();
		csme_set_up_media_new_isolation();
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

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		wp_deregister_script( 'wp-upload-media' );

		$ob_level_before = ob_get_level();
		csme_set_up_media_new_isolation();
		$ob_level_after = ob_get_level();

		$this->assertSame( $ob_level_before, $ob_level_after );
	}

	/**
	 * The COEP/COOP output buffer starts for a capable user.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_starts_coep_coop_buffer() {
		add_filter( 'csme_use_coep_coop', '__return_true' );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$ob_level_before = ob_get_level();
		csme_set_up_media_new_isolation();
		$ob_level_after = ob_get_level();

		$this->assertSame( $ob_level_before + 1, $ob_level_after );

		while ( ob_get_level() > $ob_level_before ) {
			ob_end_clean();
		}
	}

	/**
	 * The DIP output buffer starts when Chromium 137+ is detected.
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

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$ob_level_before = ob_get_level();
		csme_set_up_media_new_isolation();
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

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Neither core nor Gutenberg buffer functions exist in the base test env,
		// and COEP/COOP is filtered off, so nothing should start a buffer.
		if ( function_exists( 'wp_start_cross_origin_isolation_output_buffer' )
			|| function_exists( 'gutenberg_start_cross_origin_isolation_output_buffer' ) ) {
			$this->markTestSkipped( 'A DIP buffer function is defined in this environment.' );
		}

		$ob_level_before = ob_get_level();
		csme_set_up_media_new_isolation();
		$ob_level_after = ob_get_level();

		$this->assertSame( $ob_level_before, $ob_level_after );
	}
}
