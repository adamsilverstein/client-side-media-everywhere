<?php
/**
 * Tests for csme_add_crossorigin_attributes().
 *
 * @package ClientSideMediaEverywhere
 */

class Test_Add_Crossorigin_Attributes extends WP_UnitTestCase {

	/**
	 * Adds crossorigin="anonymous" to a cross-origin img tag.
	 */
	public function test_adds_crossorigin_to_cross_origin_img() {
		$html   = '<img src="https://external.example.com/photo.jpg" alt="photo">';
		$result = csme_add_crossorigin_attributes( $html );

		$this->assertStringContainsString( 'crossorigin="anonymous"', $result );
	}

	/**
	 * Does not add crossorigin to a same-origin img tag.
	 */
	public function test_does_not_add_crossorigin_to_same_origin_img() {
		$site_url = site_url();
		$html     = '<img src="' . $site_url . '/wp-content/uploads/photo.jpg" alt="photo">';
		$result   = csme_add_crossorigin_attributes( $html );

		$this->assertStringNotContainsString( 'crossorigin', $result );
	}

	/**
	 * Origins that differ only subtly from the site are still cross-origin.
	 *
	 * A string prefix match reads https://example.com.cdn.net as same-origin
	 * for a site at https://example.com, and misses a differing port, either
	 * of which leaves the resource unmarked and blocked under require-corp.
	 *
	 * @dataProvider data_cross_origin_lookalikes
	 *
	 * @param string $url URL to check.
	 */
	public function test_adds_crossorigin_to_lookalike_origins( $url ) {
		$result = csme_add_crossorigin_attributes( '<img src="' . $url . '" alt="photo">' );

		$this->assertStringContainsString( 'crossorigin="anonymous"', $result );
	}

	/**
	 * Data provider for origins that a prefix match would miss.
	 *
	 * @return array<string, array{string}>
	 */
	public function data_cross_origin_lookalikes() {
		$site_url = site_url();
		$host     = wp_parse_url( $site_url, PHP_URL_HOST );
		$scheme   = wp_parse_url( $site_url, PHP_URL_SCHEME );

		return array(
			'host prefix'     => array( $site_url . '.cdn.net/photo.jpg' ),
			'different port'  => array( $scheme . '://' . $host . ':8443/photo.jpg' ),
			'different host'  => array( $scheme . '://cdn.example.net/photo.jpg' ),
		);
	}

	/**
	 * The site URL itself, with nothing after it, is same-origin.
	 */
	public function test_does_not_add_crossorigin_to_bare_site_url() {
		$html   = '<img src="' . site_url() . '" alt="photo">';
		$result = csme_add_crossorigin_attributes( $html );

		$this->assertStringNotContainsString( 'crossorigin', $result );
	}

	/**
	 * A query or fragment directly after the site URL stays same-origin.
	 *
	 * @dataProvider data_same_origin_boundaries
	 *
	 * @param string $url URL to check.
	 */
	public function test_does_not_add_crossorigin_at_url_boundaries( $url ) {
		$result = csme_add_crossorigin_attributes( '<img src="' . $url . '" alt="photo">' );

		$this->assertStringNotContainsString( 'crossorigin', $result );
	}

	/**
	 * Data provider for same-origin boundary characters.
	 *
	 * @return array<string, array{string}>
	 */
	public function data_same_origin_boundaries() {
		$site_url = site_url();

		$host   = wp_parse_url( $site_url, PHP_URL_HOST );
		$scheme = wp_parse_url( $site_url, PHP_URL_SCHEME );

		return array(
			'path'             => array( $site_url . '/photo.jpg' ),
			'query'            => array( $site_url . '?p=1' ),
			'fragment'         => array( $site_url . '#top' ),
			// An explicit default port is the same origin as no port.
			'default port'     => array( $scheme . '://' . $host . ( 'https' === $scheme ? ':443' : ':80' ) . '/photo.jpg' ),
			// Outside the install directory, but still the site's own origin.
			'sibling path'     => array( $scheme . '://' . $host . '/elsewhere/photo.jpg' ),
			// Protocol-relative to the site's own host inherits its scheme.
			'protocol relative' => array( '//' . $host . '/photo.jpg' ),
		);
	}

	/**
	 * Does not add crossorigin to a root-relative URL.
	 */
	public function test_does_not_add_crossorigin_to_relative_url() {
		$html   = '<img src="/wp-content/uploads/photo.jpg" alt="photo">';
		$result = csme_add_crossorigin_attributes( $html );

		$this->assertStringNotContainsString( 'crossorigin', $result );
	}

	/**
	 * Does not overwrite an existing crossorigin attribute.
	 */
	public function test_does_not_overwrite_existing_crossorigin() {
		$html   = '<img src="https://external.example.com/photo.jpg" crossorigin="use-credentials">';
		$result = csme_add_crossorigin_attributes( $html );

		$this->assertStringContainsString( 'crossorigin="use-credentials"', $result );
		$this->assertSame( 1, substr_count( $result, 'crossorigin' ) );
	}

	/**
	 * Cross-origin scripts, styles, audio, and video get the attribute.
	 *
	 * @dataProvider data_cross_origin_elements
	 *
	 * @param string $html HTML input.
	 */
	public function test_adds_crossorigin_to_cross_origin_elements( $html ) {
		$result = csme_add_crossorigin_attributes( $html );

		$this->assertSame( 1, substr_count( $result, 'crossorigin="anonymous"' ) );
	}

