/**
 * Cross-origin isolation support for COEP/COOP mode.
 *
 * Handles credentialless iframes, crossorigin attributes on dynamically
 * added elements (require-corp only), a placeholder and notice for media
 * that cannot load at all under require-corp, and embed preview filtering
 * for browsers using COEP/COOP-based cross-origin isolation.
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

	// The URL each element's blocked load has been retried for already.
	var retried = new window.WeakMap();
	// Elements being watched until their load settles.
	var settling = new window.WeakSet();

	var UNPREVIEWABLE_ATTRIBUTE = 'data-csme-unpreviewable';
	var STYLE_ID = 'csme-unpreviewable-styles';
	var NOTICE_ID = 'csme-unpreviewable-media';

	var __ =
		window.wp && wp.i18n
			? wp.i18n.__
			: function ( text ) {
					return text;
			  };

	/*
	 * The media is fine: it displays on the site and in every other browser.
	 * Only the preview here is missing, so the copy says exactly that and
	 * never suggests the file is broken, missing, or needs uploading again.
	 */
	var SHORT_MESSAGE = __(
		"This media can't be previewed in Safari.",
		'client-side-media-everywhere'
	);
	var BLOCK_MESSAGE = __(
		"This media can't be previewed in Safari. It still displays on the site and in other browsers.",
		'client-side-media-everywhere'
	);
	var EDITOR_MESSAGE = __(
		"Some media can't be previewed in Safari: the editor is cross-origin isolated, and the site serving that media doesn't allow cross-origin requests. It still displays on the published site and in other browsers.",
		'client-side-media-everywhere'
	);

	// Client IDs of blocks whose media cannot be previewed, and who is listening.
	var unpreviewableBlocks = {};
	var blockListeners = [];
	var noticeShown = false;

	/**
	 * Subscribes to changes in the set of flagged blocks.
	 *
	 * @param {Function} listener Called on every change.
	 * @return {Function} Unsubscribes.
	 */
	function subscribeToBlocks( listener ) {
		blockListeners.push( listener );
		return function () {
			blockListeners = blockListeners.filter( function ( fn ) {
				return fn !== listener;
			} );
		};
	}

	/**
	 * Records whether a block's media can be previewed.
	 *
	 * @param {string}  clientId The block's client ID.
	 * @param {boolean} flagged  Whether its media cannot be previewed.
	 */
	function setBlockFlag( clientId, flagged ) {
		if (
			! clientId ||
			Boolean( unpreviewableBlocks[ clientId ] ) === flagged
		) {
			return;
		}

		if ( flagged ) {
			unpreviewableBlocks[ clientId ] = true;
		} else {
			delete unpreviewableBlocks[ clientId ];
		}

		blockListeners.forEach( function ( listener ) {
			listener();
		} );
	}

	/**
	 * Returns the client ID of the block an element is rendered in, if any.
	 *
	 * @param {Element} el The element.
	 * @return {string} The client ID, or an empty string.
	 */
	function getBlockClientId( el ) {
		var wrapper = el.closest( '[data-block]' );
		return wrapper ? wrapper.getAttribute( 'data-block' ) : '';
	}

	/**
	 * Shows the one editor notice explaining unpreviewable media.
	 *
	 * Shown once per page load however many elements are flagged; the
	 * per-element copy stays short and this carries the explanation.
	 */
	function showEditorNotice() {
		if ( noticeShown ) {
			return;
		}
		noticeShown = true;

		if (
			typeof wp === 'undefined' ||
			! wp.data ||
			! wp.data.dispatch( 'core/notices' )
		) {
			return;
		}

		wp.data.dispatch( 'core/notices' ).createInfoNotice( EDITOR_MESSAGE, {
			id: NOTICE_ID,
			isDismissible: true,
		} );
	}

	/**
	 * Marks an element whose load ended blocked with no way to retry.
	 *
	 * The attribute is one React does not manage, so it survives re-renders
	 * that keep the element; a re-render that replaces the element starts a
	 * fresh load, which is observed like any other.
	 *
	 * @param {Element} el The element.
	 */
	function markUnpreviewable( el ) {
		if ( ! el.hasAttribute( UNPREVIEWABLE_ATTRIBUTE ) ) {
			el.setAttribute( UNPREVIEWABLE_ATTRIBUTE, '' );
		}

		/*
		 * A VIDEO paints black behind its native controls, over anything
		 * CSS draws on it, and only stops when the controls go. React set the
		 * attribute once and does not touch it again unless the prop changes,
		 * so the property can be toggled here and put back on recovery.
		 */
		if ( el.nodeName === 'VIDEO' && el.controls ) {
			el.__csmeHadControls = true;
			el.controls = false;
		}

		setBlockFlag( getBlockClientId( el ), true );
		showEditorNotice();
	}

	/**
	 * Clears the mark from an element whose media loaded after all.
	 *
	 * @param {Element} el The element.
	 */
	function markPreviewable( el ) {
		if ( el.hasAttribute( UNPREVIEWABLE_ATTRIBUTE ) ) {
			el.removeAttribute( UNPREVIEWABLE_ATTRIBUTE );
		}
		if ( el.__csmeHadControls ) {
			delete el.__csmeHadControls;
			el.controls = true;
		}
		setBlockFlag( getBlockClientId( el ), false );
	}

	/**
	 * Builds a data URL for an inline SVG.
	 *
	 * @param {string} svg The SVG markup.
	 * @return {string} The data URL.
	 */
	function svgDataUrl( svg ) {
		return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent( svg );
	}

	/**
	 * Escapes text for use inside an XML text node.
	 *
	 * @param {string} text The text.
	 * @return {string} The escaped text.
	 */
	function escapeXml( text ) {
		return text
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	/**
	 * Adds the stylesheet that turns unpreviewable media into a neutral panel.
	 *
	 * The panel is drawn on the element itself, from two SVG background
	 * layers: a dashed frame stretched to the element, and the message
	 * centred in it. Nothing is added to the DOM and no parent is styled, so
	 * React never sees a difference, and it works for raw markup in a Classic
	 * block as well as for core blocks.
	 *
	 * Each element type needs one extra step to hide what the browser draws
	 * for a failed load. An IMG shows its alt text, which the content
	 * property replaces. A VIDEO has its controls switched off when it is
	 * marked. An AUDIO keeps drawing its native controls, with "Error" in
	 * them: WebKit offers no way to hide those while the controls attribute
	 * is present, and does not show the element at all without it. So the
	 * element is made taller than the panel, the strip holding the controls
	 * is clipped off the bottom, and a negative margin gives that height
	 * back to the layout.
	 *
	 * It goes into every observed document, the canvas included, since that
	 * is where the elements live.
	 *
	 * @param {Document} doc The document.
	 */
	function injectStyles( doc ) {
		if ( ! isRequireCorp || doc.getElementById( STYLE_ID ) ) {
			return;
		}

		var text = svgDataUrl(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 120">' +
				'<text x="300" y="60" text-anchor="middle" dominant-baseline="middle" ' +
				'font-family="-apple-system,BlinkMacSystemFont,&#39;Segoe UI&#39;,Roboto,Oxygen-Sans,Ubuntu,Cantarell,&#39;Helvetica Neue&#39;,sans-serif" ' +
				'font-size="14" fill="#1e1e1e">' +
				escapeXml( SHORT_MESSAGE ) +
				'</text></svg>'
		);
		var frame = svgDataUrl(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 120" preserveAspectRatio="none">' +
				'<rect x="0.5" y="0.5" width="599" height="119" rx="2" fill="#f0f0f0" ' +
				'stroke="#949494" stroke-dasharray="4 3" vector-effect="non-scaling-stroke"/></svg>'
		);
		var selector = '[' + UNPREVIEWABLE_ATTRIBUTE + ']';

		var style = doc.createElement( 'style' );
		style.id = STYLE_ID;
		style.textContent = [
			selector +
				'{display:block !important;box-sizing:border-box !important;width:100% !important;height:120px !important;max-height:none !important;aspect-ratio:auto !important;padding:0 !important;border:0 !important;border-radius:0 !important;' +
				'background:url("' +
				text +
				'") center 0/min(100%,600px) 120px no-repeat,url("' +
				frame +
				'") 0 0/100% 120px no-repeat !important;background-color:transparent !important}',
			'audio' +
				selector +
				'{height:160px !important;margin-bottom:-40px !important;clip-path:inset(0 0 40px 0) !important}',
			'img' +
				selector +
				'{content:url("' +
				text +
				'");background-image:url("' +
				frame +
				'") !important;background-size:100% 120px !important;background-position:0 0 !important}',
		].join( '\n' );

		( doc.head || doc.documentElement ).appendChild( style );
	}

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
	 * interrupted, and a load that already succeeded is left alone. Only
	 * after the retry has settled is the element marked as unpreviewable,
	 * so rescuable media never flickers through the placeholder.
	 *
	 * @param {HTMLImageElement|HTMLMediaElement} el The element.
	 */
	function settleLoad( el ) {
		if ( settling.has( el ) ) {
			return;
		}

		var url = getMediaUrl( el );
		if ( ! isCrossOriginUrl( url ) ) {
			// A same-origin URL replacing a flagged one clears the flag.
			markPreviewable( el );
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

			if ( state === 'failed' && retried.get( el ) !== url ) {
				retried.set( el, url );
				attempts = 0;
				retryLoad( el );
				setTimeout( check, 250 );
				return;
			}

			settling.delete( el );

			if ( state === 'failed' ) {
				markUnpreviewable( el );
			} else {
				markPreviewable( el );
			}
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

		injectStyles( doc );

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

	// Unpreviewable media notice — the same pattern as the embed filter,
	// for the blocks whose media the observer can find blocked.
	if (
		isRequireCorp &&
		typeof wp !== 'undefined' &&
		wp.hooks &&
		wp.hooks.addFilter &&
		wp.compose &&
		wp.compose.createHigherOrderComponent &&
		wp.blockEditor &&
		wp.blockEditor.InspectorControls &&
		wp.components &&
		wp.components.Notice &&
		wp.element.useSyncExternalStore
	) {
		/*
		 * The inspector opens on the Content tab when a block has one, and
		 * on Settings otherwise, so the notice goes on whichever the user
		 * lands on. Only the Image block has a Content tab today.
		 */
		var MEDIA_BLOCK_GROUPS = {
			'core/image': 'content',
			'core/audio': 'default',
			'core/video': 'default',
		};

		var withUnpreviewableNotice = wp.compose.createHigherOrderComponent(
			function ( BlockEdit ) {
				return function UnpreviewableNotice( props ) {
					var clientId = props.clientId;
					var flagged = wp.element.useSyncExternalStore(
						subscribeToBlocks,
						function () {
							return Boolean( unpreviewableBlocks[ clientId ] );
						}
					);

					if ( ! MEDIA_BLOCK_GROUPS[ props.name ] || ! flagged ) {
						return wp.element.createElement( BlockEdit, props );
					}

					/*
					 * The block keeps its own edit component, toolbar and
					 * controls; the notice joins them in the inspector. That
					 * survives React re-renders in a way an element written
					 * into the canvas would not, and the stylesheet already
					 * replaces the broken rendering in the canvas itself.
					 */
					return wp.element.createElement(
						wp.element.Fragment,
						null,
						wp.element.createElement( BlockEdit, props ),
						wp.element.createElement(
							wp.blockEditor.InspectorControls,
							{ group: MEDIA_BLOCK_GROUPS[ props.name ] },
							wp.element.createElement(
								wp.components.Notice,
								{
									status: 'info',
									isDismissible: false,
									className: 'csme-unpreviewable-notice',
								},
								BLOCK_MESSAGE
							)
						)
					);
				};
			},
			'withUnpreviewableNotice'
		);

		wp.hooks.addFilter(
			'editor.BlockEdit',
			'client-side-media-everywhere/unpreviewable-media-notice',
			withUnpreviewableNotice
		);
	}
} )();
