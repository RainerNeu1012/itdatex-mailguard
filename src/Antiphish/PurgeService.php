<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish;

use Itdatex\Mailguard\Imap\Action as ImapAction;
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
	 * nicht nur quarantaenisierte. Iteriert alle mg_messages-Rows mit passendem
	 * from_addr und ruft QuarantineService::purge_message auf. Der Purge-Pfad
	 * kennt beide Faelle: quarantaenisierte Mails werden aus dem Quarantaene-
	 * Ordner expungt, alle anderen aus dem Source-Ordner.
	 *
	 * @return array{ok:bool,purged:int,skipped:int,failed:int,failures?:array<int,array<string,string>>}
	 */
	public static function hard_purge_sender( int $customer_id, string $from_addr ) : array {
		$from_addr = strtolower( trim( $from_addr ) );
		if ( $from_addr === '' || ! str_contains( $from_addr, '@' ) ) {
			return [ 'ok' => false, 'purged' => 0, 'skipped' => 0, 'failed' => 0, 'error' => 'bad_from_addr' ];
		}

		global $wpdb;
		$t   = ImapMessage::table();
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$t}
			 WHERE customer_id = %d AND LOWER(from_addr) = %s
			 ORDER BY id ASC",
			$customer_id, $from_addr
		) );

		$purged = 0; $skipped = 0; $failed = 0;
		$failures = [];
		foreach ( $ids ?: [] as $mid ) {
			$res = QuarantineService::purge_message( (int) $mid, $customer_id );
			if ( ! empty( $res['ok'] ) ) {
				$purged++;
				continue;
			}
			$err = (string) ( $res['error'] ?? 'unknown' );
			if ( $err === 'not_found' ) {
				$skipped++;
			} else {
				$failed++;
				$failures[] = [ 'message_id' => (int) $mid, 'error' => $err ];
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
