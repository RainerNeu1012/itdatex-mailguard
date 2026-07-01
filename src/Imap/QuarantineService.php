<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap;

use Itdatex\Mailguard\Installer;

/**
 * Verschiebt gefährliche Mails nach User-Bestätigung in einen
 * Quarantäne-Ordner auf dem IMAP-Server (RFC 6851 UID MOVE, sonst
 * UID COPY + \Deleted ohne EXPUNGE). Audit in mg_actions, Undo
 * via reverse-Move solange undo_until läuft.
 *
 * Designentscheidungen:
 *  - KEIN EXPUNGE durch das Plugin: ein einzelner False-Positive bleibt
 *    erholbar. Der Server (oder ein anderer Mail-Client beim Folder-Close)
 *    räumt irgendwann selbst auf.
 *  - mg_messages behält Source-Folder/UID, damit der inkrementelle Pull
 *    (mg_imap_folders.last_uid) konsistent bleibt. Quarantäne-Status
 *    wird nur über mg_messages.quarantine_action_id markiert.
 *  - Quarantäne-Folder wird nicht in mg_imap_folders aufgenommen → vom
 *    Pull ausgeschlossen, sonst gäbe es einen Loop "Mail wandert in
 *    Quarantine → Pull holt sie als neue Mail wieder rein".
 */
final class QuarantineService {

	/**
	 * Verschiebt eine Mail in den Quarantäne-Ordner des Accounts.
	 *
	 * @param string $actor Action::ACTOR_USER bei manuellem Klick im Portal,
	 *                      Action::ACTOR_AUTO wenn der Scanner ab Schwellwert
	 *                      automatisch quarantänisiert. Wird im Audit-Log
	 *                      gespeichert, damit der Customer Auto- vs. User-Aktionen
	 *                      unterscheiden kann.
	 *
	 * @return array{ok:bool,action_id?:int,error?:string,detail?:string}
	 */
	public static function quarantine( int $message_id, int $customer_id, string $actor = Action::ACTOR_USER ) : array {
		$msg = Message::find_for_customer( $message_id, $customer_id );
		if ( ! $msg ) {
			return [ 'ok' => false, 'error' => 'not_found' ];
		}
		if ( ! empty( $msg['quarantine_action_id'] ) ) {
			return [ 'ok' => false, 'error' => 'already_quarantined' ];
		}
		$account = Account::find_for_customer( (int) $msg['account_id'], $customer_id );
		if ( ! $account ) {
			return [ 'ok' => false, 'error' => 'account_gone' ];
		}

		$source_folder = (string) $msg['folder'];
		$source_uid    = (int) $msg['imap_uid'];
		$target_folder = self::quarantine_folder_for_account( $account );

		try {
			$client = ClientFactory::for_account_folder( $account, $source_folder );
			$client->connect();
		} catch ( \Throwable $e ) {
			return [ 'ok' => false, 'error' => 'connect_failed', 'detail' => $e->getMessage() ];
		}

		try {
			$client->ensure_folder( $target_folder );
		} catch ( \Throwable $e ) {
			$client->close();
			return [ 'ok' => false, 'error' => 'create_folder_failed', 'detail' => $e->getMessage() ];
		}

		// Vor-MOVE Existenz-Check: IMAP MOVE auf eine nicht-existente UID liefert
		// auf vielen Servern kommentarlos OK ohne Effekt. Wenn die in der DB
		// gespeicherte UID stale ist (z.B. weil der User die Mail in seinem
		// Mail-Client bereits bewegt hat), versuchen wir, sie via Message-ID
		// nachzuziehen — sonst FRÜHE Rückgabe, bevor wir false-positive Audit
		// schreiben.
		try {
			$source_exists = $client->uid_exists( $source_uid );
		} catch ( \Throwable $e ) {
			$source_exists = true;  // best-effort: weiter mit MOVE-Versuch
		}
		if ( ! $source_exists ) {
			if ( (string) $msg['msg_id_hdr'] !== '' ) {
				try {
					$actual = $client->find_uid_by_message_id( (string) $msg['msg_id_hdr'] );
				} catch ( \Throwable $e ) {
					$actual = 0;
				}
				if ( $actual === 0 ) {
					$client->close();
					return [ 'ok' => false, 'error' => 'source_mail_gone' ];
				}
				Message::update_uid( $message_id, $customer_id, $actual );
				$source_uid = $actual;
			} else {
				$client->close();
				return [ 'ok' => false, 'error' => 'source_mail_gone' ];
			}
		}

		try {
			$target_uid = $client->move_uid( $source_uid, $target_folder );
		} catch ( \Throwable $e ) {
			$client->close();
			return [ 'ok' => false, 'error' => 'move_failed', 'detail' => $e->getMessage() ];
		}

		// target_uid=0 ist auf Servern ohne UIDPLUS normal. Wir versuchen
		// best-effort, sie per Message-ID-Search im Ziel nachzuziehen —
		// wenn das auch nichts liefert, schreiben wir die Action mit
		// target_uid=0. Undo muss dann zur Laufzeit per Search/Scan suchen.
		if ( $target_uid === 0 && (string) $msg['msg_id_hdr'] !== '' ) {
			try {
				$client->select_folder( $target_folder );
				$target_uid = $client->find_uid_by_message_id( (string) $msg['msg_id_hdr'] );
			} catch ( \Throwable $e ) {
				// best-effort
			}
		}
		$client->close();

		$undo_until = gmdate( 'Y-m-d H:i:s', time() + Installer::ACTION_UNDO_TTL_DAYS * DAY_IN_SECONDS );
		$action_id  = Action::create( [
			'customer_id'        => $customer_id,
			'account_id'         => (int) $msg['account_id'],
			'message_id'         => $message_id,
			'action'             => Action::ACTION_QUARANTINE,
			'source_folder'      => $source_folder,
			'source_uid'         => $source_uid,
			'target_folder'      => $target_folder,
			'target_uid'         => $target_uid,
			'verdict_snap'       => (string) $msg['scan_verdict'],
			'verdict_score_snap' => $msg['scan_score'] !== null ? (int) $msg['scan_score'] : null,
			'subject_snap'       => mb_substr( (string) $msg['subject'], 0, 500 ),
			'from_addr_snap'     => mb_substr( (string) $msg['from_addr'], 0, 320 ),
			'status'             => Action::STATUS_DONE,
			'actor'              => $actor === Action::ACTOR_AUTO ? Action::ACTOR_AUTO : Action::ACTOR_USER,
			'undo_until'         => $undo_until,
		] );
		if ( ! $action_id ) {
			return [ 'ok' => false, 'error' => 'audit_failed' ];
		}
		Message::set_quarantine_action( $message_id, $customer_id, $action_id );

		return [ 'ok' => true, 'action_id' => $action_id ];
	}

