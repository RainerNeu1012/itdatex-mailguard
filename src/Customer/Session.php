<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Customer;

use Itdatex\Mailguard\Admin\Settings;

/**
 * Cookie-basierte Customer-Session. Signiertes Token (HMAC) — kein DB-Lookup pro Request.
 * Trennscharf von WP-Cookies, damit Site-Owner-Logins und Customer-Logins kollisionsfrei laufen.
 */
final class Session {

	public const COOKIE = 'itdatex_mg_session';

	public static function start( int $customer_id ) : string {
		$ttl_days = max( 1, (int) Settings::get( 'session_ttl_days', 14 ) );
		$expires  = time() + $ttl_days * DAY_IN_SECONDS;
		$token    = Token::sign_session( $customer_id, $expires );
		self::write_cookie( $token, $expires );
		return $token;
	}

	public static function destroy() : void {
		self::write_cookie( '', time() - 3600 );
	}

	public static function current_customer_id() : int {
		$token = $_COOKIE[ self::COOKIE ] ?? '';
		if ( ! is_string( $token ) || $token === '' ) {
			return 0;
		}
		$payload = Token::verify_session( $token );
		return $payload ? (int) $payload['customer_id'] : 0;
	}

	private static function write_cookie( string $value, int $expires ) : void {
		$secure = is_ssl();
		$args = [
			'expires'  => $expires,
			'path'     => '/',
			'domain'   => '',
			'secure'   => $secure,
			'httponly' => true,
			'samesite' => 'Lax',
		];
		// Sicher: setcookie mit Array-Optionen (PHP 7.3+).
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, $value, $args );
		}
		// Damit der aktuelle Request den neuen Wert sieht.
		$_COOKIE[ self::COOKIE ] = $value;
	}
}
