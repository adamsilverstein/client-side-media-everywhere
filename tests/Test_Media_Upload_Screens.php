<?php
/**
 * Tests for isolating the media screens and enqueueing core's upload pipeline there.
 *
 * @package ClientSideMediaEverywhere
 */

class Test_Media_Upload_Screens extends WP_UnitTestCase {

	/**
	 * Skips on WordPress versions without the media upload pipeline (before 7.2).
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! csme_has_core_media_upload_pipeline() ) {
			$this->markTestSkipped( 'Requires the media upload pipeline from WordPress 7.2.' );
		}

		// Core only registers the pipeline scripts in the admin.
		set_current_screen( 'upload' );
		$GLOBALS['wp_scripts'] = null;

		add_filter( 'csme_use_coep_coop', '__return_true' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
	}

	/**
	 * Tear down after each test.
	 */
	public function tear_down(): void {
		remove_all_filters( 'csme_use_coep_coop' );
		unset( $_GET['mode'], $_GET['action'] );
		$GLOBALS['current_screen'] = null;
		$GLOBALS['wp_scripts']     = null;
		parent::tear_down();
	}

	/**
	 * Returns the inline script printed before the pipeline script.
	 */
	private function get_pipeline_inline_script(): string {
		$before = wp_scripts()->get_data( 'media-upload-pipeline', 'before' );

		return is_array( $before ) ? implode( "\n", $before ) : '';
	}

	/**
	 * The "Add New Media File" screen is isolated.
	 */
	public function test_media_new_screen_is_isolated(): void {
		set_current_screen( 'media' );

		$this->assertTrue( csme_should_set_up_cross_origin_isolation() );
	}

	/**
	 * The Media Library is isolated in grid mode.
	 */
	public function test_media_library_grid_is_isolated(): void {
		$_GET['mode'] = 'grid';
		set_current_screen( 'upload' );

		$this->assertTrue( csme_should_set_up_cross_origin_isolation() );
	}

	/**
	 * The Media Library is left alone in list mode, which has no uploader.
	 */
	public function test_media_library_list_is_not_isolated(): void {
		$_GET['mode'] = 'list';
		set_current_screen( 'upload' );

		$this->assertFalse( csme_should_set_up_cross_origin_isolation() );
	}

	/**
	 * The page builder guard on `action` does not apply to the media screens.
	 */
	public function test_action_parameter_does_not_block_media_screens(): void {
		$_GET['mode']   = 'grid';
		$_GET['action'] = 'delete';
		set_current_screen( 'upload' );

		$this->assertTrue( csme_should_set_up_cross_origin_isolation() );
	}

	/**
	 * Users who cannot upload do not get isolated media screens.
	 */
	public function test_media_screens_require_upload_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		set_current_screen( 'media' );

		$this->assertFalse( csme_should_set_up_cross_origin_isolation() );
	}

	/**
	 * The media frame script is enqueued with the pipeline settings.
	 */
	public function test_media_frame_upload_is_enqueued_with_settings(): void {
		set_current_screen( 'upload' );

		csme_enqueue_media_frame_upload();

		$this->assertTrue( wp_script_is( 'media-frame-upload', 'enqueued' ) );

		$inline = $this->get_pipeline_inline_script();
		$this->assertStringContainsString( 'window._wpMediaUploadPipelineSettings = ', $inline );
		$this->assertStringContainsString( '"isBlockEditor":false', $inline );
	}

	/**
	 * The settings are only added once when both screen scripts are enqueued.
	 */
	public function test_pipeline_settings_are_added_once(): void {
		set_current_screen( 'media' );

		csme_enqueue_media_frame_upload();
		csme_enqueue_media_new_upload( 'media-new.php' );

		$this->assertSame( 1, substr_count( $this->get_pipeline_inline_script(), '_wpMediaUploadPipelineSettings' ) );
	}

	/**
	 * The "Add New Media File" script is only enqueued on its own screen.
	 */
	public function test_media_new_upload_is_only_enqueued_on_media_new(): void {
		set_current_screen( 'upload' );

		csme_enqueue_media_new_upload( 'upload.php' );
		$this->assertFalse( wp_script_is( 'media-new-upload', 'enqueued' ) );

		csme_enqueue_media_new_upload( 'media-new.php' );
		$this->assertTrue( wp_script_is( 'media-new-upload', 'enqueued' ) );
	}
}
