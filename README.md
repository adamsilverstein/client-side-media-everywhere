# Client-Side Media Experiments

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

### HEIC uploads

WordPress 7.1 handles HEIC/HEIF uploads in core: MIME types, file type detection, HEIC-to-JPEG output mapping, and client-side conversion via the vips WASM pipeline or a canvas-based fallback for browsers with native HEVC decoding (such as Safari). This plugin no longer ships any HEIC handling of its own.

## Requirements

- WordPress 6.8+ with the Gutenberg plugin, or WordPress 7.1+.
- The client-side media processing feature must be enabled (it is on by default in secure contexts).
- A secure context: the site must be served over HTTPS (or from `localhost`). Cross-origin isolation and `SharedArrayBuffer` are unavailable otherwise, and the editor falls back to server-side processing.

## Installation

1. Upload the `client-side-media-experiments` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. The COEP/COOP headers activate automatically on browsers that need them.

## Configuration

### Disable COEP/COOP headers

Navigate to **Settings → Media** and uncheck **Enable**, or programmatically:

```php
add_filter( 'csme_use_coep_coop', '__return_false' );
```

## Development

### Building a release zip

To package the plugin for the WordPress.org plugin repository (or manual installation), run:

```bash
composer build
```

This produces `dist/client-side-media-experiments.zip` containing only the runtime files, with a single top-level `client-side-media-experiments/` directory as required by WordPress.org. Development files are excluded per `.distignore` - the same exclusion list used by the release deploy workflow.

## License

This plugin is licensed under [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html).
