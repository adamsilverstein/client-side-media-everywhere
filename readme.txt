=== Client-Side Media Everywhere ===
Contributors: adamsilverstein
Tags: media, performance, cross-origin, wasm
Requires at least: 6.8
Tested up to: 7.1
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enables client-side media processing on Firefox and Safari via COEP/COOP cross-origin isolation headers.

== Description ==

WordPress 7.1 and Gutenberg include client-side media processing powered by WebAssembly (wasm-vips). This requires cross-origin isolation, which is achieved via Document-Isolation-Policy (DIP) on Chrome 137+.

However, Firefox and Safari do not yet support DIP, so client-side media processing is disabled on those browsers.

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

The COEP/COOP headers are only sent on block editor admin pages, not on the front-end. Some third-party plugins that use iframes in the editor may be affected. If you experience issues, you can deactivate the plugin.

= Can I disable the COEP/COOP behavior? =

Yes - deactivate the plugin. Sending the COEP/COOP headers is the plugin's only job, so there is no separate settings toggle: activating the plugin turns the behavior on, deactivating it turns it off.

To keep the plugin active but suppress the headers programmatically (for example, per environment), use the `csme_use_coep_coop` filter:

`add_filter( 'csme_use_coep_coop', '__return_false' );`

= How do HEIC uploads work? =

WordPress 7.1 converts HEIC images client-side where possible and server-side otherwise. This plugin no longer includes any HEIC handling of its own.

== Changelog ==

= Unreleased =
* Renamed the plugin from "Client-Side Media Experiments" to "Client-Side Media Everywhere" to better describe what it does: bringing the WordPress client-side media processing feature to browsers that core does not cover.
* Removed the settings screen and the `csme_enabled` option. Activating the plugin now always enables the COEP/COOP headers; deactivate the plugin to turn them off. Note: sites that had unchecked **Enable** under Settings > Media will have the headers re-enabled after updating. Use the `csme_use_coep_coop` filter to disable the behavior programmatically.
* Added crossorigin="anonymous" to cross-origin images on Safari (require-corp), where they would otherwise be blocked by the embedder policy. Covers src and srcset, including protocol-relative URLs.

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
