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
// We use a direct query because there is no WordPress function to delete
// transients by prefix. Both the transient value and its timeout companion
// key must be removed.
global $wpdb;

$prefix        = '_transient_ai_chat_rl_';
$timeout_prefix = '_transient_timeout_ai_chat_rl_';

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( $prefix ) . '%',
		$wpdb->esc_like( $timeout_prefix ) . '%'
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
