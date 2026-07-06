<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Customer;

use Itdatex\Mailguard\Installer;

/**
 * DB-backed Long-Lived-Bearer-Token fuer mobile Apps (Capacitor).
 *
 * Warum eigene Klasse statt Reuse der Cookie-Session-Token (Token::sign_session):
 *  - Native Apps koennen keine HttpOnly-Cookies portable halten → Bearer-Header noetig.
 *  - Wir wollen Revocation-Faehigkeit (Diebstahl, Logout auf allen Geraeten): das
 *    HMAC-Signed-Cookie-Muster erlaubt das nicht ohne DB-Blacklist.
 *  - Refresh-Rotation: separate Refresh-Tokens (30 Tage) rotieren bei jedem Refresh,
 *    Access-Tokens (1 Stunde) sind kurzlebig — reduziert Blast-Radius bei Leak.
 *
 * DB-Format:
 *  - token_hash / refresh_hash: sha256 hex des Klartext-Tokens (Uniqueness + Lookup).
 *  - Klartext-Token wird NIE persistiert; Client speichert ihn selbst.
 *  - Cache: WP-Object-Cache-Hit pro Access-Token, TTL 5 min → 1 DB-Query pro 5min pro Device.
 */
final class ApiToken {

	public const ACCESS_TTL_SECONDS  = 3600;      // 1 Stunde
	public const REFRESH_TTL_SECONDS = 2592000;   // 30 Tage
	public const CACHE_GROUP         = 'mg_api_tokens';
	public const CACHE_TTL           = 300;       // 5 min

	private static function table() : string {
		global $wpdb;
		return $wpdb->prefix . Installer::TABLE_API_TOKENS;
	}

	/**
	 * Erzeugt neues Token-Paar fuer einen Customer.
	 *
	 * @return array{access_token:string,refresh_token:string,access_expires_at:int,refresh_expires_at:int,token_id:int}
	 */
	public static function issue( int $customer_id, string $platform, string $name ) : array {
		global $wpdb;
		$access  = self::random_token();
		$refresh = self::random_token();
		$now       = time();
		$acc_exp   = $now + self::ACCESS_TTL_SECONDS;
		$ref_exp   = $now + self::REFRESH_TTL_SECONDS;
		$platform  = self::sanitize_platform( $platform );

		$wpdb->insert( self::table(), [
			'customer_id'        => $customer_id,
			'token_hash'         => hash( 'sha256', $access ),
			'refresh_hash'       => hash( 'sha256', $refresh ),
			'name'               => mb_substr( sanitize_text_field( $name ), 0, 120 ),
			'platform'           => $platform,
			'access_expires_at'  => gmdate( 'Y-m-d H:i:s', $acc_exp ),
			'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', $ref_exp ),
			'created_at'         => gmdate( 'Y-m-d H:i:s', $now ),
		] );
		$id = (int) $wpdb->insert_id;

