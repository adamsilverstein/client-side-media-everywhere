<?php
/**
 * Uninstall handler for Client-Side Media Everywhere.
 *
 * Removes plugin options when the plugin is deleted.
 *
 * @package ClientSideMediaEverywhere
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Legacy option from the removed settings screen; kept so existing installs are cleaned up.
delete_option( 'csme_enabled' );
