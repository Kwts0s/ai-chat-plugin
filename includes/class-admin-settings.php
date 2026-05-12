<?php
/**
 * Admin settings page — tabbed, custom form saved via a single option.
 *
 * @package AIChatPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AI_Chat_Admin_Settings
 */
final class AI_Chat_Admin_Settings {

	/** Nonce action for the settings form. */
	private const NONCE_ACTION = 'ai_chat_settings_save';

	/** Nonce field name. */
	private const NONCE_NAME = 'ai_chat_settings_nonce';

	/** Available tabs (slug → translation key). */
	private const TABS = array(
		'backend',
		'branding',
		'appearance',
		'display',
		'advanced',
	);

	/**
	 * Tab field map used to preserve non-active tab values on save.
	 *
	 * Since only one tab's inputs are rendered at a time, this map ensures
	 * saving one tab updates only that tab's fields and keeps other tab values.
	 */
	private const TAB_FIELDS = array(
		'backend'    => array( 'backend_url', 'connection_mode' ),
		'branding'   => array( 'company_name', 'company_subtitle', 'company_logo', 'bubble_icon_svg_media_url', 'bubble_icon_svg', 'welcome_message', 'disclaimer', 'online_text' ),
		'appearance' => array( 'primary_color', 'secondary_color', 'bg_color', 'text_color', 'bubble_position', 'border_radius', 'bubble_size', 'bubble_style', 'bubble_border_width', 'bubble_border_color', 'custom_css' ),
		'display'    => array( 'display_mode', 'display_page_ids' ),
		'advanced'   => array( 'store_transcript', 'request_timeout', 'rate_limit', 'debug_mode' ),
	);

