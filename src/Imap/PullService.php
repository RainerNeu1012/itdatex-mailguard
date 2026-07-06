<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap;

/**
 * Pull-Orchestrierung pro Folder. Ein Account kann mehrere Folder haben
 * (INBOX, Junk, Custom-Labels), pro Folder eigenes last_uid.
 *
 * Strategie:
 *  - Pro Cron-Lauf: bis zu MAX_FOLDERS_PER_RUN aktive Folder (FIFO nach last_test_at NULL first)
 *  - Pro Folder: bis zu MAX_UIDS_PER_FOLDER neue UIDs (Schutz gegen Initial-Sync-Bombe)
 *  - last_uid wird nach jedem Folder-Lauf in mg_imap_folders gespeichert
 *
 * Backward-compat: pull_account(account_id, customer_id) pullt alle aktiven
 * Folder des Accounts; wird vom REST-Endpoint /accounts/{id}/pull genutzt.
 */
final class PullService {

	public const MAX_FOLDERS_PER_RUN = 25;
	public const MAX_UIDS_PER_FOLDER = 50;

	public const FOLDER_SYNC_TTL_SECONDS = 3600;

	public static function pull_all() : array {
		// Vor dem eigentlichen Pull: pro Account einmal pro Stunde die IMAP-
		// Folder-Liste syncen (via Transient-Throttle), damit neu angelegte
		// Ordner automatisch mit reinkommen ohne User-Aktion.
		self::maybe_sync_all_accounts();

		$folders = Folder::list_active( self::MAX_FOLDERS_PER_RUN );
		if ( ! $folders ) {
			return [ 'folders' => 0 ];
		}
		$summary = [];
		foreach ( $folders as $f ) {
			$summary[] = self::pull_folder( (int) $f['id'], (int) $f['customer_id'] );
		}
		return [ 'folders' => count( $summary ), 'results' => $summary ];
	}

	/**
	 * Backward-compatible: pullt alle aktiven Folder eines Accounts.
	 * Wird vom Single-Account-REST-Endpoint genutzt.
	 */
	public static function pull_account( int $account_id, int $customer_id ) : array {
		global $wpdb;

		// User-triggered Pull → Folder-Liste immer syncen, damit "Jetzt abholen"
		// nach einem neu angelegten IMAP-Ordner sofort greift.
		$account = Account::find_for_customer( $account_id, $customer_id );
		if ( $account ) {
			Folder::sync_from_imap( $account, $customer_id );
		}

		$t = Folder::table();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE account_id = %d AND customer_id = %d AND status = 'active' ORDER BY id ASC",
			$account_id, $customer_id
		), ARRAY_A );
		if ( ! $rows ) {
			return [ 'account_id' => $account_id, 'ok' => false, 'error' => 'no_active_folders' ];
		}
		$summary = [];
		foreach ( $rows as $f ) {
			$summary[] = self::pull_folder( (int) $f['id'], $customer_id );
		}
		$fetched = array_sum( array_map( static fn( $r ) => (int) ( $r['fetched'] ?? 0 ), $summary ) );
		return [
			'account_id' => $account_id,
			'ok'         => true,
			'folders'    => count( $summary ),
			'fetched'    => $fetched,
			'results'    => $summary,
		];
	}

	/**
	 * Iteriert alle aktiven Accounts und syncen die Folder-Liste, wenn
	 * der letzte Sync laenger als FOLDER_SYNC_TTL_SECONDS her ist.
	 * Wird vor jedem pull_all-Cron-Lauf aufgerufen — billig (1 IMAP-LIST
	 * pro Account und Stunde).
	 */
	public static function maybe_sync_all_accounts() : int {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT * FROM ' . $wpdb->prefix . "mg_imap_accounts WHERE status = 'active'",
			ARRAY_A
		);
		if ( ! $rows ) { return 0; }
		$synced = 0;
		foreach ( $rows as $row ) {
			$key = 'mg_folder_sync_' . (int) $row['id'];
			if ( get_transient( $key ) ) { continue; }
			set_transient( $key, 1, self::FOLDER_SYNC_TTL_SECONDS );
			Folder::sync_from_imap( $row, (int) $row['customer_id'] );
			$synced++;
		}
		return $synced;
	}

	public static function pull_folder( int $folder_id, int $customer_id ) : array {
		$folder = Folder::find_for_customer( $folder_id, $customer_id );
		if ( ! $folder ) {
			return [ 'folder_id' => $folder_id, 'ok' => false, 'error' => 'not_found' ];
		}
		if ( (string) $folder['status'] !== 'active' ) {
			return [ 'folder_id' => $folder_id, 'ok' => false, 'error' => 'inactive' ];
		}
		$account = Account::find_for_customer( (int) $folder['account_id'], $customer_id );
		if ( ! $account ) {
			return [ 'folder_id' => $folder_id, 'ok' => false, 'error' => 'account_gone' ];
		}

		try {
			$client = ClientFactory::for_account_folder( $account, (string) $folder['folder_name'] );
		} catch ( \Throwable $e ) {
			Folder::record_test( $folder_id, $customer_id, false, 'auth: ' . $e->getMessage() );
			return [ 'folder_id' => $folder_id, 'ok' => false, 'error' => 'auth_failed', 'detail' => $e->getMessage() ];
		}
		try {
			$client->connect();
		} catch ( \Throwable $e ) {
			Folder::record_test( $folder_id, $customer_id, false, 'connect: ' . $e->getMessage() );
			return [ 'folder_id' => $folder_id, 'ok' => false, 'error' => 'connect_failed', 'detail' => $e->getMessage() ];
		}

		try {
			$uids = $client->uids_since( (int) $folder['last_uid'], self::MAX_UIDS_PER_FOLDER );
		} catch ( \Throwable $e ) {
			$client->close();
			return [ 'folder_id' => $folder_id, 'ok' => false, 'error' => 'list_failed', 'detail' => $e->getMessage() ];
		}

		$inserted = 0; $dup = 0; $err = 0; $max_uid = (int) $folder['last_uid'];
		foreach ( $uids as $uid ) {
			try {
				$msg = $client->fetch_message( $uid );
			} catch ( \Throwable $e ) {
				$err++;
				continue;
			}
			$status = Message::ingest( $customer_id, (int) $folder['account_id'], (string) $folder['folder_name'], $msg );
			if ( $status === 'inserted' ) { $inserted++; }
			elseif ( $status === 'duplicate' ) { $dup++; }
			else { $err++; }
			if ( $uid > $max_uid ) { $max_uid = $uid; }
		}
		$client->close();

		if ( $max_uid > (int) $folder['last_uid'] ) {
			Folder::update_last_uid( $folder_id, $customer_id, $max_uid );
		}
		Folder::record_test( $folder_id, $customer_id, true, sprintf( 'pull ok · +%d dup=%d err=%d', $inserted, $dup, $err ) );

		return [
			'folder_id'   => $folder_id,
			'folder_name' => (string) $folder['folder_name'],
			'ok'          => true,
			'fetched'     => $inserted,
			'duplicates'  => $dup,
			'errors'      => $err,
			'last_uid'    => $max_uid,
		];
	}
}
