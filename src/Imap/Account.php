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

	/**
	 * Persistiert einen transient-oder-echten IMAP-Fehler fuer UI-Transparenz.
	 * last_error_count wird inkrementiert — ein wiederkehrender Fehler (mehrmals
	 * hintereinander) deutet auf permanentes Problem (Passwort geaendert,
	 * Refresh-Token revoked); ein 1er-Count ist meist ein transient Hiccup.
	 * Account-id (nicht customer-id-gekoppelt) damit System-Jobs (Cron) das
	 * auch aufrufen koennen.
	 */
	public static function record_error( int $id, string $detail ) : void {
		if ( $id <= 0 ) { return; }
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT customer_id, label, username, last_error_count FROM ' . self::table() . ' WHERE id = %d',
			$id
		), ARRAY_A );
		if ( ! $row ) { return; }
		$cur_count = (int) $row['last_error_count'];
		$new_count = $cur_count + 1;
		$backoff   = self::compute_backoff_seconds( $new_count );
		$wpdb->update( self::table(), [
			'last_error'       => mb_substr( $detail, 0, 500 ),
			'last_error_at'    => gmdate( 'Y-m-d H:i:s' ),
			'last_error_count' => $new_count,
			'next_retry_after' => gmdate( 'Y-m-d H:i:s', time() + $backoff ),
		], [ 'id' => $id ] );

		// Eskalations-Benachrichtigung: beim Uebergang 2→3 (erster "kritischer" Punkt),
		// dann nochmal bei 10 (dauerhaftes Problem — user hat 3er-Alert ignoriert).
		// Dedup ueber genaue Transition-Zahl: wenn cur_count anders war, feuern wir.
		if ( $new_count === 3 || $new_count === 10 ) {
			self::notify_imap_fail(
				(int) $row['customer_id'],
				$id,
				(string) ( $row['label'] ?: $row['username'] ),
				$detail,
				$new_count
			);
		}
	}

	private static function notify_imap_fail( int $customer_id, int $account_id, string $label, string $detail, int $count ) : void {
		// In-Portal-Benachrichtigung (Bell-Dropdown im Portal)
		\Itdatex\Mailguard\Notify\Notification::create( $customer_id, [
			'event' => 'imap.auth_failed',
			'title' => sprintf( 'IMAP-Konto "%s" nicht erreichbar', mb_substr( $label, 0, 60 ) ),
			'body'  => sprintf(
				"%dx hintereinander fehlgeschlagen.\n\nFehler: %s\n\nBitte Passwort im Portal unter Postfaecher neu eintragen.",
				$count,
				mb_substr( $detail, 0, 300 )
			),
			'route' => 'accounts',
		] );

		// E-Mail an die Login-Adresse des Customers — kritische Eskalation
		$customer_email = (string) ( $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare(
			'SELECT email FROM ' . $GLOBALS['wpdb']->prefix . 'mg_customers WHERE id = %d',
			$customer_id
		) ) ?? '' );
		if ( $customer_email !== '' ) {
			\Itdatex\Mailguard\Customer\Mailer::send_imap_fail_alert( $customer_email, $label, $detail, $count );
		}
		\Itdatex\Mailguard\Customer\AuditLog::emit(
			$customer_id,
			'imap.auth_failed_alert',
			\Itdatex\Mailguard\Customer\AuditLog::SEV_WARN,
			[ 'account_id' => $account_id, 'count' => $count ]
		);
	}

	public static function clear_error( int $id ) : void {
		if ( $id <= 0 ) { return; }
		global $wpdb;
		// Nur loeschen wenn vorher ein Fehler gesetzt war — sonst sparen wir
		// den UPDATE-Call auf jedem Successful-Connect.
		$cur = $wpdb->get_var( $wpdb->prepare(
			'SELECT last_error FROM ' . self::table() . ' WHERE id = %d',
			$id
		) );
		if ( $cur === null ) { return; }
		$wpdb->update( self::table(), [
			'last_error'       => null,
			'last_error_at'    => null,
			'last_error_count' => 0,
			'next_retry_after' => null,
		], [ 'id' => $id ] );
	}

	/**
	 * Exponential-Backoff-Rezept fuer wiederholte Auth-Fehler. Schuetzt vor:
	 *  - Provider-seitigem Rate-Limit nach vielen fehlgeschlagenen Logins
	 *    (GMX blockt c-client temporaer nach ~5-10 Fehlschlaegen)
	 *  - Eigenem Ressourcen-Verschleiss (jeder Cron-Pull-Zyklus waer sonst
	 *    ein weiterer Fehlschlag mit 5 s Retry-Sleep)
	 *
	 * Timing:
	 *   count 1-2  →  5 min  (frische Fehler, vermutlich transient — schnell retry)
	 *   count 3-5  → 15 min  (naechster regulaerer Cron-Zyklus)
	 *   count 6-10 → 30 min  (schon-mal-benachrichtigt, User informiert)
	 *   count 11+  →  2 h    (dauerhaftes Problem, nur ab-und-zu retryen)
	 */
	public static function compute_backoff_seconds( int $count ) : int {
		if ( $count <= 2 )  { return  5 * MINUTE_IN_SECONDS; }
		if ( $count <= 5 )  { return 15 * MINUTE_IN_SECONDS; }
		if ( $count <= 10 ) { return 30 * MINUTE_IN_SECONDS; }
		return 2 * HOUR_IN_SECONDS;
	}

	/**
	 * true wenn das Konto aktuell wegen vorheriger Fehlschlaege in Backoff
	 * steht und der naechste Retry noch nicht erlaubt ist.
	 */
	public static function is_in_backoff( int $id ) : bool {
		if ( $id <= 0 ) { return false; }
		global $wpdb;
		$next = $wpdb->get_var( $wpdb->prepare(
			'SELECT next_retry_after FROM ' . self::table() . ' WHERE id = %d',
			$id
		) );
		if ( ! $next ) { return false; }
		return strtotime( (string) $next . ' UTC' ) > time();
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
			// Default 50: fängt klares Phishing (Blacklist-Hit → immer Score 100,
			// LLM-Rescoring + Red-Flag-Kombination ≥ 50). Schwelle 30 wurde
			// getestet und produziert zu viele False-Positives bei Transaktional-
			// Mails (PayPal/Apple/LinkedIn/DHL werden mit "Zahlung/Konto"-Keywords
			// auf Score 30-49 geratet).
			'auto_quarantine_min_score' => 50,
			'imap_namespace_prefix'  => null,
			'imap_delimiter'         => null,
		];
	}

	/**
	 * Persistiert den vom IMAP-Client detektierten Personal-Namespace
	 * (Prefix inkl. Delimiter, z. B. "INBOX.") und den Hierarchy-Delimiter
	 * ("." oder "/"). Wird vom QuarantineService lazy aufgerufen, wenn
	 * die Felder NULL sind — danach bleibt das Ergebnis fuer den Account
	 * stabil und kein weiterer Namespace-Query ist noetig.
	 *
	 * Prefix wird auf 32 Zeichen begrenzt (DB-Spalte), Delimiter auf 1
	 * Zeichen. Leerer String ist ein gueltiger Wert fuer prefix und
	 * markiert "kein Prefix erforderlich" (Microsoft/Gmail/iCloud).
	 */
	public static function save_namespace( int $id, string $prefix, string $delimiter ) : bool {
		if ( $id <= 0 || $delimiter === '' ) { return false; }
		global $wpdb;
		return (bool) $wpdb->update( self::table(), [
			'imap_namespace_prefix' => mb_substr( $prefix, 0, 32 ),
			'imap_delimiter'        => mb_substr( $delimiter, 0, 1 ),
		], [ 'id' => $id ], [ '%s', '%s' ], [ '%d' ] );
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
			'last_error'       => $row['last_error']    ?? null,
			'last_error_at'    => $row['last_error_at'] ?? null,
			'last_error_count' => isset( $row['last_error_count'] ) ? (int) $row['last_error_count'] : 0,
			'next_retry_after' => $row['next_retry_after'] ?? null,
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
