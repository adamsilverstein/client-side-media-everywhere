<?php
/**
 * Tests for the media template crossorigin normalization.
 *
 * @package ClientSideMediaEverywhere
 */

/**
 * Covers csme_filter_media_template_crossorigin() and csme_override_media_templates().
 */
class Test_Media_Templates extends WP_UnitTestCase {

	/**
	 * Loads the media templates and turns on the feature that gates them.
	 *
	 * `wp_enqueue_media()` normally pulls the template file in, and WordPress
	 * 7.1 only injects its own crossorigin attributes when client-side media
	 * processing is enabled, which needs a secure context the test suite does
	 * not have. Without the filter the printed-template tests would pass on
	 * 7.1 without ever meeting the attributes they exist to strip.
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . WPINC . '/media-template.php';

		add_filter( 'wp_client_side_media_processing_enabled', '__return_true' );
	}

	/**
	 * Wraps template markup in the script tag wp_print_media_templates() uses.
	 *
	 * @param string $inner Template markup.
	 * @return string The wrapped template.
	 */
	private function template( $inner ) {
		return '<script type="text/html" id="tmpl-attachment">' . $inner . '</script>';
	}

	/**
	 * Media tags in a template get the attribute under require-corp.
	 */
	public function test_adds_crossorigin_to_media_tags() {
		$html = $this->template( '<audio src="{{ data.url }}"></audio><video src="{{ data.url }}"></video><img src="{{ data.url }}" />' );

		$result = csme_filter_media_template_crossorigin( $html, true );

		$this->assertSame( 3, substr_count( $result, 'crossorigin="anonymous"' ) );
		$this->assertStringContainsString( '<audio crossorigin="anonymous" src="{{ data.url }}">', $result );
		$this->assertStringContainsString( '<video crossorigin="anonymous" src="{{ data.url }}">', $result );
		$this->assertStringContainsString( '<img crossorigin="anonymous" src="{{ data.url }}" />', $result );
	}

	/**
	 * Media tags in a template lose the attribute under credentialless.
	 */
	public function test_removes_crossorigin_from_media_tags() {
		$html = $this->template( '<audio crossorigin="anonymous" src="{{ data.url }}"></audio><video crossorigin="anonymous" src="{{ data.url }}"></video>' );

		$result = csme_filter_media_template_crossorigin( $html, false );

		$this->assertStringNotContainsString( 'crossorigin', $result );
	}

	/**
	 * A template that already matches the mode comes back byte for byte.
	 */
	public function test_leaves_matching_template_untouched() {
		$without = $this->template( '<audio src="{{ data.url }}"></audio>' );
		$with    = $this->template( '<audio crossorigin="anonymous" src="{{ data.url }}"></audio>' );

		$this->assertSame( $without, csme_filter_media_template_crossorigin( $without, false ) );
		$this->assertSame( $with, csme_filter_media_template_crossorigin( $with, true ) );
	}

	/**
	 * Applying the filter twice changes nothing the second time.
	 */
	public function test_is_idempotent() {
		$html = $this->template( '<video poster="{{ data.image }}" src="{{ data.url }}"></video>' );

		$once  = csme_filter_media_template_crossorigin( $html, true );
		$twice = csme_filter_media_template_crossorigin( $once, true );

		$this->assertSame( $once, $twice );
	}

	/**
	 * Only text/html script blocks are treated as templates.
	 */
	public function test_ignores_markup_outside_templates() {
		$html = '<video src="https://cdn.example.com/v.mp4"></video>'
			. '<script type="application/json">{"tag":"<video src=\'x\'>"}</script>'
			. $this->template( '<video src="{{ data.url }}"></video>' );

		$result = csme_filter_media_template_crossorigin( $html, true );

		$this->assertStringContainsString( '<video src="https://cdn.example.com/v.mp4">', $result );
		$this->assertStringContainsString( '{"tag":"<video src=\'x\'>"}', $result );
		$this->assertStringContainsString( '<video crossorigin="anonymous" src="{{ data.url }}">', $result );
	}

	/**
	 * Non-media tags in a template are left alone.
	 */
	public function test_leaves_other_tags_alone() {
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Template markup, not a real script tag.
		$html = $this->template( '<div class="attachment"><script src="{{ data.url }}"></script><link href="{{ data.url }}" /></div>' );

		$this->assertSame( $html, csme_filter_media_template_crossorigin( $html, true ) );
	}

