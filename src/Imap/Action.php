<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap;

use Itdatex\Mailguard\Installer;

/**
 * Audit-Log für reversible IMAP-Server-Aktionen (Quarantäne-MOVE, Undo).
 * ALLE Queries tenant-scoped via customer_id.
 *
 * Aktionen sind absichtlich nicht in mg_messages eingebaut — sie sind
 * eigenständige Events, die auch dann sinnvoll bleiben, wenn die zugehörige
 * Mail-Row später gelöscht wird (z.B. nach Folder-Removal).
 */
final class Action {

	public const ACTION_QUARANTINE      = 'quarantine';
	public const ACTION_UNDO_QUARANTINE = 'undo_quarantine';
	public const ACTION_PURGE           = 'purge';

	public const STATUS_DONE   = 'done';
	public const STATUS_UNDONE = 'undone';
	public const STATUS_FAILED = 'failed';

	public const ACTOR_USER = 'user';
	public const ACTOR_AUTO = 'auto';

	public static function table() : string {
		global $wpdb;
		return $wpdb->prefix . Installer::TABLE_ACTIONS;
	}

	public static function find_for_customer( int $id, int $customer_id ) : ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE id = %d AND customer_id = %d LIMIT 1',
			$id, $customer_id
		), ARRAY_A );
		return $row ?: null;
	}

	public static function list_for_customer( int $customer_id, int $page = 1, int $per_page = 50 ) : array {
		global $wpdb;
		$per_page = max( 1, min( 100, $per_page ) );
		$page     = max( 1, $page );
		$offset   = ( $page - 1 ) * $per_page;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE customer_id = %d ORDER BY id DESC LIMIT %d OFFSET %d',
			$customer_id, $per_page, $offset
		), ARRAY_A );
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::table() . ' WHERE customer_id = %d',
			$customer_id
		) );
		return [
			'items'    => array_map( [ __CLASS__, 'public_view' ], $rows ?: [] ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		];
	}

	public static function create( array $data ) : int {
		global $wpdb;
		$ok = $wpdb->insert( self::table(), array_merge( [
			'status'     => self::STATUS_DONE,
			'created_at' => current_time( 'mysql', true ),
		], $data ) );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function mark_undone( int $id, int $customer_id ) : bool {
		global $wpdb;
		return (bool) $wpdb->update( self::table(),
			[ 'status' => self::STATUS_UNDONE, 'undone_at' => current_time( 'mysql', true ) ],
			[ 'id' => $id, 'customer_id' => $customer_id ]
		);
	}

	public static function mark_failed( int $id, int $customer_id, string $detail ) : bool {
		global $wpdb;
		return (bool) $wpdb->update( self::table(),
			[ 'status' => self::STATUS_FAILED, 'error_detail' => mb_substr( $detail, 0, 500 ) ],
			[ 'id' => $id, 'customer_id' => $customer_id ]
		);
	}

	public static function public_view( array $row ) : array {
		return [
			'id'                  => (int) $row['id'],
			'account_id'          => (int) $row['account_id'],
			'message_id'          => (int) $row['message_id'],
			'action'              => (string) $row['action'],
			'source_folder'       => (string) $row['source_folder'],
			'source_uid'          => (int) $row['source_uid'],
			'target_folder'       => (string) $row['target_folder'],
			'target_uid'          => (int) $row['target_uid'],
			'verdict_snap'        => (string) $row['verdict_snap'],
			'verdict_score_snap'  => $row['verdict_score_snap'] !== null ? (int) $row['verdict_score_snap'] : null,
			'subject_snap'        => (string) $row['subject_snap'],
			'from_addr_snap'      => (string) $row['from_addr_snap'],
			'status'              => (string) $row['status'],
			'actor'               => (string) ( $row['actor'] ?? self::ACTOR_USER ),
			'error_detail'        => $row['error_detail'] ?: null,
			'undo_until'          => $row['undo_until'] ?: null,
			'undone_at'           => $row['undone_at']  ?: null,
			'created_at'          => (string) $row['created_at'],
			'undo_available'      => self::is_undo_available( $row ),
		];
	}

	private static function is_undo_available( array $row ) : bool {
		if ( (string) $row['action'] !== self::ACTION_QUARANTINE ) {
			return false;
		}
		if ( (string) $row['status'] !== self::STATUS_DONE ) {
			return false;
		}
		$until = (string) ( $row['undo_until'] ?? '' );
		if ( $until === '' ) { return true; }
		return strtotime( $until . ' UTC' ) > time();
	}
}