	/**
	 * Data provider for cross-origin elements.
	 *
	 * @return array[]
	 */
	public function data_cross_origin_elements() {
		return array(
			'script'                 => array( '<script src="https://cdn.example.com/app.js"></script>' ),
			'link stylesheet'        => array( '<link rel="stylesheet" href="https://cdn.example.com/app.css">' ),
			'audio src'              => array( '<audio src="https://cdn.example.com/a.mp3"></audio>' ),
			'video src'              => array( '<video src="https://cdn.example.com/a.mp4"></video>' ),
			'video poster'           => array( '<video src="/a.mp4" poster="https://cdn.example.com/p.jpg"></video>' ),
			'protocol-relative img'  => array( '<img src="//cdn.example.com/photo.jpg" alt="photo">' ),
			'img srcset only'        => array( '<img srcset="https://cdn.example.com/a.jpg 1x, https://cdn.example.com/a-2x.jpg 2x">' ),
			'img cross-origin srcset' => array( '<img src="/a.jpg" srcset="/a.jpg 1x, https://cdn.example.com/a-2x.jpg 2x">' ),
		);
	}

	/**
	 * Same-origin, root-relative, and URL-less elements are left alone.
	 *
	 * @dataProvider data_same_origin_elements
	 *
	 * @param string $html HTML input.
	 */
	public function test_leaves_same_origin_elements_alone( $html ) {
		$this->assertSame( $html, csme_add_crossorigin_attributes( $html ) );
	}

	/**
	 * Data provider for same-origin elements.
	 *
	 * @return array[]
	 */
	public function data_same_origin_elements() {
		return array(
			'script'             => array( '<script src="/wp-includes/js/a.js"></script>' ),
			'img without src'    => array( '<img alt="placeholder">' ),
			'same-origin srcset' => array( '<img src="/a.jpg" srcset="/a.jpg 1x, /a-2x.jpg 2x">' ),
			'unrelated tags'     => array( '<div class="container"><p>Hello</p></div>' ),
			'text only'          => array( '<p>Just some text</p>' ),
		);
	}

	/**
	 * A cross-origin SOURCE marks its AUDIO or VIDEO parent, once, and the
	 * SOURCE elements themselves stay untouched.
	 */
	public function test_marks_media_parent_of_cross_origin_source() {
		$html   = '<video><source src="https://cdn.example.com/a.mp4"><source src="https://cdn.example.com/b.webm"></video>';
		$result = csme_add_crossorigin_attributes( $html );

		$this->assertStringStartsWith( '<video crossorigin="anonymous">', $result );
		$this->assertSame( 1, substr_count( $result, 'crossorigin' ) );
	}

	/**
	 * A TRACK between sources does not end the source list.
	 */
	public function test_track_does_not_end_source_list() {
		$html   = '<video><source src="/a.mp4"><track src="/t.vtt"><source src="https://cdn.example.com/b.webm"></video>';
		$result = csme_add_crossorigin_attributes( $html );

		$this->assertStringStartsWith( '<video crossorigin="anonymous">', $result );
	}

	/**
	 * A media element closed implicitly must not receive the attribute for
	 * a SOURCE that belongs to a later element.
	 */
	public function test_implied_close_does_not_leak_to_later_source() {
		$html   = '<div><video><source src="/a.mp4"></div><picture><source srcset="https://cdn.example.com/i.avif"><img src="/i.jpg"></picture>';
		$result = csme_add_crossorigin_attributes( $html );

		$this->assertSame( $html, $result );
	}

	/**
	 * Fallback content ends the source list, so a SOURCE after it is ignored.
	 */
	public function test_fallback_content_ends_source_list() {
		$html   = '<video><p>fallback</p><source src="https://cdn.example.com/b.mp4"></video>';
		$result = csme_add_crossorigin_attributes( $html );

		$this->assertSame( $html, $result );
	}

	/**
	 * A SOURCE outside any media element marks nothing.
	 */
	public function test_source_outside_media_element_marks_nothing() {
		$html   = '<picture><source srcset="https://cdn.example.com/i.avif"><img src="/i.jpg"></picture><audio src="/l.mp3"></audio>';
		$result = csme_add_crossorigin_attributes( $html );

		$this->assertSame( $html, $result );
	}

	/**
	 * Only the media element that owns the cross-origin SOURCE is marked, and
	 * an element that already has the attribute is left alone.
	 */
	public function test_marks_only_the_owning_media_element() {
		$html   = '<video><source src="/a.mp4"></video><p>x</p><video><source src="https://cdn.example.com/b.mp4"></video><video crossorigin="anonymous"><source src="https://cdn.example.com/c.mp4"></video>';
		$result = csme_add_crossorigin_attributes( $html );

		$this->assertSame(
			'<video><source src="/a.mp4"></video><p>x</p><video crossorigin="anonymous"><source src="https://cdn.example.com/b.mp4"></video><video crossorigin="anonymous"><source src="https://cdn.example.com/c.mp4"></video>',
			$result
		);
	}

	/**
	 * Marking parents never seeks backwards, so there is no seek budget to
	 * exhaust on a page with many media elements.
	 */
	public function test_marks_every_parent_on_a_large_page() {
		$html   = str_repeat( '<video><source src="https://cdn.example.com/a.mp4"></video>', 600 );
		$result = csme_add_crossorigin_attributes( $html );

		$this->assertSame( 600, substr_count( $result, '<video crossorigin="anonymous">' ) );
	}
}
