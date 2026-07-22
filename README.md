# Client-Side Media Everywhere

Enables client-side media processing on Firefox and Safari via COEP/COOP cross-origin isolation headers.

## Features

### Cross-Origin Isolation (COEP/COOP)

WordPress 7.1 and Gutenberg include client-side media processing powered by WebAssembly (wasm-vips). This requires cross-origin isolation, which is achieved via Document-Isolation-Policy (DIP) on Chrome 137+.

However, Firefox and Safari do not yet support DIP, and neither does Chrome before 137, so client-side media processing is disabled on those browsers.

This plugin restores support by sending the older COEP/COOP headers on browsers where DIP is not available:

- Sends `Cross-Origin-Opener-Policy: same-origin` and `Cross-Origin-Embedder-Policy: credentialless` (or `require-corp` on Safari) headers in the block editor.
- Adds `crossorigin="anonymous"` attributes to cross-origin resources.
- Adds `credentialless` attribute to iframes so they continue working under COEP.
- Filters embed previews for providers that do not support credentialless iframes (Facebook, SmugMug).

## Tradeoffs

Cross-origin isolation is a security boundary. It works by making the browser refuse cross-origin resources that have not opted in, or strip their credentials. That is what unlocks `SharedArrayBuffer` and wasm-vips, but it is also why Chrome moved to Document-Isolation-Policy: DIP gives the same isolation without imposing these restrictions on the rest of the page.

**Scope of the impact.** Only browsers that receive the COEP/COOP headers are affected - Firefox, Safari, and Chrome < 137 - and only on block editor screens (post editor, site editor, block widgets) for users who can upload files. The front-end, the rest of wp-admin, and Chrome 137+ are untouched.

What can break on those screens:

- **oEmbed previews.** Embeds are iframed. Under `credentialless` (Firefox, Chrome < 137) the plugin adds the `credentialless` attribute so they still load, but without cookies - embeds that need a logged-in session render logged-out or not at all. Facebook and SmugMug do not work with credentialless iframes, so their live previews are disabled in the editor and the placeholder is shown instead. Safari does not support `credentialless` at all, so under `require-corp` any embed whose provider does not send its own COEP header is blocked outright.
- **Media served from third-party origins.** Images, video, audio, and fonts loaded into the editor from a CDN or another domain must opt in with `Cross-Origin-Resource-Policy`. Under `credentialless` they load but without credentials, so anything behind a signed cookie fails. Under `require-corp` (Safari) they are blocked unless the server sends CORP - the plugin adds `crossorigin="anonymous"` to cross-origin images to give them a CORS path instead, which only helps if the server sends `Access-Control-Allow-Origin`.
- **Popup-based authentication.** `Cross-Origin-Opener-Policy: same-origin` severs the `window.opener` link to cross-origin popups. Plugins that connect to an external service by opening an OAuth popup and waiting for it to call back into the opener will hang.
- **Plugins that load editor assets cross-origin.** Any third-party script, stylesheet, or font pulled into the editor from another origin is subject to the same rules.
- **The classic block.** The classic block and other TinyMCE-based UIs commonly load third-party assets, and are a frequent place for the failures above to surface.

These are the same constraints the block editor lived with before Chrome shipped Document-Isolation-Policy. If you hit one, you can suppress the headers per environment or per user with the [`csme_use_coep_coop` filter](#disable-coepcoop-headers-programmatically), or deactivate the plugin - media processing then falls back to the server on the affected browsers, which is the behavior without this plugin installed.

## Requirements

- WordPress 6.8+ with the Gutenberg plugin, or WordPress 7.1+.
- The client-side media processing feature must be enabled (it is on by default in secure contexts).
- A secure context: the site must be served over HTTPS (or from `localhost`). Cross-origin isolation and `SharedArrayBuffer` are unavailable otherwise, and the editor falls back to server-side processing.

## Installation

1. Upload the `client-side-media-everywhere` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. The COEP/COOP headers activate automatically on browsers that need them.

## Configuration

The plugin has no settings screen: activating it is opting in to the COEP/COOP headers, and deactivating it turns them off.

### Disable COEP/COOP headers programmatically

To keep the plugin active but suppress the headers (for example, conditionally per environment or per user), use the `csme_use_coep_coop` filter:

```php
add_filter( 'csme_use_coep_coop', '__return_false' );
```

The filter receives the computed default (`true` on browsers that need COEP/COOP, `false` where Document-Isolation-Policy applies), so it can also be used for conditional logic:

```php
add_filter(
	'csme_use_coep_coop',
	function ( $use_coep_coop ) {
		if ( 'staging' === wp_get_environment_type() ) {
			return false;
		}
		return $use_coep_coop;
	}
);
```

## Development

### Static analysis

The PHP codebase is analyzed with [PHPStan](https://phpstan.org/) at level 5 (configured in `phpstan.neon.dist`). To run it locally:

```bash
composer install
composer phpstan
```

PHPStan also runs in CI and fails the build on new errors. Fix reported issues with real type fixes (accurate docblocks, guards for `false`/`null` returns) rather than ignores - there is no baseline, and the goal is to keep it that way.

### Coding standards

```bash
composer lint
```

### Building a release zip

To package the plugin for the WordPress.org plugin repository (or manual installation), run:

```bash
composer build
```

This produces `dist/client-side-media-everywhere.zip` containing only the runtime files, with a single top-level `client-side-media-everywhere/` directory as required by WordPress.org. Development files are excluded per `.distignore` - the same exclusion list used by the release deploy workflow.

## License

This plugin is licensed under [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html).
