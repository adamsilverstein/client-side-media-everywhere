/**
 * External dependencies
 */
import * as path from 'path';

/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const TEST_IMAGE_PATH = path.join( __dirname, '..', 'fixtures', 'test-image.jpg' );

const SCREENS = [
	{ name: 'Media Library grid', path: 'upload.php', query: 'mode=grid' },
	{ name: 'Add New Media File', path: 'media-new.php', query: '' },
];

test.describe( 'Client-side uploads on the media screens', () => {
	test.beforeEach( async ( { browserName } ) => {
		test.skip(
			browserName === 'chromium',
			'WordPress handles these screens itself with DIP on Chrome 137+'
		);
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllMedia();
	} );

	for ( const screen of SCREENS ) {
		test( `should isolate ${ screen.name } with COEP/COOP`, async ( {
			admin,
			page,
		} ) => {
			const responsePromise = page.waitForResponse(
				( resp ) =>
					resp.url().includes( `/wp-admin/${ screen.path }` ) &&
					resp.request().resourceType() === 'document' &&
					resp.status() === 200
			);

			await admin.visitAdminPage( screen.path, screen.query );

			const headers = ( await responsePromise ).headers();
			expect( headers[ 'cross-origin-opener-policy' ] ).toBe(
				'same-origin'
			);
			expect( headers[ 'cross-origin-embedder-policy' ] ).toBeTruthy();

			expect(
				await page.evaluate( () => window.crossOriginIsolated )
			).toBe( true );
		} );

		test( `should upload client-side from ${ screen.name }`, async ( {
			admin,
			page,
		} ) => {
			const classicUploads: string[] = [];
			page.on( 'request', ( request ) => {
				if ( request.url().includes( 'async-upload.php' ) ) {
					classicUploads.push( request.url() );
				}
			} );

			await admin.visitAdminPage( screen.path, screen.query );

			// The sub-sizes are generated in the browser and sideloaded one by one.
			const sideloadPromise = page.waitForResponse(
				( response ) =>
					response.request().method() === 'POST' &&
					/wp\/v2\/media\/\d+\/sideload/.test(
						decodeURIComponent( response.url() )
					),
				{ timeout: 60_000 }
			);

			await page
				.locator( 'input[type="file"]' )
				.first()
				.setInputFiles( TEST_IMAGE_PATH );

			expect( ( await sideloadPromise ).ok() ).toBe( true );

			expect( classicUploads ).toEqual( [] );
		} );

		test( `should warn before leaving ${ screen.name } mid-upload`, async ( {
			admin,
			page,
		} ) => {
			await admin.visitAdminPage( screen.path, screen.query );

			// Hold the first sideload so the upload stays in flight.
			let releaseSideload = () => {};
			const sideloadHeld = new Promise< void >( ( resolve ) => {
				page.route(
					( url ) =>
						/wp\/v2\/media\/\d+\/sideload/.test(
							decodeURIComponent( url.toString() )
						),
					async ( route ) => {
						resolve();
						await new Promise< void >( ( release ) => {
							releaseSideload = release;
						} );
						await route.continue();
					},
					{ times: 1 }
				);
			} );

			// Browsers only show the prompt after the user has interacted with the page.
			await page.locator( '.wrap h1' ).first().click();

			await page
				.locator( 'input[type="file"]' )
				.first()
				.setInputFiles( TEST_IMAGE_PATH );

			await sideloadHeld;

			const dialogPromise = page.waitForEvent( 'dialog' );
			await page.close( { runBeforeUnload: true } );
			const dialog = await dialogPromise;
			expect( dialog.type() ).toBe( 'beforeunload' );

			// Stay on the page and let the upload finish.
			await dialog.dismiss();
			releaseSideload();
			expect( page.isClosed() ).toBe( false );
		} );
	}
} );
