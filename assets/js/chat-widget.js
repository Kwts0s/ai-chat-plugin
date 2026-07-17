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

	/**
	 * Normalize backend responses across API versions.
	 *
	 * @param {*} data Backend response payload.
	 * @returns {?Object}
	 */
	function normalizeResponse( data ) {
		if ( ! data ) {
			return null;
		}

		if ( Array.isArray( data ) ) {
			data = data[ 0 ] || null;
		}

		if ( ! data || typeof data !== 'object' || typeof data.text !== 'string' ) {
			return null;
		}

		return data;
	}

	/**
	 * Allow only safe absolute http(s) URLs from the backend.
	 *
	 * @param {*} url
	 * @returns {string}
	 */
	function sanitizeAttachmentUrl( url ) {
		if ( typeof url !== 'string' || ! url ) {
			return '';
		}

		try {
			var parsed = new URL( url, window.location.href );
			if ( parsed.protocol !== 'http:' && parsed.protocol !== 'https:' ) {
				return '';
			}

			return parsed.href;
		} catch ( e ) {
			return '';
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
			// Fallback: use crypto.getRandomValues() (supported in all browsers that
			// meet the WordPress 6.0 browser support requirement).
			if ( typeof crypto !== 'undefined' && crypto.getRandomValues ) {
				var bytes = new Uint8Array( 16 );
				crypto.getRandomValues( bytes );
				// Set version to 4.
				bytes[ 6 ] = ( bytes[ 6 ] & 0x0f ) | 0x40;
				// Set variant bits.
				bytes[ 8 ] = ( bytes[ 8 ] & 0x3f ) | 0x80;
				var hex = Array.from( bytes ).map( function ( b ) {
					return b.toString( 16 ).padStart( 2, '0' );
				} ).join( '' );
				return (
					hex.slice( 0, 8 ) + '-' +
					hex.slice( 8, 12 ) + '-' +
					hex.slice( 12, 16 ) + '-' +
					hex.slice( 16, 20 ) + '-' +
					hex.slice( 20 )
				);
			}
			// Emergency fallback: timestamp-based identifier for environments where
			// the crypto API is completely absent. Not cryptographically random but
			// sufficient for a chat session correlation ID. Such environments are not
			// supported by WordPress 6.0 itself.
			var ts  = Date.now().toString( 16 ).padStart( 12, '0' );
			var perf = ( typeof performance !== 'undefined' && performance.now )
				? Math.floor( performance.now() * 1000 ).toString( 16 ).padStart( 12, '0' )
				: '000000000000';
			var combined = ( ts + perf + '00000000' ).slice( 0, 32 );
			combined = combined.slice( 0, 12 ) + '4' + combined.slice( 13, 16 )
				+ '8' + combined.slice( 17, 20 ) + combined.slice( 20 );
			return (
				combined.slice( 0, 8 ) + '-' +
				combined.slice( 8, 12 ) + '-' +
				combined.slice( 12, 16 ) + '-' +
				combined.slice( 16, 20 ) + '-' +
				combined.slice( 20, 32 )
			);
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
		 * Fetch a fresh proxy nonce.
		 *
		 * @returns {Promise<boolean>}
		 */
		refreshNonce: function () {
			if ( cfg.mode !== 'proxy' || ! cfg.nonceUrl ) {
				return Promise.resolve( false );
			}

			return fetch( cfg.nonceUrl, {
				method:      'GET',
				cache:       'no-store',
				credentials: 'same-origin',
			} ).then( function ( response ) {
				if ( ! response.ok ) {
					return false;
				}
				return response.json().then( function ( data ) {
					if ( data && data.nonce ) {
						cfg.nonce = String( data.nonce );
						log( 'Nonce refreshed' );
						return true;
					}
					return false;
				} ).catch( function () {
					return false;
				} );
			} ).catch( function () {
				return false;
			} );
		},

		/**
		 * Send a message and return the backend response.
		 *
		 * @param {string} message
		 * @param {boolean} hasRetriedNonce
		 * @returns {Promise<{sessionId: string, text: string, channel: string, attachments?: Array<Object>}>}
		 */
		send: function ( message, hasRetriedNonce ) {
			var retried = !! hasRetriedNonce;
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
						if (
							cfg.mode === 'proxy' &&
							! retried &&
							response.status === 403 &&
							errData &&
							errData.code === 'invalid_nonce'
						) {
							return API.refreshNonce().then( function ( refreshed ) {
								if ( refreshed ) {
									return API.send( message, true );
								}
								throw new Error( errData.message || ( 'HTTP ' + response.status ) );
							} );
						}
						throw new Error( errData.message || ( 'HTTP ' + response.status ) );
					} );
				}

				return response.json();
			} ).then( function ( data ) {
				var normalized = normalizeResponse( data );
				if ( ! normalized ) {
					throw new Error( ( cfg.i18n && cfg.i18n.error ) || 'An error occurred. Please try again.' );
				}

				// Let backend update the session ID if needed.
				if ( normalized.sessionId ) {
					Session.id = normalized.sessionId;
					Session.persist();
				}
				return normalized;
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
			welcomeMessage: null,
			readyQuestionsWrap: null,
		},
		isOpen:    false,
		isLoading: false,
		readyQuestionsDismissed: false,

		// ── Init ────────────────────────────────────────────────────────────────

		init: function () {
			this.createBubble();
			this.createPanel();
			this.bindEvents();

			// Restore transcript.
			Transcript.load();
			this.readyQuestionsDismissed = Transcript.messages.some( function ( m ) {
				return m && m.role === 'user';
			} );
			var self = this;
			Transcript.messages.forEach( function ( m ) {
				self.addMessage( m.role, m.text, false );
			} );

			// Show welcome message only if there is no transcript.
			if ( ! Transcript.messages.length && cfg.welcomeMessage ) {
				this.addMessage( 'bot', cfg.welcomeMessage, false, {
					isWelcome: true,
					persist: false,
				} );
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

			if ( cfg.bubbleIconUrl ) {
				var bubbleIconImg = document.createElement( 'img' );
				bubbleIconImg.className = 'ai-chat-bubble-icon-img';
				bubbleIconImg.src = String( cfg.bubbleIconUrl );
				bubbleIconImg.alt = '';
				bubbleIconImg.setAttribute( 'aria-hidden', 'true' );
				bubble.appendChild( bubbleIconImg );
			} else {
				bubble.innerHTML = cfg.bubbleIcon || this.defaultIcon();
			}

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
			var logoHtml = this.brandIconHTML();

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
				+       '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"'
				+       ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
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

		brandIconHTML: function () {
			if ( cfg.bubbleIconUrl ) {
				return '<img src="' + escHtml( cfg.bubbleIconUrl ) + '" alt="' + escHtml( cfg.companyName || '' ) + '" class="ai-chat-logo ai-chat-logo--bubble-icon" />';
			}
			if ( cfg.bubbleIcon ) {
				return '<span class="ai-chat-logo ai-chat-logo--bubble-icon ai-chat-logo-svg" aria-hidden="true">' + cfg.bubbleIcon + '</span>';
			}
			if ( cfg.logoUrl ) {
				return '<img src="' + escHtml( cfg.logoUrl ) + '" alt="' + escHtml( cfg.companyName || '' ) + '" class="ai-chat-logo" />';
			}
			return '<div class="ai-chat-logo-placeholder" aria-hidden="true">' + this.defaultIcon() + '</div>';
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
			this.el.panel.addEventListener( 'click', function ( e ) {
				var button = e.target && e.target.closest ? e.target.closest( '.ai-chat-ready-question' ) : null;
				if ( ! button || ! self.el.panel.contains( button ) || self.isLoading ) {
					return;
				}
				var question = button.getAttribute( 'data-question' ) || '';
				self.sendMessage( question );
			} );

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
			this.sendMessage( message );
		},

		sendMessage: function ( message ) {
			var trimmedMessage = String( message || '' ).trim();
			if ( ! trimmedMessage || this.isLoading ) { return; }
			this.dismissReadyQuestions();

			this.el.input.value     = '';
			this.el.input.style.height = 'auto';
			this.el.sendBtn.disabled   = true;

			this.addMessage( 'user', trimmedMessage );
			this.setLoading( true );

			var self = this;
			API.send( trimmedMessage ).then( function ( data ) {
				self.removeTyping();
				self.addMessage( 'bot', ( data && data.text ) ? data.text : '', true, {
					attachments: data && data.attachments,
				} );
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
		addMessage: function ( role, text, scroll, options ) {
			if ( scroll === undefined ) { scroll = true; }
			options = options || {};

			var msgEl  = document.createElement( 'div' );
			msgEl.className = 'ai-chat-message ai-chat-message--' + role;
			if ( options.isWelcome ) {
				msgEl.classList.add( 'ai-chat-message--welcome' );
				this.el.welcomeMessage = msgEl;
			}
			msgEl.setAttribute( 'role', 'article' );

			var bubble = document.createElement( 'div' );
			bubble.className = 'ai-chat-message-bubble';
			bubble.innerHTML = this.formatText( text );

			if ( role === 'bot' && options.attachments ) {
				this.appendAttachments( bubble, options.attachments );
			}

			msgEl.appendChild( bubble );
			this.el.messages.appendChild( msgEl );
			if ( role === 'bot' ) {
				this.ensureReadyQuestionsAfterFirstBotMessage( msgEl );
			}

			if ( options.persist !== false ) {
				Transcript.add( role, text );
			}

			if ( scroll ) { this.scrollToBottom(); }
		},

		appendAttachments: function ( container, attachments ) {
			if ( ! Array.isArray( attachments ) || ! attachments.length ) {
				return;
			}

			attachments.forEach( function ( attachment ) {
				if ( ! attachment || typeof attachment !== 'object' ) {
					return;
				}

				if ( attachment.type === 'map' ) {
					var mapWrap = document.createElement( 'div' );
					mapWrap.className = 'ai-chat-attachment ai-chat-attachment--map';

					if ( attachment.embedUrl ) {
						var embedUrl = sanitizeAttachmentUrl( attachment.embedUrl );
						if ( ! embedUrl ) {
							return;
						}
						var iframe = document.createElement( 'iframe' );
						iframe.className = 'ai-chat-attachment-map';
						iframe.src = embedUrl;
						iframe.loading = 'lazy';
						iframe.referrerPolicy = 'no-referrer-when-downgrade';
						iframe.title = String( attachment.label || 'Map' );
						mapWrap.appendChild( iframe );
					}

					if ( attachment.url ) {
						var mapUrl = sanitizeAttachmentUrl( attachment.url );
						if ( ! mapUrl ) {
							container.appendChild( mapWrap );
							return;
						}
						var mapLink = document.createElement( 'a' );
						mapLink.className = 'ai-chat-attachment-link';
						mapLink.href = mapUrl;
						mapLink.target = '_blank';
						mapLink.rel = 'noopener noreferrer';
						mapLink.textContent = String( attachment.label || attachment.url );
						mapWrap.appendChild( mapLink );
					}

					container.appendChild( mapWrap );
					return;
				}

				if ( attachment.type === 'image' ) {
					var imageUrl = sanitizeAttachmentUrl( attachment.url || attachment.src );
					if ( ! imageUrl ) {
						return;
					}

					var imageWrap = document.createElement( 'figure' );
					imageWrap.className = 'ai-chat-attachment ai-chat-attachment--image';

					var imageLink = document.createElement( 'a' );
					imageLink.href = imageUrl;
					imageLink.target = '_blank';
					imageLink.rel = 'noopener noreferrer';
					imageLink.className = 'ai-chat-inline-image-link';

					var image = document.createElement( 'img' );
					image.className = 'ai-chat-inline-image';
					image.src = imageUrl;
					image.alt = String( attachment.label || 'Image attachment' );
					image.loading = 'lazy';
					imageLink.appendChild( image );
					imageWrap.appendChild( imageLink );

					if ( attachment.label ) {
						var caption = document.createElement( 'figcaption' );
						caption.className = 'ai-chat-attachment-caption';
						caption.textContent = String( attachment.label );
						imageWrap.appendChild( caption );
					}

					container.appendChild( imageWrap );
					return;
				}

				if ( attachment.url ) {
					var genericUrl = sanitizeAttachmentUrl( attachment.url );
					if ( ! genericUrl ) {
						return;
					}
					var genericLink = document.createElement( 'a' );
					genericLink.className = 'ai-chat-attachment-link';
					genericLink.href = genericUrl;
					genericLink.target = '_blank';
					genericLink.rel = 'noopener noreferrer';
					genericLink.textContent = String( attachment.label || attachment.url );
					container.appendChild( genericLink );
				}
			} );
		},

		ensureReadyQuestionsAfterFirstBotMessage: function ( anchorMessageEl ) {
			var readyQuestions = Array.isArray( cfg.readyQuestions ) ? cfg.readyQuestions : [];
			var self = this;
			if ( ! readyQuestions.length || this.el.readyQuestionsWrap || this.readyQuestionsDismissed ) {
				return;
			}

			var readyQuestionsLabel = ( cfg.i18n && cfg.i18n.readyQuestions ) || 'Ready to use questions';
			var wrap = document.createElement( 'div' );
			wrap.className = 'ai-chat-ready-questions-wrap';
			wrap.innerHTML = '<div class="ai-chat-ready-questions" aria-label="' + escHtml( readyQuestionsLabel ) + '">'
				+ '<p class="ai-chat-ready-questions-title">' + escHtml( readyQuestionsLabel ) + '</p>'
				+ readyQuestions.map( function ( question ) {
					return '<button class="ai-chat-ready-question" type="button" data-question="'
						+ escHtml( question ) + '">' + escHtml( question ) + '</button>';
				} ).join( '' )
				+ '</div>';

			var anchorBubble = anchorMessageEl.querySelector( '.ai-chat-message-bubble' );
			if ( anchorMessageEl.classList.contains( 'ai-chat-message--welcome' ) && anchorBubble ) {
				wrap.classList.add( 'ai-chat-ready-questions-wrap--inside-welcome' );
				anchorBubble.appendChild( wrap );
			} else {
				anchorMessageEl.insertAdjacentElement( 'afterend', wrap );
			}
			this.el.readyQuestionsWrap = wrap;
			if ( this.readyQuestionsDismissed ) {
				self.dismissReadyQuestions();
			}
		},

		dismissReadyQuestions: function () {
			if ( this.readyQuestionsDismissed ) {
				return;
			}

			this.readyQuestionsDismissed = true;
			if ( this.el.readyQuestionsWrap ) {
				this.el.readyQuestionsWrap.remove();
				this.el.readyQuestionsWrap = null;
			}
			if ( this.el.welcomeMessage ) {
				this.el.welcomeMessage.remove();
				this.el.welcomeMessage = null;
			}
		},

		/**
		 * Escape HTML then apply minimal safe formatting.
		 *
		 * @param {string} text
		 * @returns {string}
		 */
		formatText: function ( text ) {
			var imageTokens = [];
			var safe = String( text || '' ).replace( /!\[([^\]]*)\]\(([^)\s]+)\)/g, function ( match, alt, url ) {
				var imageUrl = sanitizeAttachmentUrl( url );
				if ( ! imageUrl ) {
					return match;
				}

				var token = '@@AI_CHAT_IMAGE_' + imageTokens.length + '@@';
				imageTokens.push(
					'<figure class="ai-chat-attachment ai-chat-attachment--image">'
					+ '<a class="ai-chat-inline-image-link" href="' + escHtml( imageUrl ) + '" target="_blank" rel="noopener noreferrer">'
					+ '<img class="ai-chat-inline-image" src="' + escHtml( imageUrl ) + '" alt="' + escHtml( alt || 'Inline image' ) + '" loading="lazy" />'
					+ '</a>'
					+ ( alt ? '<figcaption class="ai-chat-attachment-caption">' + escHtml( alt ) + '</figcaption>' : '' )
					+ '</figure>'
				);

				return token;
			} );

			safe = escHtml( safe );
			// Newlines → <br>.
			safe = safe.replace( /\n/g, '<br>' );
			// **bold**
			safe = safe.replace( /\*\*(.*?)\*\*/g, '<strong>$1</strong>' );
			// *italic*
			safe = safe.replace( /\*(.*?)\*/g, '<em>$1</em>' );
			imageTokens.forEach( function ( imageHtml, index ) {
				safe = safe.replace( '@@AI_CHAT_IMAGE_' + index + '@@', imageHtml );
			} );
			return safe;
		},

		// ── Loading / typing ──────────────────────────────────────────────────────

		setLoading: function ( loading ) {
			this.isLoading = loading;
			this.el.sendBtn.disabled = loading || ! this.el.input.value.trim();
			Array.prototype.forEach.call( this.el.panel.querySelectorAll( '.ai-chat-ready-question' ), function ( button ) {
				button.disabled = loading;
			} );
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
