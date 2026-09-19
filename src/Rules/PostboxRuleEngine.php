<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Rules;

use Itdatex\Mailguard\Antiphish\PurgeService;
use Itdatex\Mailguard\Imap\Account as ImapAccount;
use Itdatex\Mailguard\Imap\ClientFactory;
use Itdatex\Mailguard\Installer;

/**
 * Wendet Postfach-Regeln auf eine gescannte Mail an.
 *
 * Regel-Reihenfolge: priority ASC, dann id ASC. Erste Regel die matched
 * fuehrt ihre Aktionen aus. Wenn stop_processing=1, endet die Kette;
 * sonst laufen weitere passende Regeln der Reihe nach.
 *
 * Sicherheits-Guard: Regeln werden NUR angewendet wenn die Mail nicht
 * per Auto-Quarantaene/Purge bereits weg ist. Das wird vom Aufrufer
 * (ScanService) geprueft — Engine::apply erwartet die aktuelle Message-
 * Row und einen $context mit dem Skip-Flag.
 */
final class PostboxRuleEngine {

	/**
	 * @param array $msg  aktuelle mg_messages-Row
	 * @return array{applied:array<int,array>, folder_after:?string, deleted:bool}
	 */
	public static function apply( int $customer_id, array $msg ) : array {
		$rules = PostboxRule::list_enabled_for_customer( $customer_id );
		if ( empty( $rules ) ) {
			return [ 'applied' => [], 'folder_after' => null, 'deleted' => false ];
		}

		$applied      = [];
		$folder_after = null;
		$deleted      = false;

		foreach ( $rules as $r ) {
			$conditions = json_decode( (string) $r['conditions_json'], true );
			$actions    = json_decode( (string) $r['actions_json'], true );
			if ( ! is_array( $conditions ) || ! is_array( $actions ) ) { continue; }

			$match_op = (string) $r['match_op'];
			if ( ! self::matches( $conditions, $match_op, $msg ) ) { continue; }

			// Wir haben einen Treffer — Actions ausfuehren.
			foreach ( $actions as $a ) {
				if ( ! is_array( $a ) ) { continue; }
				$type = (string) ( $a['type'] ?? '' );
				if ( $type === 'delete' ) {
					// Hard-Purge via bestehenden PurgeService — respektiert Audit-Log.
					$res = PurgeService::purge_message( (int) $msg['id'], $customer_id );
					if ( ! empty( $res['ok'] ) ) {
						$deleted = true;
					}
					// Nach delete keine weiteren Actions dieser Regel — Mail ist weg.
					break;
				}
				if ( $type === 'move' ) {
					$folder = (string) ( $a['folder'] ?? '' );
					if ( $folder === '' ) { continue; }
					$ok = self::move_message( $msg, $folder );
					if ( $ok ) {
						$folder_after = $folder;
					}
					continue;
				}
				if ( $type === 'flag' ) {
					// TODO: IMAP-Flag setzen. Ohne Client-API-Erweiterung erstmal no-op.
					// Wir loggen es in $applied, damit User sieht dass die Regel
					// theoretisch getriggert haette.
					continue;
				}
			}

			PostboxRule::record_hit( (int) $r['id'] );
			$applied[] = [
				'rule_id'   => (int) $r['id'],
				'name'      => (string) $r['name'],
				'actions'   => $actions,
				'deleted'   => $deleted,
				'moved_to'  => $folder_after,
			];

			if ( $deleted )                                { break; }
			if ( (int) $r['stop_processing'] === 1 )       { break; }
		}

		return [
			'applied'      => $applied,
			'folder_after' => $folder_after,
			'deleted'      => $deleted,
		];
	}

	/**
	 * @param array $conditions Liste von {field, op, value}
	 */
	private static function matches( array $conditions, string $match_op, array $msg ) : bool {
		$results = [];
		foreach ( $conditions as $c ) {
			if ( ! is_array( $c ) ) { continue; }
			$results[] = self::eval_condition( $c, $msg );
		}
		if ( empty( $results ) ) { return false; }
		if ( $match_op === 'OR' ) { return in_array( true, $results, true ); }
		return ! in_array( false, $results, true );
	}

