<?php
/**
 * Frontend rendering — outputs the chat widget in wp_footer and registers the shortcode.
 *
 * @package AIChatPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AI_Chat_Frontend
 */
final class AI_Chat_Frontend {

	public function __construct() {
		add_action( 'wp_footer', array( $this, 'render_widget' ) );
		add_shortcode( 'ai_chat_widget', array( $this, 'render_shortcode' ) );
	}

	// ── Widget ──────────────────────────────────────────────────────────────

	/**
	 * Output the widget container in wp_footer.
	 *
	 * The actual UI is built by the JS; PHP only outputs the mount point
	 * and a <noscript> fallback.
	 */
	public function render_widget(): void {
		$settings = AI_Chat_Sanitizer::get_settings();

		// Don't render if assets aren't enqueued (page not in scope, or shortcode mode).
		if ( ! wp_script_is( 'ai-chat-widget', 'enqueued' ) ) {
			return;
		}

		include AI_CHAT_DIR . 'templates/chat-widget.php';
	}

	// ── Shortcode ────────────────────────────────────────────────────────────

	/**
	 * Handle the [ai_chat_widget] shortcode.
	 *
	 * Enqueues assets on demand and outputs a trigger button.
	 *
	 * @param array<string, mixed> $atts    Shortcode attributes (unused).
	 * @param string|null          $content Inner content (unused).
	 * @return string HTML output.
	 */
	public function render_shortcode( array $atts, ?string $content = null ): string {
		$settings = AI_Chat_Sanitizer::get_settings();

		// Enqueue assets on-demand.
		if ( ! wp_script_is( 'ai-chat-widget', 'enqueued' ) ) {
			wp_enqueue_style( 'ai-chat-widget' );
			wp_enqueue_script( 'ai-chat-widget' );
		}

		$label = esc_html( $settings['company_name'] ?: __( 'Chat with us', 'ai-chat-plugin' ) );

		return sprintf(
			'<button type="button" class="ai-chat-shortcode-trigger" data-ai-chat-open="1" aria-label="%s">%s</button>',
			esc_attr( sprintf( /* translators: %s: company name. */ __( 'Open chat with %s', 'ai-chat-plugin' ), $label ) ),
			esc_html( __( 'Chat with us', 'ai-chat-plugin' ) )
		);
	}
}
