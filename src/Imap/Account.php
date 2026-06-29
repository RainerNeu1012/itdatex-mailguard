<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap;

use Itdatex\Mailguard\Installer;

/**
 * DB-Layer fuer mg_imap_accounts.
 * ALLE Queries sind tenant-scoped via customer_id.
 */
final class Account {

	public static function table() : string {
		global $wpdb;
		return $wpdb->prefix . Installer::TABLE_IMAP_ACCOUNTS;
	}

	public static function list_for_customer( int $customer_id ) : array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE customer_id = %d ORDER BY id ASC',
			$customer_id
		), ARRAY_A );
		return array_map( [ __CLASS__, 'public_view' ], $rows ?: [] );
	}

	/**
	 * Lookup ohne customer-Scope — NUR fuer interne Verwendung.
	 * Aufrufer MUSS customer_id selbst gegenchecken (s. find_for_customer).
	 */
	public static function find( int $id ) : ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1',
			$id
		), ARRAY_A );
		return $row ?: null;
	}

	public static function find_for_customer( int $id, int $customer_id ) : ?array {
		$row = self::find( $id );
		if ( ! $row || (int) $row['customer_id'] !== $customer_id ) {
			return null;
		}
		return $row;
	}

	public static function create( int $customer_id, array $data ) : int {
		global $wpdb;
		$row = array_merge(
			self::defaults(),
			self::filter_writable( $data ),
			[
				'customer_id' => $customer_id,
				'created_at'  => current_time( 'mysql', true ),
			]
		);
		if ( ! empty( $data['password'] ) ) {
			$row['password_enc'] = Crypto::encrypt( (string) $data['password'] );
		}
		$ok = $wpdb->insert( self::table(), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function update( int $id, int $customer_id, array $data ) : bool {
		global $wpdb;
		$row = self::find_for_customer( $id, $customer_id );
		if ( ! $row ) {
			return false;
		}
		$set = self::filter_writable( $data );
		// Password nur ersetzen, wenn ein neues uebergeben wurde.
		if ( ! empty( $data['password'] ) ) {
			$set['password_enc'] = Crypto::encrypt( (string) $data['password'] );
		}
		if ( empty( $set ) ) {
			return true;
		}
		return (bool) $wpdb->update( self::table(), $set, [ 'id' => $id, 'customer_id' => $customer_id ] );
	}

	public static function delete( int $id, int $customer_id ) : bool {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), [ 'id' => $id, 'customer_id' => $customer_id ] );
	}

	public static function record_test( int $id, int $customer_id, bool $ok, string $detail ) : void {
		global $wpdb;
		$wpdb->update( self::table(), [
			'last_test_at'     => current_time( 'mysql', true ),
			'last_test_ok'     => $ok ? 1 : 0,
			'last_test_detail' => mb_substr( $detail, 0, 500 ),
		], [ 'id' => $id, 'customer_id' => $customer_id ] );
	}

	private static function defaults() : array {
		return [
			'label'      => '',
			'host'       => '',
			'port'       => 993,
			'encryption' => 'ssl',
			'username'   => '',
			'password_enc' => '',
			'folder'     => 'INBOX',
			'status'     => 'active',
			'last_uid'   => 0,
			'auth_type'  => 'basic',
			'oauth_provider'         => '',
			'oauth_access_token_enc' => '',
			'oauth_refresh_token_enc'=> '',
			'oauth_token_expires_at' => null,
			'oauth_scope'            => '',
			'quarantine_folder'      => '',
			'auto_quarantine_min_score' => null,
		];
	}

	private static function filter_writable( array $in ) : array {
		$allowed = [];
		foreach ( [ 'label', 'host', 'username', 'folder' ] as $k ) {
			if ( isset( $in[ $k ] ) ) { $allowed[ $k ] = sanitize_text_field( (string) $in[ $k ] ); }
		}
		if ( isset( $in['port'] ) ) {
			$allowed['port'] = max( 1, min( 65535, (int) $in['port'] ) );
		}
		if ( isset( $in['encryption'] ) ) {
			$enc = (string) $in['encryption'];
			$allowed['encryption'] = in_array( $enc, [ 'ssl', 'tls', 'none' ], true ) ? $enc : 'ssl';
		}
		if ( isset( $in['status'] ) ) {
			$st = (string) $in['status'];
			$allowed['status'] = in_array( $st, [ 'active', 'disabled' ], true ) ? $st : 'active';
		}
		if ( isset( $in['auth_type'] ) ) {
			$at = (string) $in['auth_type'];
			$allowed['auth_type'] = in_array( $at, [ 'basic', 'oauth_microsoft', 'oauth_google' ], true ) ? $at : 'basic';
		}
		if ( array_key_exists( 'auto_quarantine_min_score', $in ) ) {
			// null / "" / "0" → Feature aus. Sonst Range-Check gegen
			// Installer::AUTO_QUARANTINE_MIN_SCORE..100.
			$raw = $in['auto_quarantine_min_score'];
			if ( $raw === null || $raw === '' || $raw === 0 || $raw === '0' ) {
				$allowed['auto_quarantine_min_score'] = null;
			} else {
				$v = (int) $raw;
				$min = \Itdatex\Mailguard\Installer::AUTO_QUARANTINE_MIN_SCORE;
				$allowed['auto_quarantine_min_score'] = max( $min, min( 100, $v ) );
			}
		}
		if ( isset( $in['quarantine_folder'] ) ) {
			$allowed['quarantine_folder'] = sanitize_text_field( (string) $in['quarantine_folder'] );
		}
		return $allowed;
	}

	/**
	 * Speichert OAuth-Tokens (Access + Refresh) verschluesselt im Account-Row.
	 * Refresh-Token wird nur ueberschrieben, wenn ein neues geliefert wurde —
	 * Microsoft schickt im Refresh-Response sonst nichts und wir wuerden
	 * das alte Long-lived-Token loeschen.
	 */
	public static function store_oauth_tokens( int $id, int $customer_id, array $tokens ) : bool {
		global $wpdb;
		$set = [];
		if ( isset( $tokens['access_token'] ) && $tokens['access_token'] !== '' ) {
			$set['oauth_access_token_enc'] = Crypto::encrypt( (string) $tokens['access_token'] );
		}
		if ( ! empty( $tokens['refresh_token'] ) ) {
			$set['oauth_refresh_token_enc'] = Crypto::encrypt( (string) $tokens['refresh_token'] );
		}
		if ( isset( $tokens['expires_in'] ) ) {
			$set['oauth_token_expires_at'] = gmdate( 'Y-m-d H:i:s', time() + max( 60, (int) $tokens['expires_in'] - 30 ) );
		}
		if ( isset( $tokens['scope'] ) ) {
			$set['oauth_scope'] = (string) $tokens['scope'];
		}
		if ( ! $set ) { return true; }
		return (bool) $wpdb->update( self::table(), $set, [ 'id' => $id, 'customer_id' => $customer_id ] );
	}

	/** Findet ein OAuth-Konto fuer einen Customer anhand Provider + Username. */
	public static function find_oauth_by_username( int $customer_id, string $provider, string $username ) : ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE customer_id = %d AND oauth_provider = %s AND username = %s LIMIT 1',
			$customer_id, $provider, $username
		), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Sicheres Public-View ohne Passwort/Internal-Felder.
	 */
	public static function public_view( array $row ) : array {
		$auth_type = (string) ( $row['auth_type'] ?? 'basic' );
		return [
			'id'               => (int) $row['id'],
			'label'            => (string) $row['label'],
			'host'             => (string) $row['host'],
			'port'             => (int) $row['port'],
			'encryption'       => (string) $row['encryption'],
			'username'         => (string) $row['username'],
			'folder'           => (string) $row['folder'],
			'status'           => (string) $row['status'],
			'last_uid'         => (int) $row['last_uid'],
			'last_test_at'     => $row['last_test_at']     ?: null,
			'last_test_ok'     => isset( $row['last_test_ok'] ) ? (int) $row['last_test_ok'] : null,
			'last_test_detail' => $row['last_test_detail'] ?: null,
			'created_at'       => (string) $row['created_at'],
			'has_password'     => ( $row['password_enc'] ?? '' ) !== '',
			'auth_type'        => $auth_type,
			'oauth_provider'   => (string) ( $row['oauth_provider'] ?? '' ),
			'oauth_connected'  => $auth_type !== 'basic' && ! empty( $row['oauth_refresh_token_enc'] ),
			'oauth_expires_at' => $row['oauth_token_expires_at'] ?? null,
			'quarantine_folder'         => (string) ( $row['quarantine_folder'] ?? '' ),
			'auto_quarantine_min_score' => isset( $row['auto_quarantine_min_score'] ) && $row['auto_quarantine_min_score'] !== null
				? (int) $row['auto_quarantine_min_score']
				: null,
		];
	}
}
