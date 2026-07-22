<?php
/**
 * Tests for csme_enqueue_media_new_scripts().
 *
 * @package ClientSideMediaEverywhere
 */

class Test_Media_New_Enqueue extends WP_UnitTestCase {

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

		// The integration requires core's (or Gutenberg's) upload-media
		// package to be registered. Register a stub for the test.
		wp_register_script( 'wp-upload-media', 'https://example.org/upload-media.js', array(), '1.0', true );
	}

	/**
	 * Tear down after each test.
	 */
	public function tear_down() {
		wp_dequeue_script( 'csme-media-new-upload' );
		wp_deregister_script( 'csme-media-new-upload' );

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
	 * The enqueue callback is hooked on admin_enqueue_scripts.
	 */
	public function test_hooked_on_admin_enqueue_scripts() {
		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', 'csme_enqueue_media_new_scripts' ) );
	}

	/**
	 * Script is enqueued on media-new.php.
	 */
	public function test_script_enqueued_on_media_new_php() {
		csme_enqueue_media_new_scripts( 'media-new.php' );

		$this->assertTrue( wp_script_is( 'csme-media-new-upload', 'enqueued' ) );
	}

	/**
	 * Script is not enqueued on other admin pages.
	 */
	public function test_script_not_enqueued_on_other_pages() {
		csme_enqueue_media_new_scripts( 'upload.php' );
		csme_enqueue_media_new_scripts( 'options-general.php' );

		$this->assertFalse( wp_script_is( 'csme-media-new-upload', 'enqueued' ) );
	}

	/**
	 * Script is not enqueued when the upload-media package is not registered.
	 */
	public function test_script_not_enqueued_without_upload_media() {
		wp_deregister_script( 'wp-upload-media' );

		csme_enqueue_media_new_scripts( 'media-new.php' );

		$this->assertFalse( wp_script_is( 'csme-media-new-upload', 'enqueued' ) );
	}

	/**
	 * The script depends on plupload-handlers and wp-upload-media, not
	 * wp-block-editor or media-views (which media-new.php never loads).
	 */
	public function test_dependencies() {
		csme_enqueue_media_new_scripts( 'media-new.php' );

		$script = wp_scripts()->registered['csme-media-new-upload'];
		$this->assertContains( 'plupload-handlers', $script->deps );
		$this->assertContains( 'wp-upload-media', $script->deps );
		$this->assertNotContains( 'wp-block-editor', $script->deps );
		$this->assertNotContains( 'media-views', $script->deps );
	}

	/**
	 * The inline settings are exactly the JSON encoding of the shared
	 * grid settings, under the media-new global.
	 */
	public function test_inline_settings_match_shared_settings() {
		csme_enqueue_media_new_scripts( 'media-new.php' );

		$before = wp_scripts()->get_data( 'csme-media-new-upload', 'before' );
		$inline = implode( "\n", (array) $before );

		$this->assertStringContainsString(
			'window.csmeMediaNewSettings = ' . wp_json_encode( csme_get_media_library_settings() ) . ';',
			$inline
		);
	}
}
