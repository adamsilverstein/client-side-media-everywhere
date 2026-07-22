=== Client-Side Media Everywhere ===
Contributors: adamsilverstein
Tags: media, performance, cross-origin, wasm
Requires at least: 6.8
Tested up to: 7.1
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enables client-side media processing on Firefox and Safari via COEP/COOP cross-origin isolation headers.

== Description ==

WordPress 7.1 and Gutenberg include client-side media processing powered by WebAssembly (wasm-vips). This requires cross-origin isolation, which is achieved via Document-Isolation-Policy (DIP) on Chrome 137+.

However, Firefox and Safari do not yet support DIP, and neither does Chrome before 137, so client-side media processing is disabled on those browsers.

This plugin restores support for Firefox and Safari by sending the older COEP/COOP headers (Cross-Origin-Embedder-Policy / Cross-Origin-Opener-Policy) on browsers where DIP is not available.

**What this plugin does:**

* Sends `Cross-Origin-Opener-Policy: same-origin` and `Cross-Origin-Embedder-Policy: credentialless` (or `require-corp` on Safari) headers in the block editor.
* Adds `crossorigin="anonymous"` attributes to cross-origin resources.
* Adds `credentialless` attribute to iframes so they continue working under COEP.
* Filters embed previews for providers that do not support credentialless iframes (Facebook, SmugMug).

**Requirements:**

* WordPress 6.8+ with the Gutenberg plugin (which provides the client-side media processing feature), or WordPress 7.1+.
* The client-side media processing feature must be enabled (it is on by default in secure contexts).
* HTTPS (or localhost): client-side media processing requires a secure context.

== Tradeoffs ==

Cross-origin isolation is a security boundary. It works by making the browser refuse cross-origin resources that have not opted in, or strip their credentials. That is what unlocks SharedArrayBuffer and wasm-vips, but it is also why Chrome moved to Document-Isolation-Policy: DIP gives the same isolation without imposing these restrictions on the rest of the page.

Only browsers that receive the COEP/COOP headers are affected - Firefox, Safari, and Chrome < 137 - and only on block editor screens (post editor, site editor, block widgets) for users who can upload files. The front-end, the rest of wp-admin, and Chrome 137+ are untouched.

What can break on those screens:

* **oEmbed previews.** Embeds are iframed. Under `credentialless` (Firefox, Chrome < 137) the plugin adds the `credentialless` attribute so they still load, but without cookies - embeds that need a logged-in session render logged-out or not at all. Facebook and SmugMug do not work with credentialless iframes, so their live previews are disabled in the editor and the placeholder is shown instead. Safari does not support `credentialless` at all, so under `require-corp` any embed whose provider does not send its own COEP header is blocked outright.
* **Media served from third-party origins.** Images, video, audio, and fonts loaded into the editor from a CDN or another domain must opt in with `Cross-Origin-Resource-Policy`. Under `credentialless` they load but without credentials, so anything behind a signed cookie fails. Under `require-corp` (Safari) they are blocked unless the server sends CORP - the plugin adds `crossorigin="anonymous"` to cross-origin images to give them a CORS path instead, which only helps if the server sends `Access-Control-Allow-Origin`.
* **Popup-based authentication.** `Cross-Origin-Opener-Policy: same-origin` severs the `window.opener` link to cross-origin popups. Plugins that connect to an external service by opening an OAuth popup and waiting for it to call back into the opener will hang.
* **Plugins that load editor assets cross-origin.** Any third-party script, stylesheet, or font pulled into the editor from another origin is subject to the same rules.
* **The classic block.** The classic block and other TinyMCE-based UIs commonly load third-party assets, and are a frequent place for the failures above to surface.

These are the same constraints the block editor lived with before Chrome shipped Document-Isolation-Policy. If you hit one, suppress the headers with the `csme_use_coep_coop` filter or deactivate the plugin - media processing then falls back to the server on the affected browsers, which is the behavior without this plugin installed.

== Installation ==

1. Upload the `client-side-media-everywhere` folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. The plugin activates automatically on browsers that need COEP/COOP headers.

== Frequently Asked Questions ==

= Why is media still processed server-side? =

Check that your site is served over HTTPS (or localhost). Client-side media processing requires a secure context; without one, cross-origin isolation is unavailable and WordPress silently falls back to server-side processing. The editor logs an informational message in the browser console explaining the reason for the fallback.

= Do I need this plugin on Chrome? =

No. Chrome 137+ uses Document-Isolation-Policy, which is handled by WordPress core / Gutenberg. This plugin only activates on browsers that do not support DIP.

= Will this break my site? =

Not the front-end: the COEP/COOP headers are only sent on block editor admin pages, and only on browsers that need them (Firefox, Safari, Chrome < 137). Within the editor on those browsers, cross-origin isolation can break oEmbed previews, media served from third-party origins, popup-based authentication flows, and plugins that load editor assets cross-origin. See the Tradeoffs section for the details and for how to turn the headers off if you hit one.

= Can I disable the COEP/COOP behavior? =

Yes - deactivate the plugin. Sending the COEP/COOP headers is the plugin's only job, so there is no separate settings toggle: activating the plugin turns the behavior on, deactivating it turns it off.

To keep the plugin active but suppress the headers programmatically (for example, per environment), use the `csme_use_coep_coop` filter:

`add_filter( 'csme_use_coep_coop', '__return_false' );`

= How do HEIC uploads work? =

WordPress 7.1 converts HEIC images client-side where possible and server-side otherwise. This plugin no longer includes any HEIC handling of its own.

== Changelog ==

= 1.1.0 =
* Renamed the plugin from "Client-Side Media Experiments" to "Client-Side Media Everywhere" to better describe what it does: bringing the WordPress client-side media processing feature to browsers that core does not cover.
* Removed the settings screen and the `csme_enabled` option. Activating the plugin now always enables the COEP/COOP headers; deactivate the plugin to turn them off. Note: sites that had unchecked **Enable** under Settings > Media will have the headers re-enabled after updating. Use the `csme_use_coep_coop` filter to disable the behavior programmatically.
* Added crossorigin="anonymous" to cross-origin images on Safari (require-corp), where they would otherwise be blocked by the embedder policy. Covers src and srcset, including protocol-relative URLs.
* Included the GPL-2.0 license text in the plugin package.
* Excluded development files (package.json, package-lock.json, .wp-env.json) from the distribution zip.

= 1.0.0 =
* First stable release, fully compatible with the WordPress 7.1 client-side media processing feature.
* Removed the HEIC conversion module (heic2any/CDN): WordPress 7.1 handles HEIC uploads in core.
* Fixed Chromium detection against WordPress 7.1 function names, so no COEP/COOP headers are sent where DIP applies.
* Deferred initialization to `plugins_loaded` so plugin activation order no longer matters.
* The `csme_enabled` setting default no longer depends on the browser saving the settings.
* Scoped the `__coepCoopIsolation` flag to block editor screens instead of every admin page.
* Added an uninstall handler that removes the plugin option on deletion.

= 0.2.0 =
* Added optional HEIC/HEIF upload support with client-side conversion to JPEG.
* HEIC conversion library loaded dynamically from CDN (heic2any / libheif).
* New setting under Settings > Media to enable HEIC support.
* Filterable CDN URL via `csme_heic_library_url`.

= 0.1.0 =
* Initial release.
* COEP/COOP header support for Firefox and Safari.
* Credentialless iframe handling.
* Embed preview filtering for incompatible providers.
