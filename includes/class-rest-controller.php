<?php
/**
 * Proxy REST API controller — forwards chat messages to the configured backend.
 *
 * Endpoint: POST /wp-json/ai-chat/v1/chat
 * Endpoint: GET  /wp-json/ai-chat/v1/health
 *
 * @package AIChatPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AI_Chat_REST_Controller
 */
final class AI_Chat_REST_Controller {

	/** REST API namespace. */
	public const NAMESPACE = 'ai-chat/v1';

	/** Rate-limit window in seconds. */
	private const RATE_WINDOW = 60;

	/** Transient key prefix for rate limiting. */
	private const RATE_KEY_PREFIX = 'ai_chat_rl_';

	/** Maximum message length. */
	private const MAX_MESSAGE_LENGTH = 2000;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/** Register REST routes. */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/chat',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_chat' ),
				'permission_callback' => array( $this, 'check_nonce_and_rate_limit' ),
				'args'                => array(
					'message'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'validate_callback' => array( $this, 'validate_message' ),
					),
					'sessionId' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'channel'   => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'chat',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_health' ),
				'permission_callback' => array( $this, 'check_admin_capability' ),
			)
		);
	}

	// ── Permission callbacks ────────────────────────────────────────────────

	/**
	 * Verify the custom nonce and apply rate limiting for the /chat endpoint.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool|WP_Error
	 */
	public function check_nonce_and_rate_limit( WP_REST_Request $request ): bool|WP_Error {
		// Verify nonce (works for both logged-in and anonymous visitors).
		$nonce = $request->get_header( 'X-AI-Chat-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'ai_chat_proxy' ) ) {
			return new WP_Error(
				'invalid_nonce',
				__( 'Invalid or missing security token.', 'ai-chat-plugin' ),
				array( 'status' => 403 )
			);
		}

		// Rate limit.
		$limit_error = $this->enforce_rate_limit();
		if ( is_wp_error( $limit_error ) ) {
			return $limit_error;
		}

		// Ensure proxy mode is actually enabled.
		$settings = AI_Chat_Sanitizer::get_settings();
		if ( 'proxy' !== $settings['connection_mode'] ) {
			return new WP_Error(
				'proxy_disabled',
				__( 'Proxy mode is not enabled.', 'ai-chat-plugin' ),
				array( 'status' => 403 )
			);
		}

		if ( empty( $settings['backend_url'] ) ) {
			return new WP_Error(
				'no_backend',
				__( 'No backend URL configured.', 'ai-chat-plugin' ),
				array( 'status' => 503 )
			);
		}

		return true;
	}

	/**
	 * Restrict health endpoint to users who can manage options.
	 *
	 * @return bool|WP_Error
	 */
	public function check_admin_capability(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to access this endpoint.', 'ai-chat-plugin' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	// ── Validation callbacks ────────────────────────────────────────────────

	/**
	 * Validate the message field.
	 *
	 * @param mixed           $value   Value to validate.
	 * @param WP_REST_Request $request Incoming request.
	 * @param string          $param   Parameter name.
	 * @return true|WP_Error
	 */
	public function validate_message( mixed $value, WP_REST_Request $request, string $param ): bool|WP_Error {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return new WP_Error( 'empty_message', __( 'Message cannot be empty.', 'ai-chat-plugin' ) );
		}
		if ( mb_strlen( $value ) > self::MAX_MESSAGE_LENGTH ) {
			return new WP_Error(
				'message_too_long',
				/* translators: %d: maximum allowed characters. */
				sprintf( __( 'Message must not exceed %d characters.', 'ai-chat-plugin' ), self::MAX_MESSAGE_LENGTH )
			);
		}
		return true;
	}

	// ── Endpoint handlers ───────────────────────────────────────────────────

	/**
	 * Proxy a chat message to the configured backend.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_chat( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$settings   = AI_Chat_Sanitizer::get_settings();
		$message    = $request->get_param( 'message' );
		$session_id = AI_Chat_Session_Manager::validate_or_create(
			(string) $request->get_param( 'sessionId' )
		);

		/**
		 * Allow developers to modify or validate the payload before forwarding.
		 *
		 * @param array           $payload  Message payload.
		 * @param WP_REST_Request $request  Original request.
		 */
		$payload = apply_filters(
			'ai_chat_proxy_payload',
			array(
				'sessionId' => $session_id,
				'message'   => $message,
				'channel'   => 'chat',
			),
			$request
		);

		$client   = new AI_Chat_API_Client( $settings['backend_url'], (int) $settings['request_timeout'] );
		$response = $client->send_chat( (string) $payload['message'], (string) $payload['sessionId'] );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				$response->get_error_code(),
				$response->get_error_message(),
				array( 'status' => $this->wp_error_to_http_status( $response ) )
			);
		}

		/**
		 * Allow developers to modify the response before it is returned to the browser.
		 *
		 * @param array           $data    Backend response data.
		 * @param WP_REST_Request $request Original request.
		 */
		$data = apply_filters( 'ai_chat_proxy_response', $response, $request );

		return rest_ensure_response( $data );
	}

	/**
	 * Proxy a health check to the configured backend (admin only).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_health( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$settings = AI_Chat_Sanitizer::get_settings();

		if ( empty( $settings['backend_url'] ) ) {
			return rest_ensure_response(
				array( 'status' => 'UNCONFIGURED', 'message' => __( 'No backend URL configured.', 'ai-chat-plugin' ) )
			);
		}

		$client = new AI_Chat_API_Client( $settings['backend_url'], 10 );
		$result = $client->check_health();

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				array( 'status' => 'DOWN', 'message' => $result->get_error_message() )
			);
		}

		return rest_ensure_response( $result );
	}

	// ── Rate limiting ───────────────────────────────────────────────────────

	/**
	 * Check rate limit for the current visitor.
	 *
	 * Uses WordPress transients. The window is fixed (does not slide):
	 * a fresh 60-second bucket is created on the first request.
	 *
	 * @return true|WP_Error
	 */
	private function enforce_rate_limit(): bool|WP_Error {
		$settings = AI_Chat_Sanitizer::get_settings();
		$limit    = (int) $settings['rate_limit'];
		$ip       = $this->get_client_ip();
		$key      = self::RATE_KEY_PREFIX . md5( $ip );

		$entry = get_transient( $key );

		if ( false === $entry ) {
			// First request in this window.
			set_transient( $key, array( 'count' => 1, 'expires' => time() + self::RATE_WINDOW ), self::RATE_WINDOW );
			return true;
		}

		if ( ! is_array( $entry ) ) {
			set_transient( $key, array( 'count' => 1, 'expires' => time() + self::RATE_WINDOW ), self::RATE_WINDOW );
			return true;
		}

		if ( $entry['count'] >= $limit ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many requests. Please wait before sending another message.', 'ai-chat-plugin' ),
				array( 'status' => 429 )
			);
		}

		// Increment within existing window without resetting the expiry.
		$remaining_ttl = max( 1, (int) ( $entry['expires'] - time() ) );
		$entry['count']++;
		set_transient( $key, $entry, $remaining_ttl );

		return true;
	}

	/**
	 * Retrieve the real client IP address.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		$candidates = array(
			'HTTP_CLIENT_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);

		foreach ( $candidates as $key ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below via filter_var.
			$value = isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) ) : '';
			if ( '' === $value ) {
				continue;
			}
			// Take the first IP if there are multiple (e.g. X-Forwarded-For chain).
			$ip = trim( explode( ',', $value )[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Map a WP_Error code to an appropriate HTTP status code.
	 *
	 * @param WP_Error $error The error.
	 * @return int
	 */
	private function wp_error_to_http_status( WP_Error $error ): int {
		$data = $error->get_error_data();
		if ( is_array( $data ) && isset( $data['status'] ) ) {
			return (int) $data['status'];
		}
		return 502;
	}
}
