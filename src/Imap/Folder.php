<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap;

use Itdatex\Mailguard\Installer;

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
	public static function create( int $account_id, int $customer_id, string $folder_name, string $display_name = '' ) : int {
		global $wpdb;
		$folder_name = sanitize_text_field( $folder_name );
		if ( $folder_name === '' ) { return 0; }
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
			'status'       => 'active',
			'last_uid'     => 0,
			'created_at'   => current_time( 'mysql', true ),
		] );
		return $ok ? (int) $wpdb->insert_id : 0;
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
