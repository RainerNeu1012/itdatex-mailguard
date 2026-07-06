<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish;

use Itdatex\Mailguard\Imap\Account;
use Itdatex\Mailguard\Imap\Action as ImapAction;
use Itdatex\Mailguard\Imap\ClientFactory;
use Itdatex\Mailguard\Imap\Message as ImapMessage;
use Itdatex\Mailguard\Imap\QuarantineService;
use Itdatex\Mailguard\Installer;
use Itdatex\Mailguard\Rules\Rule;

/**
 * Bulk-Aktion nach Newsletter-Abmeldung: verschiebt alle noch nicht
 * quarantänisierten Newsletter-Mails eines Absenders in den
 * Quarantäne-Ordner (QuarantineService — kein EXPUNGE, wiederherstellbar
 * über Undo) und legt optional eine Blacklist-Regel an, damit künftige
 * Mails des Senders vom Scanner als 'dangerous' markiert werden.
 *
 * Auto-Move für künftige Mails hängt an der bestehenden
 * `auto_quarantine_min_score`-Schwelle des IMAP-Accounts: Blacklist setzt
 * score=100, jeder gesetzte Schwellwert löst also Auto-Quarantäne aus. Ist
 * kein Schwellwert gesetzt, werden Mails immerhin rot markiert und der
 * Sender bleibt aus den Newsletter-Abos ausgesperrt.
 */
final class PurgeService {

