/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

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

		const responsePromise = page.waitForEvent( 'response', ( resp ) =>
			resp.url().includes( '/wp-admin/post-new.php' )
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

		const responsePromise = page.waitForEvent( 'response', ( resp ) =>
			resp.url().includes( '/wp-admin/post-new.php' )
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

		const responsePromise = page.waitForEvent( 'response', ( resp ) =>
			resp.url().includes( '/wp-admin/post-new.php' )
		);

		await admin.createNewPost();

		const response = await responsePromise;
		expect( response.headers()[ 'cross-origin-embedder-policy' ] ).toBe(
			'require-corp'
		);
	} );

	test( 'should not send COEP/COOP headers on Chrome 137+', async ( {
		admin,
		page,
		browserName,
	} ) => {
		test.skip(
			browserName !== 'chromium',
			'Only relevant for Chrome 137+'
		);

		const responsePromise = page.waitForEvent( 'response', ( resp ) =>
			resp.url().includes( '/wp-admin/post-new.php' )
		);

		await admin.createNewPost();

		const response = await responsePromise;
		const headers = response.headers();

		expect( headers[ 'cross-origin-opener-policy' ] ).toBeUndefined();
		expect( headers[ 'cross-origin-embedder-policy' ] ).toBeUndefined();
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

	test( 'should add credentialless attribute to iframes', async ( {
		admin,
		page,
		browserName,
	} ) => {
		test.skip(
			browserName === 'chromium',
			'Chrome 137+ uses DIP instead of COEP/COOP'
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
