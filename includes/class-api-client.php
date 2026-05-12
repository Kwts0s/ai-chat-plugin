<?php
/**
 * HTTP client — sends requests to the configured backend API.
 *
 * @package AIChatPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AI_Chat_API_Client
 */
final class AI_Chat_API_Client {

	/** @var string Backend base URL (no trailing slash). */
	private string $base_url;

	/** @var int Request timeout in seconds. */
	private int $timeout;

	/**
	 * Constructor.
	 *
	 * @param string $base_url Backend base URL.
	 * @param int    $timeout  Request timeout seconds.
	 */
	public function __construct( string $base_url, int $timeout = 30 ) {
		$this->base_url = rtrim( $base_url, '/' );
		$this->timeout  = max( 5, min( 120, $timeout ) );
	}

	// ── Public methods ──────────────────────────────────────────────────────

	/**
	 * Send a chat message to the backend.
	 *
	 * @param string $message   User message.
	 * @param string $session_id Session ID.
	 * @return array{sessionId: string, text: string, channel: string}|WP_Error
	 */
	public function send_chat( string $message, string $session_id ) {
		if ( empty( $this->base_url ) ) {
			return new WP_Error( 'no_backend', __( 'Backend URL is not configured.', 'ai-chat-plugin' ) );
		}

		$body = wp_json_encode(
			array(
				'sessionId' => $session_id,
				'message'   => $message,
				'channel'   => 'chat',
			)
		);

		if ( false === $body ) {
			return new WP_Error( 'encode_error', __( 'Failed to encode request body.', 'ai-chat-plugin' ) );
		}

		$response = $this->post( '/chat', $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || ! isset( $data['text'] ) ) {
			return new WP_Error( 'bad_response', __( 'Invalid response from backend.', 'ai-chat-plugin' ) );
		}

		return $data;
	}

	/**
	 * Call the backend health endpoint.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function check_health() {
		if ( empty( $this->base_url ) ) {
			return new WP_Error( 'no_backend', __( 'Backend URL is not configured.', 'ai-chat-plugin' ) );
		}

		$response = wp_remote_get(
			$this->base_url . '/chat/health',
			array(
				'timeout'    => $this->timeout,
				'user-agent' => $this->user_agent(),
				'sslverify'  => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? $data : array( 'status' => (int) $code >= 200 && (int) $code < 300 ? 'UP' : 'DOWN' );
	}

	// ── Private helpers ─────────────────────────────────────────────────────

	/**
	 * Perform a POST request to the given path.
	 *
	 * @param string $path Relative path (e.g. '/chat').
	 * @param string $body JSON body.
	 * @return array<string, mixed>|WP_Error WordPress response or error.
	 */
	private function post( string $path, string $body ) {
		/**
		 * Allow developers to modify the outgoing args.
		 *
		 * @param array  $args     wp_remote_post args.
		 * @param string $path     Request path.
		 * @param string $base_url Backend base URL.
		 */
		$args = apply_filters(
			'ai_chat_api_request_args',
			array(
				'method'      => 'POST',
				'timeout'     => $this->timeout,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
					'User-Agent'   => $this->user_agent(),
				),
				'body'        => $body,
				'data_format' => 'body',
				'sslverify'   => true,
			),
			$path,
			$this->base_url
		);

		$response = wp_remote_post( $this->base_url . $path, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$msg = wp_remote_retrieve_response_message( $response );
			return new WP_Error(
				'backend_error',
				/* translators: %1$d HTTP status code, %2$s status message. */
				sprintf( __( 'Backend returned HTTP %1$d: %2$s', 'ai-chat-plugin' ), $code, $msg ),
				array( 'status' => $code )
			);
		}

		return $response;
	}

	/**
	 * Build the User-Agent string.
	 *
	 * @return string
	 */
	private function user_agent(): string {
		return sprintf( 'WordPress/%s AI-Chat-Plugin/%s', get_bloginfo( 'version' ), AI_CHAT_VERSION );
	}
}