	private static function eval_condition( array $c, array $msg ) : bool {
		$field = (string) ( $c['field'] ?? '' );
		$op    = (string) ( $c['op']    ?? '' );
		$want  = $c['value'] ?? '';

		$have = self::field_value( $field, $msg );

		// Numerische Ops
		if ( in_array( $op, [ 'gt', 'ge', 'lt', 'le', 'eq', 'ne' ], true ) ) {
			$h = (int) $have;
			$w = (int) $want;
			switch ( $op ) {
				case 'gt': return $h >  $w;
				case 'ge': return $h >= $w;
				case 'lt': return $h <  $w;
				case 'le': return $h <= $w;
				case 'eq': return $h === $w;
				case 'ne': return $h !== $w;
			}
		}

		// String-Ops — beide Seiten case-insensitive.
		$h = mb_strtolower( (string) $have );
		$w = mb_strtolower( (string) $want );
		switch ( $op ) {
			case 'equals':       return $h === $w;
			case 'not_equals':   return $h !== $w;
			case 'contains':     return $w !== '' && str_contains( $h, $w );
			case 'not_contains': return $w === '' || ! str_contains( $h, $w );
			case 'starts_with':  return $w !== '' && str_starts_with( $h, $w );
			case 'ends_with':    return $w !== '' && str_ends_with( $h, $w );
		}
		return false;
	}

	private static function field_value( string $field, array $msg ) : mixed {
		switch ( $field ) {
			case 'from_addr':   return (string) ( $msg['from_addr']       ?? '' );
			case 'from_domain':
				$addr = (string) ( $msg['from_addr'] ?? '' );
				$at = strrpos( $addr, '@' );
				return $at !== false ? substr( $addr, $at + 1 ) : '';
			case 'from_name':        return (string) ( $msg['from_name']        ?? '' );
			case 'subject':          return (string) ( $msg['subject']          ?? '' );
			case 'body':             return (string) ( $msg['body_preview']     ?? '' );
			case 'verdict':          return (string) ( $msg['scan_verdict']     ?? '' );
			case 'score':            return (int)    ( $msg['scan_score']       ?? 0 );
			case 'has_unsub':        return (int) ( ! empty( $msg['has_unsub'] ) ? 1 : 0 );
			case 'has_attachments':  return (int) ( ! empty( $msg['has_attachments'] ) ? 1 : 0 );
		}
		return '';
	}

	/**
	 * Verschiebt die Mail per IMAP von ihrem aktuellen Folder in $target_folder.
	 * Legt den Zielordner an falls nicht vorhanden. Aktualisiert die mg_messages-Row
	 * (folder + imap_uid) damit spaetere Aktionen (Undo, Purge) den neuen Standort
	 * kennen.
	 *
	 * Fehler swallowen — der Scan-Fluss darf nicht wegen eines fehlerhaften Ordner-
	 * Namens abbrechen. Rueckgabe: true bei Erfolg, false bei Problem.
	 */
	private static function move_message( array $msg, string $target_folder ) : bool {
		global $wpdb;
		$account_id  = (int) ( $msg['account_id']  ?? 0 );
		$customer_id = (int) ( $msg['customer_id'] ?? 0 );
		$msg_id      = (int) ( $msg['id']          ?? 0 );
		$src_folder  = (string) ( $msg['folder']   ?? '' );
		$src_uid     = (int) ( $msg['imap_uid']    ?? 0 );
		if ( $src_folder === '' || $src_uid === 0 || $account_id === 0 ) { return false; }
		if ( $src_folder === $target_folder ) { return true; } // schon dort

		$acct = ImapAccount::find_for_customer( $account_id, $customer_id );
		if ( ! $acct ) { return false; }

		try {
			$client = ClientFactory::for_account( $acct );
			$client->connect();
			$client->select_folder( $src_folder );
			$client->ensure_folder( $target_folder );
			$new_uid = $client->move_uid( $src_uid, $target_folder );
			$client->close();
		} catch ( \Throwable $e ) {
			error_log( 'PostboxRuleEngine::move_message fehlgeschlagen: ' . $e->getMessage() );
			return false;
		}

		$t_msg = $wpdb->prefix . Installer::TABLE_MESSAGES;
		$wpdb->update( $t_msg, [
			'folder'    => mb_substr( $target_folder, 0, 190 ),
			'imap_uid'  => $new_uid > 0 ? $new_uid : $src_uid,
		], [ 'id' => $msg_id ] );
		return true;
	}
}
