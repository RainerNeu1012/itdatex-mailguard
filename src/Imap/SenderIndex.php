<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap;

use Itdatex\Mailguard\Installer;

/**
 * Sender-Aggregat der Inbox: gruppiert mg_messages pro from_addr und liefert
 * Zaehler + neuestes Subject/Datum + Worst-Verdict pro Absender. Referenz-
 * Vorbild ist Antiphish\Subscriptions, aber ueber ALLE Mails (nicht nur
 * has_unsub=1) und mit Verdict-Aggregation.
 */
final class SenderIndex {

	/**
	 * @return array{items:array<array<string,mixed>>,total:int,page:int,per_page:int}
	 */
	public static function list_for_customer( int $customer_id, array $filter = [], int $page = 1, int $per_page = 50 ) : array {
		global $wpdb;
		$per_page = max( 1, min( 200, $per_page ) );
		$page     = max( 1, $page );
		$offset   = ( $page - 1 ) * $per_page;
		$t        = $wpdb->prefix . Installer::TABLE_MESSAGES;

		$where  = [ 'customer_id = %d', "from_addr <> ''" ];
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

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT LOWER(from_addr)) FROM {$t} {$where_sql}",
			$params
		) );
		if ( $total === 0 ) {
			return [ 'items' => [], 'total' => 0, 'page' => $page, 'per_page' => $per_page ];
		}

		// SUBSTRING_INDEX + GROUP_CONCAT liefert den zuletzt-gesehenen from_name
		// bzw. Subject, indem wir per Sort-Klausel absteigend nach Datum sortieren
		// und dann das erste Element per Trennzeichen zurueckholen. group_concat_max_len
		// kann in Ausnahmefaellen (langer Subject-Historie) knapp werden; wir setzen
		// Session-Limit hoch, um Truncation zu vermeiden.
		$wpdb->query( 'SET SESSION group_concat_max_len = 100000' );

		$sql = "SELECT
				LOWER(from_addr) AS from_addr_lc,
				MAX(from_addr) AS from_addr,
				SUBSTRING_INDEX(GROUP_CONCAT(from_name ORDER BY COALESCE(date_hdr, fetched_at) DESC SEPARATOR '\\x1f'), '\\x1f', 1) AS latest_from_name,
				SUBSTRING_INDEX(GROUP_CONCAT(subject   ORDER BY COALESCE(date_hdr, fetched_at) DESC SEPARATOR '\\x1f'), '\\x1f', 1) AS latest_subject,
				COUNT(*) AS msg_count,
				MAX(COALESCE(date_hdr, fetched_at)) AS latest_at,
				MAX(has_unsub) AS has_unsub,
				SUM(CASE WHEN scan_verdict='dangerous'  THEN 1 ELSE 0 END) AS dangerous_count,
				SUM(CASE WHEN scan_verdict='suspicious' THEN 1 ELSE 0 END) AS suspicious_count,
				SUM(CASE WHEN scan_status IN ('pending','scanning') THEN 1 ELSE 0 END) AS pending_count
			FROM {$t}
			{$where_sql}
			GROUP BY LOWER(from_addr)
			ORDER BY latest_at DESC
			LIMIT %d OFFSET %d";

		$rows = $wpdb->get_results( $wpdb->prepare(
			$sql,
			array_merge( $params, [ $per_page, $offset ] )
		), ARRAY_A );

		$items    = [];
		$senders  = [];
		foreach ( $rows ?: [] as $r ) {
			$fa        = (string) $r['from_addr_lc'];
			$senders[] = $fa;
			$dangerous = (int) $r['dangerous_count'];
			$suspicious= (int) $r['suspicious_count'];
			$pending   = (int) $r['pending_count'];
			$worst     = $dangerous > 0 ? 'dangerous'
						: ( $suspicious > 0 ? 'suspicious'
							: ( $pending > 0 ? 'pending' : 'clean' ) );

			$items[] = [
				'from_addr'          => $fa,
				'from_addr_display'  => (string) $r['from_addr'],
				'latest_from_name'   => (string) ( $r['latest_from_name'] ?? '' ),
				'latest_subject'     => (string) ( $r['latest_subject'] ?? '' ),
				'msg_count'          => (int) $r['msg_count'],
				'latest_at'          => (string) $r['latest_at'],
				'has_unsub'          => (int) $r['has_unsub'],
				'dangerous_count'    => $dangerous,
				'suspicious_count'   => $suspicious,
				'pending_count'      => $pending,
				'worst_verdict'      => $worst,
				'sender_unsubscribed'=> false,
				'sender_blocked'     => false,
			];
		}

		// "Bereits abgemeldet"-Marker analog zu Message::list_for_customer.
		if ( $senders ) {
			$u  = $wpdb->prefix . Installer::TABLE_UNSUBS;
			$ph = implode( ',', array_fill( 0, count( $senders ), '%s' ) );
			$sql = "SELECT LOWER(m2.from_addr) AS fa FROM {$u} u
				JOIN {$t} m2 ON u.message_id = m2.id
				WHERE u.customer_id = %d AND u.api_status = 'unsubscribed' AND LOWER(m2.from_addr) IN ({$ph})
				GROUP BY fa";
			$prepared_params = array_merge( [ $customer_id ], $senders );
			$unsub_rows = $wpdb->get_col( $wpdb->prepare( $sql, $prepared_params ) );
			$unsub_set  = array_flip( $unsub_rows ?: [] );

			// "Sender blockiert"-Marker: Blacklist-Rule mit match_type=from_addr.
			$r  = $wpdb->prefix . Installer::TABLE_RULES;
			$rsql = "SELECT LOWER(pattern) AS fa FROM {$r}
				WHERE customer_id = %d AND kind = 'blacklist' AND match_type = 'from_addr'
				  AND LOWER(pattern) IN ({$ph})";
			$block_rows = $wpdb->get_col( $wpdb->prepare( $rsql, $prepared_params ) );
			$block_set  = array_flip( $block_rows ?: [] );

			foreach ( $items as &$it ) {
				$it['sender_unsubscribed'] = isset( $unsub_set[ $it['from_addr'] ] );
				$it['sender_blocked']      = isset( $block_set[ $it['from_addr'] ] );
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
}