	/**
	 * Macht eine Quarantäne rückgängig: holt die Mail per UID MOVE
	 * vom Quarantäne-Folder zurück in den Source-Folder.
	 *
	 * @return array{ok:bool,error?:string,detail?:string}
	 */
	public static function undo( int $action_id, int $customer_id ) : array {
		$row = Action::find_for_customer( $action_id, $customer_id );
		if ( ! $row ) {
			return [ 'ok' => false, 'error' => 'not_found' ];
		}
		if ( (string) $row['action'] !== Action::ACTION_QUARANTINE ) {
			return [ 'ok' => false, 'error' => 'not_undoable' ];
		}
		if ( (string) $row['status'] !== Action::STATUS_DONE ) {
			return [ 'ok' => false, 'error' => 'already_undone_or_failed' ];
		}
		$until = (string) ( $row['undo_until'] ?? '' );
		if ( $until !== '' && strtotime( $until . ' UTC' ) < time() ) {
			return [ 'ok' => false, 'error' => 'undo_expired' ];
		}

		$account = Account::find_for_customer( (int) $row['account_id'], $customer_id );
		if ( ! $account ) {
			return [ 'ok' => false, 'error' => 'account_gone' ];
		}

		$target_folder = (string) $row['target_folder']; // Quarantäne war Ziel beim Quarantine
		$source_folder = (string) $row['source_folder']; // ursprünglicher Ordner
		$target_uid    = (int) $row['target_uid'];

		try {
			$client = ClientFactory::for_account_folder( $account, $target_folder );
			$client->connect();
		} catch ( \Throwable $e ) {
			return [ 'ok' => false, 'error' => 'connect_failed', 'detail' => $e->getMessage() ];
		}

		// Wenn target_uid 0 (UIDPLUS fehlte beim Quarantine), per Message-ID nachziehen.
		if ( $target_uid === 0 ) {
			$msg = Message::find_for_customer( (int) $row['message_id'], $customer_id );
			$msg_id_hdr = $msg ? (string) $msg['msg_id_hdr'] : '';
			if ( $msg_id_hdr !== '' ) {
				try {
					$target_uid = $client->find_uid_by_message_id( $msg_id_hdr );
				} catch ( \Throwable $e ) {
					$client->close();
					return [ 'ok' => false, 'error' => 'find_target_failed', 'detail' => $e->getMessage() ];
				}
			}
		}
		if ( $target_uid === 0 ) {
			$client->close();
			Action::mark_failed( $action_id, $customer_id, 'target_uid_unknown' );
			return [ 'ok' => false, 'error' => 'target_uid_unknown' ];
		}

		try {
			$new_source_uid = $client->move_uid( $target_uid, $source_folder );
		} catch ( \Throwable $e ) {
			$client->close();
			Action::mark_failed( $action_id, $customer_id, $e->getMessage() );
			return [ 'ok' => false, 'error' => 'move_failed', 'detail' => $e->getMessage() ];
		}

		// Neue Source-UID nachziehen — IMAP COPY/MOVE vergibt im Ziel-Folder eine
		// neue UID. Ohne Update läuft die nächste Operation auf mg_messages.imap_uid
		// ins Leere (siehe UID-Drift-Bug 2026-06-29 mit false-positive Auto-Quarantäne).
		if ( $new_source_uid === 0 ) {
			$msg = Message::find_for_customer( (int) $row['message_id'], $customer_id );
			$msg_id_hdr = $msg ? (string) $msg['msg_id_hdr'] : '';
			if ( $msg_id_hdr !== '' ) {
				try {
					$client->select_folder( $source_folder );
					$new_source_uid = $client->find_uid_by_message_id( $msg_id_hdr );
				} catch ( \Throwable $e ) {
					// best-effort — DB-Update entfällt, Action wird trotzdem als
					// undone markiert (der Move selbst war erfolgreich).
				}
			}
		}
		$client->close();

		Action::mark_undone( $action_id, $customer_id );
		Message::set_quarantine_action( (int) $row['message_id'], $customer_id, null );
		if ( $new_source_uid > 0 ) {
			Message::update_uid( (int) $row['message_id'], $customer_id, $new_source_uid );
		}

		// Sekundärer Audit-Eintrag — symmetrische Sicht im Aktionen-Log.
		Action::create( [
			'customer_id'   => $customer_id,
			'account_id'    => (int) $row['account_id'],
			'message_id'    => (int) $row['message_id'],
			'action'        => Action::ACTION_UNDO_QUARANTINE,
			'source_folder' => $target_folder,
			'source_uid'    => $target_uid,
			'target_folder' => $source_folder,
			'target_uid'    => 0,
			'verdict_snap'  => (string) $row['verdict_snap'],
			'verdict_score_snap' => $row['verdict_score_snap'] !== null ? (int) $row['verdict_score_snap'] : null,
			'subject_snap'  => (string) $row['subject_snap'],
			'from_addr_snap'=> (string) $row['from_addr_snap'],
			'status'        => Action::STATUS_DONE,
			'undo_until'    => null,
		] );

		return [ 'ok' => true ];
	}

