<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap;

use Itdatex\Mailguard\Antiphish\EradicateDomains;
use Itdatex\Mailguard\Antiphish\ScanService;
use Itdatex\Mailguard\Installer;

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
		global $wpdb;
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

		$inserted = 0; $dup = 0; $err = 0; $eradicated = 0; $max_uid = (int) $folder['last_uid'];
		// UIDs, die per Auto-Vernichten geblockt wurden, sammeln wir und
		// expungen sie am Ende gesammelt — ein EXPUNGE pro UID waere N Roundtrips,
		// eine Batch spart die Latenz auf grossen IONOS-Konten.
		$eradicate_uids = [];
		foreach ( $uids as $uid ) {
			try {
				$msg = $client->fetch_message( $uid );
			} catch ( \Throwable $e ) {
				$err++;
				continue;
			}

			// Auto-Vernichten-Check: bevor die Mail ueberhaupt in mg_messages
			// landet, pruefen wir die Domain gegen die Liste des Kunden. Bei
			// Treffer: UID zum Sammel-EXPUNGE vormerken, Hit zaehlen, weiter.
			$from_addr = (string) ( $msg['from_addr'] ?? '' );
			$domain    = EradicateDomains::extract_domain( $from_addr );
			if ( $domain && EradicateDomains::is_active( $customer_id, $domain ) ) {
				$eradicate_uids[] = $uid;
				EradicateDomains::record_hit( $customer_id, $domain );
				$eradicated++;
				if ( $uid > $max_uid ) { $max_uid = $uid; }
				continue;
			}

			$status = Message::ingest( $customer_id, (int) $folder['account_id'], (string) $folder['folder_name'], $msg );
			if ( $status === 'inserted' ) { $inserted++; }
			elseif ( $status === 'duplicate' ) { $dup++; }
			else { $err++; }
			if ( $uid > $max_uid ) { $max_uid = $uid; }
		}
		// Batch-EXPUNGE der auto-vernichteten UIDs. Failures nicht fatal:
		// wenn das EXPUNGE scheitert, hat der User beim naechsten Pull-Lauf
		// eine "unerwartet gelandete" Mail in der Inbox — der Domain-Filter
		// greift dann aber trotzdem erneut und versucht es nochmal.
		if ( $eradicate_uids ) {
			try {
				$client->expunge_uids( $eradicate_uids );
			} catch ( \Throwable $e ) {
				// bewusst still — Log via Folder::record_test unten wuerde nur
				// den Erfolgsfall verwaessern; die naechste Runde faengt's ab.
			}
		}
		$client->close();

		if ( $max_uid > (int) $folder['last_uid'] ) {
			Folder::update_last_uid( $folder_id, $customer_id, $max_uid );
		}

		// Neu eingefuegte Nachrichten sofort synchron scannen — sonst laege
		// die Pull-Response fertig vor, aber die neuen Mails haetten noch
		// keinen Verdict-Badge, bis der 5-min-Cron drueberlaeuft. Wir holen
		// uns die IDs der zuletzt-eingefuegten $inserted Mails aus dieser
		// Folder (nach id DESC + LIMIT) und scannen chronologisch aeltere-
		// zuerst. Fehler beim Scan sind nicht fatal — die Mail bleibt in
		// pending und der naechste Cron-Lauf versucht es erneut.
		$scanned_ok = 0;
		if ( $inserted > 0 ) {
			$t = $wpdb->prefix . Installer::TABLE_MESSAGES;
			$new_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$t} WHERE customer_id = %d AND account_id = %d AND folder = %s AND scan_status = 'pending' ORDER BY id DESC LIMIT %d",
				$customer_id,
				(int) $folder['account_id'],
				(string) $folder['folder_name'],
				$inserted
			) );
			foreach ( array_reverse( $new_ids ) as $mid ) {
				$res = ScanService::scan_message( (int) $mid );
				if ( ! empty( $res['ok'] ) ) { $scanned_ok++; }
			}
		}

		Folder::record_test( $folder_id, $customer_id, true, sprintf( 'pull ok · +%d dup=%d era=%d err=%d scan=%d', $inserted, $dup, $eradicated, $err, $scanned_ok ) );

		return [
			'folder_id'   => $folder_id,
			'folder_name' => (string) $folder['folder_name'],
			'ok'          => true,
			'fetched'     => $inserted,
			'scanned'     => $scanned_ok,
			'duplicates'  => $dup,
			'eradicated'  => $eradicated,
			'errors'      => $err,
			'last_uid'    => $max_uid,
		];
	}
}
