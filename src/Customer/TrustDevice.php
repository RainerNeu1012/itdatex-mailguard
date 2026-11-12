<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Customer;

use Itdatex\Mailguard\Installer;

/**
 * "Dieses Geraet 30 Tage merken" nach 2FA-Login. Signiertes Cookie mit
 * `cid|device_id|exp`, Server speichert SHA256(device_id) in mg_trusted_devices.
 *
 * Flow:
 *   1. Login mit korrektem Passwort + 2FA-Code + remember_device=true
 *      → issue(): generiert device_id (hex), schreibt Hash in DB, setzt Cookie
 *   2. Naechster Login mit korrektem Passwort → verify_for_customer()
 *      prueft Cookie + DB-Row → bei Match wird 2FA-Challenge geskippt
 *   3. User kann Devices im Portal einzeln revoken (Activity-View) oder
 *      pauschal ueber Passwort-Change / 2FA-Disable (revoke_all_for_customer)
 *
 * Nicht-Mobile only: Cookie-basiert, Mobile-App nutzt eigenes Bearer-Token-
 * System (laengerer Refresh-Token) + hat eh schon ein per-Device-Token.
 */
final class TrustDevice {

	public const COOKIE  = 'itdatex_mg_trust';
	public const TTL_DAYS = 30;

	/** Nach erfolgreichem 2FA-Login: neues Trust-Token ausstellen. */
	public static function issue( int $customer_id, string $label = '' ) : void {
		global $wpdb;
		$device_id = bin2hex( random_bytes( 24 ) ); // 48 hex-chars → im Cookie
		$hash      = hash( 'sha256', $device_id );
		$expires   = time() + self::TTL_DAYS * DAY_IN_SECONDS;
		$wpdb->insert( $wpdb->prefix . Installer::TABLE_TRUSTED_DEVICES, [
			'customer_id' => $customer_id,
			'device_hash' => $hash,
			'label'       => mb_substr( $label ?: self::default_label(), 0, 100 ),
			'ua'          => mb_substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 ),
			'ip'          => self::client_ip(),
			'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			'expires_at'  => gmdate( 'Y-m-d H:i:s', $expires ),
		] );
		// Cookie-Payload: cid|device_id|exp — signieren damit Device-ID nicht geaendert werden kann.
		$payload = $customer_id . '|' . $device_id . '|' . $expires;
		$sig     = self::sign( $payload );
		self::write_cookie( $payload . '|' . $sig, $expires );
		AuditLog::emit( $customer_id, 'device.trusted', AuditLog::SEV_INFO );
	}

	/** Beim Login prüfen: ist für $customer_id ein gueltiges Trust-Cookie da? */
	public static function verify_for_customer( int $customer_id ) : bool {
		$raw = (string) ( $_COOKIE[ self::COOKIE ] ?? '' );
		if ( $raw === '' ) { return false; }
		$parts = explode( '|', $raw );
		if ( count( $parts ) !== 4 ) { return false; }
		[ $cid, $device_id, $exp, $sig ] = $parts;
		if ( (int) $cid !== $customer_id ) { return false; }
		if ( (int) $exp <= time() ) { return false; }
		$payload = $cid . '|' . $device_id . '|' . $exp;
		if ( ! hash_equals( self::sign( $payload ), $sig ) ) { return false; }
		// DB-Lookup: Hash muss existieren + nicht revoked + nicht expired
		global $wpdb;
		$hash = hash( 'sha256', $device_id );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, revoked_at, expires_at FROM {$wpdb->prefix}" . Installer::TABLE_TRUSTED_DEVICES . "
			 WHERE device_hash = %s AND customer_id = %d LIMIT 1",
			$hash, $customer_id
		), ARRAY_A );
		if ( ! $row || $row['revoked_at'] ) { return false; }
		if ( strtotime( (string) $row['expires_at'] . ' UTC' ) < time() ) { return false; }
		// last_seen_at aktualisieren (fire-and-forget)
		@$wpdb->update(
			$wpdb->prefix . Installer::TABLE_TRUSTED_DEVICES,
			[ 'last_seen_at' => gmdate( 'Y-m-d H:i:s' ) ],
			[ 'id' => (int) $row['id'] ]
		);
		return true;
	}

	public static function list_for_customer( int $customer_id ) : array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, label, ua, ip, created_at, last_seen_at, expires_at, revoked_at
			 FROM {$wpdb->prefix}" . Installer::TABLE_TRUSTED_DEVICES . "
			 WHERE customer_id = %d
			 ORDER BY (revoked_at IS NULL) DESC, COALESCE(last_seen_at, created_at) DESC",
			$customer_id
		), ARRAY_A );
		return array_map( static function( $r ) {
			$r['id'] = (int) $r['id'];
			return $r;
		}, $rows ?: [] );
	}

	public static function revoke( int $id, int $customer_id ) : bool {
		global $wpdb;
		$n = (int) $wpdb->update(
			$wpdb->prefix . Installer::TABLE_TRUSTED_DEVICES,
			[ 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ],
			[ 'id' => $id, 'customer_id' => $customer_id, 'revoked_at' => null ]
		);
		if ( $n > 0 ) {
			AuditLog::emit( $customer_id, 'device.untrusted', AuditLog::SEV_INFO, [ 'device_id' => $id ] );
		}
		return $n > 0;
	}

	public static function revoke_all_for_customer( int $customer_id ) : int {
		global $wpdb;
		$n = (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}" . Installer::TABLE_TRUSTED_DEVICES . "
			 SET revoked_at = %s
			 WHERE customer_id = %d AND revoked_at IS NULL",
			gmdate( 'Y-m-d H:i:s' ), $customer_id
		) );
		return $n;
	}

	private static function sign( string $payload ) : string {
		return hash_hmac( 'sha256', $payload, self::secret() );
	}

	private static function secret() : string {
		// LOGGED_IN_KEY ist WP-Core-Konstante, in wp-config.php pro Site zufaellig gesetzt.
		return defined( 'LOGGED_IN_KEY' ) ? (string) LOGGED_IN_KEY : 'mg-fallback-dev-only';
	}

	private static function write_cookie( string $value, int $expires ) : void {
		if ( headers_sent() ) { return; }
		setcookie( self::COOKIE, $value, [
			'expires'  => $expires,
			'path'     => '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		] );
		$_COOKIE[ self::COOKIE ] = $value;
	}

	private static function default_label() : string {
		$ua = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );
		if ( preg_match( '/(Firefox|Chrome|Safari|Edg)\/[\d.]+/', $ua, $m ) ) {
			$browser = $m[1] === 'Edg' ? 'Edge' : $m[1];
			if ( preg_match( '/\((Windows|Macintosh|Linux|iPhone|Android)[^)]*\)/', $ua, $m2 ) ) {
				return $browser . ' auf ' . $m2[1];
			}
			return $browser;
		}
		return 'Unbekanntes Geraet';
	}

	private static function client_ip() : string {
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) { return (string) $_SERVER['HTTP_CF_CONNECTING_IP']; }
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$first = trim( explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] );
			if ( filter_var( $first, FILTER_VALIDATE_IP ) ) { return $first; }
		}
		return (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
	}
}
