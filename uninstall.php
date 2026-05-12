<?php
/**
 * Uninstall handler — removes all plugin data when the plugin is deleted.
 *
 * WordPress calls this file automatically when the plugin is removed
 * via the Plugins admin page.
 *
 * @package AIChatPlugin
 */

// Guard: only run when WordPress is uninstalling the plugin.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete the main settings option.
delete_option( 'ai_chat_settings' );

// Delete any rate-limiting transients created by the proxy controller.
global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ai_chat_rl_%' OR option_name LIKE '_transient_timeout_ai_chat_rl_%'"
);
