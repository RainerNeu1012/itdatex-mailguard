<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Customer;

use Itdatex\Mailguard\Installer;

/**
 * Sensitive-Action-Audit-Log. Pro Event eine Zeile in mg_audit_log.
 * Emit-Call ist "fire and forget" — kein return-Wert, kein Throw auf Fail.
 * Events werden im Portal/Konto unter "Letzte Kontoaktivitaet" sichtbar.
 *
 * Event-Namespacing: `<domain>.<action>` mit snake_case.
 *   auth.login_success / auth.login_failed / auth.logout
 *   two_fa.enable / two_fa.disable / two_fa.reset_request / two_fa.reset_confirm
 *   password.change / password.reset_request / password.reset_confirm
 *   device.trusted / session.revoked / sessions.revoked_all
 */
final class AuditLog {

	public const SEV_INFO     = 'info';
	public const SEV_WARN     = 'warn';
	public const SEV_CRITICAL = 'critical';

	public static function emit( ?int $customer_id, string $event, string $severity = self::SEV_INFO, array $meta = [] ) : void {
		global $wpdb;
		$t = $wpdb->prefix . Installer::TABLE_AUDIT_LOG;
		// Suppress failures — audit-write darf einen User-Flow nie blocken
		@$wpdb->insert( $t, [
			'customer_id' => $customer_id,
			'event'       => mb_substr( $event, 0, 48 ),
			'severity'    => in_array( $severity, [ self::SEV_INFO, self::SEV_WARN, self::SEV_CRITICAL ], true ) ? $severity : self::SEV_INFO,
			'actor_ip'    => self::client_ip(),
			'actor_ua'    => self::client_ua(),
			'meta_json'   => $meta ? wp_json_encode( $meta ) : null,
			'created_at'  => gmdate( 'Y-m-d H:i:s' ),
		] );
	}

	/**
	 * Letzte N Events fuer einen Customer. Portal-View benutzt das.
	 */
	public static function list_for_customer( int $customer_id, int $limit = 50 ) : array {
		global $wpdb;
		$t = $wpdb->prefix . Installer::TABLE_AUDIT_LOG;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, event, severity, actor_ip, actor_ua, meta_json, created_at
			 FROM {$t} WHERE customer_id = %d
			 ORDER BY id DESC LIMIT %d",
			$customer_id, $limit
		), ARRAY_A );
		return array_map( static function( $r ) {
			$r['id']        = (int) $r['id'];
			$r['meta']      = $r['meta_json'] ? json_decode( (string) $r['meta_json'], true ) : null;
			unset( $r['meta_json'] );
			return $r;
		}, $rows ?: [] );
	}

	private static function client_ip() : string {
		$candidates = [];
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$candidates[] = (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
		}
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$first = trim( explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] );
			$candidates[] = $first;
		}
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$candidates[] = (string) $_SERVER['REMOTE_ADDR'];
		}
		foreach ( $candidates as $ip ) {
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) { return $ip; }
		}
		return '';
	}

	private static function client_ua() : string {
		$ua = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );
		return mb_substr( $ua, 0, 255 );
	}
}
