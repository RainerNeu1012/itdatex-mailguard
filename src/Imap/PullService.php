<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap;

/**
 * Pull-Orchestrierung: ein Account, alle aktiven Accounts, Cron-Hook.
 *
 * Strategie:
 *  - Pro Cron-Lauf: bis zu MAX_ACCOUNTS_PER_RUN aktive Accounts (FIFO nach last_test_at NULL first)
 *  - Pro Account: bis zu MAX_UIDS_PER_ACCOUNT neue UIDs (Schutz gegen erste-Sync-Bombe)
 *  - last_uid wird nach jedem Account-Lauf in mg_imap_accounts gespeichert
 *
 * Skalierung: bei vielen Accounts/Site sollte das spaeter durch eine
 * Worker-Queue ersetzt werden (Action Scheduler / systemd-Service).
 */
final class PullService {

	public const MAX_ACCOUNTS_PER_RUN  = 25;
	public const MAX_UIDS_PER_ACCOUNT  = 50;

	public static function pull_all() : array {
		global $wpdb;
		$table = $wpdb->prefix . \Itdatex\Mailguard\Installer::TABLE_IMAP_ACCOUNTS;
		$rows  = $wpdb->get_results(
			"SELECT id, customer_id FROM {$table}
			 WHERE status = 'active'
			 ORDER BY (last_test_at IS NULL) DESC, last_test_at ASC, id ASC
			 LIMIT " . (int) self::MAX_ACCOUNTS_PER_RUN,
			ARRAY_A
		);
		if ( ! $rows ) {
			return [ 'accounts' => 0 ];
		}
		$summary = [];
		foreach ( $rows as $r ) {
			$summary[] = self::pull_account( (int) $r['id'], (int) $r['customer_id'] );
		}
		return [ 'accounts' => count( $summary ), 'results' => $summary ];
	}

	public static function pull_account( int $account_id, int $customer_id ) : array {
		$row = Account::find_for_customer( $account_id, $customer_id );
		if ( ! $row ) {
			return [ 'account_id' => $account_id, 'ok' => false, 'error' => 'not_found' ];
		}
		if ( (string) $row['status'] !== 'active' ) {
			return [ 'account_id' => $account_id, 'ok' => false, 'error' => 'inactive' ];
		}
		$plain = Crypto::decrypt( (string) $row['password_enc'] );
		if ( $plain === '' ) {
			return [ 'account_id' => $account_id, 'ok' => false, 'error' => 'no_password' ];
		}

		$client = new ImapClient(
			(string) $row['host'],
			(int)    $row['port'],
			(string) $row['encryption'],
			(string) $row['folder'],
			(string) $row['username'],
			$plain
		);
		try {
			$client->connect();
		} catch ( \Throwable $e ) {
			Account::record_test( $account_id, $customer_id, false, 'connect: ' . $e->getMessage() );
			return [ 'account_id' => $account_id, 'ok' => false, 'error' => 'connect_failed', 'detail' => $e->getMessage() ];
		}

		try {
			$uids = $client->uids_since( (int) $row['last_uid'], self::MAX_UIDS_PER_ACCOUNT );
		} catch ( \Throwable $e ) {
			$client->close();
			return [ 'account_id' => $account_id, 'ok' => false, 'error' => 'list_failed', 'detail' => $e->getMessage() ];
		}

		$inserted = 0; $dup = 0; $err = 0; $max_uid = (int) $row['last_uid'];
		foreach ( $uids as $uid ) {
			try {
				$msg = $client->fetch_message( $uid );
			} catch ( \Throwable $e ) {
				$err++;
				continue;
			}
			$status = Message::ingest( $customer_id, $account_id, (string) $row['folder'], $msg );
			if ( $status === 'inserted' ) { $inserted++; }
			elseif ( $status === 'duplicate' ) { $dup++; }
			else { $err++; }
			if ( $uid > $max_uid ) { $max_uid = $uid; }
		}
		$client->close();

		if ( $max_uid > (int) $row['last_uid'] ) {
			global $wpdb;
			$wpdb->update(
				$wpdb->prefix . \Itdatex\Mailguard\Installer::TABLE_IMAP_ACCOUNTS,
				[ 'last_uid' => $max_uid ],
				[ 'id' => $account_id, 'customer_id' => $customer_id ]
			);
		}

		Account::record_test( $account_id, $customer_id, true, sprintf( 'pull ok · +%d dup=%d err=%d', $inserted, $dup, $err ) );

		return [
			'account_id' => $account_id,
			'ok'         => true,
			'fetched'    => $inserted,
			'duplicates' => $dup,
			'errors'     => $err,
			'last_uid'   => $max_uid,
		];
	}
}
