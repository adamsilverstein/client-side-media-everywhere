<?php
/**
 * Settings page for Client-Side Media Experiments.
 *
 * Adds a toggle under Settings > Media to enable or disable
 * client-side media processing support.
 *
 * @package ClientSideMediaExperiments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin settings on the Media settings page.
 *
 * The csme_enabled option defaults to on; whether COEP/COOP headers are
 * actually sent for a given request is decided at runtime by
 * csme_should_use_coep_coop(), which detects DIP-capable browsers.
 */
function csme_register_settings() {
	register_setting(
		'media',
		'csme_enabled',
		array(
			'type'              => 'integer',
			'default'           => 1,
			'sanitize_callback' => 'csme_sanitize_enabled',
		)
	);

	add_settings_section(
		'csme_settings_section',
		__( 'Client-Side Media', 'client-side-media-experiments' ),
		'csme_settings_section_callback',
		'media'
	);

	add_settings_field(
		'csme_enabled_field',
		__( 'Enable', 'client-side-media-experiments' ),
		'csme_enabled_field_callback',
		'media',
		'csme_settings_section'
	);

	register_setting(
		'media',
		'csme_heic_enabled',
		array(
			'type'              => 'integer',
			'default'           => 1,
			'sanitize_callback' => 'csme_sanitize_enabled',
		)
	);

	add_settings_field(
		'csme_heic_enabled_field',
		__( 'HEIC Support', 'client-side-media-experiments' ),
		'csme_heic_enabled_field_callback',
		'media',
		'csme_settings_section'
	);
}
add_action( 'admin_init', 'csme_register_settings' );

/**
 * Sanitizes the enabled setting value.
 *
 * @param mixed $value The setting value.
 * @return int Sanitized value (1 or 0).
 */
function csme_sanitize_enabled( $value ) {
	return $value ? 1 : 0;
}

/**
 * Outputs the settings section description.
 */
function csme_settings_section_callback() {
	echo '<p>' . esc_html__( 'Configure client-side media processing via COEP/COOP cross-origin isolation headers.', 'client-side-media-experiments' ) . '</p>';
}

/**
 * Outputs the checkbox field for the enabled setting.
 */
function csme_enabled_field_callback() {
	$enabled = get_option( 'csme_enabled', 1 );
	?>
	<input type="hidden" name="csme_enabled" value="0" />
	<label for="csme_enabled">
		<input type="checkbox" id="csme_enabled" name="csme_enabled" value="1" <?php checked( $enabled, 1 ); ?> />
		<?php esc_html_e( 'Enable client-side media processing support via COEP/COOP headers.', 'client-side-media-experiments' ); ?>
	</label>
	<?php
}

/**
 * Outputs the checkbox field for the HEIC enabled setting.
 */
function csme_heic_enabled_field_callback() {
	$enabled = get_option( 'csme_heic_enabled', 1 );
	?>
	<input type="hidden" name="csme_heic_enabled" value="0" />
	<label for="csme_heic_enabled">
		<input type="checkbox" id="csme_heic_enabled" name="csme_heic_enabled" value="1" <?php checked( $enabled, 1 ); ?> />
		<?php esc_html_e( 'Enable HEIC/HEIF image upload support (converts to JPEG on the client side).', 'client-side-media-experiments' ); ?>
	</label>
	<p class="description">
		<?php esc_html_e( 'When enabled, HEIC images are converted to JPEG in the browser using the heic2any library (which uses libheif, LGPL-3.0 licensed), loaded from an external CDN at runtime.', 'client-side-media-experiments' ); ?>
	</p>
	<?php
}

/**
 * Disables COEP/COOP when the setting is off.
 *
 * @param bool $use_coep_coop Whether COEP/COOP should be used.
 * @return bool Filtered value.
 */
function csme_maybe_disable_coep_coop( $use_coep_coop ) {
	if ( ! get_option( 'csme_enabled', 1 ) ) {
		return false;
	}
	return $use_coep_coop;
}
add_filter( 'csme_use_coep_coop', 'csme_maybe_disable_coep_coop', 5 );
