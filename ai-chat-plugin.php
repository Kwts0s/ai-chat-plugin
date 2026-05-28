<?php
/**
 * Plugin Name: AI Chatbot Plugin
 * Plugin URI:  https://github.com/Kwts0s/ai-chat-plugin
 * Description: A customizable website chatbot widget powered by an external backend API. Supports proxy mode, full branding customization, and secure admin settings.
 * Version:     1.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author:      Advance Services Web
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-chat-plugin
 * Domain Path: /languages
 *
 * @package AIChatPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Plugin constants ────────────────────────────────────────────────────────
define( 'AI_CHAT_VERSION', '1.0.0' );
define( 'AI_CHAT_FILE', __FILE__ );
define( 'AI_CHAT_DIR', plugin_dir_path( __FILE__ ) );
define( 'AI_CHAT_URL', plugin_dir_url( __FILE__ ) );
define( 'AI_CHAT_OPTION_KEY', 'ai_chat_settings' );
define( 'AI_CHAT_MIN_WP', '6.0' );
define( 'AI_CHAT_MIN_PHP', '7.4' );

// ── Load dependencies ───────────────────────────────────────────────────────
require_once AI_CHAT_DIR . 'includes/class-sanitizer.php';
require_once AI_CHAT_DIR . 'includes/class-api-client.php';
require_once AI_CHAT_DIR . 'includes/class-session-manager.php';
require_once AI_CHAT_DIR . 'includes/class-assets.php';
require_once AI_CHAT_DIR . 'includes/class-admin-settings.php';
require_once AI_CHAT_DIR . 'includes/class-rest-controller.php';
require_once AI_CHAT_DIR . 'includes/class-frontend.php';
require_once AI_CHAT_DIR . 'includes/class-plugin.php';

// ── Bootstrap ───────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', static function (): void {
	load_plugin_textdomain(
		'ai-chat-plugin',
		false,
		dirname( plugin_basename( AI_CHAT_FILE ) ) . '/languages'
	);
	AI_Chat_Plugin::get_instance();
} );

// ── Activation / Deactivation / Uninstall hooks ─────────────────────────────
register_activation_hook( __FILE__, array( 'AI_Chat_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AI_Chat_Plugin', 'deactivate' ) );
