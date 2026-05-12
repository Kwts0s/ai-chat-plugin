/**
 * AI Chat Plugin — Admin JavaScript
 *
 * Handles color pickers and the "Test Connection" health check.
 *
 * @package AIChatPlugin
 */

/* global aiChatAdmin, jQuery */

( function ( $ ) {
	'use strict';

	$( function () {

		// ── Color pickers ─────────────────────────────────────────────────────
		$( '.ai-chat-color-picker' ).wpColorPicker();

		// ── Test Connection ───────────────────────────────────────────────────
		var $btn    = $( '#ai-chat-test-connection' );
		var $result = $( '#ai-chat-health-result' );

		$btn.on( 'click', function () {
			var backendUrl = $( '#ai_chat_backend_url' ).val().trim();

			if ( ! backendUrl ) {
				$result
					.text( aiChatAdmin.i18n.noUrl )
					.removeClass( 'status-ok status-loading' )
					.addClass( 'status-error' );
				return;
			}

			$result
				.text( aiChatAdmin.i18n.checking )
				.removeClass( 'status-ok status-error' )
				.addClass( 'status-loading' );

			$btn.prop( 'disabled', true );

			$.ajax( {
				url:      aiChatAdmin.healthUrl,
				method:   'GET',
				headers:  { 'X-WP-Nonce': aiChatAdmin.nonce },
				timeout:  15000,
			} ).done( function ( data ) {
				var up = data && ( data.status === 'UP' || data.status === 'up' );
				if ( up ) {
					$result
						.text( aiChatAdmin.i18n.connected )
						.removeClass( 'status-error status-loading' )
						.addClass( 'status-ok' );
				} else {
					var msg = ( data && data.status ) ? data.status : aiChatAdmin.i18n.failed;
					$result
						.text( msg )
						.removeClass( 'status-ok status-loading' )
						.addClass( 'status-error' );
				}
			} ).fail( function ( xhr ) {
				var errMsg = '';
				try {
					var body = JSON.parse( xhr.responseText );
					errMsg = body.message || '';
				} catch ( e ) { /* noop */ }
				$result
					.text( ( errMsg || aiChatAdmin.i18n.failed ) )
					.removeClass( 'status-ok status-loading' )
					.addClass( 'status-error' );
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

	} );

} )( jQuery );
