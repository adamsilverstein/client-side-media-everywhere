/**
 * Cross-origin isolation support for COEP/COOP mode.
 *
 * Handles credentialless iframes, crossorigin attributes on dynamically
 * added elements (require-corp only), and embed preview filtering for
 * browsers using COEP/COOP-based cross-origin isolation.
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
	 * Resolves which element should carry the crossorigin attribute.
	 *
	 * A SOURCE element has no crossorigin attribute of its own: the owning
	 * AUDIO or VIDEO element governs the fetch for every candidate in its
	 * source list, so the attribute goes on the media parent and setting it
	 * on the SOURCE does nothing. A SOURCE with no media parent, eg. inside
	 * a PICTURE, is left alone, matching csme_add_crossorigin_attributes().
	 *
	 * @param {Element} el The element that matched.
	 * @return {Element|null} The element to mark, or null when there is none.
	 */
	function resolveCrossOriginTarget( el ) {
		if ( el.nodeName !== 'SOURCE' ) {
			return el;
		}

		// SOURCE is always a direct child of the element that owns it.
		var parent = el.parentNode;

		if (
			parent &&
			( parent.nodeName === 'AUDIO' || parent.nodeName === 'VIDEO' )
		) {
			return parent;
		}

		return null;
	}

	var isRequireCorp = window.__coepMode === 'require-corp';

	var RESOURCE_SELECTOR = 'audio,img,source,script,video,link,iframe';
	var RESOURCE_TAGS = [
		'AUDIO',
		'IMG',
		'SOURCE',
		'SCRIPT',
		'VIDEO',
		'LINK',
		'IFRAME',
	];

	// Elements whose blocked load has been retried once already.
	var retried = new window.WeakSet();
	// Elements being watched until their load settles.
	var settling = new window.WeakSet();

	/**
	 * Whether a URL points at another origin over HTTP(S).
	 *
	 * Only such a resource is subject to the embedder policy. A data or blob
	 * URL is never fetched across the network, and a relative URL resolves
	 * against the site.
	 *
	 * @param {string} url An absolute URL.
	 * @return {boolean} Whether the URL is cross-origin.
	 */
	function isCrossOriginUrl( url ) {
		if ( ! url ) {
			return false;
		}

		var parsed;
		try {
			parsed = new window.URL( url );
		} catch ( e ) {
			return false;
		}

		if ( parsed.protocol !== 'http:' && parsed.protocol !== 'https:' ) {
			return false;
		}

		return parsed.origin !== window.location.origin;
	}

	/**
	 * Returns the absolute URL a media element is loading, if any.
	 *
	 * currentSrc is the candidate the browser picked, resolved. Before
	 * selection ran, a media element with a source list has neither it nor
	 * src, so the first SOURCE stands in.
	 *
	 * @param {HTMLImageElement|HTMLMediaElement} el The element.
	 * @return {string} The URL, or an empty string.
	 */
	function getMediaUrl( el ) {
		if ( el.currentSrc ) {
			return el.currentSrc;
		}
		if ( el.src ) {
			return el.src;
		}
		var source = el.querySelector( 'source[src]' );
		return source ? source.src : '';
	}

	/**
	 * Reads whether an element's load is pending, done, or failed.
	 *
	 * A failed state is readable at any time after the fact, so nothing has
	 * to race the load: a blocked IMG is complete with no natural size, and
	 * a blocked AUDIO or VIDEO ends at NETWORK_NO_SOURCE with an error set.
	 *
	 * @param {HTMLImageElement|HTMLMediaElement} el The element.
	 * @return {string} One of 'loading', 'loaded', or 'failed'.
	 */
	function getLoadState( el ) {
		if ( el.nodeName === 'IMG' ) {
			if ( ! el.complete ) {
				return 'loading';
			}
			return el.naturalWidth > 0 ? 'loaded' : 'failed';
		}

		if ( el.error || el.networkState === 3 ) {
			return 'failed';
		}
		if ( el.readyState >= 1 ) {
			return 'loaded';
		}
		return 'loading';
	}

	/**
	 * Starts an element's fetch over again.
	 *
	 * The crossorigin attribute is read when a fetch starts, so setting it on
	 * an element that already tried to load changes nothing until the load
	 * is retried. An IMG refetches when its src is reassigned; a media element
	 * reruns resource selection on load(). Neither is a structural change, so
	 * React does not notice.
	 *
	 * @param {HTMLImageElement|HTMLMediaElement} el The element.
	 */
	function retryLoad( el ) {
		if ( el.nodeName !== 'IMG' ) {
			el.load();
			return;
		}

		var attribute = el.hasAttribute( 'src' ) ? 'src' : 'srcset';
		var value = el.getAttribute( attribute );
		if ( value === null ) {
			return;
		}
		el.removeAttribute( attribute );
		el.setAttribute( attribute, value );
	}

	/**
	 * Watches a cross-origin media element until its load settles.
	 *
	 * A load that ends blocked is retried exactly once, now that the element
	 * carries the crossorigin attribute. An in-flight load is never
	 * interrupted, and a load that already succeeded is left alone.
	 *
	 * @param {HTMLImageElement|HTMLMediaElement} el The element.
	 */
	function settleLoad( el ) {
		if ( settling.has( el ) || ! isCrossOriginUrl( getMediaUrl( el ) ) ) {
			return;
		}
		settling.add( el );

		var attempts = 0;

		function check() {
			if ( ! el.isConnected ) {
				settling.delete( el );
				return;
			}

			var state = getLoadState( el );

			if ( state === 'loading' ) {
				if ( ++attempts < 40 ) {
					setTimeout( check, 250 );
				} else {
					settling.delete( el );
				}
				return;
			}

			if ( state === 'failed' && ! retried.has( el ) ) {
				retried.add( el );
				attempts = 0;
				retryLoad( el );
				setTimeout( check, 250 );
				return;
			}

			settling.delete( el );
		}

		check();
	}

	/**
	 * Adds crossorigin="anonymous" and credentialless attributes to elements.
	 *
	 * The crossorigin attribute is only added under require-corp (Safari),
	 * where cross-origin resources without a Cross-Origin-Resource-Policy
	 * header are blocked and a CORS request is their only way to load.
	 * Under credentialless (Firefox, Chrome < 137) those resources already
	 * load without credentials, and forcing CORS mode would break any of
	 * them served without Access-Control-Allow-Origin.
	 *
	 * @param {Element} el The element to modify.
	 */
	function addCrossOriginAttributes( el ) {
		if ( el.nodeName !== 'IFRAME' && isRequireCorp ) {
			var target = resolveCrossOriginTarget( el );

			if ( target ) {
				if ( ! target.hasAttribute( 'crossorigin' ) ) {
					target.setAttribute( 'crossorigin', 'anonymous' );
				}

				if (
					target.nodeName === 'IMG' ||
					target.nodeName === 'AUDIO' ||
					target.nodeName === 'VIDEO'
				) {
					settleLoad( target );
				}
			}
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

	/**
	 * Observes a document and marks what it already contains.
	 *
	 * A MutationObserver only reports changes made after observe(), so a
	 * sweep picks up everything that rendered before this point.
	 *
	 * @param {Document} doc The document to observe.
	 */
	function observeDocument( doc ) {
		observer.observe( doc, {
			childList: true,
			attributes: true,
			subtree: true,
		} );

		var els = doc.querySelectorAll( RESOURCE_SELECTOR );
		for ( var i = 0; i < els.length; i++ ) {
			handleElement( els[ i ] );
		}
	}

	/**
	 * Keeps an iframe's current document under observation.
	 *
	 * The editor canvas iframe starts out as about:blank and only later gets
	 * its real document, outside any event the parent can listen for. So the
	 * observed document is tracked by identity and the attachment is retried
	 * immediately, on load, and on a short bounded poll, re-observing
	 * whenever contentDocument is a different object. A boolean "already
	 * observed" latch is exactly the failure this replaces: it would be set
	 * against about:blank and never looked at again.
	 *
	 * @param {HTMLIFrameElement} iframe The iframe.
	 */
	function watchIframe( iframe ) {
		// Sandboxed embed iframes should not get modified.
		if ( iframe.classList.contains( 'components-sandbox' ) ) {
			return;
		}

		if ( iframe.__csmeAttach ) {
			iframe.__csmeAttach();
			return;
		}

		function attach() {
			var doc;
			try {
				doc = iframe.contentDocument;
			} catch ( e ) {
				// A cross-origin iframe stays inaccessible; nothing to do.
				return false;
			}

			if ( ! doc || ! doc.body ) {
				return true;
			}

			if ( iframe.__csmeObservedDocument !== doc ) {
				iframe.__csmeObservedDocument = doc;
				observeDocument( doc );
			}

			return true;
		}

		iframe.__csmeAttach = attach;
		iframe.addEventListener( 'load', attach );

		var polls = 0;
		var timer = setInterval( function () {
			if ( ! iframe.isConnected || ! attach() || ++polls >= 40 ) {
				clearInterval( timer );
			}
		}, 250 );

		attach();
	}

	/**
	 * Handles one element the observer or a sweep turned up.
	 *
	 * @param {Element} el The element.
	 */
	function handleElement( el ) {
		addCrossOriginAttributes( el );

		if ( el.nodeName === 'IFRAME' ) {
			watchIframe( el );
		}
	}

	/*
	 * Detects dynamically added DOM nodes that are missing the crossorigin attribute.
	 */
	var observer = new window.MutationObserver( function ( mutations ) {
		mutations.forEach( function ( mutation ) {
			[ mutation.addedNodes, mutation.target ].forEach(
				function ( value ) {
					var nodes =
						value instanceof window.NodeList ? value : [ value ];

					for ( var i = 0; i < nodes.length; i++ ) {
						var el = nodes[ i ];

						if ( ! el.querySelectorAll ) {
							// Most likely a text node.
							continue;
						}

						var children = el.querySelectorAll( RESOURCE_SELECTOR );
						for ( var j = 0; j < children.length; j++ ) {
							handleElement( children[ j ] );
						}

						if ( RESOURCE_TAGS.indexOf( el.nodeName ) !== -1 ) {
							handleElement( el );
						}
					}
				}
			);
		} );
	} );

	/**
	 * Start observing the document, waiting for its body if needed.
	 */
	function startObservingBody() {
		if ( document.body ) {
			observeDocument( document );
		} else if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', function () {
				if ( document.body ) {
					observeDocument( document );
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
