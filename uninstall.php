<?php
/**
 * Uninstall handler for Client-Side Media Experiments.
 *
 * Removes plugin options when the plugin is deleted.
 *
 * @package ClientSideMediaExperiments
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Legacy option from the removed settings screen; kept so existing installs are cleaned up.
delete_option( 'csme_enabled' );