		return [
			'token_id'           => $id,
			'access_token'       => $access,
			'refresh_token'      => $refresh,
			'access_expires_at'  => $acc_exp,
			'refresh_expires_at' => $ref_exp,
		];
	}

	/**
	 * Rotiert Refresh-Token → neues Access+Refresh-Paar. Der alte
	 * Refresh-Token wird durch die Rotation ungueltig (uniq auf refresh_hash).
	 *
	 * @return array{access_token:string,refresh_token:string,access_expires_at:int,refresh_expires_at:int,customer_id:int,token_id:int}|null
	 */
	public static function refresh( string $refresh_token ) : ?array {
		global $wpdb;
		$hash = hash( 'sha256', $refresh_token );
		$row  = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE refresh_hash = %s LIMIT 1',
			$hash
		), ARRAY_A );
		if ( ! $row || $row['revoked_at'] ) { return null; }
		if ( strtotime( (string) $row['refresh_expires_at'] . ' UTC' ) < time() ) { return null; }

		$new_access  = self::random_token();
		$new_refresh = self::random_token();
		$now         = time();
		$acc_exp     = $now + self::ACCESS_TTL_SECONDS;
		$ref_exp     = $now + self::REFRESH_TTL_SECONDS;

		// Alten Access-Cache invalidieren, falls noch warm.
		wp_cache_delete( (string) $row['token_hash'], self::CACHE_GROUP );

		$wpdb->update( self::table(),
			[
				'token_hash'         => hash( 'sha256', $new_access ),
				'refresh_hash'       => hash( 'sha256', $new_refresh ),
				'access_expires_at'  => gmdate( 'Y-m-d H:i:s', $acc_exp ),
				'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', $ref_exp ),
				'last_used_at'       => gmdate( 'Y-m-d H:i:s', $now ),
			],
			[ 'id' => (int) $row['id'] ]
		);

		return [
			'token_id'           => (int) $row['id'],
			'customer_id'        => (int) $row['customer_id'],
			'access_token'       => $new_access,
			'refresh_token'      => $new_refresh,
			'access_expires_at'  => $acc_exp,
			'refresh_expires_at' => $ref_exp,
		];
	}

	/**
	 * Prueft Access-Token gegen die DB. Liefert customer_id oder null.
	 * Cache-Hits vermeiden eine DB-Query pro Request; last_used_at wird
	 * bewusst NICHT bei jedem Cache-Hit aktualisiert (Write-Amp).
	 */
	public static function verify_access( string $access_token ) : ?int {
		if ( $access_token === '' ) { return null; }
		$hash = hash( 'sha256', $access_token );

		$cached = wp_cache_get( $hash, self::CACHE_GROUP );
		if ( is_array( $cached ) && isset( $cached['cid'], $cached['exp'] ) ) {
			if ( (int) $cached['exp'] > time() ) {
				return (int) $cached['cid'];
			}
			wp_cache_delete( $hash, self::CACHE_GROUP );
		}

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT id, customer_id, access_expires_at, revoked_at FROM ' . self::table() . ' WHERE token_hash = %s LIMIT 1',
			$hash
		), ARRAY_A );
		if ( ! $row || $row['revoked_at'] ) { return null; }
		$exp = strtotime( (string) $row['access_expires_at'] . ' UTC' );
		if ( $exp < time() ) { return null; }

		wp_cache_set( $hash, [ 'cid' => (int) $row['customer_id'], 'exp' => $exp ], self::CACHE_GROUP, self::CACHE_TTL );

		// last_used_at throttled updaten: nur wenn last_used_at NULL oder > 5 min alt.
		// Kein zusaetzlicher DB-Query wenn Cache-Hit im naechsten Aufruf.
		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . ' SET last_used_at = %s WHERE id = %d AND (last_used_at IS NULL OR last_used_at < %s)',
			gmdate( 'Y-m-d H:i:s', time() ),
			(int) $row['id'],
			gmdate( 'Y-m-d H:i:s', time() - self::CACHE_TTL )
		) );

		return (int) $row['customer_id'];
	}

	public static function revoke( int $id, int $customer_id ) : bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT token_hash FROM ' . self::table() . ' WHERE id = %d AND customer_id = %d LIMIT 1',
			$id, $customer_id
		), ARRAY_A );
		if ( ! $row ) { return false; }
		wp_cache_delete( (string) $row['token_hash'], self::CACHE_GROUP );
		return (bool) $wpdb->update( self::table(),
			[ 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ],
			[ 'id' => $id, 'customer_id' => $customer_id ]
		);
	}

	public static function revoke_by_hash( string $access_token, int $customer_id ) : bool {
		global $wpdb;
		$hash = hash( 'sha256', $access_token );
		wp_cache_delete( $hash, self::CACHE_GROUP );
		return (bool) $wpdb->update( self::table(),
			[ 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ],
			[ 'token_hash' => $hash, 'customer_id' => $customer_id ]
		);
	}

	/** Portal-View "Angemeldete Geraete". */
	public static function list_for_customer( int $customer_id ) : array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, name, platform, last_used_at, access_expires_at, refresh_expires_at, revoked_at, created_at
			 FROM ' . self::table() . '
			 WHERE customer_id = %d
			 ORDER BY (revoked_at IS NULL) DESC, COALESCE(last_used_at, created_at) DESC',
			$customer_id
		), ARRAY_A );
		return array_map( static function ( $r ) {
			return [
				'id'                 => (int) $r['id'],
				'name'               => (string) $r['name'],
				'platform'           => (string) $r['platform'],
				'last_used_at'       => $r['last_used_at'] ?: null,
				'access_expires_at'  => (string) $r['access_expires_at'],
				'refresh_expires_at' => $r['refresh_expires_at'] ?: null,
				'revoked_at'         => $r['revoked_at'] ?: null,
				'created_at'         => (string) $r['created_at'],
			];
		}, $rows ?: [] );
	}

	private static function random_token() : string {
		// 32 bytes Entropie, base64url — 43 Zeichen.
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}

	private static function sanitize_platform( string $p ) : string {
		$p = strtolower( trim( $p ) );
		return in_array( $p, [ 'ios', 'android', 'web' ], true ) ? $p : 'unknown';
	}
}
