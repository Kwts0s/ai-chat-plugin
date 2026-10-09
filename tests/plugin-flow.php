<?php
/** Run with: php tests/plugin-flow.php (WordPress function stubs, no site required). */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
define( 'AI_CHAT_DIR', dirname( __DIR__ ) . '/' );
define( 'AI_CHAT_URL', 'https://example.test/wp-content/plugins/ai-chat-plugin/' );
define( 'AI_CHAT_VERSION', 'test' );
define( 'AI_CHAT_OPTION_KEY', 'ai_chat_settings' );

class WP_Error {
	public function __construct( private string $code, private string $message, private array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): array { return $this->data; }
}
class WP_REST_Response {
	public array $headers = array();
	public function __construct( public $data ) {}
	public function header( string $name, string $value ): void { $this->headers[ $name ] = $value; }
}
class WP_REST_Request {
	public function __construct( private array $params = array(), private array $headers = array() ) {}
	public function get_param( string $name ) { return $this->params[ $name ] ?? null; }
	public function get_header( string $name ): string { return $this->headers[ strtolower( $name ) ] ?? ''; }
}
class WP_Post {
	public function __construct( public string $post_content ) {}
}

function check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function __( string $text ): string { return $text; }
function esc_html_e( string $text ): void { echo htmlspecialchars( $text ); }
function esc_html( string $text ): string { return htmlspecialchars( $text ); }
function esc_attr( string $text ): string { return htmlspecialchars( $text ); }
function esc_url( string $url ): string { return $url; }
function esc_url_raw( string $url ): string { return $url; }
function sanitize_text_field( string $text ): string { return trim( $text ); }
function wp_parse_args( array $input, array $defaults ): array { return array_merge( $defaults, $input ); }
function get_option(): array { return $GLOBALS['settings']; }
function add_action(): void {}
function add_shortcode(): void {}
function apply_filters( string $hook, $value ) { return $value; }
function rest_url( string $path ): string { return 'https://example.test/wp-json/' . $path; }
function rest_ensure_response( $data ): WP_REST_Response { return new WP_REST_Response( $data ); }
function wp_json_encode( $data ): string { return json_encode( $data ); }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function get_bloginfo(): string { return 'test'; }
function get_transient( string $key ) { return $GLOBALS['transients'][ $key ] ?? false; }
function set_transient( string $key, $value ): void { $GLOBALS['transients'][ $key ] = $value; }
function wp_create_nonce( string $action ): string { return $action . ':' . $GLOBALS['current_user']; }
function wp_verify_nonce( string $nonce, string $action ): bool { return $nonce === wp_create_nonce( $action ); }
function get_queried_object() { return $GLOBALS['post'] ?? null; }
function has_shortcode( string $content, string $tag ): bool { return str_contains( $content, '[' . $tag ); }
function wp_register_style( string $name ): void { $GLOBALS['registered_styles'][ $name ] = true; }
function wp_register_script( string $name ): void { $GLOBALS['registered_scripts'][ $name ] = true; }
function wp_enqueue_style( string $name ): void { $GLOBALS['enqueued_styles'][ $name ] = true; }
function wp_enqueue_script( string $name ): void { $GLOBALS['enqueued_scripts'][ $name ] = true; }
function wp_script_is( string $name, string $status ): bool { return $status === 'enqueued' && isset( $GLOBALS['enqueued_scripts'][ $name ] ); }
function wp_localize_script( string $name, string $object, array $data ): void { $GLOBALS['localized'][ $name ] = $data; }
function wp_add_inline_style( string $name, string $css ): void { $GLOBALS['inline_styles'][ $name ] = $css; }
function wp_strip_all_tags( string $text ): string { return strip_tags( $text ); }
function wp_remote_retrieve_response_code( array $response ): int { return $response['code']; }
function wp_remote_retrieve_response_message( array $response ): string { return $response['message']; }
function wp_remote_retrieve_body( array $response ): string { return $response['body']; }
function wp_remote_post( string $url, array $args ): array {
	$GLOBALS['backend_requests'][] = $args;
	$body = json_decode( $args['body'], true );
	$turn = count( $GLOBALS['backend_requests'] );
	if ( $turn > 1 && ( $args['headers']['X-Chat-History-Token'] ?? '' ) !== 'test-history-token' ) {
		return array( 'code' => 403, 'message' => 'Forbidden', 'body' => '{"message":"Chat history is not available for this session."}' );
	}
	return array( 'code' => 201, 'message' => 'Created', 'body' => json_encode( array( array(
		'sessionId' => $body['sessionId'], 'text' => 'answer', 'historyAccessToken' => 'test-history-token',
	) ) ) );
}

require_once AI_CHAT_DIR . 'includes/class-sanitizer.php';
require_once AI_CHAT_DIR . 'includes/class-session-manager.php';
require_once AI_CHAT_DIR . 'includes/class-api-client.php';
require_once AI_CHAT_DIR . 'includes/class-rest-controller.php';
require_once AI_CHAT_DIR . 'includes/class-assets.php';
require_once AI_CHAT_DIR . 'includes/class-frontend.php';

