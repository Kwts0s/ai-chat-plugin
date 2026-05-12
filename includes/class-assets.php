<?php
/**
 * Asset manager — enqueues frontend and admin scripts/styles.
 *
 * @package AIChatPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AI_Chat_Assets
 */
final class AI_Chat_Assets {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
	}

	// ── Frontend ────────────────────────────────────────────────────────────

	/**
	 * Enqueue the chat widget on the frontend (only where needed).
	 */
	public function enqueue_frontend(): void {
		$settings = AI_Chat_Sanitizer::get_settings();

		if ( ! $this->should_load_on_current_page( $settings ) ) {
			return;
		}

		wp_enqueue_style(
			'ai-chat-widget',
			AI_CHAT_URL . 'assets/css/chat-widget.css',
			array(),
			AI_CHAT_VERSION
		);

		wp_enqueue_script(
			'ai-chat-widget',
			AI_CHAT_URL . 'assets/js/chat-widget.js',
			array(),
			AI_CHAT_VERSION,
			true // Load in footer.
		);

		// Localise the safe, non-sensitive config for the JS widget.
		wp_localize_script(
			'ai-chat-widget',
			'aiChatWidgetConfig',
			$this->build_js_config( $settings )
		);

		// Inject CSS custom properties as an inline style.
		$this->inline_css_variables( $settings );
	}

	// ── Admin ───────────────────────────────────────────────────────────────

	/**
	 * Enqueue admin assets only on the plugin settings page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_admin( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'ai-chat-settings' ) ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'ai-chat-admin',
			AI_CHAT_URL . 'assets/css/admin.css',
			array( 'wp-color-picker' ),
			AI_CHAT_VERSION
		);

		wp_enqueue_script(
			'ai-chat-admin',
			AI_CHAT_URL . 'assets/js/admin.js',
			array( 'wp-color-picker', 'jquery' ),
			AI_CHAT_VERSION,
			true
		);

		wp_localize_script(
			'ai-chat-admin',
			'aiChatAdmin',
			array(
				'healthUrl' => esc_url_raw( rest_url( AI_Chat_REST_Controller::NAMESPACE . '/health' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'i18n'      => array(
					'checking'   => __( 'Checking…', 'ai-chat-plugin' ),
					'connected'  => __( 'Connected ✓', 'ai-chat-plugin' ),
					'failed'     => __( 'Connection failed', 'ai-chat-plugin' ),
					'noUrl'      => __( 'Please enter a backend URL first.', 'ai-chat-plugin' ),
				),
			)
		);
	}

	// ── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Decide whether the widget should be loaded on the current page.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return bool
	 */
	private function should_load_on_current_page( array $settings ): bool {
		// If display_mode is 'shortcode', assets are still needed (shortcode may be present).
		// We enqueue them lazily from the shortcode itself but keep it simple here.
		if ( 'shortcode' === $settings['display_mode'] ) {
			return false; // Enqueued on demand by AI_Chat_Frontend.
		}

		if ( 'global' === $settings['display_mode'] ) {
			return true;
		}

		// 'selected' mode — check if the current post/page ID is in the allow-list.
		if ( 'selected' === $settings['display_mode'] ) {
			$allowed_ids = (array) $settings['display_page_ids'];
			$current_id  = get_the_ID();
			return $current_id && in_array( (int) $current_id, array_map( 'intval', $allowed_ids ), true );
		}

		return false;
	}

	/**
	 * Build the JS config object (never expose backend URL in proxy mode).
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return array<string, mixed>
	 */
	private function build_js_config( array $settings ): array {
		$is_proxy = ( 'proxy' === $settings['connection_mode'] );

		$config = array(
			'enabled'         => true,
			'mode'            => $settings['connection_mode'],
			'proxyUrl'        => $is_proxy ? esc_url_raw( rest_url( AI_Chat_REST_Controller::NAMESPACE . '/chat' ) ) : '',
			'nonceUrl'        => $is_proxy ? esc_url_raw( rest_url( AI_Chat_REST_Controller::NAMESPACE . '/nonce' ) ) : '',
			'nonce'           => $is_proxy ? wp_create_nonce( 'ai_chat_proxy' ) : '',
			'companyName'     => esc_html( $settings['company_name'] ),
			'companySubtitle' => esc_html( $settings['company_subtitle'] ),
			'logoUrl'         => esc_url( $settings['company_logo'] ),
			'bubbleIconUrl'   => esc_url( $settings['bubble_icon_svg_media_url'] ),
			'bubbleIcon'      => $settings['bubble_icon_svg'], // Already sanitized SVG.
			'welcomeMessage'  => esc_html( $settings['welcome_message'] ),
			'disclaimer'      => esc_html( $settings['disclaimer'] ),
			'onlineText'      => esc_html( $settings['online_text'] ),
			'bubblePosition'  => $settings['bubble_position'],
			'storeTranscript' => (bool) $settings['store_transcript'],
			'timeout'         => (int) $settings['request_timeout'],
			'debug'           => (bool) $settings['debug_mode'],
			'i18n'            => array(
				'openChat'   => __( 'Open chat', 'ai-chat-plugin' ),
				'close'      => __( 'Close chat', 'ai-chat-plugin' ),
				'send'       => __( 'Send message', 'ai-chat-plugin' ),
				'placeholder'=> __( 'Type a message…', 'ai-chat-plugin' ),
				'inputLabel' => __( 'Message input', 'ai-chat-plugin' ),
				'messages'   => __( 'Chat messages', 'ai-chat-plugin' ),
				'timeout'    => __( 'Request timed out. Please try again.', 'ai-chat-plugin' ),
				'error'      => __( 'An error occurred. Please try again.', 'ai-chat-plugin' ),
				'reset'      => __( 'Clear conversation', 'ai-chat-plugin' ),
			),
		);

		// Only expose backend URL in direct mode (CORS must be enabled on the backend).
		if ( ! $is_proxy ) {
			$config['backendUrl'] = esc_url_raw( $settings['backend_url'] );
		}

		/**
		 * Allow developers to extend the JS configuration object.
		 *
		 * @param array $config   Config passed to the frontend.
		 * @param array $settings Plugin settings.
		 */
		return apply_filters( 'ai_chat_js_config', $config, $settings );
	}

	/**
	 * Output an inline <style> that sets CSS custom properties from admin settings.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 */
	private function inline_css_variables( array $settings ): void {
		$position_class = ( 'bottom-left' === $settings['bubble_position'] ) ? 'ai-chat--left' : '';
		$radius         = (int) $settings['border_radius'];
		$bubble_size    = (int) $settings['bubble_size'];
		$bubble_border_width = (int) $settings['bubble_border_width'];
		$bubble_radius  = '50%';
		if ( 'rounded' === $settings['bubble_style'] ) {
			$bubble_radius = '16px';
		} elseif ( 'square' === $settings['bubble_style'] ) {
			$bubble_radius = '8px';
		}

		$vars = sprintf(
			'--ai-chat-primary:%s;--ai-chat-secondary:%s;--ai-chat-bg:%s;--ai-chat-text:%s;--ai-chat-radius:%dpx;--ai-chat-bubble-size:%dpx;--ai-chat-bubble-radius:%s;--ai-chat-bubble-border-width:%dpx;--ai-chat-bubble-border-color:%s;',
			esc_attr( $settings['primary_color'] ),
			esc_attr( $settings['secondary_color'] ),
			esc_attr( $settings['bg_color'] ),
			esc_attr( $settings['text_color'] ),
			$radius,
			$bubble_size,
			esc_attr( $bubble_radius ),
			$bubble_border_width,
			esc_attr( $settings['bubble_border_color'] )
		);

		// Position vars.
		if ( 'bottom-left' === $settings['bubble_position'] ) {
			$vars .= '--ai-chat-bubble-right:auto;--ai-chat-bubble-left:24px;';
		} else {
			$vars .= '--ai-chat-bubble-right:24px;--ai-chat-bubble-left:auto;';
		}

		$inline_css = ':root{' . $vars . '}';

		// Append custom CSS if provided.
		if ( ! empty( $settings['custom_css'] ) ) {
			$inline_css .= "\n" . wp_strip_all_tags( $settings['custom_css'] );
		}

		wp_add_inline_style( 'ai-chat-widget', $inline_css );
	}
}