	/**
	 * The printed templates carry the attribute under require-corp.
	 *
	 * Asserted against whatever wp_print_media_templates() actually emits, so
	 * it holds both on WordPress 7.1, which adds the attribute itself, and on
	 * the releases before and after it, which do not.
	 */
	public function test_printed_templates_carry_crossorigin_under_require_corp() {
		$this->assertSame( array(), $this->printed_media_tags_without_crossorigin( 'require-corp' ) );
	}

	/**
	 * The printed templates carry no attribute under credentialless.
	 */
	public function test_printed_templates_drop_crossorigin_under_credentialless() {
		$this->assertSame( array(), $this->printed_media_tags_with_crossorigin( 'credentialless' ) );
	}

	/**
	 * Prints the media templates through the plugin in the given COEP mode.
	 *
	 * @param string $mode Either 'require-corp' or 'credentialless'.
	 * @return string The printed templates.
	 */
	private function print_templates( $mode ) {
		$filter = static function () use ( $mode ) {
			return 'require-corp' === $mode;
		};

		// csme_get_coep_mode() reads $is_safari, which is what decides the mode.
		global $is_safari;
		$was_safari = $is_safari;
		$is_safari  = $filter();

		ob_start();
		csme_print_media_templates();
		$html = (string) ob_get_clean();

		$is_safari = $was_safari;

		return $html;
	}

	/**
	 * Media tags in the printed templates that are missing the attribute.
	 *
	 * @param string $mode Either 'require-corp' or 'credentialless'.
	 * @return string[] The offending tags.
	 */
	private function printed_media_tags_without_crossorigin( $mode ) {
		return $this->printed_media_tags( $mode, false );
	}

	/**
	 * Media tags in the printed templates that carry the attribute.
	 *
	 * @param string $mode Either 'require-corp' or 'credentialless'.
	 * @return string[] The offending tags.
	 */
	private function printed_media_tags_with_crossorigin( $mode ) {
		return $this->printed_media_tags( $mode, true );
	}

	/**
	 * Collects AUDIO and VIDEO template tags by whether they carry the attribute.
	 *
	 * @param string $mode     Either 'require-corp' or 'credentialless'.
	 * @param bool   $has_attr Whether to collect tags that have the attribute.
	 * @return string[] The matching tags, as template ids.
	 */
	private function printed_media_tags( $mode, $has_attr ) {
		$found = array();

		$script_processor = new WP_HTML_Tag_Processor( $this->print_templates( $mode ) );

		while ( $script_processor->next_tag( array( 'tag_name' => 'SCRIPT' ) ) ) {
			if ( 'text/html' !== $script_processor->get_attribute( 'type' ) ) {
				continue;
			}

			$id                 = (string) $script_processor->get_attribute( 'id' );
			$template_processor = new WP_HTML_Tag_Processor( $script_processor->get_modifiable_text() );

			while ( $template_processor->next_tag() ) {
				if ( ! in_array( $template_processor->get_tag(), array( 'AUDIO', 'VIDEO' ), true ) ) {
					continue;
				}

				if ( is_string( $template_processor->get_attribute( 'crossorigin' ) ) === $has_attr ) {
					$found[] = $id . ':' . $template_processor->get_tag();
				}
			}
		}

		return $found;
	}

	/**
	 * The override takes the admin_footer action over from core.
	 */
	public function test_override_replaces_the_core_action() {
		add_action( 'admin_footer', 'wp_print_media_templates' );

		csme_override_media_templates();

		$this->assertFalse( has_action( 'admin_footer', 'wp_print_media_templates' ) );
		$this->assertNotFalse( has_action( 'admin_footer', 'csme_print_media_templates' ) );

		remove_action( 'admin_footer', 'csme_print_media_templates' );
	}

	/**
	 * The override steps aside when something else already took the action.
	 *
	 * The Gutenberg plugin swaps the same action out for its own wrapper.
	 * Wrapping a wrapper would print the templates twice.
	 */
	public function test_override_steps_aside_when_action_is_gone() {
		remove_action( 'admin_footer', 'wp_print_media_templates' );

		csme_override_media_templates();

		$this->assertFalse( has_action( 'admin_footer', 'csme_print_media_templates' ) );
	}
}
