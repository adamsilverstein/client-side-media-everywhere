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

delete_option( 'csme_enabled' );
