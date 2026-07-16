/**
 * Cross-origin isolation support for COEP/COOP mode.
 *
 * Handles credentialless iframes, crossorigin attributes on dynamically
 * added elements, and embed preview filtering for browsers using
 * COEP/COOP-based cross-origin isolation.
 *
 * Only runs when the page is cross-origin isolated via COEP/COOP
 * (indicated by the __coepCoopIsolation flag set by the PHP side).
 */

/* global wp */

( function () {
	if ( ! window.crossOriginIsolated || ! window.__coepCoopIsolation ) {
		return;
	}

	/**
	 * Adds crossorigin="anonymous" and credentialless attributes to elements.
	 *
	 * IMG elements only get the attribute under require-corp (Safari):
	 * crossorigin="anonymous" forces CORS-mode fetches, which breaks
	 * cross-origin images from servers without CORS headers. Under
	 * credentialless those images load fine without the attribute.
	 *
	 * @param {Element} el The element to modify.
	 */
	function addCrossOriginAttributes( el ) {
		if (
			el.nodeName === 'IMG' &&
			window.__coepMode !== 'require-corp'
		) {
			return;
		}

		if ( ! el.hasAttribute( 'crossorigin' ) ) {
			el.setAttribute( 'crossorigin', 'anonymous' );
		}

		// For iframes, add the credentialless attribute.
		if (
			el.nodeName === 'IFRAME' &&
			! el.hasAttribute( 'credentialless' )
		) {
			// Do not modify the iframed editor canvas.
			if (
				el.getAttribute( 'src' ) &&
				el.getAttribute( 'src' ).indexOf( 'blob:' ) === 0
			) {
				return;
			}

			el.setAttribute( 'credentialless', '' );

			// Reload the iframe to ensure the new attribute is taken into account.
			var origSrc = el.getAttribute( 'src' ) || '';
			el.setAttribute( 'src', '' );
			el.setAttribute( 'src', origSrc );
		}
	}

	/*
	 * Detects dynamically added DOM nodes that are missing the crossorigin attribute.
	 */
	var observer = new window.MutationObserver( function ( mutations ) {
		mutations.forEach( function ( mutation ) {
			[ mutation.addedNodes, mutation.target ].forEach( function (
				value
			) {
				var nodes =
					value instanceof window.NodeList ? value : [ value ];

				for ( var i = 0; i < nodes.length; i++ ) {
					var el = nodes[ i ];

					if ( ! el.querySelectorAll ) {
						// Most likely a text node.
						continue;
					}

					var children = el.querySelectorAll(
						'img,source,script,video,link,iframe'
					);
					for ( var j = 0; j < children.length; j++ ) {
						addCrossOriginAttributes( children[ j ] );
					}

					// For non-sandboxed iframes, observe their content document.
					if ( el.nodeName === 'IFRAME' ) {
						var iframeNode = el;

						// Sandboxed iframes should not get modified.
						var isEmbedSandboxIframe =
							iframeNode.classList.contains(
								'components-sandbox'
							);

						if ( ! isEmbedSandboxIframe ) {
							iframeNode.addEventListener( 'load', function () {
								try {
									if (
										this.contentDocument &&
										this.contentDocument.body
									) {
										observer.observe(
											this.contentDocument,
											{
												childList: true,
												attributes: true,
												subtree: true,
											}
										);
									}
								} catch ( e ) {
									// Iframe may be cross-origin or otherwise inaccessible.
								}
							} );
						}
					}

					if (
						[
							'IMG',
							'SOURCE',
							'SCRIPT',
							'VIDEO',
							'LINK',
							'IFRAME',
						].indexOf( el.nodeName ) !== -1
					) {
						addCrossOriginAttributes( el );
					}
				}
			} );
		} );
	} );

	/**
	 * Start observing the document body, waiting for it to be available if needed.
	 */
	function startObservingBody() {
		if ( document.body ) {
			observer.observe( document.body, {
				childList: true,
				attributes: true,
				subtree: true,
			} );
		} else if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', function () {
				if ( document.body ) {
					observer.observe( document.body, {
						childList: true,
						attributes: true,
						subtree: true,
					} );
				}
			} );
		}
	}

	startObservingBody();

	// Embed preview filter — disable previews for providers that don't
	// work with credentialless iframes.
	if (
		typeof wp !== 'undefined' &&
		wp.hooks &&
		wp.hooks.addFilter &&
		wp.compose &&
		wp.compose.createHigherOrderComponent
	) {
		var supportsCredentialless =
			'credentialless' in window.HTMLIFrameElement.prototype;

		var disableEmbedPreviews = wp.compose.createHigherOrderComponent(
			function ( BlockEdit ) {
				return function DisableEmbedPreviews( props ) {
					if ( 'core/embed' !== props.name ) {
						return wp.element.createElement( BlockEdit, props );
					}

					// These providers do not support credentialless iframes.
					var previewable =
						supportsCredentialless &&
						[ 'facebook', 'smugmug' ].indexOf(
							props.attributes.providerNameSlug
						) === -1;

					var newAttributes = {};
					for ( var key in props.attributes ) {
						if ( props.attributes.hasOwnProperty( key ) ) {
							newAttributes[ key ] = props.attributes[ key ];
						}
					}
					newAttributes.previewable = previewable;

					var newProps = {};
					for ( var prop in props ) {
						if ( props.hasOwnProperty( prop ) ) {
							newProps[ prop ] = props[ prop ];
						}
					}
					newProps.attributes = newAttributes;

					return wp.element.createElement( BlockEdit, newProps );
				};
			},
			'withDisabledEmbedPreviews'
		);

		wp.hooks.addFilter(
			'editor.BlockEdit',
			'client-side-media-everywhere/disable-embed-previews',
			disableEmbedPreviews
		);
	}
} )();
