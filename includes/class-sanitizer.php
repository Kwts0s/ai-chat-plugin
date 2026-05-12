<?php
/**
 * Sanitization helpers and default settings schema.
 *
 * @package AIChatPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AI_Chat_Sanitizer
 */
final class AI_Chat_Sanitizer {

	/** Allowed bubble styles. */
	private const ALLOWED_BUBBLE_STYLES = array( 'circle', 'rounded', 'square' );

	// ── Default settings ────────────────────────────────────────────────────

	/**
	 * Return the full default settings array.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'backend_url'      => '',
			'connection_mode'  => 'proxy',
			'company_name'     => 'AI Assistant',
			'company_subtitle' => 'Always here to help',
			'company_logo'     => '',
			'bubble_icon_svg_media_url' => '',
			'bubble_icon_svg'  => '',
			'welcome_message'  => 'Hello! How can I help you today?',
			'disclaimer'       => __( 'This chat is powered by AI. Responses may not always be accurate.', 'ai-chat-plugin' ),
			'online_text'      => 'Online',
			'primary_color'    => '#4f46e5',
			'secondary_color'  => '#818cf8',
			'bg_color'         => '#f8fafc',
			'text_color'       => '#1e293b',
			'bubble_position'  => 'bottom-right',
			'border_radius'    => 16,
			'bubble_size'      => 58,
			'bubble_style'     => 'circle',
			'bubble_border_width' => 0,
			'bubble_border_color' => '#ffffff',
			'custom_css'       => '',
			'display_mode'     => 'global',
			'display_page_ids' => array(),
			'store_transcript' => false,
			'request_timeout'  => 30,
			'rate_limit'       => 20,
			'debug_mode'       => false,
		);
	}

	// ── Settings sanitizer ──────────────────────────────────────────────────

	/**
	 * Sanitize the full settings array coming from the admin form.
	 *
	 * @param array<string, mixed> $input Raw POST input.
	 * @return array<string, mixed>
	 */
	public static function sanitize_settings( array $input ): array {
		$defaults = self::defaults();
		$clean    = array();

		// Backend URL — must be a valid http/https URL.
		$raw_url = isset( $input['backend_url'] ) ? trim( (string) $input['backend_url'] ) : '';
		$esc_url = esc_url_raw( $raw_url, array( 'http', 'https' ) );
		$clean['backend_url'] = filter_var( $esc_url, FILTER_VALIDATE_URL ) ? $esc_url : $defaults['backend_url'];

		// Strip trailing slash.
		$clean['backend_url'] = rtrim( $clean['backend_url'], '/' );

		// Connection mode.
		$clean['connection_mode'] = in_array( $input['connection_mode'] ?? '', array( 'proxy', 'direct' ), true )
			? $input['connection_mode']
			: $defaults['connection_mode'];

		// Text fields.
		foreach ( array( 'company_name', 'company_subtitle', 'welcome_message', 'disclaimer', 'online_text' ) as $field ) {
			$clean[ $field ] = isset( $input[ $field ] )
				? sanitize_text_field( $input[ $field ] )
				: $defaults[ $field ];
		}

		// Logo URL.
		$logo_raw        = isset( $input['company_logo'] ) ? trim( (string) $input['company_logo'] ) : '';
		$clean['company_logo'] = filter_var( esc_url_raw( $logo_raw ), FILTER_VALIDATE_URL ) ? esc_url_raw( $logo_raw ) : $defaults['company_logo'];

		// Bubble icon SVG media URL.
		$bubble_svg_url_raw = isset( $input['bubble_icon_svg_media_url'] ) ? trim( (string) $input['bubble_icon_svg_media_url'] ) : '';
		$clean['bubble_icon_svg_media_url'] = filter_var( esc_url_raw( $bubble_svg_url_raw ), FILTER_VALIDATE_URL ) ? esc_url_raw( $bubble_svg_url_raw ) : $defaults['bubble_icon_svg_media_url'];

		// Bubble SVG — strictly sanitized.
		$clean['bubble_icon_svg'] = isset( $input['bubble_icon_svg'] )
			? self::sanitize_svg( (string) $input['bubble_icon_svg'] )
			: $defaults['bubble_icon_svg'];

		// Color fields — must be valid hex colors.
		foreach ( array( 'primary_color', 'secondary_color', 'bg_color', 'text_color', 'bubble_border_color' ) as $field ) {
			$clean[ $field ] = isset( $input[ $field ] ) && self::is_valid_hex_color( $input[ $field ] )
				? sanitize_hex_color( $input[ $field ] )
				: $defaults[ $field ];
		}

		// Bubble position.
		$clean['bubble_position'] = in_array( $input['bubble_position'] ?? '', array( 'bottom-right', 'bottom-left' ), true )
			? $input['bubble_position']
			: $defaults['bubble_position'];

		// Border radius — integer 0–50.
		$clean['border_radius'] = isset( $input['border_radius'] )
			? max( 0, min( 50, (int) $input['border_radius'] ) )
			: $defaults['border_radius'];

		// Bubble size — integer 44–90.
		$clean['bubble_size'] = isset( $input['bubble_size'] )
			? max( 44, min( 90, (int) $input['bubble_size'] ) )
			: $defaults['bubble_size'];

		// Bubble style.
		$clean['bubble_style'] = in_array( $input['bubble_style'] ?? '', self::allowed_bubble_styles(), true )
			? $input['bubble_style']
			: $defaults['bubble_style'];

		// Bubble border width — integer 0–8.
		$clean['bubble_border_width'] = isset( $input['bubble_border_width'] )
			? max( 0, min( 8, (int) $input['bubble_border_width'] ) )
			: $defaults['bubble_border_width'];

		// Custom CSS — minimal sanitization (allow CSS but strip PHP/HTML tags).
		$clean['custom_css'] = isset( $input['custom_css'] )
			? wp_strip_all_tags( (string) $input['custom_css'] )
			: $defaults['custom_css'];

		// Display mode.
		$clean['display_mode'] = in_array( $input['display_mode'] ?? '', array( 'global', 'selected', 'shortcode' ), true )
			? $input['display_mode']
			: $defaults['display_mode'];

		// Page IDs — comma-separated integers.
		if ( isset( $input['display_page_ids'] ) && is_string( $input['display_page_ids'] ) ) {
			$ids = array_filter(
				array_map( 'intval', explode( ',', $input['display_page_ids'] ) ),
				fn( int $id ) => $id > 0
			);
			$clean['display_page_ids'] = array_values( $ids );
		} else {
			$clean['display_page_ids'] = $defaults['display_page_ids'];
		}

		// Boolean flags.
		$clean['store_transcript'] = ! empty( $input['store_transcript'] );
		$clean['debug_mode']       = ! empty( $input['debug_mode'] );

		// Numeric / range values.
		$clean['request_timeout'] = isset( $input['request_timeout'] )
			? max( 5, min( 120, (int) $input['request_timeout'] ) )
			: $defaults['request_timeout'];

		$clean['rate_limit'] = isset( $input['rate_limit'] )
			? max( 1, min( 200, (int) $input['rate_limit'] ) )
			: $defaults['rate_limit'];

		return $clean;
	}

