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

	// wp_options-Key fuer die JTI-Blacklist. Format: [ jti => exp_ts, ... ].
	// Autoloaded, weil verify_session bei jedem REST-Request rueckfragt.
	private const BLACKLIST_OPTION = 'itdatex_mailguard_session_blacklist';

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
		$jti = (string) ( $payload['jti'] ?? '' );
		if ( $jti !== '' && isset( self::blacklist()[ $jti ] ) ) {
			return null;
		}
		return [ 'customer_id' => $cid, 'expires_at' => $exp, 'jti' => $jti ];
	}

	/**
	 * Widerruft ein einzelnes Session-Token per JTI. Der HMAC-signierte Token
	 * bleibt zwar syntaktisch gueltig, aber verify_session lehnt ihn ab.
	 * Blacklist-Eintraege werden bei jeder Revoke-Operation von abgelaufenen
	 * JTIs bereinigt — die Option waechst also nur mit lebenden Tokens.
	 *
	 * @return bool true wenn revoked, false bei kaputtem/abgelaufenem Token.
	 */
	public static function revoke_session( string $token ) : bool {
		$parts = explode( '.', $token );
		if ( count( $parts ) !== 2 ) { return false; }
		$raw = self::b64url_decode( $parts[0] );
		if ( $raw === null ) { return false; }
		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) { return false; }
		$jti = (string) ( $payload['jti'] ?? '' );
		$exp = (int) ( $payload['exp'] ?? 0 );
		if ( $jti === '' || $exp <= time() ) { return false; }
		self::blacklist_jti( $jti, $exp );
		return true;
	}

	/**
	 * Untere Primitive: markiert einen bekannten JTI (aus einer verify'ten
	 * Session oder aus der WebSession-Tabelle) als widerrufen. Prunt dabei
	 * abgelaufene Blacklist-Eintraege, damit die Option nicht endlos waechst.
	 */
	public static function blacklist_jti( string $jti, int $exp ) : void {
		if ( $jti === '' || $exp <= time() ) { return; }
		$now = time();
		$bl  = array_filter( self::blacklist(), static fn ( $e ) => (int) $e > $now );
		$bl[ $jti ] = $exp;
		update_option( self::BLACKLIST_OPTION, $bl, true );
	}

	private static function blacklist() : array {
		$bl = get_option( self::BLACKLIST_OPTION, [] );
		return is_array( $bl ) ? $bl : [];
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