$GLOBALS['settings'] = array_merge( AI_Chat_Sanitizer::defaults(), array(
	'backend_url' => 'https://backend.example.test', 'connection_mode' => 'proxy', 'rate_limit' => 3,
) );
$_SERVER['REMOTE_ADDR'] = '192.0.2.4';
$controller = new AI_Chat_REST_Controller();
foreach ( array( 0, 7 ) as $page_user ) {
	$GLOBALS['current_user'] = $page_user;
	$page_nonce = wp_create_nonce( 'ai_chat_proxy' );
	$GLOBALS['current_user'] = 0; // REST cookie auth without X-WP-Nonce.
	$nonce = $controller->handle_nonce( new WP_REST_Request() )->data['nonce'];
	check( $nonce === 'ai_chat_proxy:0', 'REST nonce must match anonymous REST context' );
	$GLOBALS['transients'] = array();
	check( $controller->check_nonce_and_rate_limit( new WP_REST_Request( array(), array( 'x-ai-chat-nonce' => $nonce ) ) ) === true, 'first message nonce failed' );
	if ( $page_user ) { check( $page_nonce !== $nonce, 'test did not model logged-in mismatch' ); }
}
echo "PASS anonymous and logged-in first messages\n";

$session_id = AI_Chat_Session_Manager::generate();
$GLOBALS['backend_requests'] = array();
$first = $controller->handle_chat( new WP_REST_Request( array( 'message' => 'first', 'sessionId' => $session_id ) ) );
check( $first instanceof WP_REST_Response && $first->data['historyAccessToken'] === 'test-history-token', 'first backend turn failed' );
$second = $controller->handle_chat( new WP_REST_Request( array( 'message' => 'second', 'sessionId' => $session_id ), array( 'x-chat-history-token' => $first->data['historyAccessToken'] ) ) );
check( $second instanceof WP_REST_Response && $second->data['sessionId'] === $session_id, 'second backend turn failed' );
check( $GLOBALS['backend_requests'][1]['headers']['X-Chat-History-Token'] === 'test-history-token', 'history header was not forwarded' );
echo "PASS two messages in one session\n";

$log_path = tempnam( sys_get_temp_dir(), 'ai-chat-test-' );
ini_set( 'log_errors', '1' );
ini_set( 'error_log', $log_path );
$client = new AI_Chat_API_Client( 'https://backend.example.test' );
$error = $client->send_chat( 'third', $session_id );
check( $error instanceof WP_Error && $error->get_error_code() === 'backend_error', 'backend error was not retained' );
check( ! str_contains( $error->get_error_message(), 'Chat history is not available for this session.' ), 'backend body leaked publicly' );
$diagnostic = file_get_contents( $log_path );
unlink( $log_path );
check( str_contains( $diagnostic, 'history_unavailable' ), 'history failure reason was not logged' );
check( ! str_contains( $diagnostic, 'Chat history is not available for this session.' ), 'raw backend body was logged' );
echo "PASS backend error diagnostics\n";

$GLOBALS['settings']['display_mode'] = 'shortcode';
$GLOBALS['post'] = new WP_Post( 'No shortcode here' );
$assets = new AI_Chat_Assets();
$assets->enqueue_frontend();
check( isset( $GLOBALS['registered_scripts']['ai-chat-widget'], $GLOBALS['localized']['ai-chat-widget'], $GLOBALS['inline_styles']['ai-chat-widget'] ), 'shortcode assets were not configured' );
check( ! isset( $GLOBALS['enqueued_scripts']['ai-chat-widget'] ), 'shortcode-only mode loaded globally' );
$frontend = new AI_Chat_Frontend();
check( str_contains( $frontend->render_shortcode( array() ), 'data-ai-chat-open' ), 'shortcode trigger missing' );
check( isset( $GLOBALS['enqueued_scripts']['ai-chat-widget'], $GLOBALS['enqueued_styles']['ai-chat-widget'] ), 'shortcode assets were not enqueued' );
ob_start();
$frontend->render_widget();
$rendered = ob_get_clean();
check( str_contains( $rendered, 'ai-chat-root' ), 'shortcode widget root missing' );
echo "PASS shortcode-only rendering\n";

$GLOBALS['settings']['rate_limit'] = 1;
$GLOBALS['transients'] = array();
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
$nonce_request = new WP_REST_Request( array(), array( 'x-ai-chat-nonce' => $nonce ) );
check( $controller->check_nonce_and_rate_limit( $nonce_request ) === true, 'first rate-limit request failed' );
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.2';
$blocked = $controller->check_nonce_and_rate_limit( $nonce_request );
check( $blocked instanceof WP_Error && $blocked->get_error_code() === 'rate_limited', 'spoofed forwarding header bypassed rate limit' );
echo "PASS rate-limit header spoofing\n";

$clean = AI_Chat_Sanitizer::sanitize_svg( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" onload="alert(1)" style="color:red"><path d="M0 0" onclick="alert(2)"/></svg>' );
check( str_contains( $clean, 'viewBox=' ) && str_contains( $clean, 'd="M0 0"' ), 'safe SVG attributes removed' );
check( ! str_contains( $clean, 'onload' ) && ! str_contains( $clean, 'onclick' ) && ! str_contains( $clean, 'style=' ), 'unsafe SVG attributes survived' );
check( AI_Chat_Sanitizer::sanitize_svg( '<div><svg/></div>' ) === '', 'non-SVG root accepted' );
echo "PASS SVG root attributes\n";
