/**
 * External dependencies
 */
import * as path from 'path';

/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const TEST_IMAGE_PATH = path.join(
	__dirname,
	'..',
	'fixtures',
	'test-image.jpg'
);

// The plupload HTML5 runtime creates this hidden file input over the
// "Select Files" browse button; setting files on it triggers FilesAdded.
const FILE_INPUT_SELECTOR = '.moxie-shim-html5 input[type="file"]';

test.describe( 'Add New Media File client-side uploads', () => {
	test.afterEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllMedia();
	} );

	test( 'isolates the screen on Firefox and WebKit', async ( {
		page,
		browserName,
	} ) => {
		test.skip(
			browserName === 'chromium',
			'Chromium uses Document-Isolation-Policy, covered separately'
		);

		const responsePromise = page.waitForResponse(
			( resp ) =>
				resp.url().includes( '/wp-admin/media-new.php' ) &&
				resp.request().resourceType() === 'document' &&
				resp.status() === 200
		);

		await page.goto( '/wp-admin/media-new.php' );

		const headers = ( await responsePromise ).headers();
		expect( headers[ 'cross-origin-opener-policy' ] ).toBe( 'same-origin' );
		expect( headers[ 'cross-origin-embedder-policy' ] ).toMatch(
			/^(credentialless|require-corp)$/
		);

		const isolated = await page.evaluate( () =>
			Boolean( window.crossOriginIsolated )
		);
		expect( isolated ).toBe( true );
	} );

	test( 'sends the DIP header on Chromium', async ( {
		page,
		browserName,
	} ) => {
		test.skip(
			browserName !== 'chromium',
			'Only Chromium 137+ receives Document-Isolation-Policy'
		);

		const responsePromise = page.waitForResponse(
			( resp ) =>
				resp.url().includes( '/wp-admin/media-new.php' ) &&
				resp.request().resourceType() === 'document' &&
				resp.status() === 200
		);

		await page.goto( '/wp-admin/media-new.php' );

		const headers = ( await responsePromise ).headers();
		// Core does not isolate media-new.php, so the plugin starts the DIP
		// buffer here itself.
		expect( headers[ 'document-isolation-policy' ] ).toBe(
			'isolate-and-credentialless'
		);
	} );

	test( 'uploads an image through the client-side pipeline', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/media-new.php' );

		const isolated = await page.evaluate( () =>
			Boolean( window.crossOriginIsolated )
		);
		// Playwright's Chromium build lacks Document-Isolation-Policy, so
		// isolation is legitimately unavailable there and the pipeline
		// falls back to classic uploads. Only assert where isolation is real.
		test.skip(
			! isolated,
			'The client-side pipeline requires a cross-origin isolated context'
		);

		// The REST route may be a pretty permalink (/wp/v2/media) or the plain
		// form (index.php?rest_route=%2Fwp%2Fv2%2Fmedia), so match on the
		// decoded URL.
		let mediaCreateCount = 0;
		let sideloadCount = 0;
		let finalizeCount = 0;
		const asyncUploads: string[] = [];
		page.on( 'request', ( request ) => {
			if ( request.method() !== 'POST' ) {
				return;
			}
			const url = request.url();
			if ( url.includes( '/async-upload.php' ) ) {
				// The pipeline still POSTs to async-upload.php once per
				// upload to fetch the finished item markup (fetch=3, no file
				// payload); only file uploads must not go through it.
				const postData = request.postData() || '';
				if ( ! /(^|&)fetch=/.test( postData ) ) {
					asyncUploads.push( url );
				}
				return;
			}
			const decoded = decodeURIComponent( url );
			if ( /\/wp\/v2\/media\/\d+\/sideload/.test( decoded ) ) {
				sideloadCount++;
			} else if ( /\/wp\/v2\/media\/\d+\/finalize/.test( decoded ) ) {
				finalizeCount++;
			} else if ( /\/wp\/v2\/media(?:[?&]|$)/.test( decoded ) ) {
				mediaCreateCount++;
			}
		} );

		const fileInput = page.locator( FILE_INPUT_SELECTOR ).first();
		await fileInput.waitFor( { state: 'attached', timeout: 30_000 } );
		await fileInput.setInputFiles( TEST_IMAGE_PATH );

		// The finished attachment row renders with the Edit link fetched
		// from the async-upload.php markup endpoint.
		await expect(
			page.locator( '#media-items .media-item .edit-attachment' ).first()
		).toBeVisible( { timeout: 60_000 } );

		// The original upload and every sideload go through the REST API,
		// and the upload is finalized exactly once.
		expect( mediaCreateCount ).toBeGreaterThanOrEqual( 1 );
		expect( sideloadCount ).toBeGreaterThanOrEqual( 1 );
		expect( finalizeCount ).toBe( 1 );

		// No file upload goes through the classic async-upload.php endpoint.
		expect( asyncUploads ).toEqual( [] );
	} );

	test( 'warns before leaving while a pipeline upload is in flight', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/media-new.php' );

		const isolated = await page.evaluate( () =>
			Boolean( window.crossOriginIsolated )
		);
		test.skip(
			! isolated,
			'The client-side pipeline requires a cross-origin isolated context'
		);

		// Hold sideload requests so the upload stays in flight at a
		// deterministic point.
		const heldRoutes: Array< {
			continue: () => Promise< void >;
		} > = [];
		let holding = true;
		await page.route(
			( url ) => decodeURIComponent( url.href ).includes( '/sideload' ),
			async ( route ) => {
				if ( holding ) {
					heldRoutes.push( route );
					return;
				}
				await route.continue();
			}
		);
		const sideloadRequested = page.waitForRequest(
			( request ) =>
				decodeURIComponent( request.url() ).includes( '/sideload' ),
			{ timeout: 60_000 }
		);

		const fileInput = page.locator( FILE_INPUT_SELECTOR ).first();
		await fileInput.waitFor( { state: 'attached', timeout: 30_000 } );
		await fileInput.setInputFiles( TEST_IMAGE_PATH );
		await sideloadRequested;

		// A synthetic cancelable event exercises the guard's listener
		// without triggering the real (untestable) browser prompt.
		const preventedWhileUploading = await page.evaluate( () => {
			const event = new Event( 'beforeunload', { cancelable: true } );
			window.dispatchEvent( event );
			return event.defaultPrevented;
		} );
		expect( preventedWhileUploading ).toBe( true );

		// Release the held requests, let the upload finish, and verify the
		// guard disengages once nothing is in flight anymore.
		holding = false;
		for ( const route of heldRoutes ) {
			await route.continue();
		}
		await expect(
			page.locator( '#media-items .media-item .edit-attachment' ).first()
		).toBeVisible( { timeout: 60_000 } );

		const preventedAfterUpload = await page.evaluate( () => {
			const event = new Event( 'beforeunload', { cancelable: true } );
			window.dispatchEvent( event );
			return event.defaultPrevented;
		} );
		expect( preventedAfterUpload ).toBe( false );
	} );

	test( 'shows an error for a disallowed file type', async ( { page } ) => {
		await page.goto( '/wp-admin/media-new.php' );

		const isolated = await page.evaluate( () =>
			Boolean( window.crossOriginIsolated )
		);
		test.skip(
			! isolated,
			'The client-side pipeline requires a cross-origin isolated context'
		);

		const fileInput = page.locator( FILE_INPUT_SELECTOR ).first();
		await fileInput.waitFor( { state: 'attached', timeout: 30_000 } );
		await fileInput.setInputFiles( {
			name: 'disallowed.xyz',
			mimeType: 'application/octet-stream',
			buffer: Buffer.from( 'not an allowed file type' ),
		} );

		// The error surfaces either as a pipeline per-item error
		// (itemAjaxError renders .error-div inside the media item) or as a
		// plupload extension rejection (a .media-item.error element),
		// depending on which layer rejects the file first.
		await expect(
			page.locator( '.media-item .error-div, .media-item.error' ).first()
		).toBeVisible( { timeout: 30_000 } );
	} );
} );
