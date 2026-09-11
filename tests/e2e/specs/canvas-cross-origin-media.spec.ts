/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/*
 * wp-env runs a development site next to the tests site on another port, so
 * a request from the tests site to it is cross-origin without leaving the
 * machine. The `cors-fixture` mu-plugin makes one URL on it answer with
 * Access-Control-Allow-Origin; its static files send no CORS headers at all.
 */
const DEV_ORIGIN = `http://localhost:${ process.env.WP_ENV_PORT || '8888' }`;
const CORS_IMAGE = `${ DEV_ORIGIN }/?csme_e2e_cors_image=1`;
const NO_CORS_IMAGE = `${ DEV_ORIGIN }/wp-includes/images/media/default.png`;
const NO_CORS_AUDIO = `${ DEV_ORIGIN }/wp-includes/images/media/audio.png`;

test.describe( 'Cross-origin media in the editor canvas', () => {
	test.beforeEach( async ( { admin, browserName } ) => {
		test.skip(
			browserName === 'chromium',
			'The COEP/COOP script does not load on Chromium'
		);
		await admin.createNewPost();
	} );

	test( 'adds crossorigin to canvas media only under require-corp', async ( {
		editor,
		page,
		browserName,
	} ) => {
		await editor.insertBlock( {
			name: 'core/image',
			attributes: { url: NO_CORS_IMAGE, alt: 'Cross-origin image' },
		} );
		await editor.insertBlock( {
			name: 'core/audio',
			attributes: { src: NO_CORS_AUDIO },
		} );

		const img = editor.canvas.locator( `img[src="${ NO_CORS_IMAGE }"]` );
		const audio = editor.canvas.locator(
			`audio[src="${ NO_CORS_AUDIO }"]`
		);
		await expect( img ).toBeAttached();
		await expect( audio ).toBeAttached();

		if ( browserName === 'webkit' ) {
			await expect( img ).toHaveAttribute( 'crossorigin', 'anonymous' );
			await expect( audio ).toHaveAttribute( 'crossorigin', 'anonymous' );
		} else {
			// Give the observer a moment to (not) react.
			await page.waitForTimeout( 1000 );
			await expect( img ).not.toHaveAttribute( 'crossorigin' );
			await expect( audio ).not.toHaveAttribute( 'crossorigin' );
		}
	} );

	test( 'loads a CORS-enabled cross-origin image in the canvas on WebKit', async ( {
		editor,
		browserName,
	} ) => {
		test.skip(
			browserName !== 'webkit',
			'Only require-corp blocks the load'
		);

		await editor.insertBlock( {
			name: 'core/image',
			attributes: { url: CORS_IMAGE, alt: 'CORS image' },
		} );

		const img = editor.canvas.locator( `img[src="${ CORS_IMAGE }"]` );
		await expect( img ).toHaveAttribute( 'crossorigin', 'anonymous' );
		await expect
			.poll( () =>
				img.evaluate( ( el: HTMLImageElement ) => el.naturalWidth )
			)
			.toBeGreaterThan( 0 );
	} );

	test( 'keeps marking media after the canvas document is replaced', async ( {
		editor,
		page,
		pageUtils,
		browserName,
	} ) => {
		test.skip(
			browserName !== 'webkit',
			'Only require-corp adds the attribute'
		);

		await editor.insertBlock( {
			name: 'core/image',
			attributes: { url: NO_CORS_IMAGE, alt: 'First image' },
		} );
		await expect(
			editor.canvas.locator( `img[src="${ NO_CORS_IMAGE }"]` )
		).toHaveAttribute( 'crossorigin', 'anonymous' );

		// Switching to the code editor and back tears the canvas iframe down
		// and builds a new one with a new document.
		await pageUtils.pressKeys( 'secondary+M' );
		await expect(
			page.getByRole( 'textbox', { name: 'Type text or HTML' } )
		).toBeVisible();
		await pageUtils.pressKeys( 'secondary+M' );
		await expect(
			editor.canvas.locator( `img[src="${ NO_CORS_IMAGE }"]` )
		).toBeAttached();

		await editor.insertBlock( {
			name: 'core/image',
			attributes: { url: CORS_IMAGE, alt: 'Second image' },
		} );

		const first = editor.canvas.locator( `img[src="${ NO_CORS_IMAGE }"]` );
		const second = editor.canvas.locator( `img[src="${ CORS_IMAGE }"]` );
		await expect( first ).toHaveAttribute( 'crossorigin', 'anonymous' );
		await expect( second ).toHaveAttribute( 'crossorigin', 'anonymous' );
		await expect
			.poll( () =>
				second.evaluate( ( el: HTMLImageElement ) => el.naturalWidth )
			)
			.toBeGreaterThan( 0 );
	} );

	test( 'retries a blocked image a bounded number of times', async ( {
		editor,
		page,
		browserName,
	} ) => {
		test.skip( browserName !== 'webkit', 'Only require-corp retries' );

		// Only the canvas counts: the Image block also probes the URL from
		// the top frame on its own, and that fetch is not the plugin's.
		let requests = 0;
		page.on( 'request', ( request ) => {
			if (
				request.url() === NO_CORS_IMAGE &&
				request.frame() !== page.mainFrame()
			) {
				requests++;
			}
		} );

		await editor.insertBlock( {
			name: 'core/image',
			attributes: { url: NO_CORS_IMAGE, alt: 'Blocked image' },
		} );
		await expect(
			editor.canvas.locator( `img[src="${ NO_CORS_IMAGE }"]` )
		).toHaveAttribute( 'crossorigin', 'anonymous' );

		/*
		 * Three at most: the initial load, WebKit's own refetch when the
		 * crossorigin attribute changes, and the plugin's single retry.
		 */
		await page.waitForTimeout( 3000 );
		const settled = requests;
		expect( settled ).toBeGreaterThan( 0 );
		expect( settled ).toBeLessThanOrEqual( 3 );

		// Then make sure it stays settled.
		await page.waitForTimeout( 2000 );
		expect( requests ).toBe( settled );
	} );
} );