	// ── SVG sanitizer ───────────────────────────────────────────────────────

	/**
	 * Strip potentially dangerous content from an SVG string.
	 *
	 * Allows only a safe subset of elements and attributes.
	 *
	 * @param string $svg Raw SVG input.
	 * @return string Sanitized SVG or empty string on failure.
	 */
	public static function sanitize_svg( string $svg ): string {
		$svg = trim( $svg );
		if ( '' === $svg ) {
			return '';
		}

		// Must contain an <svg> element.
		if ( ! preg_match( '/<svg[\s>]/i', $svg ) ) {
			return '';
		}

		$allowed_tags = array(
			'svg', 'g', 'path', 'circle', 'rect', 'ellipse', 'line',
			'polyline', 'polygon', 'use', 'defs', 'symbol', 'title', 'desc',
			'linearGradient', 'radialGradient', 'stop', 'clipPath', 'mask',
		);

		$allowed_attrs = array(
			'xmlns', 'viewBox', 'width', 'height', 'fill', 'stroke',
			'stroke-width', 'stroke-linecap', 'stroke-linejoin',
			'stroke-dasharray', 'stroke-dashoffset', 'opacity', 'class',
			'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'y1',
			'x2', 'y2', 'points', 'transform', 'gradientUnits',
			'gradientTransform', 'offset', 'stop-color', 'stop-opacity',
			'clip-path', 'mask', 'aria-hidden', 'role', 'focusable',
			'xmlns:xlink', 'id',
		);

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadXML( $svg, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET );
		libxml_clear_errors();

		if ( ! $dom->documentElement ) {
			return '';
		}

		self::clean_svg_node( $dom->documentElement, $allowed_tags, $allowed_attrs );

		$output = $dom->saveXML( $dom->documentElement );
		return $output ?: '';
	}