	/**
	 * Löscht eine quarantänisierte Mail endgültig vom IMAP-Server:
	 * EXPUNGE im Quarantäne-Folder + Löschen der mg_messages-Row +
	 * ACTION_PURGE-Eintrag im Audit-Log (nicht undo-fähig).
	 *
	 * Sicherheitsschranke: nur wenn die zugrundeliegende Quarantäne-Action
	 * status=done ist und die Mail noch in mg_messages steht. Undo bleibt
	 * bis zum Purge möglich; nach Purge ist der Vorgang endgültig.
	 *
	 * @return array{ok:bool,error?:string,detail?:string}
	 */
	public static function purge( int $action_id, int $customer_id ) : array {
		$row = Action::find_for_customer( $action_id, $customer_id );
		if ( ! $row ) {
			return [ 'ok' => false, 'error' => 'not_found' ];
		}
		if ( (string) $row['action'] !== Action::ACTION_QUARANTINE ) {
			return [ 'ok' => false, 'error' => 'not_quarantine_action' ];
		}
		if ( (string) $row['status'] !== Action::STATUS_DONE ) {
			return [ 'ok' => false, 'error' => 'not_purgeable' ];
		}

		$account = Account::find_for_customer( (int) $row['account_id'], $customer_id );
		if ( ! $account ) {
			return [ 'ok' => false, 'error' => 'account_gone' ];
		}

		$target_folder = (string) $row['target_folder'];
		$target_uid    = (int) $row['target_uid'];
		$message_id    = (int) $row['message_id'];

		try {
			$client = ClientFactory::for_account_folder( $account, $target_folder );
			$client->connect();
		} catch ( \Throwable $e ) {
			return [ 'ok' => false, 'error' => 'connect_failed', 'detail' => $e->getMessage() ];
		}

		// Wenn target_uid 0 (UIDPLUS fehlte beim Quarantine-Zeitpunkt), per Message-ID nachziehen.
		if ( $target_uid === 0 ) {
			$msg = Message::find_for_customer( $message_id, $customer_id );
			$msg_id_hdr = $msg ? (string) $msg['msg_id_hdr'] : '';
			if ( $msg_id_hdr !== '' ) {
				try {
					$target_uid = $client->find_uid_by_message_id( $msg_id_hdr );
				} catch ( \Throwable $e ) {
					$client->close();
					return [ 'ok' => false, 'error' => 'find_target_failed', 'detail' => $e->getMessage() ];
				}
			}
		}
		if ( $target_uid === 0 ) {
			$client->close();
			return [ 'ok' => false, 'error' => 'target_uid_unknown' ];
		}

		try {
			$client->expunge_uid( $target_uid );
		} catch ( \Throwable $e ) {
			$client->close();
			return [ 'ok' => false, 'error' => 'expunge_failed', 'detail' => $e->getMessage() ];
		}
		$client->close();

		// Quarantine-Action als undone markieren, damit sie nicht mehr als
		// "wiederherstellbar" angezeigt wird — der Zielzustand ist jetzt "weg".
		Action::mark_undone( $action_id, $customer_id );

		// Sekundärer Audit-Eintrag: Purge, endgueltig, kein undo.
		Action::create( [
			'customer_id'        => $customer_id,
			'account_id'         => (int) $row['account_id'],
			'message_id'         => $message_id,
			'action'             => Action::ACTION_PURGE,
			'source_folder'      => $target_folder,
			'source_uid'         => $target_uid,
			'target_folder'      => '',
			'target_uid'         => 0,
			'verdict_snap'       => (string) $row['verdict_snap'],
			'verdict_score_snap' => $row['verdict_score_snap'] !== null ? (int) $row['verdict_score_snap'] : null,
			'subject_snap'       => (string) $row['subject_snap'],
			'from_addr_snap'     => (string) $row['from_addr_snap'],
			'status'             => Action::STATUS_DONE,
			'actor'              => Action::ACTOR_USER,
			'undo_until'         => null,
		] );

		// mg_messages-Row entfernen — die Mail existiert nicht mehr, die
		// Referenz waere nur noch Ballast in der Inbox-Liste.
		Message::delete( $message_id, $customer_id );

		return [ 'ok' => true ];
	}

	/**
	 * Liefert den Quarantäne-Ordner-Namen für einen Account.
	 * Default ist Installer::DEFAULT_QUARANTINE_FOLDER, kann pro
	 * Account in mg_imap_accounts.quarantine_folder überschrieben werden.
	 */
	public static function quarantine_folder_for_account( array $account ) : string {
		$custom = trim( (string) ( $account['quarantine_folder'] ?? '' ) );
		return $custom !== '' ? $custom : Installer::DEFAULT_QUARANTINE_FOLDER;
	}
}
