<?php
/**
 * Chat widget HTML template — output in wp_footer.
 *
 * This file must only be included via AI_Chat_Frontend::render_widget().
 *
 * @package AIChatPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="ai-chat-root" class="ai-chat-root" aria-live="polite">
	<?php /* The floating bubble and panel are injected by chat-widget.js */ ?>
	<noscript>
		<p class="ai-chat-noscript">
			<?php esc_html_e( 'JavaScript is required to use the chat widget.', 'ai-chat-plugin' ); ?>
		</p>
	</noscript>
</div>
