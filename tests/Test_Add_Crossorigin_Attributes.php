<?php
/**
 * Tests for csme_add_crossorigin_to_images().
 *
 * @package ClientSideMediaEverywhere
 */

class Test_Add_Crossorigin_Attributes extends WP_UnitTestCase {

	/**
	 * Adds crossorigin="anonymous" to a cross-origin img tag.
	 */
	public function test_adds_crossorigin_to_cross_origin_img() {
		$html   = '<img src="https://external.example.com/photo.jpg" alt="photo">';
		$result = csme_add_crossorigin_to_images( $html );

		$this->assertStringContainsString( 'crossorigin="anonymous"', $result );
	}

	/**
	 * Does not add crossorigin to a same-origin img tag.
	 */
	public function test_does_not_add_crossorigin_to_same_origin_img() {
		$site_url = site_url();
		$html     = '<img src="' . $site_url . '/wp-content/uploads/photo.jpg" alt="photo">';
		$result   = csme_add_crossorigin_to_images( $html );

		$this->assertStringNotContainsString( 'crossorigin', $result );
	}

	/**
	 * Does not add crossorigin to a root-relative URL.
	 */
	public function test_does_not_add_crossorigin_to_relative_url() {
		$html   = '<img src="/wp-content/uploads/photo.jpg" alt="photo">';
		$result = csme_add_crossorigin_to_images( $html );

		$this->assertStringNotContainsString( 'crossorigin', $result );
	}

	/**
	 * Does not overwrite an existing crossorigin attribute.
	 */
	public function test_does_not_overwrite_existing_crossorigin() {
		$html   = '<img src="https://external.example.com/photo.jpg" crossorigin="use-credentials">';
		$result = csme_add_crossorigin_to_images( $html );

		$this->assertStringContainsString( 'crossorigin="use-credentials"', $result );
		$this->assertSame( 1, substr_count( $result, 'crossorigin' ) );
	}

	/**
	 * Does not modify non-IMG tags (function only processes IMG elements).
	 */
	public function test_does_not_modify_non_img_tags() {
		$html   = '<script src="https://cdn.example.com/app.js"></script>';
		$result = csme_add_crossorigin_to_images( $html );

		$this->assertStringNotContainsString( 'crossorigin', $result );
	}

	/**
	 * Does not modify unrelated tags.
	 */
	public function test_does_not_modify_unrelated_tags() {
		$html   = '<div class="container"><p>Hello</p></div>';
		$result = csme_add_crossorigin_to_images( $html );

		$this->assertSame( $html, $result );
	}

	/**
	 * Handles multiple img elements, only modifying cross-origin ones.
	 */
	public function test_handles_multiple_img_elements() {
		$site_url = site_url();
		$html     = '<img src="https://external.example.com/a.jpg"><img src="' . $site_url . '/b.jpg">';
		$result   = csme_add_crossorigin_to_images( $html );

		$this->assertSame( 1, substr_count( $result, 'crossorigin="anonymous"' ) );
	}

	/**
	 * Returns input unchanged when no tags need modification.
	 */
	public function test_returns_unchanged_when_nothing_to_modify() {
		$html   = '<p>Just some text</p>';
		$result = csme_add_crossorigin_to_images( $html );

		$this->assertSame( $html, $result );
	}

	/**
	 * Does not add crossorigin to an img tag without a src attribute.
	 */
	public function test_does_not_add_crossorigin_when_no_src() {
		$html   = '<img alt="placeholder">';
		$result = csme_add_crossorigin_to_images( $html );

		$this->assertStringNotContainsString( 'crossorigin', $result );
	}

	/**
	 * Adds crossorigin to protocol-relative cross-origin URLs.
	 */
	public function test_adds_crossorigin_to_protocol_relative_url() {
		$html   = '<img src="//cdn.example.com/photo.jpg" alt="photo">';
		$result = csme_add_crossorigin_to_images( $html );

		$this->assertStringContainsString( 'crossorigin="anonymous"', $result );
	}

	/**
	 * Adds crossorigin when srcset has a cross-origin candidate but src is same-origin.
	 */
	public function test_adds_crossorigin_for_cross_origin_srcset() {
		$site_url = site_url();
		$html     = '<img src="' . $site_url . '/a.jpg" srcset="' . $site_url . '/a.jpg 1x, https://cdn.example.com/a-2x.jpg 2x">';
		$result   = csme_add_crossorigin_to_images( $html );

		$this->assertStringContainsString( 'crossorigin="anonymous"', $result );
	}

	/**
	 * Adds crossorigin to an img with only a cross-origin srcset (no src).
	 */
	public function test_adds_crossorigin_for_srcset_only_img() {
		$html   = '<img srcset="https://cdn.example.com/a.jpg 1x, https://cdn.example.com/a-2x.jpg 2x">';
		$result = csme_add_crossorigin_to_images( $html );

		$this->assertStringContainsString( 'crossorigin="anonymous"', $result );
	}

	/**
	 * Does not add crossorigin when src and all srcset candidates are same-origin.
	 */
	public function test_does_not_add_crossorigin_for_same_origin_srcset() {
		$site_url = site_url();
		$html     = '<img src="' . $site_url . '/a.jpg" srcset="' . $site_url . '/a.jpg 1x, /uploads/a-2x.jpg 2x">';
		$result   = csme_add_crossorigin_to_images( $html );

		$this->assertStringNotContainsString( 'crossorigin', $result );
	}
}
