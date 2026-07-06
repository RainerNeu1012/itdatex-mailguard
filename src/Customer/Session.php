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

	/** Cache fuer den JTI des aktuellen Cookie-Requests (verify_session-Ergebnis). */
	private static ?string $current_jti = null;

	public static function start( int $customer_id ) : string {
		$ttl_days = max( 1, (int) Settings::get( 'session_ttl_days', 14 ) );
		$expires  = time() + $ttl_days * DAY_IN_SECONDS;
		$token    = Token::sign_session( $customer_id, $expires );
		self::write_cookie( $token, $expires );

		// Session-Tracking-Row anlegen. Fehler ignorieren — der Cookie-Login
		// funktioniert auch ohne Tabelle (fresh Install vor Migration).
		$payload = Token::verify_session( $token );
		if ( $payload && isset( $payload['jti'] ) ) {
			WebSession::insert( $customer_id, (string) $payload['jti'], $expires );
		}
		return $token;
	}

	public static function destroy() : void {
		// Aktuellen JTI aus dem Cookie ziehen — wenn wir hier stehen, ist er
		// (noch) im $_COOKIE — bevor wir ihn überschreiben.
		$token = (string) ( $_COOKIE[ self::COOKIE ] ?? '' );
		if ( $token !== '' ) {
			$payload = Token::verify_session( $token );
			if ( $payload && ! empty( $payload['jti'] ) ) {
				$jti = (string) $payload['jti'];
				Token::blacklist_jti( $jti, (int) $payload['expires_at'] );
				WebSession::revoke_by_jti( $jti );
			}
		}
		self::write_cookie( '', time() - 3600 );
	}

	public static function current_customer_id() : int {
		// 1) Bearer-Header hat Vorrang — App-Traffic laeuft immer per Authorization,
		//    nie via Cookie. Damit die Web-SPA und die App gleichzeitig funktionieren,
		//    fallen wir bei fehlendem Header auf den Cookie zurueck.
		$hdr = self::authorization_header();
		if ( $hdr !== '' && stripos( $hdr, 'Bearer ' ) === 0 ) {
			$token = trim( substr( $hdr, 7 ) );
			if ( $token !== '' ) {
				$cid = ApiToken::verify_access( $token );
				if ( $cid ) { return $cid; }
			}
		}

		// 2) Cookie-Session (Web-SPA)
		$token = $_COOKIE[ self::COOKIE ] ?? '';
		if ( ! is_string( $token ) || $token === '' ) {
			return 0;
		}
		$payload = Token::verify_session( $token );
		if ( ! $payload ) { return 0; }
		$cid = (int) $payload['customer_id'];
		$jti = (string) ( $payload['jti'] ?? '' );
		self::$current_jti = $jti;
		if ( $jti !== '' ) {
			WebSession::touch( $jti );
		}
		return $cid;
	}

	/** JTI der aktuellen Cookie-Session, falls verifiziert — sonst leerer String. */
	public static function current_jti() : string {
		if ( self::$current_jti !== null ) {
			return self::$current_jti;
		}
		self::current_customer_id();
		return (string) self::$current_jti;
	}

	private static function authorization_header() : string {
		// PHP-FPM/Apache/Nginx-Kombinationen liefern den Header unterschiedlich.
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return (string) $_SERVER['HTTP_AUTHORIZATION'];
		}
		if ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}
		if ( function_exists( 'apache_request_headers' ) ) {
			$hdrs = apache_request_headers();
			foreach ( $hdrs as $k => $v ) {
				if ( strcasecmp( $k, 'Authorization' ) === 0 ) {
					return (string) $v;
				}
			}
		}
		return '';
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
