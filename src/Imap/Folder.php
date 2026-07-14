<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap;

use Itdatex\Mailguard\Installer;
use Itdatex\Mailguard\Imap\ClientFactory;
use Itdatex\Mailguard\Imap\QuarantineService;

/**
 * DB-Layer fuer mg_imap_folders. Ein Folder gehoert immer einem Account
 * und einem Customer (denormalisiert, damit Customer-scoped Queries
 * keinen JOIN brauchen).
 *
 * ALLE Lookups sind tenant-scoped via customer_id.
 *
 * Existiert, weil Endkunden in der Regel nicht nur INBOX scannen wollen —
 * Junk/Spam, Archiv, Custom-Folder gehoeren oft dazu. Pro Folder eigenes
 * last_uid, damit das inkrementelle Pulling sauber bleibt.
 */
final class Folder {

	public static function table() : string {
		global $wpdb;
		return $wpdb->prefix . Installer::TABLE_IMAP_FOLDERS;
	}

	public static function list_for_account( int $account_id, int $customer_id ) : array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE account_id = %d AND customer_id = %d ORDER BY id ASC',
			$account_id, $customer_id
		), ARRAY_A );
		return array_map( [ __CLASS__, 'public_view' ], $rows ?: [] );
	}

	/** Interne Lookup-Variante mit Tenant-Check. */
	public static function find_for_customer( int $id, int $customer_id ) : ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE id = %d AND customer_id = %d LIMIT 1',
			$id, $customer_id
		), ARRAY_A );
		return $row ?: null;
	}

	/** Alle aktiven Folders eines Customers (fuer Cron-Iteration). */
	public static function list_active_for_customer( int $customer_id ) : array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . " WHERE customer_id = %d AND status = 'active' ORDER BY id ASC",
			$customer_id
		), ARRAY_A ) ?: [];
	}

	/** Alle aktiven Folders systemweit (Cron-Hauptlauf). */
	public static function list_active( int $limit = 200 ) : array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . " WHERE status = 'active'
			 ORDER BY (last_test_at IS NULL) DESC, last_test_at ASC, id ASC
			 LIMIT %d",
			$limit
		), ARRAY_A ) ?: [];
	}

	/**
	 * Idempotenter Insert: existiert account_id+folder_name schon, gibt der
	 * existierenden Eintrag-ID zurueck.
	 */
	public static function create( int $account_id, int $customer_id, string $folder_name, string $display_name = '', string $status = 'active' ) : int {
		global $wpdb;
		$folder_name = sanitize_text_field( $folder_name );
		if ( $folder_name === '' ) { return 0; }
		if ( ! in_array( $status, [ 'active', 'disabled' ], true ) ) { $status = 'active'; }
		$existing = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::table() . ' WHERE account_id = %d AND folder_name = %s LIMIT 1',
			$account_id, $folder_name
		) );
		if ( $existing ) { return (int) $existing; }

		$ok = $wpdb->insert( self::table(), [
			'account_id'   => $account_id,
			'customer_id'  => $customer_id,
			'folder_name'  => $folder_name,
			'display_name' => sanitize_text_field( $display_name ),
			'status'       => $status,
			'last_uid'     => 0,
			'created_at'   => current_time( 'mysql', true ),
		] );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Erkennt Systemordner (Sent/Drafts/Trash/Deleted/Outbox/Notes/Archive/All-Mail/
	 * Sync-Issues), die vom Pull ausgeschlossen bleiben sollen — sonst quarantaeniert
	 * MailGuard Mails, die der User selbst geschrieben oder laengst geloescht hat.
	 *
	 * Zwei Signalquellen:
	 *  1. RFC-6154 SPECIAL-USE-Flags (\Sent, \Drafts, \Trash, \Archive, \All).
	 *     Nur der raw-IMAP-Client (XOauth2ImapClient) liefert die aus — die
	 *     c-client-Extension in ImapClient kennt keine SPECIAL-USE-Konstanten.
	 *  2. Namens-Heuristik (DE+EN) als Fallback fuer c-client- und aeltere Server.
	 *
	 * `\Junk` wird bewusst NICHT gefiltert: Spam-Ordner ist der Kernanwendungsfall.
	 */
	public static function is_system_folder( string $name, array $attrs = [] ) : bool {
		$attrs_lower = array_map( 'strtolower', $attrs );
		foreach ( [ '\\sent', '\\drafts', '\\trash', '\\archive', '\\all', '\\important', '\\flagged' ] as $flag ) {
			if ( in_array( $flag, $attrs_lower, true ) ) { return true; }
		}
		// Alle Pfad-Segmente pruefen — Kinder von System-Ordnern
		// ("Synchronisierungsprobleme/Konflikte", "Deleted/Quarantine",
		// "[Gmail]/Sent Mail") gelten ebenfalls als System.
		$segments = preg_split( '#[/.]+#', $name ) ?: [];
		$patterns = [
			'/^sent(\b|$| mail| items| messages)/i',
			'/^gesend/i',
			'/^drafts?$/i',
			'/^entw(u|\x{00fc})rf/iu',
			'/^trash$/i',
			'/^papierkorb/i',
			'/^deleted/i',
			'/^gel(o|\x{00f6})scht/iu',
			'/^outbox$/i',
			'/^postausg/i',
			'/^notes?$/i',
			'/^notiz/i',
			'/^archive?$/i',
			'/^archiv$/i',
			'/^all mail$/i',
			'/^alle nachrichten$/i',
			'/^synchronisierungsprobleme/i',
			'/^sync(hronization)? issues?/i',
			'/^conversation history$/i',
			'/^rss[- ]?feeds?$/i',
		];
		foreach ( $segments as $seg ) {
			$seg = trim( $seg );
			if ( $seg === '' ) { continue; }
			foreach ( $patterns as $rx ) {
				if ( preg_match( $rx, $seg ) ) { return true; }
			}
		}
		return false;
	}

	public static function update_status( int $id, int $customer_id, string $status ) : bool {
		global $wpdb;
		if ( ! in_array( $status, [ 'active', 'disabled' ], true ) ) { return false; }
		return (bool) $wpdb->update( self::table(),
			[ 'status' => $status ],
			[ 'id' => $id, 'customer_id' => $customer_id ]
		);
	}

	public static function delete( int $id, int $customer_id ) : bool {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), [ 'id' => $id, 'customer_id' => $customer_id ] );
	}

	public static function update_last_uid( int $id, int $customer_id, int $uid ) : void {
		global $wpdb;
		$wpdb->update( self::table(),
			[ 'last_uid' => $uid ],
			[ 'id' => $id, 'customer_id' => $customer_id ]
		);
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
	 * Synchronisiert die Folder-Tabelle mit dem tatsaechlichen IMAP-Baum:
	 * connectet, LISTet alle Folder, legt neu-entdeckte Folder an. Bereits
	 * konfigurierte Folder werden nicht angefasst (auch nicht reaktiviert,
	 * wenn der User sie manuell auf 'disabled' gesetzt hat).
	 *
	 * Ausgeschlossen: der Quarantaene-Folder des Accounts — sonst wuerden
	 * quarantaenisierte Mails beim naechsten Pull wieder als neue Mails
	 * eingesammelt (Loop). Non-selectable Folder ('\Noselect'-Attribut,
	 * wie Gmail-Label-Container) werden ebenfalls uebersprungen.
	 *
	 * Systemordner (Sent/Drafts/Trash/Deleted/Outbox/Notes/Archive/All-Mail)
	 * werden mit status='disabled' angelegt — sie sind fuer den User sichtbar,
	 * werden aber nicht gepullt, bis er sie bewusst aktiviert. Ohne diese
	 * Schranke landen selbst-geschriebene und laengst geloeschte Mails in der
	 * Auto-Quarantaene (Bug 2026-07-14).
	 *
	 * @return array{ok:bool,discovered?:int,added?:int,skipped?:int,error?:string,detail?:string}
	 */
	public static function sync_from_imap( array $account, int $customer_id ) : array {
		$account_id = (int) $account['id'];
		try {
			$client = ClientFactory::for_account( $account );
			$client->connect();
			$folders = $client->list_folders();
			$client->close();
		} catch ( \Throwable $e ) {
			return [ 'ok' => false, 'error' => 'connect_failed', 'detail' => $e->getMessage() ];
		}

		$quar_name = QuarantineService::quarantine_folder_for_account( $account );
		$existing  = array_flip( array_column( self::list_for_account( $account_id, $customer_id ), 'folder_name' ) );

		$added = 0; $skipped = 0;
		foreach ( $folders as $f ) {
			$name = (string) ( $f['name'] ?? '' );
			if ( $name === '' ) { $skipped++; continue; }
			if ( $name === $quar_name ) { $skipped++; continue; }
			$attrs = (array) ( $f['attributes'] ?? [] );
			$attrs_lower = array_map( 'strtolower', $attrs );
			// Server-Container ohne selektierbare Mails (Gmail-Label-Root, IMAP-Root).
			if ( in_array( '\\noselect', $attrs_lower, true ) ) { $skipped++; continue; }
			if ( isset( $existing[ $name ] ) ) { $skipped++; continue; }

			$status = self::is_system_folder( $name, $attrs ) ? 'disabled' : 'active';
			$new_id = self::create( $account_id, $customer_id, $name, (string) ( $f['display'] ?? '' ), $status );
			if ( $new_id > 0 ) { $added++; } else { $skipped++; }
		}

		return [ 'ok' => true, 'discovered' => count( $folders ), 'added' => $added, 'skipped' => $skipped ];
	}

	public static function public_view( array $row ) : array {
		return [
			'id'               => (int) $row['id'],
			'account_id'       => (int) $row['account_id'],
			'folder_name'      => (string) $row['folder_name'],
			'display_name'     => (string) ( $row['display_name'] ?? '' ),
			'status'           => (string) $row['status'],
			'last_uid'         => (int) $row['last_uid'],
			'last_test_at'     => $row['last_test_at']     ?: null,
			'last_test_ok'     => isset( $row['last_test_ok'] ) ? (int) $row['last_test_ok'] : null,
			'last_test_detail' => $row['last_test_detail'] ?: null,
			'created_at'       => (string) $row['created_at'],
		];
	}
}
