/**
 * External dependencies
 */
import * as path from 'path';

/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const TEST_IMAGE_PATH = path.join( __dirname, '..', 'fixtures', 'test-image.jpg' );

test.describe( 'Cross-Origin Isolation', () => {
	test( 'should be cross-origin isolated on Firefox', async ( {
		admin,
		page,
		browserName,
	} ) => {
		test.skip(
			browserName === 'chromium',
			'Chrome 137+ uses DIP instead of COEP/COOP'
		);

		await admin.createNewPost();

		const crossOriginIsolated = await page.evaluate( () => {
			return Boolean( window.crossOriginIsolated );
		} );
		expect( crossOriginIsolated ).toBe( true );
	} );

	test( 'should send COOP header on Firefox and WebKit', async ( {
		admin,
		page,
		browserName,
	} ) => {
		test.skip(
			browserName === 'chromium',
			'Chrome 137+ uses DIP instead of COEP/COOP'
		);

		const responsePromise = page.waitForResponse( ( resp ) =>
			resp.url().includes( '/wp-admin/post-new.php' ) &&
			resp.request().resourceType() === 'document' &&
			resp.status() === 200
		);

		await admin.createNewPost();

		const response = await responsePromise;
		const headers = response.headers();

		expect( headers[ 'cross-origin-opener-policy' ] ).toBe(
			'same-origin'
		);
	} );

	test( 'should send credentialless COEP on Firefox', async ( {
		admin,
		page,
		browserName,
	} ) => {
		test.skip( browserName !== 'firefox', 'Only Firefox uses credentialless COEP' );

		const responsePromise = page.waitForResponse( ( resp ) =>
			resp.url().includes( '/wp-admin/post-new.php' ) &&
			resp.request().resourceType() === 'document' &&
			resp.status() === 200
		);

		await admin.createNewPost();

		const response = await responsePromise;
		expect( response.headers()[ 'cross-origin-embedder-policy' ] ).toBe(
			'credentialless'
		);
	} );

	test( 'should send require-corp COEP on WebKit', async ( {
		admin,
		page,
		browserName,
	} ) => {
		test.skip( browserName !== 'webkit', 'Only WebKit/Safari uses require-corp COEP' );

		const responsePromise = page.waitForResponse( ( resp ) =>
			resp.url().includes( '/wp-admin/post-new.php' ) &&
			resp.request().resourceType() === 'document' &&
			resp.status() === 200
		);

		await admin.createNewPost();

		const response = await responsePromise;
		expect( response.headers()[ 'cross-origin-embedder-policy' ] ).toBe(
			'require-corp'
		);
	} );

	test( 'should not send COEP/COOP headers when DIP is active', async ( {
		page,
	} ) => {
		// Simulate Chrome 137+ Document-Isolation-Policy mode via filter.
		// Navigate directly with the force_dip query param.
		const response = await page.goto(
			'/wp-admin/post-new.php?csme_force_dip=1'
		);

		expect( response ).not.toBeNull();
		const headers = response!.headers();

		expect( headers[ 'cross-origin-opener-policy' ] ).toBeUndefined();
		expect( headers[ 'cross-origin-embedder-policy' ] ).toBeUndefined();
	} );

	test( 'should rely on the DIP header, not COEP/COOP, on Chromium', async ( {
		admin,
		page,
		browserName,
	} ) => {
		test.skip(
			browserName !== 'chromium',
			'Core sends Document-Isolation-Policy only on Chromium 137+'
		);

		const responsePromise = page.waitForResponse( ( resp ) =>
			resp.url().includes( '/wp-admin/post-new.php' ) &&
			resp.request().resourceType() === 'document' &&
			resp.status() === 200
		);

		await admin.createNewPost();

		const response = await responsePromise;
		const headers = response.headers();

		// Core's DIP header handles isolation; the plugin must stay inert.
		expect( headers[ 'document-isolation-policy' ] ).toBe(
			'isolate-and-credentialless'
		);
		expect( headers[ 'cross-origin-opener-policy' ] ).toBeUndefined();
		expect( headers[ 'cross-origin-embedder-policy' ] ).toBeUndefined();
	} );

	test( 'should set the __documentIsolationPolicy flag on Chromium', async ( {
		admin,
		page,
		browserName,
	} ) => {
		test.skip(
			browserName !== 'chromium',
			'Only Chromium supports Document-Isolation-Policy'
		);

		await admin.createNewPost();

		/*
		 * Note: window.crossOriginIsolated cannot be asserted here because
		 * Playwright's Chromium build does not ship Document-Isolation-Policy
		 * (Gutenberg's own E2E suite skips processing tests for the same
		 * reason). The DIP header and this PHP-side flag are the observable
		 * contract.
		 */
		const dipFlag = await page.evaluate( () => {
			return Boolean(
				( window as Window & { __documentIsolationPolicy?: boolean } )
					.__documentIsolationPolicy
			);
		} );
		expect( dipFlag ).toBe( true );
	} );

	test( 'should set __clientSideMediaProcessing flag on every browser', async ( {
		admin,
		page,
	} ) => {
		await admin.createNewPost();

		const flag = await page.evaluate( () => {
			return Boolean(
				( window as Window & { __clientSideMediaProcessing?: boolean } )
					.__clientSideMediaProcessing
			);
		} );
		expect( flag ).toBe( true );
	} );

	test( 'should expose SharedArrayBuffer on Firefox and WebKit', async ( {
		admin,
		page,
		browserName,
	} ) => {
		test.skip(
			browserName === 'chromium',
			'Chromium gets SharedArrayBuffer via DIP; this covers COEP/COOP'
		);

		await admin.createNewPost();

		// SharedArrayBuffer is the API the vips worker actually needs; it is
		// only available once the page is cross-origin isolated.
		const hasSharedArrayBuffer = await page.evaluate( () => {
			return typeof SharedArrayBuffer !== 'undefined';
		} );
		expect( hasSharedArrayBuffer ).toBe( true );
	} );

	test( 'should process an image upload client-side', async ( {
		admin,
		editor,
		page,
		browserName,
	} ) => {
		await admin.createNewPost();

		const fallbackMessages: string[] = [];
		page.on( 'console', ( message ) => {
			if (
				message.type() === 'info' &&
				message
					.text()
					.includes( 'Client-side media processing unavailable' )
			) {
				fallbackMessages.push( message.text() );
			}
		} );

		await editor.insertBlock( { name: 'core/image' } );

		const imageBlock = editor.canvas.locator(
			'role=document[name="Block: Image"i]'
		);
		await imageBlock
			.locator( 'input[type="file"]' )
			.setInputFiles( TEST_IMAGE_PATH );

		// Wait for the upload to finish: the img src switches from a blob:
		// URL to the final uploads URL.
		const image = imageBlock.locator( 'img[src]' );
		await expect( image ).toBeVisible( { timeout: 60_000 } );
		await expect
			.poll( async () => ( await image.getAttribute( 'src' ) ) ?? '', {
				timeout: 60_000,
			} )
			.toMatch( /\/wp-content\/uploads\// );

		/*
		 * The editor logs an info message and falls back to server-side
		 * processing when client-side support is missing. With the plugin's
		 * COEP/COOP isolation active on Firefox/WebKit it must not. Chromium
		 * is excluded because Playwright's build lacks DIP, so isolation is
		 * legitimately unavailable there and the upload falls back.
		 */
		if ( browserName !== 'chromium' ) {
			expect( fallbackMessages ).toEqual( [] );
		}
	} );

	test( 'should set __coepCoopIsolation JS flag', async ( {
		admin,
		page,
		browserName,
	} ) => {
		test.skip(
			browserName === 'chromium',
			'Chrome 137+ uses DIP instead of COEP/COOP'
		);

		await admin.createNewPost();

		const flag = await page.evaluate( () => {
			return Boolean(
				( window as Window & { __coepCoopIsolation?: boolean } )
					.__coepCoopIsolation
			);
		} );
		expect( flag ).toBe( true );
	} );

	test( 'should expose the COEP mode to JavaScript', async ( {
		admin,
		page,
		browserName,
	} ) => {
		await admin.createNewPost();

		const coepMode = await page.evaluate( () => {
			return ( window as Window & { __coepMode?: string } ).__coepMode;
		} );

		// Chromium uses DIP, so the COEP/COOP script (and flag) never loads.
		const expected = {
			chromium: undefined,
			firefox: 'credentialless',
			webkit: 'require-corp',
		}[ browserName ];
		expect( coepMode ).toBe( expected );
	} );

	test( 'should add crossorigin to dynamic images only under require-corp', async ( {
		admin,
		page,
		browserName,
	} ) => {
		test.skip(
			browserName === 'chromium',
			'The COEP/COOP script does not load on Chromium'
		);

		await admin.createNewPost();

		// Inject a cross-origin image and let the MutationObserver react.
		await page.evaluate( () => {
			const img = document.createElement( 'img' );
			img.id = 'csme-test-img';
			img.src = 'https://external.example.com/test.jpg';
			document.body.appendChild( img );
		} );

		if ( browserName === 'webkit' ) {
			/*
			 * Under require-corp (Safari), cross-origin images need a CORS
			 * request to load at all, so the attribute must be added.
			 */
			await page.waitForFunction( () => {
				const img = document.getElementById( 'csme-test-img' );
				return (
					img &&
					img.getAttribute( 'crossorigin' ) === 'anonymous'
				);
			} );
		} else {
			/*
			 * Under credentialless (Firefox), forcing CORS mode would break
			 * images from servers without CORS headers, so the observer must
			 * leave images alone. Give it a moment to (not) react.
			 */
			await page.waitForTimeout( 1000 );
			const crossorigin = await page.evaluate( () => {
				const img = document.getElementById( 'csme-test-img' );
				return img ? img.getAttribute( 'crossorigin' ) : 'missing';
			} );
			expect( crossorigin ).toBeNull();
		}
	} );

	test( 'should add crossorigin to dynamic non-image media elements', async ( {
		admin,
		page,
		browserName,
	} ) => {
		test.skip(
			browserName === 'chromium',
			'The COEP/COOP script does not load on Chromium'
		);

		await admin.createNewPost();

		// Non-IMG elements get the attribute in both COEP modes.
		await page.evaluate( () => {
			const video = document.createElement( 'video' );
			video.id = 'csme-test-video';
			video.preload = 'none';
			video.src = 'https://external.example.com/test.mp4';
			document.body.appendChild( video );
		} );

		await page.waitForFunction( () => {
			const video = document.getElementById( 'csme-test-video' );
			return (
				video && video.getAttribute( 'crossorigin' ) === 'anonymous'
			);
		} );
	} );

	test( 'should add credentialless attribute to iframes', async ( {
		admin,
		page,
		browserName,
	} ) => {
		test.skip(
			browserName !== 'firefox',
			'Only Firefox uses credentialless for iframes'
		);

		await admin.createNewPost();

		// Inject a plain iframe and let the MutationObserver add credentialless.
		await page.evaluate( () => {
			const iframe = document.createElement( 'iframe' );
			iframe.src = 'about:blank';
			document.body.appendChild( iframe );
		} );

		// Wait for the MutationObserver to add the credentialless attribute.
		await page.waitForFunction(
			() => {
				const iframes =
					document.querySelectorAll( 'iframe[src="about:blank"]' );
				const lastIframe = iframes[ iframes.length - 1 ];
				return lastIframe && lastIframe.hasAttribute( 'credentialless' );
			},
			{ timeout: 5000 }
		);

		// Re-query the attribute to verify.
		const result = await page.evaluate( () => {
			const iframes =
				document.querySelectorAll( 'iframe[src="about:blank"]' );
			const lastIframe = iframes[ iframes.length - 1 ];
			return lastIframe ? lastIframe.hasAttribute( 'credentialless' ) : false;
		} );

		expect( result ).toBe( true );
	} );
} );