	/**
	 * @return array{ok:bool,moved:int,skipped:int,failed:int,rule_id?:int,failures?:array<int,array<string,string>>}
	 */
	public static function purge_sender( int $customer_id, string $from_addr, bool $auto_rule = false ) : array {
		$from_addr = strtolower( trim( $from_addr ) );
		if ( $from_addr === '' || ! str_contains( $from_addr, '@' ) ) {
			return [ 'ok' => false, 'moved' => 0, 'skipped' => 0, 'failed' => 0, 'error' => 'bad_from_addr' ];
		}

		global $wpdb;
		$t   = ImapMessage::table();
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$t}
			 WHERE customer_id = %d
			   AND has_unsub = 1
			   AND LOWER(from_addr) = %s
			   AND quarantine_action_id IS NULL
			 ORDER BY id ASC",
			$customer_id, $from_addr
		) );

		$moved = 0; $skipped = 0; $failed = 0;
		$failures = [];
		foreach ( $ids ?: [] as $mid ) {
			$res = QuarantineService::quarantine( (int) $mid, $customer_id, ImapAction::ACTOR_USER );
			if ( ! empty( $res['ok'] ) ) {
				$moved++;
				continue;
			}
			$err = (string) ( $res['error'] ?? 'unknown' );
			if ( $err === 'already_quarantined' || $err === 'source_mail_gone' || $err === 'not_found' ) {
				$skipped++;
			} else {
				$failed++;
				$failures[] = [ 'message_id' => (int) $mid, 'error' => $err ];
			}
		}

		$out = [ 'ok' => $failed === 0, 'moved' => $moved, 'skipped' => $skipped, 'failed' => $failed ];
		if ( $failures ) { $out['failures'] = $failures; }

		if ( $auto_rule ) {
			$rule = self::ensure_blacklist_rule( $customer_id, $from_addr );
			if ( ! empty( $rule['id'] ) ) { $out['rule_id'] = (int) $rule['id']; }
		}
		return $out;
	}

	/**
	 * Legt eine Blacklist-from_addr-Regel für den Sender an, ohne Mails
	 * anzufassen. Wird vom Portal-Button "Blockieren" genutzt.
	 *
	 * @return array{ok:bool,id?:int,existed?:bool,error?:string}
	 */
	public static function block_sender( int $customer_id, string $from_addr, string $note = '' ) : array {
		$from_addr = strtolower( trim( $from_addr ) );
		if ( $from_addr === '' || ! str_contains( $from_addr, '@' ) ) {
			return [ 'ok' => false, 'error' => 'bad_from_addr' ];
		}
		return self::ensure_blacklist_rule( $customer_id, $from_addr, $note );
	}

	/**
	 * Endgueltiges Loeschen ALLER Mails eines Absenders — nicht nur Newsletter,
	 * nicht nur quarantaenisierte.
	 *
	 * Batched pro (account_id, source_folder): fuer jede Gruppe **eine**
	 * IMAP-Verbindung, MOVE des UID-Sets in den MailGuard-eigenen Quarantaene-
	 * Folder, dann pro Account **einmal** folder-weit EXPUNGE auf der
	 * Quarantaene (safe, weil MailGuard-only-writable). Wir vermeiden absicht-
	 * lich folder-weites EXPUNGE auf Inbox/Junk — das wuerde \Deleted-Marker
	 * aus anderen Clients (Thunderbird, Alpine) mitloeschen. c-client kennt
	 * kein UID EXPUNGE, deshalb der Umweg ueber den Quarantaene-Folder.
	 *
	 * Ohne Batching gab's bei ~4 IONOS-Mails schon nginx-502 (siehe UX-Bug
	 * 2026-07-06: pro-Mail-Connect + LOGIN summierten sich > 30 s).
	 *
	 * Quarantaenisierte Mails (mit quarantine_action_id) laufen weiter durch
	 * QuarantineService::purge_message → das haelt den bestehenden Audit-
	 * Flow (Undo-Referenz + Purge-Action) intakt und ist im Sender-Purge-Flow
	 * meist die Ausnahme, weil der User quarantaenisierte Mails ueber die
	 * Aktionen-Ansicht endgueltig loescht.
	 *
	 * @return array{ok:bool,purged:int,skipped:int,failed:int,failures?:array<int,array<string,string>>}
	 */
	public static function hard_purge_sender( int $customer_id, string $from_addr ) : array {
		$from_addr = strtolower( trim( $from_addr ) );
		if ( $from_addr === '' || ! str_contains( $from_addr, '@' ) ) {
			return [ 'ok' => false, 'purged' => 0, 'skipped' => 0, 'failed' => 0, 'error' => 'bad_from_addr' ];
		}

		global $wpdb;
		$t    = ImapMessage::table();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, account_id, folder, imap_uid, msg_id_hdr, quarantine_action_id,
			        subject, from_addr, scan_verdict, scan_score
			 FROM {$t}
			 WHERE customer_id = %d AND LOWER(from_addr) = %s
			 ORDER BY id ASC",
			$customer_id, $from_addr
		), ARRAY_A );

		$purged = 0; $skipped = 0; $failed = 0;
		$failures = [];

		// (account_id, folder) → rows to batch-move. Quarantaenisierte Mails
		// gehen durch den bestehenden Einzel-Purge-Pfad.
		$groups   = [];
		$per_item = [];
		foreach ( $rows ?: [] as $row ) {
			if ( ! empty( $row['quarantine_action_id'] ) ) {
				$per_item[] = $row;
				continue;
			}
			$key = (int) $row['account_id'] . '|' . (string) $row['folder'];
			$groups[ $key ][] = $row;
		}

		// Cache der Account-Rows + Set der Accounts, deren Quarantaene wir
		// am Ende expungen muessen.
		$account_by_id  = [];
		$quar_by_acc_id = [];
		$successful_rows_by_acc_id = [];

		foreach ( $groups as $group_rows ) {
			$first        = $group_rows[0];
			$account_id   = (int) $first['account_id'];
			$folder_name  = (string) $first['folder'];
			if ( ! isset( $account_by_id[ $account_id ] ) ) {
				$account_by_id[ $account_id ] = Account::find_for_customer( $account_id, $customer_id );
			}
			$account = $account_by_id[ $account_id ];
			if ( ! $account ) {
				foreach ( $group_rows as $r ) {
					$failed++;
					$failures[] = [ 'message_id' => (int) $r['id'], 'error' => 'account_gone' ];
				}
				continue;
			}
			$quar_folder = QuarantineService::quarantine_folder_for_account( $account );
			$quar_by_acc_id[ $account_id ] = $quar_folder;

			// Wenn die Mails schon in der Quarantaene liegen (Legacy-Rows ohne
			// quarantine_action_id, aber folder == quarantine), macht "MOVE to
			// self" keinen Sinn — manche Server (IONOS/Dovecot) blockieren
			// darauf und laufen in den FPM-Timeout (=> 502). Dann direkt in
			// place expungen: folder-weites EXPUNGE ist in der Quarantaene
			// unkritisch, weil ausschliesslich MailGuard hineinschreibt.
			$is_source_quarantine = ( $folder_name === $quar_folder );

			try {
				$client = ClientFactory::for_account_folder( $account, $folder_name );
				$client->connect();
				if ( ! $is_source_quarantine ) {
					$client->ensure_folder( $quar_folder );
				}
			} catch ( \Throwable $e ) {
				foreach ( $group_rows as $r ) {
					$failed++;
					$failures[] = [ 'message_id' => (int) $r['id'], 'error' => 'connect_failed' ];
				}
				continue;
			}

			// UIDs auflösen: gespeicherte imap_uid bevorzugen, sonst
			// Message-ID-Lookup. Zeilen ohne aufloesbare UID sind Orphans —
			// werden weiter unten per SEARCH FROM auf dem Server nachgezogen.
			$uids_to_move = [];
			$row_by_uid   = [];
			$orphans      = [];
			foreach ( $group_rows as $r ) {
				$uid = (int) $r['imap_uid'];
				$hdr = (string) ( $r['msg_id_hdr'] ?? '' );
				if ( $uid === 0 && $hdr !== '' ) {
					try {
						$uid = $client->find_uid_by_message_id( $hdr );
					} catch ( \Throwable $e ) {
						$uid = 0;
					}
				}
				if ( $uid === 0 ) {
					$orphans[] = $r;
					continue;
				}
				$uids_to_move[]     = $uid;
				$row_by_uid[ $uid ] = $r;
			}

			// Legacy-Rows ohne UID + Message-ID: SEARCH FROM auf dem Server —
			// findet die Mail unabhaengig davon was in unserer DB steht.
			// Wenn der Server nichts liefert (Mail schon manuell weg), werden
			// die Orphan-DB-Rows trotzdem geschlossen (Audit source_uid=0).
			if ( $orphans ) {
				try {
					$found = $client->uids_by_from( $from_addr );
				} catch ( \Throwable $e ) {
					$found = [];
				}
				foreach ( $found as $u ) {
					if ( $u > 0 && ! isset( $row_by_uid[ $u ] ) ) {
						$uids_to_move[] = $u;
					}
				}
				$uids_to_move = array_values( array_unique( $uids_to_move ) );
			}

			if ( $uids_to_move ) {
				try {
					if ( $is_source_quarantine ) {
						$client->expunge_uids( $uids_to_move );
					} else {
						$client->move_uids( $uids_to_move, $quar_folder );
					}
				} catch ( \Throwable $e ) {
					$client->close();
					$err = $is_source_quarantine ? 'expunge_failed' : 'move_failed';
					foreach ( $row_by_uid as $r ) {
						$failed++;
						$failures[] = [ 'message_id' => (int) $r['id'], 'error' => $err ];
					}
					foreach ( $orphans as $r ) {
						$failed++;
						$failures[] = [ 'message_id' => (int) $r['id'], 'error' => $err ];
					}
					continue;
				}
			}
			$client->close();

			foreach ( $row_by_uid as $uid => $r ) {
				$successful_rows_by_acc_id[ $account_id ][] = [ 'row' => $r, 'source_uid' => (int) $uid ];
			}
			foreach ( $orphans as $r ) {
				$successful_rows_by_acc_id[ $account_id ][] = [ 'row' => $r, 'source_uid' => 0 ];
			}
		}

		// Zweiter Schritt: pro Account einmal Quarantaene expungen. Dort ist
		// folder-weites EXPUNGE unkritisch, weil ausschliesslich MailGuard
		// hineinschreibt.
		foreach ( $successful_rows_by_acc_id as $account_id => $entries ) {
			$account     = $account_by_id[ $account_id ] ?? null;
			$quar_folder = $quar_by_acc_id[ $account_id ] ?? '';
			if ( ! $account || $quar_folder === '' ) {
				foreach ( $entries as $entry ) {
					$failed++;
					$failures[] = [ 'message_id' => (int) $entry['row']['id'], 'error' => 'quarantine_missing' ];
				}
				continue;
			}
			try {
				$qclient = ClientFactory::for_account_folder( $account, $quar_folder );
				$qclient->connect();
				$qclient->expunge_selected();
				$qclient->close();
			} catch ( \Throwable $e ) {
				// Move ist durch — Mails sind aus dem Source-Folder raus, liegen
				// nur noch in Quarantaene. Kein Datenverlust, aber noch nicht
				// endgueltig geloescht. Als Fehler ausweisen, damit die UI dem
				// User "teilweise erledigt" anzeigen kann.
				foreach ( $entries as $entry ) {
					$failed++;
					$failures[] = [ 'message_id' => (int) $entry['row']['id'], 'error' => 'expunge_failed' ];
				}
				continue;
			}

			foreach ( $entries as $entry ) {
				$r          = $entry['row'];
				$source_uid = (int) $entry['source_uid'];
				ImapAction::create( [
					'customer_id'        => $customer_id,
					'account_id'         => (int) $r['account_id'],
					'message_id'         => (int) $r['id'],
					'action'             => ImapAction::ACTION_PURGE,
					'source_folder'      => (string) $r['folder'],
					'source_uid'         => $source_uid,
					'target_folder'      => '',
					'target_uid'         => 0,
					'verdict_snap'       => (string) ( $r['scan_verdict'] ?? '' ),
					'verdict_score_snap' => isset( $r['scan_score'] ) && $r['scan_score'] !== null ? (int) $r['scan_score'] : null,
					'subject_snap'       => (string) ( $r['subject'] ?? '' ),
					'from_addr_snap'     => (string) ( $r['from_addr'] ?? '' ),
					'status'             => ImapAction::STATUS_DONE,
					'actor'              => ImapAction::ACTOR_USER,
					'undo_until'         => null,
				] );
				ImapMessage::delete( (int) $r['id'], $customer_id );
				$purged++;
			}
		}

		foreach ( $per_item as $r ) {
			$res = QuarantineService::purge_message( (int) $r['id'], $customer_id );
			if ( ! empty( $res['ok'] ) ) {
				$purged++;
				continue;
			}
			$err = (string) ( $res['error'] ?? 'unknown' );
			if ( $err === 'not_found' ) {
				$skipped++;
			} else {
				$failed++;
				$failures[] = [ 'message_id' => (int) $r['id'], 'error' => $err ];
			}
		}

		$out = [ 'ok' => $failed === 0, 'purged' => $purged, 'skipped' => $skipped, 'failed' => $failed ];
		if ( $failures ) { $out['failures'] = $failures; }
		return $out;
	}

	/**
	 * Legt eine Blacklist-from_addr-Regel an, sofern noch keine für diesen
	 * Sender existiert. Duplikate wären harmlos (Engine matched auf die erste),
	 * würden die Rules-Liste im Portal aber unnötig aufblähen.
	 *
	 * @return array{ok:bool,id?:int,existed?:bool,error?:string}
	 */
	private static function ensure_blacklist_rule( int $customer_id, string $from_addr, string $note = '' ) : array {
		global $wpdb;
		$t  = $wpdb->prefix . Installer::TABLE_RULES;
		$id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t}
			 WHERE customer_id = %d
			   AND kind = 'blacklist'
			   AND match_type = 'from_addr'
			   AND pattern = %s
			 LIMIT 1",
			$customer_id, $from_addr
		) );
		if ( $id > 0 ) { return [ 'ok' => true, 'id' => $id, 'existed' => true ]; }

		$res = Rule::create( $customer_id, [
			'kind'       => 'blacklist',
			'match_type' => 'from_addr',
			'pattern'    => $from_addr,
			'note'       => $note !== '' ? $note : 'Auto-Regel: nach Newsletter-Abmeldung angelegt',
		] );
		if ( empty( $res['ok'] ) ) {
			return [ 'ok' => false, 'error' => (string) ( $res['error'] ?? 'insert_failed' ) ];
		}
		return [ 'ok' => true, 'id' => (int) $res['id'], 'existed' => false ];
	}
}
