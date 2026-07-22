# Client-Side Media Everywhere

Enables client-side media processing on Firefox and Safari via COEP/COOP cross-origin isolation headers.

## Features

### Cross-Origin Isolation (COEP/COOP)

WordPress 7.1 and Gutenberg include client-side media processing powered by WebAssembly (wasm-vips). This requires cross-origin isolation, which is achieved via Document-Isolation-Policy (DIP) on Chrome 137+.

However, Firefox and Safari do not yet support DIP, so client-side media processing is disabled on those browsers.

This plugin restores support by sending the older COEP/COOP headers on browsers where DIP is not available:

- Sends `Cross-Origin-Opener-Policy: same-origin` and `Cross-Origin-Embedder-Policy: credentialless` (or `require-corp` on Safari) headers in the block editor.
- Adds `crossorigin="anonymous"` attributes to cross-origin resources.
- Adds `credentialless` attribute to iframes so they continue working under COEP.
- Filters embed previews for providers that do not support credentialless iframes (Facebook, SmugMug).

### Media Library uploads

WordPress core's client-side media pipeline only runs in the block editor; the classic Media Library at **Media > Library** still uploads through the server. This plugin extends the pipeline to every Media Library upload surface so images are processed in the browser:

- Restores cross-origin isolation on the Media Library grid (`upload.php`) and the **Add New Media File** screen (`media-new.php`), neither of which core isolates. On Firefox, Safari, and Chrome < 137 this uses the COEP/COOP headers above; on Chrome 137+ the plugin sends the `Document-Isolation-Policy` header there itself, since core only sends it in the block editor.
- Intercepts the grid uploader and routes files through the same client-side pipeline the block editor uses: the original image is uploaded via the REST API and thumbnails are generated in the browser, then sideloaded and finalized. HEIC conversion, animated GIF handling, and oversized-file fallbacks are all handled by core's pipeline.
- Routes the **Add New Media File** screen (`media-new.php`) through the same pipeline, reusing the screen's existing progress and error UI. This is also how the Media Library's **list mode** uploads: its "Add New Media File" button links to this screen, so both Media Library views are covered.
- Warns before leaving the page while a pipeline upload is in flight, since browser-generated thumbnails that have not been sideloaded yet would be lost (classic uploads finish server-side and need no guard).
- Falls back cleanly to the classic server-side upload when the browser does not support client-side processing.

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
