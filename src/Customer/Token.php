<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Customer;

/**
 * Crypto-Tokens fuer Email-Verification, Password-Reset und Sessions.
 *
 * Verification/Reset-Tokens: 32-byte Random, Hex => 64 chars, in DB als-ist gespeichert.
 * Session-Cookies: signiertes Token (HMAC-SHA256 ueber JSON-Payload mit customer_id + exp).
 */
final class Token {

	public static function random_token() : string {
		return bin2hex( random_bytes( 32 ) );
	}

	public static function sign_session( int $customer_id, int $expires_at ) : string {
		$payload = [
			'cid' => $customer_id,
			'exp' => $expires_at,
			'jti' => bin2hex( random_bytes( 8 ) ),
		];
		$body = self::b64url( (string) wp_json_encode( $payload ) );
		$sig  = self::b64url( hash_hmac( 'sha256', $body, self::session_key(), true ) );
		return $body . '.' . $sig;
	}

	public static function verify_session( string $token ) : ?array {
		$parts = explode( '.', $token );
		if ( count( $parts ) !== 2 ) {
			return null;
		}
		[ $body, $sig ] = $parts;
		$expected = self::b64url( hash_hmac( 'sha256', $body, self::session_key(), true ) );
		if ( ! hash_equals( $expected, $sig ) ) {
			return null;
		}
		$raw = self::b64url_decode( $body );
		if ( $raw === null ) {
			return null;
		}
		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			return null;
		}
		$exp = (int) ( $payload['exp'] ?? 0 );
		if ( $exp < time() ) {
			return null;
		}
		$cid = (int) ( $payload['cid'] ?? 0 );
		if ( $cid <= 0 ) {
			return null;
		}
		return [ 'customer_id' => $cid, 'expires_at' => $exp ];
	}

	private static function session_key() : string {
		$salt = '';
		if ( defined( 'AUTH_KEY' ) )        { $salt .= AUTH_KEY; }
		if ( defined( 'SECURE_AUTH_KEY' ) ) { $salt .= SECURE_AUTH_KEY; }
		if ( $salt === '' ) {
			$salt = 'itdatex-mailguard-fallback';
		}
		return hash( 'sha256', 'itdatex-mailguard|session|' . $salt, true );
	}

	private static function b64url( string $bin ) : string {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	private static function b64url_decode( string $s ) : ?string {
		$bin = base64_decode( strtr( $s, '-_', '+/' ), true );
		return $bin === false ? null : $bin;
	}
}
