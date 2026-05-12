<?php
/**
 * Main plugin singleton — bootstraps all components.
 *
 * @package AIChatPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AI_Chat_Plugin
 */
final class AI_Chat_Plugin {

	/** @var self|null Singleton instance */
	private static ?self $instance = null;

	/**
	 * Return (and create if needed) the single instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Private constructor — use get_instance(). */
	private function __construct() {
		$this->init();
	}

	/** Wire up all components. */
	private function init(): void {
		new AI_Chat_Assets();
		new AI_Chat_Admin_Settings();
		new AI_Chat_REST_Controller();
		new AI_Chat_Frontend();
	}

	// ── Activation / deactivation hooks ────────────────────────────────────

	/**
	 * Runs on plugin activation.
	 * Seeds default settings if none exist yet.
	 */
	public static function activate(): void {
		if ( false === get_option( AI_CHAT_OPTION_KEY ) ) {
			add_option( AI_CHAT_OPTION_KEY, AI_Chat_Sanitizer::defaults() );
		}

		// Flush rewrite rules for REST endpoint.
		flush_rewrite_rules();
	}

	/**
	 * Runs on plugin deactivation.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	/** No cloning or un-serialising of singletons. */
	public function __clone() {}
	public function __wakeup() {}
}
