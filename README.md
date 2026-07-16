# Client-Side Media Experiments

Enables client-side media processing on Firefox and Safari via COEP/COOP cross-origin isolation headers, and adds HEIC/HEIF upload support with automatic client-side conversion to JPEG.

## Features

### Cross-Origin Isolation (COEP/COOP)

WordPress 7.0 and Gutenberg include client-side media processing powered by WebAssembly (wasm-vips). This requires cross-origin isolation, which is achieved via Document-Isolation-Policy (DIP) on Chrome 137+.

However, Firefox and Safari do not yet support DIP, so client-side media processing is disabled on those browsers.

This plugin restores support by sending the older COEP/COOP headers on browsers where DIP is not available:

- Sends `Cross-Origin-Opener-Policy: same-origin` and `Cross-Origin-Embedder-Policy: credentialless` (or `require-corp` on Safari) headers in the block editor.
- Adds `crossorigin="anonymous"` attributes to cross-origin resources.
- Adds `credentialless` attribute to iframes so they continue working under COEP.
- Filters embed previews for providers that do not support credentialless iframes (Facebook, SmugMug).

### HEIC/HEIF Upload Support

HEIC support was removed from WordPress core's wasm-vips build due to LGPL/GPL license incompatibility. This plugin re-enables HEIC upload support by converting images client-side:

- **Enabled by default** — can be toggled under **Settings → Media**.
- HEIC/HEIF images are automatically converted to JPEG in the browser before upload.
- Uses the [heic2any](https://github.com/alexcorvi/heic2any) library, loaded dynamically from an external CDN only when a HEIC file is detected.
- heic2any uses [libheif](https://github.com/strukturag/libheif) (LGPL-3.0) for decoding. Since it is loaded at runtime from a CDN rather than bundled with the plugin, it is treated as a separate work and does not affect the plugin's GPL-2.0-or-later license.

## Requirements

- WordPress 6.8+ with the Gutenberg plugin, or WordPress 7.0+.
- The client-side media processing feature must be enabled.

## Installation

1. Upload the `client-side-media-experiments` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. The COEP/COOP headers activate automatically on browsers that need them. HEIC support is enabled by default.

## Configuration

### Disable COEP/COOP headers

```php
add_filter( 'csme_use_coep_coop', '__return_false' );
```

### Disable HEIC support

Navigate to **Settings → Media** and uncheck **HEIC Support**, or programmatically:

```php
update_option( 'csme_heic_enabled', 0 );
```

### Self-host the HEIC conversion library

Use the `csme_heic_library_url` filter to point to your own hosted copy of heic2any:

```php
add_filter( 'csme_heic_library_url', function () {
    return 'https://example.com/heic2any.min.js';
} );
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

The HEIC conversion library ([heic2any](https://github.com/alexcorvi/heic2any) / [libheif](https://github.com/strukturag/libheif)) is LGPL-3.0 licensed and loaded at runtime from an external CDN as a separate work.