	/**
	 * Return translated tab labels, keyed by slug.
	 *
	 * @return array<string, string>
	 */
	private function tab_labels(): array {
		return array(
			'backend'    => __( 'Backend', 'ai-chat-plugin' ),
			'branding'   => __( 'Branding', 'ai-chat-plugin' ),
			'appearance' => __( 'Appearance', 'ai-chat-plugin' ),
			'display'    => __( 'Display Rules', 'ai-chat-plugin' ),
			'advanced'   => __( 'Advanced', 'ai-chat-plugin' ),
		);
	}

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_post_ai_chat_save_settings', array( $this, 'handle_save' ) );
		add_action( 'admin_notices', array( $this, 'display_notices' ) );
	}

	// ── Menu ────────────────────────────────────────────────────────────────

	/** Register the top-level admin menu entry. */
	public function add_menu_page(): void {
		add_menu_page(
			__( 'AI Chat Plugin', 'ai-chat-plugin' ),
			__( 'AI Chat', 'ai-chat-plugin' ),
			'manage_options',
			'ai-chat-settings',
			array( $this, 'render_page' ),
			'dashicons-format-chat',
			80
		);
	}

	// ── Save handler ─────────────────────────────────────────────────────────

	/**
	 * Process the settings form submission.
	 * Hooked to admin-post.php action `ai_chat_save_settings`.
	 */
	public function handle_save(): void {
		// Capability check.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'ai-chat-plugin' ), 403 );
		}

		// Nonce check — check_admin_referer() verifies and dies on failure.
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		// All $_POST access below is after nonce verification above.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$raw = isset( $_POST['ai_chat'] ) && is_array( $_POST['ai_chat'] ) ? $_POST['ai_chat'] : array();
		$tab = isset( $_POST['ai_chat_current_tab'] ) ? sanitize_key( (string) $_POST['ai_chat_current_tab'] ) : 'backend';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$existing = AI_Chat_Sanitizer::get_settings();
		$merged   = $this->merge_tab_settings( $existing, $raw, $tab );
		$clean    = AI_Chat_Sanitizer::sanitize_settings( $merged );

		update_option( AI_CHAT_OPTION_KEY, $clean );

		// Redirect back with success.
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'ai-chat-settings',
					'tab'     => $tab,
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** Show admin notice after save. */
	public function display_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'ai-chat-settings' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification -- just reading a flag.
		if ( isset( $_GET['updated'] ) && '1' === $_GET['updated'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Settings saved.', 'ai-chat-plugin' )
				. '</p></div>';
		}
	}

	// ── Page render ──────────────────────────────────────────────────────────

	/** Render the full settings page. */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings    = AI_Chat_Sanitizer::get_settings();
		$tab_labels  = $this->tab_labels();
		// phpcs:ignore WordPress.Security.NonceVerification -- tab is UI navigation state only.
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'backend';
		if ( ! in_array( $current_tab, self::TABS, true ) ) {
			$current_tab = 'backend';
		}

		?>
		<div class="wrap ai-chat-admin-wrap">
			<h1 class="ai-chat-admin-title">
				<span class="dashicons dashicons-format-chat"></span>
				<?php esc_html_e( 'AI Chat Plugin Settings', 'ai-chat-plugin' ); ?>
			</h1>

			<nav class="nav-tab-wrapper ai-chat-tabs">
				<?php foreach ( self::TABS as $slug ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ai-chat-settings', 'tab' => $slug ), admin_url( 'admin.php' ) ) ); ?>"
					   class="nav-tab<?php echo $slug === $current_tab ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html( $tab_labels[ $slug ] ?? $slug ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ai-chat-form" enctype="multipart/form-data">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<input type="hidden" name="action" value="ai_chat_save_settings" />
				<input type="hidden" name="ai_chat_current_tab" value="<?php echo esc_attr( $current_tab ); ?>" />

				<div class="ai-chat-tab-content">
					<?php
					switch ( $current_tab ) {
						case 'backend':
							$this->render_tab_backend( $settings );
							break;
						case 'branding':
							$this->render_tab_branding( $settings );
							break;
						case 'appearance':
							$this->render_tab_appearance( $settings );
							break;
						case 'display':
							$this->render_tab_display( $settings );
							break;
						case 'advanced':
							$this->render_tab_advanced( $settings );
							break;
					}
					?>
				</div>

				<?php submit_button( __( 'Save Settings', 'ai-chat-plugin' ) ); ?>
			</form>
		</div>
		<?php
	}

	// ── Tab: Backend ─────────────────────────────────────────────────────────

	/**
	 * @param array<string, mixed> $s Settings.
	 */
	private function render_tab_backend( array $s ): void {
		?>
		<table class="form-table ai-chat-form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="ai_chat_backend_url"><?php esc_html_e( 'Backend Base URL', 'ai-chat-plugin' ); ?></label>
				</th>
				<td>
					<input type="url" id="ai_chat_backend_url" name="ai_chat[backend_url]"
					       value="<?php echo esc_attr( $s['backend_url'] ); ?>"
					       class="regular-text" placeholder="https://your-backend.example.com" />
					<p class="description"><?php esc_html_e( 'The base URL of your chatbot backend (no trailing slash). Used for /chat and /chat/health.', 'ai-chat-plugin' ); ?></p>
					<p>
						<button type="button" id="ai-chat-test-connection" class="button">
							<?php esc_html_e( 'Test Connection', 'ai-chat-plugin' ); ?>
						</button>
						<span id="ai-chat-health-result" class="ai-chat-health-result"></span>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Connection Mode', 'ai-chat-plugin' ); ?></th>
				<td>
					<fieldset>
						<label>
							<input type="radio" name="ai_chat[connection_mode]" value="proxy"
							       <?php checked( $s['connection_mode'], 'proxy' ); ?> />
							<?php esc_html_e( 'Proxy (recommended) — messages routed through WordPress', 'ai-chat-plugin' ); ?>
						</label><br>
						<label>
							<input type="radio" name="ai_chat[connection_mode]" value="direct"
							       <?php checked( $s['connection_mode'], 'direct' ); ?> />
							<?php esc_html_e( 'Direct — browser calls backend directly (requires CORS)', 'ai-chat-plugin' ); ?>
						</label>
					</fieldset>
					<p class="description">
						<?php esc_html_e( 'Proxy mode hides the backend URL from visitors and adds nonce verification. Requires the backend to be reachable from the WordPress server.', 'ai-chat-plugin' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	// ── Tab: Branding ────────────────────────────────────────────────────────

	/**
	 * @param array<string, mixed> $s Settings.
	 */
	private function render_tab_branding( array $s ): void {
		?>
		<table class="form-table ai-chat-form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ai_chat_company_name"><?php esc_html_e( 'Company Name', 'ai-chat-plugin' ); ?></label></th>
				<td>
					<input type="text" id="ai_chat_company_name" name="ai_chat[company_name]"
					       value="<?php echo esc_attr( $s['company_name'] ); ?>" class="regular-text" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ai_chat_company_subtitle"><?php esc_html_e( 'Subtitle / Tagline', 'ai-chat-plugin' ); ?></label></th>
				<td>
					<input type="text" id="ai_chat_company_subtitle" name="ai_chat[company_subtitle]"
					       value="<?php echo esc_attr( $s['company_subtitle'] ); ?>" class="regular-text" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ai_chat_company_logo"><?php esc_html_e( 'Company Logo', 'ai-chat-plugin' ); ?></label></th>
				<td>
					<input type="url" id="ai_chat_company_logo" name="ai_chat[company_logo]"
					       value="<?php echo esc_attr( $s['company_logo'] ); ?>" class="regular-text"
					       placeholder="https://example.com/logo.png" />
					<p>
						<button type="button" class="button ai-chat-media-select" data-target="#ai_chat_company_logo" data-type="image">
							<?php esc_html_e( 'Select from Media Library', 'ai-chat-plugin' ); ?>
						</button>
						<button type="button" class="button ai-chat-media-clear" data-target="#ai_chat_company_logo">
							<?php esc_html_e( 'Clear', 'ai-chat-plugin' ); ?>
						</button>
					</p>
					<p class="description"><?php esc_html_e( 'Select or upload a square image shown in the chat header (40×40 px recommended).', 'ai-chat-plugin' ); ?></p>
					<?php if ( ! empty( $s['company_logo'] ) ) : ?>
						<img src="<?php echo esc_url( $s['company_logo'] ); ?>" alt="" class="ai-chat-admin-logo-preview" />
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ai_chat_bubble_icon_svg_media_url"><?php esc_html_e( 'Bubble Icon (Media SVG)', 'ai-chat-plugin' ); ?></label></th>
				<td>
					<input type="url" id="ai_chat_bubble_icon_svg_media_url" name="ai_chat[bubble_icon_svg_media_url]"
					       value="<?php echo esc_attr( $s['bubble_icon_svg_media_url'] ); ?>" class="regular-text"
					       placeholder="https://example.com/icon.svg" />
					<p>
						<button type="button" class="button ai-chat-media-select" data-target="#ai_chat_bubble_icon_svg_media_url" data-type="image/svg+xml">
							<?php esc_html_e( 'Select SVG from Media Library', 'ai-chat-plugin' ); ?>
						</button>
						<button type="button" class="button ai-chat-media-clear" data-target="#ai_chat_bubble_icon_svg_media_url">
							<?php esc_html_e( 'Clear', 'ai-chat-plugin' ); ?>
						</button>
					</p>
					<p class="description"><?php esc_html_e( 'Use an SVG from the media library for the chat bubble icon. If set, this is used first.', 'ai-chat-plugin' ); ?></p>
					<?php if ( ! empty( $s['bubble_icon_svg_media_url'] ) ) : ?>
						<img src="<?php echo esc_url( $s['bubble_icon_svg_media_url'] ); ?>" alt="" class="ai-chat-admin-icon-preview" />
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ai_chat_bubble_icon_svg"><?php esc_html_e( 'Bubble Icon (Custom SVG Markup)', 'ai-chat-plugin' ); ?></label></th>
				<td>
					<textarea id="ai_chat_bubble_icon_svg" name="ai_chat[bubble_icon_svg]"
					          rows="6" class="large-text code"><?php echo esc_textarea( $s['bubble_icon_svg'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Optional fallback: paste safe inline SVG markup. Used when no media SVG is selected. SVG is sanitized on save.', 'ai-chat-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ai_chat_welcome_message"><?php esc_html_e( 'Welcome Message', 'ai-chat-plugin' ); ?></label></th>
				<td>
					<input type="text" id="ai_chat_welcome_message" name="ai_chat[welcome_message]"
					       value="<?php echo esc_attr( $s['welcome_message'] ); ?>" class="large-text" />
					<p class="description"><?php esc_html_e( 'First message shown when the chat opens.', 'ai-chat-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ai_chat_disclaimer"><?php esc_html_e( 'Disclaimer Text', 'ai-chat-plugin' ); ?></label></th>
				<td>
					<input type="text" id="ai_chat_disclaimer" name="ai_chat[disclaimer]"
					       value="<?php echo esc_attr( $s['disclaimer'] ); ?>" class="large-text" />
					<p class="description"><?php esc_html_e( 'Short disclaimer shown below the chat input.', 'ai-chat-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ai_chat_online_text"><?php esc_html_e( 'Online Status Label', 'ai-chat-plugin' ); ?></label></th>
				<td>
					<input type="text" id="ai_chat_online_text" name="ai_chat[online_text]"
					       value="<?php echo esc_attr( $s['online_text'] ); ?>" class="regular-text" />
				</td>
			</tr>
		</table>
		<?php
	}

	// ── Tab: Appearance ──────────────────────────────────────────────────────

	/**
	 * @param array<string, mixed> $s Settings.
	 */
	private function render_tab_appearance( array $s ): void {
		$color_fields = array(
			'primary_color'   => __( 'Primary Color', 'ai-chat-plugin' ),
			'secondary_color' => __( 'Secondary / Accent Color', 'ai-chat-plugin' ),
			'bg_color'        => __( 'Widget Background', 'ai-chat-plugin' ),
			'text_color'      => __( 'Body Text Color', 'ai-chat-plugin' ),
		);
		?>
		<table class="form-table ai-chat-form-table" role="presentation">
			<?php foreach ( $color_fields as $key => $label ) : ?>
				<tr>
					<th scope="row"><label for="ai_chat_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td>
						<input type="text" id="ai_chat_<?php echo esc_attr( $key ); ?>"
						       name="ai_chat[<?php echo esc_attr( $key ); ?>]"
						       value="<?php echo esc_attr( $s[ $key ] ); ?>"
						       class="ai-chat-color-picker" data-default-color="<?php echo esc_attr( $s[ $key ] ); ?>" />
					</td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Bubble Position', 'ai-chat-plugin' ); ?></th>
				<td>
					<fieldset>
						<label>
							<input type="radio" name="ai_chat[bubble_position]" value="bottom-right"
							       <?php checked( $s['bubble_position'], 'bottom-right' ); ?> />
							<?php esc_html_e( 'Bottom right', 'ai-chat-plugin' ); ?>
						</label><br>
						<label>
							<input type="radio" name="ai_chat[bubble_position]" value="bottom-left"
							       <?php checked( $s['bubble_position'], 'bottom-left' ); ?> />
							<?php esc_html_e( 'Bottom left', 'ai-chat-plugin' ); ?>
						</label>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ai_chat_border_radius"><?php esc_html_e( 'Border Radius (px)', 'ai-chat-plugin' ); ?></label></th>
				<td>
					<input type="number" id="ai_chat_border_radius" name="ai_chat[border_radius]"
					       value="<?php echo esc_attr( (string) $s['border_radius'] ); ?>"
					       min="0" max="50" class="small-text" /> px
					<p class="description"><?php esc_html_e( 'Rounded corners for the chat panel (0–50).', 'ai-chat-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ai_chat_bubble_size"><?php esc_html_e( 'Bubble Size (px)', 'ai-chat-plugin' ); ?></label></th>
				<td>
					<input type="number" id="ai_chat_bubble_size" name="ai_chat[bubble_size]"
					       value="<?php echo esc_attr( (string) $s['bubble_size'] ); ?>"
					       min="44" max="90" class="small-text" /> px
					<p class="description"><?php esc_html_e( 'Size of the floating chat bubble (44–90).', 'ai-chat-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Bubble Shape', 'ai-chat-plugin' ); ?></th>
				<td>
					<fieldset>
						<label>
							<input type="radio" name="ai_chat[bubble_style]" value="circle"
							       <?php checked( $s['bubble_style'], 'circle' ); ?> />
							<?php esc_html_e( 'Circle', 'ai-chat-plugin' ); ?>
						</label><br>
						<label>
							<input type="radio" name="ai_chat[bubble_style]" value="rounded"
							       <?php checked( $s['bubble_style'], 'rounded' ); ?> />
							<?php esc_html_e( 'Rounded square', 'ai-chat-plugin' ); ?>
						</label><br>
						<label>
							<input type="radio" name="ai_chat[bubble_style]" value="square"
							       <?php checked( $s['bubble_style'], 'square' ); ?> />
							<?php esc_html_e( 'Square', 'ai-chat-plugin' ); ?>
						</label>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ai_chat_bubble_border_width"><?php esc_html_e( 'Bubble Border Width (px)', 'ai-chat-plugin' ); ?></label></th>
				<td>
					<input type="number" id="ai_chat_bubble_border_width" name="ai_chat[bubble_border_width]"
					       value="<?php echo esc_attr( (string) $s['bubble_border_width'] ); ?>"
					       min="0" max="8" class="small-text" /> px
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ai_chat_bubble_border_color"><?php esc_html_e( 'Bubble Border Color', 'ai-chat-plugin' ); ?></label></th>
				<td>
					<input type="text" id="ai_chat_bubble_border_color"
					       name="ai_chat[bubble_border_color]"
					       value="<?php echo esc_attr( $s['bubble_border_color'] ); ?>"
					       class="ai-chat-color-picker" data-default-color="<?php echo esc_attr( $s['bubble_border_color'] ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ai_chat_custom_css"><?php esc_html_e( 'Custom CSS', 'ai-chat-plugin' ); ?></label></th>
				<td>
					<textarea id="ai_chat_custom_css" name="ai_chat[custom_css]"
					          rows="8" class="large-text code"><?php echo esc_textarea( $s['custom_css'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Additional CSS to append after the widget stylesheet. Use .ai-chat-* selectors to override styles.', 'ai-chat-plugin' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	// ── Tab: Display Rules ───────────────────────────────────────────────────

	/**
	 * @param array<string, mixed> $s Settings.
	 */
	private function render_tab_display( array $s ): void {
		$page_ids_string = implode( ', ', array_map( 'intval', (array) $s['display_page_ids'] ) );
		?>
		<table class="form-table ai-chat-form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Display Mode', 'ai-chat-plugin' ); ?></th>
				<td>
					<fieldset>
						<label>
							<input type="radio" name="ai_chat[display_mode]" value="global"
							       <?php checked( $s['display_mode'], 'global' ); ?> />
							<?php esc_html_e( 'Global — show on every page', 'ai-chat-plugin' ); ?>
						</label><br>
						<label>
							<input type="radio" name="ai_chat[display_mode]" value="selected"
							       <?php checked( $s['display_mode'], 'selected' ); ?> />
							<?php esc_html_e( 'Selected pages — only on the pages listed below', 'ai-chat-plugin' ); ?>
						</label><br>
						<label>
							<input type="radio" name="ai_chat[display_mode]" value="shortcode"
							       <?php checked( $s['display_mode'], 'shortcode' ); ?> />
							<?php esc_html_e( 'Shortcode only — place [ai_chat_widget] where needed', 'ai-chat-plugin' ); ?>
						</label>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ai_chat_display_page_ids"><?php esc_html_e( 'Page / Post IDs', 'ai-chat-plugin' ); ?></label></th>
				<td>
					<input type="text" id="ai_chat_display_page_ids" name="ai_chat[display_page_ids]"
					       value="<?php echo esc_attr( $page_ids_string ); ?>" class="regular-text"
					       placeholder="1, 42, 100" />
					<p class="description"><?php esc_html_e( 'Comma-separated post/page IDs where the widget should appear (used when Display Mode is "Selected pages").', 'ai-chat-plugin' ); ?></p>
				</td>
			</tr>
		</table>
		<div class="ai-chat-shortcode-info">
			<strong><?php esc_html_e( 'Shortcode:', 'ai-chat-plugin' ); ?></strong>
			<code>[ai_chat_widget]</code>
			— <?php esc_html_e( 'Place anywhere in page content to add a chat trigger button.', 'ai-chat-plugin' ); ?>
		</div>
		<?php
	}

	// ── Tab: Advanced ─────────────────────────────────────────────────────────

	/**
	 * @param array<string, mixed> $s Settings.
	 */
	private function render_tab_advanced( array $s ): void {
		?>
		<table class="form-table ai-chat-form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ai_chat_store_transcript"><?php esc_html_e( 'Store Transcript in LocalStorage', 'ai-chat-plugin' ); ?></label></th>
				<td>
					<input type="checkbox" id="ai_chat_store_transcript" name="ai_chat[store_transcript]" value="1"
					       <?php checked( (bool) $s['store_transcript'] ); ?> />
					<label for="ai_chat_store_transcript"><?php esc_html_e( 'Persist conversation across page loads', 'ai-chat-plugin' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ai_chat_request_timeout"><?php esc_html_e( 'Request Timeout (seconds)', 'ai-chat-plugin' ); ?></label></th>
				<td>
					<input type="number" id="ai_chat_request_timeout" name="ai_chat[request_timeout]"
					       value="<?php echo esc_attr( (string) $s['request_timeout'] ); ?>"
					       min="5" max="120" class="small-text" />
					<p class="description"><?php esc_html_e( 'How long to wait for a backend response before showing an error (5–120 s).', 'ai-chat-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ai_chat_rate_limit"><?php esc_html_e( 'Rate Limit (requests / 60 s per IP)', 'ai-chat-plugin' ); ?></label></th>
				<td>
					<input type="number" id="ai_chat_rate_limit" name="ai_chat[rate_limit]"
					       value="<?php echo esc_attr( (string) $s['rate_limit'] ); ?>"
					       min="1" max="200" class="small-text" />
					<p class="description"><?php esc_html_e( 'Maximum messages per visitor IP per minute in proxy mode.', 'ai-chat-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ai_chat_debug_mode"><?php esc_html_e( 'Debug Mode', 'ai-chat-plugin' ); ?></label></th>
				<td>
					<input type="checkbox" id="ai_chat_debug_mode" name="ai_chat[debug_mode]" value="1"
					       <?php checked( (bool) $s['debug_mode'] ); ?> />
					<label for="ai_chat_debug_mode"><?php esc_html_e( 'Enable verbose console logging in the browser widget', 'ai-chat-plugin' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Reset Session', 'ai-chat-plugin' ); ?></th>
				<td>
					<p class="description"><?php esc_html_e( 'Visitors can reset their own chat session by clearing localStorage in their browser. No server-side action is needed.', 'ai-chat-plugin' ); ?></p>
				</td>
			</tr>
		</table>

		<hr />
		<h3><?php esc_html_e( 'Developer Hooks', 'ai-chat-plugin' ); ?></h3>
		<ul class="ai-chat-hooks-list">
			<li><code>ai_chat_proxy_payload</code> — <?php esc_html_e( 'Filter the payload before it is forwarded to the backend.', 'ai-chat-plugin' ); ?></li>
			<li><code>ai_chat_proxy_response</code> — <?php esc_html_e( 'Filter the backend response before it is returned to the browser.', 'ai-chat-plugin' ); ?></li>
			<li><code>ai_chat_js_config</code> — <?php esc_html_e( 'Filter the JS config object passed to the frontend widget.', 'ai-chat-plugin' ); ?></li>
			<li><code>ai_chat_api_request_args</code> — <?php esc_html_e( 'Filter wp_remote_post args for outgoing backend requests.', 'ai-chat-plugin' ); ?></li>
		</ul>
		<?php
	}

	/**
	 * Merge submitted fields from the current tab into existing settings.
	 *
	 * @param array<string, mixed> $existing Existing settings.
	 * @param array<string, mixed> $raw      Raw submitted tab fields.
	 * @param string               $tab      Current tab slug.
	 * @return array<string, mixed>
	 */
	private function merge_tab_settings( array $existing, array $raw, string $tab ): array {
		$merged = $existing;
		$fields = self::TAB_FIELDS[ $tab ] ?? array();

		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $raw ) ) {
				$merged[ $field ] = $raw[ $field ];
			}
		}

		// For checkboxes in Advanced tab, missing means intentionally unchecked.
		if ( 'advanced' === $tab ) {
			foreach ( array( 'store_transcript', 'debug_mode' ) as $bool_field ) {
				if ( ! array_key_exists( $bool_field, $raw ) ) {
					$merged[ $bool_field ] = '';
				}
			}
		}

		return $merged;
	}
}