	/**
	 * Recursively remove unsafe nodes and attributes from a DOMElement.
	 *
	 * @param DOMElement    $node          Current node.
	 * @param string[]      $allowed_tags  Allowed tag names.
	 * @param string[]      $allowed_attrs Allowed attribute names.
	 */
	private static function clean_svg_node( DOMElement $node, array $allowed_tags, array $allowed_attrs ): void {
		// Collect children first (we may remove them during iteration).
		$children = array();
		foreach ( $node->childNodes as $child ) {
			$children[] = $child;
		}

		foreach ( $children as $child ) {
			if ( $child instanceof DOMElement ) {
				$tag = strtolower( $child->localName );
				if ( ! in_array( $tag, $allowed_tags, true ) ) {
					$node->removeChild( $child );
					continue;
				}

				// Remove disallowed/unsafe attributes.
				$attrs_to_remove = array();
				foreach ( $child->attributes as $attr ) {
					$name = strtolower( $attr->name );

					// Block event handlers and dangerous attrs.
					if ( strncmp( $name, 'on', 2 ) === 0 ) {
						$attrs_to_remove[] = $attr->name;
						continue;
					}
					// Block href/xlink:href with javascript: or data: URIs.
					if ( in_array( $name, array( 'href', 'xlink:href' ), true ) ) {
						$val = strtolower( trim( $attr->value ) );
						if ( strncmp( $val, 'javascript:', 11 ) === 0 || strncmp( $val, 'data:', 5 ) === 0 ) {
							$attrs_to_remove[] = $attr->name;
							continue;
						}
					}

					if ( ! in_array( $name, $allowed_attrs, true ) ) {
						$attrs_to_remove[] = $attr->name;
					}
				}

				foreach ( $attrs_to_remove as $attr_name ) {
					$child->removeAttribute( $attr_name );
				}

				self::clean_svg_node( $child, $allowed_tags, $allowed_attrs );

			} elseif ( $child instanceof DOMProcessingInstruction || $child instanceof DOMComment ) {
				$node->removeChild( $child );
			}
		}
	}

	// ── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Check whether a string is a valid 3- or 6-digit hex color (with leading #).
	 *
	 * @param string $color Color string.
	 * @return bool
	 */
	public static function is_valid_hex_color( string $color ): bool {
		return (bool) preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color );
	}

	/**
	 * Return allowed bubble style values.
	 *
	 * @return string[]
	 */
	public static function allowed_bubble_styles(): array {
		return self::ALLOWED_BUBBLE_STYLES;
	}

	/**
	 * Return merged settings (stored + defaults for any missing keys).
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings(): array {
		$stored = get_option( AI_CHAT_OPTION_KEY, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}
}
