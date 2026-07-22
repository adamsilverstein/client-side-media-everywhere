<?php
/**
 * Tests for csme_enqueue_media_library_scripts() and settings.
 *
 * @package ClientSideMediaEverywhere
 */

class Test_Media_Library_Enqueue extends WP_UnitTestCase {

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
		unset( $_GET['mode'] );
		wp_dequeue_script( 'csme-media-library-upload' );
		wp_deregister_script( 'csme-media-library-upload' );

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
	 * Script is enqueued on upload.php in grid mode.
	 */
	public function test_script_enqueued_on_upload_php_grid() {
		$_GET['mode'] = 'grid';

		csme_enqueue_media_library_scripts( 'upload.php' );

		$this->assertTrue( wp_script_is( 'csme-media-library-upload', 'enqueued' ) );
	}

	/**
	 * Script is not enqueued on upload.php in list mode.
	 */
	public function test_script_not_enqueued_in_list_mode() {
		$_GET['mode'] = 'list';

		csme_enqueue_media_library_scripts( 'upload.php' );

		$this->assertFalse( wp_script_is( 'csme-media-library-upload', 'enqueued' ) );
	}

	/**
	 * Script is not enqueued on other admin pages.
	 */
	public function test_script_not_enqueued_on_other_pages() {
		$_GET['mode'] = 'grid';

		csme_enqueue_media_library_scripts( 'options-general.php' );

		$this->assertFalse( wp_script_is( 'csme-media-library-upload', 'enqueued' ) );
	}

	/**
	 * Script is not enqueued when the upload-media package is not registered.
	 */
	public function test_script_not_enqueued_without_upload_media() {
		$_GET['mode'] = 'grid';
		wp_deregister_script( 'wp-upload-media' );

		csme_enqueue_media_library_scripts( 'upload.php' );

		$this->assertFalse( wp_script_is( 'csme-media-library-upload', 'enqueued' ) );
	}

	/**
	 * The script depends on media-views and wp-upload-media, not wp-block-editor.
	 */
	public function test_dependencies() {
		$_GET['mode'] = 'grid';

		csme_enqueue_media_library_scripts( 'upload.php' );

		$script = wp_scripts()->registered['csme-media-library-upload'];
		$this->assertContains( 'media-views', $script->deps );
		$this->assertContains( 'wp-upload-media', $script->deps );
		$this->assertNotContains( 'wp-block-editor', $script->deps );
	}

	/**
	 * The inline settings expose all six pipeline keys.
	 */
	public function test_inline_settings_expose_all_keys() {
		$_GET['mode'] = 'grid';

		csme_enqueue_media_library_scripts( 'upload.php' );

		$before = wp_scripts()->get_data( 'csme-media-library-upload', 'before' );
		$inline = implode( "\n", (array) $before );

		$this->assertStringContainsString( 'window.csmeMediaLibrarySettings', $inline );

		foreach ( array(
			'maxUploadFileSize',
			'allowedMimeTypes',
			'allImageSizes',
			'bigImageSizeThreshold',
			'imageStripMeta',
			'imageMaxBitDepth',
		) as $key ) {
			$this->assertStringContainsString( $key, $inline );
		}
	}

	/**
	 * The big-image threshold in the settings responds to its filter.
	 */
	public function test_big_image_size_threshold_filter() {
		add_filter(
			'big_image_size_threshold',
			static function () {
				return 4096;
			}
		);

		$settings = csme_get_media_library_settings();

		$this->assertSame( 4096, $settings['bigImageSizeThreshold'] );
	}

	/**
	 * The settings array contains the expected value types.
	 */
	public function test_settings_value_types() {
		$settings = csme_get_media_library_settings();

		$this->assertIsInt( $settings['maxUploadFileSize'] );
		$this->assertIsArray( $settings['allowedMimeTypes'] );
		$this->assertIsArray( $settings['allImageSizes'] );
		$this->assertIsInt( $settings['bigImageSizeThreshold'] );
		$this->assertIsBool( $settings['imageStripMeta'] );
		$this->assertIsInt( $settings['imageMaxBitDepth'] );
	}
}
