/**
 * AI Chat Plugin — Frontend Widget
 *
 * Vanilla JS, no external dependencies.
 * Config is provided by PHP via wp_localize_script as `aiChatWidgetConfig`.
 *
 * @package AIChatPlugin
 */

/* global aiChatWidgetConfig */

( function () {
	'use strict';

	/** @type {Record<string, *>} */
	var cfg = ( typeof aiChatWidgetConfig !== 'undefined' ) ? aiChatWidgetConfig : {};

	if ( ! cfg.enabled ) {
		return;
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Escape a string for safe insertion as HTML text content.
	 *
	 * @param {string} str
	 * @returns {string}
	 */
	function escHtml( str ) {
		var d = document.createElement( 'div' );
		d.textContent = String( str );
		return d.innerHTML;
	}

	/**
	 * Conditional console logger — only outputs when debug mode is on.
	 *
	 * @param {...*} args
	 */
	function log() {
		if ( cfg.debug ) {
			var args = Array.prototype.slice.call( arguments );
			args.unshift( '[AI Chat]' );
			console.log.apply( console, args ); // eslint-disable-line no-console
		}
	}

	// ── Session management ────────────────────────────────────────────────────

	var Session = {
		/** @type {string} */
		key: 'ai_chat_session_id',
		/** @type {string} */
		id: '',

		init: function () {
			try {
				this.id = localStorage.getItem( this.key ) || '';
			} catch ( e ) {
				this.id = '';
			}
			if ( ! this.id ) {
				this.id = this.generate();
				this.persist();
			}
			log( 'Session ID:', this.id );
		},

		generate: function () {
			if ( typeof crypto !== 'undefined' && crypto.randomUUID ) {
				return crypto.randomUUID();
			}
			// Fallback UUID v4.
			return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( c ) {
				var r = ( Math.random() * 16 ) | 0;
				var v = c === 'x' ? r : ( r & 0x3 ) | 0x8;
				return v.toString( 16 );
			} );
		},

		persist: function () {
			try {
				localStorage.setItem( this.key, this.id );
			} catch ( e ) { /* Storage unavailable */ }
		},

		reset: function () {
			this.id = this.generate();
			this.persist();
			if ( cfg.storeTranscript ) {
				try { localStorage.removeItem( Transcript.key ); } catch ( e ) { /* noop */ }
			}
			log( 'Session reset:', this.id );
		},
	};

	// ── Transcript ────────────────────────────────────────────────────────────

	var Transcript = {
		key: 'ai_chat_transcript',
		/** @type {Array<{role: string, text: string, ts: number}>} */
		messages: [],

		load: function () {
			if ( ! cfg.storeTranscript ) { return; }
			try {
				var stored = localStorage.getItem( this.key );
				if ( stored ) {
					this.messages = JSON.parse( stored ) || [];
				}
			} catch ( e ) {
				this.messages = [];
			}
		},

		add: function ( role, text ) {
			this.messages.push( { role: role, text: text, ts: Date.now() } );
			if ( cfg.storeTranscript ) {
				try {
					localStorage.setItem( this.key, JSON.stringify( this.messages ) );
				} catch ( e ) { /* Storage full */ }
			}
		},

		clear: function () {
			this.messages = [];
			try { localStorage.removeItem( this.key ); } catch ( e ) { /* noop */ }
		},
	};

	// ── API client ────────────────────────────────────────────────────────────

	var API = {
		/**
		 * Send a message and return the backend response.
		 *
		 * @param {string} message
		 * @returns {Promise<{sessionId: string, text: string, channel: string}>}
		 */
		send: function ( message ) {
			var timeoutMs = ( ( cfg.timeout || 30 ) * 1000 );
			var controller = ( typeof AbortController !== 'undefined' ) ? new AbortController() : null;
			var timer = controller ? setTimeout( function () { controller.abort(); }, timeoutMs ) : null;

			var url, headers, body;

			if ( cfg.mode === 'direct' ) {
				url     = ( cfg.backendUrl || '' ) + '/chat';
				headers = { 'Content-Type': 'application/json' };
			} else {
				url     = cfg.proxyUrl || '';
				headers = {
					'Content-Type':    'application/json',
					'X-AI-Chat-Nonce': cfg.nonce || '',
				};
			}

			body = JSON.stringify( {
				sessionId: Session.id,
				message:   message,
				channel:   'chat',
			} );

			var fetchOptions = {
				method:  'POST',
				headers: headers,
				body:    body,
			};
			if ( controller ) {
				fetchOptions.signal = controller.signal;
			}

			return fetch( url, fetchOptions ).then( function ( response ) {
				if ( timer ) { clearTimeout( timer ); }

				if ( ! response.ok ) {
					return response.json().catch( function () { return {}; } ).then( function ( errData ) {
						throw new Error( errData.message || ( 'HTTP ' + response.status ) );
					} );
				}

				return response.json();
			} ).then( function ( data ) {
				// Let backend update the session ID if needed.
				if ( data && data.sessionId ) {
					Session.id = data.sessionId;
					Session.persist();
				}
				return data;
			} ).catch( function ( err ) {
				if ( timer ) { clearTimeout( timer ); }
				if ( err && err.name === 'AbortError' ) {
					throw new Error( ( cfg.i18n && cfg.i18n.timeout ) || 'Request timed out. Please try again.' );
				}
				throw err;
			} );
		},
	};

	// ── Widget UI ─────────────────────────────────────────────────────────────

	var Widget = {
		el: {
			root:     null,
			bubble:   null,
			panel:    null,
			messages: null,
			input:    null,
			sendBtn:  null,
			closeBtn: null,
		},
		isOpen:    false,
		isLoading: false,

		// ── Init ────────────────────────────────────────────────────────────────

		init: function () {
			this.createBubble();
			this.createPanel();
			this.bindEvents();

			// Restore transcript.
			Transcript.load();
			var self = this;
			Transcript.messages.forEach( function ( m ) {
				self.addMessage( m.role, m.text, false );
			} );

			// Show welcome message only if there is no transcript.
			if ( ! Transcript.messages.length && cfg.welcomeMessage ) {
				this.addMessage( 'bot', cfg.welcomeMessage, false );
			}

			// Handle shortcode trigger buttons.
			document.addEventListener( 'click', function ( e ) {
				if ( e.target && e.target.closest( '[data-ai-chat-open]' ) ) {
					self.open();
				}
			} );

			log( 'Widget initialized' );
		},

		// ── DOM creation ────────────────────────────────────────────────────────

		createBubble: function () {
			var bubble = document.createElement( 'button' );
			bubble.id        = 'ai-chat-bubble';
			bubble.type      = 'button';
			bubble.className = 'ai-chat-bubble';
			bubble.setAttribute( 'aria-label', ( cfg.i18n && cfg.i18n.openChat ) || 'Open chat' );
			bubble.setAttribute( 'aria-expanded', 'false' );
			bubble.setAttribute( 'aria-controls', 'ai-chat-panel' );
			bubble.innerHTML = cfg.bubbleIcon || this.defaultIcon();

			document.body.appendChild( bubble );
			this.el.bubble = bubble;
		},

		defaultIcon: function () {
			return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"'
				+ ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
				+ ' stroke-linejoin="round" aria-hidden="true" focusable="false">'
				+ '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>'
				+ '</svg>';
		},

		createPanel: function () {
			var panel = document.createElement( 'div' );
			panel.id        = 'ai-chat-panel';
			panel.className = 'ai-chat-panel';
			panel.setAttribute( 'role', 'dialog' );
			panel.setAttribute( 'aria-label', escHtml( cfg.companyName || 'Chat' ) );
			panel.setAttribute( 'aria-hidden', 'true' );
			panel.innerHTML = this.panelHTML();

			document.body.appendChild( panel );

			this.el.panel    = panel;
			this.el.messages = panel.querySelector( '.ai-chat-messages' );
			this.el.input    = panel.querySelector( '.ai-chat-input' );
			this.el.sendBtn  = panel.querySelector( '.ai-chat-send-btn' );
			this.el.closeBtn = panel.querySelector( '.ai-chat-close-btn' );
		},

		panelHTML: function () {
			var logoHtml = cfg.logoUrl
				? '<img src="' + escHtml( cfg.logoUrl ) + '" alt="' + escHtml( cfg.companyName || '' ) + '" class="ai-chat-logo" />'
				: '<div class="ai-chat-logo-placeholder" aria-hidden="true"></div>';

			var disclaimerHtml = cfg.disclaimer
				? '<p class="ai-chat-disclaimer">' + escHtml( cfg.disclaimer ) + '</p>'
				: '';

			var closeLbl  = ( cfg.i18n && cfg.i18n.close )       || 'Close chat';
			var msgLbl    = ( cfg.i18n && cfg.i18n.messages )     || 'Chat messages';
			var inputLbl  = ( cfg.i18n && cfg.i18n.inputLabel )   || 'Message input';
			var placeholder = ( cfg.i18n && cfg.i18n.placeholder ) || 'Type a message\u2026';
			var sendLbl   = ( cfg.i18n && cfg.i18n.send )         || 'Send message';
			var onlineText = escHtml( cfg.onlineText || 'Online' );

			return ''
				+ '<div class="ai-chat-header">'
				+   '<div class="ai-chat-header-brand">'
				+     logoHtml
				+     '<div class="ai-chat-header-info">'
				+       '<span class="ai-chat-company-name">' + escHtml( cfg.companyName || 'AI Assistant' ) + '</span>'
				+       '<span class="ai-chat-company-subtitle">' + escHtml( cfg.companySubtitle || '' ) + '</span>'
				+     '</div>'
				+   '</div>'
				+   '<div class="ai-chat-header-actions">'
				+     '<span class="ai-chat-online-badge" aria-live="polite">'
				+       '<span class="ai-chat-online-dot" aria-hidden="true"></span>'
				+       onlineText
				+     '</span>'
				+     '<button class="ai-chat-close-btn" type="button" aria-label="' + escHtml( closeLbl ) + '">'
				+       '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"'
				+       ' fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"'
				+       ' stroke-linejoin="round" aria-hidden="true" focusable="false">'
				+       '<line x1="18" y1="6" x2="6" y2="18"></line>'
				+       '<line x1="6" y1="6" x2="18" y2="18"></line>'
				+       '</svg>'
				+     '</button>'
				+   '</div>'
				+ '</div>'
				+ '<div class="ai-chat-messages" role="log" aria-live="polite" aria-label="' + escHtml( msgLbl ) + '"></div>'
				+ '<div class="ai-chat-input-area">'
				+   '<div class="ai-chat-input-row">'
				+     '<textarea class="ai-chat-input" rows="1"'
				+       ' placeholder="' + escHtml( placeholder ) + '"'
				+       ' aria-label="' + escHtml( inputLbl ) + '"'
				+       ' maxlength="2000"></textarea>'
				+     '<button class="ai-chat-send-btn" type="button"'
				+       ' aria-label="' + escHtml( sendLbl ) + '" disabled>'
				+       '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"'
				+       ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
				+       ' stroke-linejoin="round" aria-hidden="true" focusable="false">'
				+       '<line x1="22" y1="2" x2="11" y2="13"></line>'
				+       '<polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>'
				+       '</svg>'
				+     '</button>'
				+   '</div>'
				+   disclaimerHtml
				+ '</div>';
		},

		// ── Events ──────────────────────────────────────────────────────────────

		bindEvents: function () {
			var self = this;

			this.el.bubble.addEventListener( 'click', function () { self.toggle(); } );
			this.el.closeBtn.addEventListener( 'click', function () { self.close(); } );

			this.el.input.addEventListener( 'input', function () { self.onInputChange(); } );
			this.el.input.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' && ! e.shiftKey ) {
					e.preventDefault();
					self.handleSend();
				}
			} );

			this.el.sendBtn.addEventListener( 'click', function () { self.handleSend(); } );

			// ESC closes the panel and returns focus to the bubble.
			document.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Escape' && self.isOpen ) {
					self.close();
					self.el.bubble.focus();
				}
			} );
		},

		// ── Open / close ─────────────────────────────────────────────────────────

		toggle: function () {
			this.isOpen ? this.close() : this.open();
		},

		open: function () {
			this.isOpen = true;
			this.el.panel.classList.add( 'ai-chat-panel--open' );
			this.el.panel.setAttribute( 'aria-hidden', 'false' );
			this.el.bubble.setAttribute( 'aria-expanded', 'true' );
			this.scrollToBottom();
			var self = this;
			setTimeout( function () { self.el.input.focus(); }, 300 );
			log( 'Panel opened' );
		},

		close: function () {
			this.isOpen = false;
			this.el.panel.classList.remove( 'ai-chat-panel--open' );
			this.el.panel.setAttribute( 'aria-hidden', 'true' );
			this.el.bubble.setAttribute( 'aria-expanded', 'false' );
			log( 'Panel closed' );
		},

		// ── Input handling ────────────────────────────────────────────────────────

		onInputChange: function () {
			var val = this.el.input.value.trim();
			this.el.sendBtn.disabled = ( ! val || this.isLoading );

			// Auto-resize the textarea.
			this.el.input.style.height = 'auto';
			this.el.input.style.height = Math.min( this.el.input.scrollHeight, 120 ) + 'px';
		},

		handleSend: function () {
			var message = this.el.input.value.trim();
			if ( ! message || this.isLoading ) { return; }

			this.el.input.value     = '';
			this.el.input.style.height = 'auto';
			this.el.sendBtn.disabled   = true;

			this.addMessage( 'user', message );
			this.setLoading( true );

			var self = this;
			API.send( message ).then( function ( data ) {
				self.removeTyping();
				self.addMessage( 'bot', ( data && data.text ) ? data.text : '' );
			} ).catch( function ( err ) {
				self.removeTyping();
				self.showError( ( err && err.message ) || ( ( cfg.i18n && cfg.i18n.error ) || 'An error occurred. Please try again.' ) );
				log( 'Error:', err );
			} ).then( function () {
				self.setLoading( false );
			} );
		},

		// ── Message rendering ─────────────────────────────────────────────────────

		/**
		 * @param {string}  role   'user' | 'bot'
		 * @param {string}  text   Message text.
		 * @param {boolean} scroll Whether to scroll to bottom.
		 */
		addMessage: function ( role, text, scroll ) {
			if ( scroll === undefined ) { scroll = true; }

			var msgEl  = document.createElement( 'div' );
			msgEl.className = 'ai-chat-message ai-chat-message--' + role;
			msgEl.setAttribute( 'role', 'article' );

			var bubble = document.createElement( 'div' );
			bubble.className = 'ai-chat-message-bubble';
			bubble.innerHTML = this.formatText( text );

			msgEl.appendChild( bubble );
			this.el.messages.appendChild( msgEl );

			Transcript.add( role, text );

			if ( scroll ) { this.scrollToBottom(); }
		},

		/**
		 * Escape HTML then apply minimal safe formatting.
		 *
		 * @param {string} text
		 * @returns {string}
		 */
		formatText: function ( text ) {
			var safe = escHtml( text );
			// Newlines → <br>.
			safe = safe.replace( /\n/g, '<br>' );
			// **bold**
			safe = safe.replace( /\*\*(.*?)\*\*/g, '<strong>$1</strong>' );
			// *italic*
			safe = safe.replace( /\*(.*?)\*/g, '<em>$1</em>' );
			return safe;
		},

		// ── Loading / typing ──────────────────────────────────────────────────────

		setLoading: function ( loading ) {
			this.isLoading = loading;
			this.el.sendBtn.disabled = loading || ! this.el.input.value.trim();
			if ( loading ) { this.showTyping(); }
		},

		showTyping: function () {
			var el = document.createElement( 'div' );
			el.className = 'ai-chat-message ai-chat-message--bot ai-chat-typing';
			el.setAttribute( 'aria-label', 'Assistant is typing' );
			el.innerHTML = '<div class="ai-chat-message-bubble ai-chat-typing-bubble">'
				+ '<span class="ai-chat-typing-dot"></span>'
				+ '<span class="ai-chat-typing-dot"></span>'
				+ '<span class="ai-chat-typing-dot"></span>'
				+ '</div>';
			this.el.messages.appendChild( el );
			this.scrollToBottom();
		},

		removeTyping: function () {
			var el = this.el.messages.querySelector( '.ai-chat-typing' );
			if ( el ) { el.remove(); }
		},

		// ── Error ────────────────────────────────────────────────────────────────

		showError: function ( msg ) {
			var el = document.createElement( 'div' );
			el.className = 'ai-chat-error-msg';
			el.setAttribute( 'role', 'alert' );
			el.textContent = msg;
			this.el.messages.appendChild( el );
			this.scrollToBottom();
		},

		// ── Scroll ───────────────────────────────────────────────────────────────

		scrollToBottom: function () {
			var msgs = this.el.messages;
			requestAnimationFrame( function () {
				msgs.scrollTop = msgs.scrollHeight;
			} );
		},
	};

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	function boot() {
		Session.init();
		Widget.init();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

} )();
