<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap;

use Itdatex\Mailguard\Installer;

/**
 * Persistenz fuer mg_messages. Alle Queries tenant-scoped via customer_id.
 */
final class Message {

	public static function table() : string {
		global $wpdb;
		return $wpdb->prefix . Installer::TABLE_MESSAGES;
	}

	public static function list_for_customer( int $customer_id, array $filter = [], int $page = 1, int $per_page = 25 ) : array {
		global $wpdb;
		$per_page = max( 1, min( 100, $per_page ) );
		$page     = max( 1, $page );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = [ 'customer_id = %d' ];
		$params = [ $customer_id ];

		if ( ! empty( $filter['account_id'] ) ) {
			$where[]  = 'account_id = %d';
			$params[] = (int) $filter['account_id'];
		}
		if ( ! empty( $filter['unsub_only'] ) ) {
			$where[] = 'has_unsub = 1';
		}
		if ( ! empty( $filter['verdict'] ) ) {
			$v = (string) $filter['verdict'];
			if ( in_array( $v, [ 'clean', 'suspicious', 'dangerous' ], true ) ) {
				$where[]  = 'scan_verdict = %s';
				$params[] = $v;
			} elseif ( $v === 'unscanned' ) {
				$where[] = "scan_status IN ('pending','scanning','error')";
			} elseif ( $v === 'risky' ) {
				$where[] = "scan_verdict IN ('suspicious','dangerous')";
			}
		}
		if ( ! empty( $filter['q'] ) ) {
			$q        = '%' . $wpdb->esc_like( (string) $filter['q'] ) . '%';
			$where[]  = '(subject LIKE %s OR from_addr LIKE %s OR from_name LIKE %s)';
			$params[] = $q; $params[] = $q; $params[] = $q;
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . " {$where_sql} ORDER BY COALESCE(date_hdr, fetched_at) DESC LIMIT %d OFFSET %d",
			array_merge( $params, [ $per_page, $offset ] )
		), ARRAY_A );

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::table() . " {$where_sql}",
			$params
		) );

		$items = array_map( [ __CLASS__, 'public_view' ], $rows ?: [] );

		// "Bereits abgemeldet"-Marker: pro from_addr der Seite einmal in mg_unsubs
		// nachschauen, ob ein erfolgreicher Unsub existiert. Eine Query, kein
		// N+1, customer-scoped.
		$senders = array_unique( array_filter( array_map( static fn( $r ) => strtolower( (string) $r['from_addr'] ), $rows ?: [] ) ) );
		if ( $senders ) {
			$u  = $wpdb->prefix . \Itdatex\Mailguard\Installer::TABLE_UNSUBS;
			$ph = implode( ',', array_fill( 0, count( $senders ), '%s' ) );
			$sql = "SELECT LOWER(m2.from_addr) AS fa FROM {$u} u
				JOIN " . self::table() . " m2 ON u.message_id = m2.id
				WHERE u.customer_id = %d AND u.api_status = 'unsubscribed' AND LOWER(m2.from_addr) IN ({$ph})
				GROUP BY fa";
			$prepared_params = array_merge( [ $customer_id ], $senders );
			$unsub_rows = $wpdb->get_col( $wpdb->prepare( $sql, $prepared_params ) );
			$unsub_set = array_flip( $unsub_rows ?: [] );
			foreach ( $items as &$it ) {
				$it['sender_unsubscribed'] = isset( $unsub_set[ strtolower( $it['from_addr'] ) ] );
			}
			unset( $it );
		}

		return [
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		];
	}

	public static function stats_for_customer( int $customer_id ) : array {
		global $wpdb;
		$t = self::table();
		return [
			'total'       => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE customer_id = %d", $customer_id ) ),
			'has_unsub'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE customer_id = %d AND has_unsub = 1", $customer_id ) ),
			'pending_scan'=> (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE customer_id = %d AND scan_status IN ('pending','scanning')", $customer_id ) ),
			'clean'       => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE customer_id = %d AND scan_verdict = 'clean'", $customer_id ) ),
			'suspicious'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE customer_id = %d AND scan_verdict = 'suspicious'", $customer_id ) ),
			'dangerous'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE customer_id = %d AND scan_verdict = 'dangerous'", $customer_id ) ),
		];
	}

	/**
	 * Upsert eines Mail-Fetches. Liefert 'inserted'|'duplicate'|'failed'.
	 */
	public static function ingest( int $customer_id, int $account_id, string $folder, array $msg ) : string {
		global $wpdb;
		$payload = [
			'customer_id'     => $customer_id,
			'account_id'      => $account_id,
			'imap_uid'        => (int) $msg['uid'],
			'folder'          => $folder,
			'msg_id_hdr'      => mb_substr( (string) ( $msg['message_id'] ?? '' ), 0, 255 ),
			'from_addr'       => mb_substr( (string) ( $msg['from_addr'] ?? '' ), 0, 320 ),
			'from_name'       => mb_substr( (string) ( $msg['from_name'] ?? '' ), 0, 255 ),
			'subject'         => mb_substr( (string) ( $msg['subject'] ?? '' ), 0, 500 ),
			'date_hdr'        => $msg['date_hdr'] ?: null,
			'fetched_at'      => current_time( 'mysql', true ),
			'has_unsub'       => ! empty( $msg['list_unsub'] ) ? 1 : 0,
			'list_unsub_raw'  => (string) ( $msg['list_unsub'] ?? '' ),
			'list_unsub_post' => mb_substr( (string) ( $msg['list_unsub_post'] ?? '' ), 0, 255 ),
			'body_preview'    => (string) ( $msg['body_preview'] ?? '' ),
			'scan_status'     => 'pending',
		];
		$res = $wpdb->insert( self::table(), $payload );
		if ( $res ) {
			return 'inserted';
		}
		// Duplicate-Key oder anderer Insert-Fehler — Unique Key (account_id, imap_uid, folder).
		return $wpdb->last_error && str_contains( $wpdb->last_error, 'Duplicate' ) ? 'duplicate' : 'failed';
	}

	public static function delete( int $id, int $customer_id ) : bool {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), [ 'id' => $id, 'customer_id' => $customer_id ] );
	}

	public static function set_quarantine_action( int $id, int $customer_id, ?int $action_id ) : bool {
		global $wpdb;
		return (bool) $wpdb->update( self::table(),
			[ 'quarantine_action_id' => $action_id ],
			[ 'id' => $id, 'customer_id' => $customer_id ]
		);
	}

	/**
	 * Aktualisiert die IMAP-UID einer Mail. Nötig nach jedem MOVE — jedes
	 * COPY+DELETE auf dem Server vergibt eine neue UID, und ein veralteter
	 * Wert in mg_messages würde künftige MOVE/UID-FETCH-Operationen still ins
	 * Leere laufen lassen (siehe Quarantine-UID-Drift-Bug 2026-06-29).
	 */
	public static function update_uid( int $id, int $customer_id, int $new_uid ) : bool {
		global $wpdb;
		return (bool) $wpdb->update( self::table(),
			[ 'imap_uid' => $new_uid ],
			[ 'id' => $id, 'customer_id' => $customer_id ]
		);
	}

	public static function find_for_customer( int $id, int $customer_id ) : ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE id = %d AND customer_id = %d LIMIT 1',
			$id, $customer_id
		), ARRAY_A );
		return $row ?: null;
	}

	public static function public_view( array $row ) : array {
		$reasons = $row['scan_reasons'] ? json_decode( (string) $row['scan_reasons'], true ) : null;
		return [
			'id'              => (int) $row['id'],
			'account_id'      => (int) $row['account_id'],
			'folder'          => (string) $row['folder'],
			'uid'             => (int) $row['imap_uid'],
			'from_addr'       => (string) $row['from_addr'],
			'from_name'       => (string) $row['from_name'],
			'subject'         => (string) $row['subject'],
			'date_hdr'        => $row['date_hdr'] ?: null,
			'fetched_at'      => (string) $row['fetched_at'],
			'has_unsub'       => (int) $row['has_unsub'],
			'list_unsub_post' => (string) ( $row['list_unsub_post'] ?? '' ),
			'body_preview'    => (string) ( $row['body_preview'] ?? '' ),
			'scan_status'     => (string) $row['scan_status'],
			'scan_verdict'    => (string) $row['scan_verdict'],
			'scan_score'      => $row['scan_score'] !== null ? (int) $row['scan_score'] : null,
			'scan_reasons'    => is_array( $reasons ) ? $reasons : [],
			'scanned_at'      => $row['scanned_at'] ?: null,
			'sender_unsubscribed'  => false,  // wird in list_for_customer angereichert
			'quarantine_action_id' => isset( $row['quarantine_action_id'] ) && $row['quarantine_action_id'] !== null
				? (int) $row['quarantine_action_id'] : null,
		];
	}
}
