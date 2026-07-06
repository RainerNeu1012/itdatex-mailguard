<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Customer;

use Itdatex\Mailguard\Installer;

/**
 * Tracking-Table fuer Web-Sessions (Cookie-basierte HMAC-Tokens). Die
 * eigentliche Auth-Pruefung laeuft weiterhin ueber Token::verify_session
 * (stateless, kein DB-Lookup pro Request); diese Tabelle listet nur, wo
 * der User gerade eingeloggt ist, damit er einzelne Browser-Sessions von
 * der Geraete-Seite widerrufen kann. Revoke schreibt den JTI in die
 * Token-Blacklist — nur die Blacklist wirkt bei verify_session.
 */
final class WebSession {

	public static function table() : string {
		global $wpdb;
		return $wpdb->prefix . Installer::TABLE_WEB_SESSIONS;
	}

	public static function insert( int $customer_id, string $jti, int $expires_at ) : int {
		global $wpdb;
		$ua = mb_substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 );
		$ip = mb_substr( self::client_ip(), 0, 45 );
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( self::table(), [
			'customer_id'  => $customer_id,
			'jti'          => $jti,
			'ua'           => $ua,
			'ip'           => $ip,
			'created_at'   => $now,
			'last_seen_at' => $now,
			'expires_at'   => gmdate( 'Y-m-d H:i:s', $expires_at ),
		] );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Throttled last_seen_at-Update. Nur wenn der aktuelle Wert leer oder
	 * aelter als $throttle_seconds ist, wird geschrieben — vermeidet einen
	 * DB-Write pro REST-Request bei aktiver Session.
	 */
	public static function touch( string $jti, int $throttle_seconds = 300 ) : void {
		if ( $jti === '' ) { return; }
		global $wpdb;
		$now       = gmdate( 'Y-m-d H:i:s' );
		$threshold = gmdate( 'Y-m-d H:i:s', time() - $throttle_seconds );
		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . '
			 SET last_seen_at = %s
			 WHERE jti = %s AND (last_seen_at IS NULL OR last_seen_at < %s)',
			$now, $jti, $threshold
		) );
	}

	public static function list_for_customer( int $customer_id, string $current_jti = '' ) : array {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, jti, ua, ip, created_at, last_seen_at, expires_at
			 FROM ' . self::table() . '
			 WHERE customer_id = %d
			   AND revoked_at IS NULL
			   AND expires_at > %s
			 ORDER BY COALESCE(last_seen_at, created_at) DESC',
			$customer_id, $now
		), ARRAY_A );
		return array_map( static function ( $r ) use ( $current_jti ) {
			return [
				'id'           => (int) $r['id'],
				'ua'           => (string) $r['ua'],
				'ip'           => (string) $r['ip'],
				'created_at'   => (string) $r['created_at'],
				'last_seen_at' => $r['last_seen_at'] ?: null,
				'expires_at'   => (string) $r['expires_at'],
				'is_current'   => $current_jti !== '' && (string) $r['jti'] === $current_jti,
			];
		}, $rows ?: [] );
	}

	/**
	 * Revoke per Row-ID. Holt den JTI, schreibt ihn in die Token-Blacklist
	 * und markiert die Row als revoked. Der Cookie bleibt beim Client bis
	 * er sich neu einloggt — verify_session lehnt ihn aber ab dem naechsten
	 * Request ab.
	 *
	 * @return array{ok:bool,is_current?:bool,error?:string}
	 */
	public static function revoke_by_id( int $id, int $customer_id, string $current_jti = '' ) : array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT jti, expires_at FROM ' . self::table() . '
			 WHERE id = %d AND customer_id = %d AND revoked_at IS NULL',
			$id, $customer_id
		), ARRAY_A );
		if ( ! $row ) { return [ 'ok' => false, 'error' => 'not_found' ]; }

		$jti = (string) $row['jti'];
		$exp = strtotime( (string) $row['expires_at'] . ' UTC' );
		if ( $jti !== '' && $exp > time() ) {
			Token::blacklist_jti( $jti, $exp );
		}
		$wpdb->update( self::table(),
			[ 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ],
			[ 'id' => $id, 'customer_id' => $customer_id ]
		);
		return [ 'ok' => true, 'is_current' => $current_jti !== '' && $jti === $current_jti ];
	}

	/**
	 * Revoke per JTI. Wird von Session::destroy (Logout) benutzt.
	 */
	public static function revoke_by_jti( string $jti ) : void {
		if ( $jti === '' ) { return; }
		global $wpdb;
		$wpdb->update( self::table(),
			[ 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ],
			[ 'jti' => $jti ]
		);
	}

	private static function client_ip() : string {
		// Nur direkter REMOTE_ADDR — X-Forwarded-For nicht vertrauenswuerdig
		// ohne Proxy-Whitelist. Fuer die Anzeige "wo bist du eingeloggt"
		// reicht die Proxy-IP; genauer wird's nur mit Reverse-Proxy-Config.
		return (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
	}
}
