<?php
/**
 * PHP-side session management utilities.
 *
 * The "session" here means the chat session ID that is generated in the browser
 * and optionally stored in localStorage. On the WordPress/proxy side we only
 * validate the shape of the ID; the browser is the source of truth.
 *
 * @package AIChatPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AI_Chat_Session_Manager
 */
final class AI_Chat_Session_Manager {

	/** Regular expression for a valid UUID v4. */
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

	/**
	 * Validate and sanitize a session ID supplied by the browser.
	 *
	 * If the supplied value does not look like a UUID v4 (or is empty),
	 * a fresh UUID is generated server-side.
	 *
	 * @param string $session_id Client-supplied session ID.
	 * @return string
	 */
	public static function validate_or_create( string $session_id ): string {
		$session_id = sanitize_text_field( $session_id );

		if ( self::is_valid_uuid( $session_id ) ) {
			return $session_id;
		}

		return self::generate();
	}

	/**
	 * Check whether a string is a valid UUID v4.
	 *
	 * @param string $id Candidate ID.
	 * @return bool
	 */
	public static function is_valid_uuid( string $id ): bool {
		return (bool) preg_match( self::UUID_PATTERN, $id );
	}

	/**
	 * Generate a new UUID v4 string.
	 *
	 * Uses PHP's random_bytes() for cryptographic randomness.
	 *
	 * @return string
	 */
	public static function generate(): string {
		try {
			$bytes = random_bytes( 16 );
		} catch ( \Exception $e ) {
			// Fallback (should never happen on a sane system).
			$bytes = openssl_random_pseudo_bytes( 16 );
		}

		// Set version to 4.
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		// Set bits 6-7 to binary 10.
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}
}
