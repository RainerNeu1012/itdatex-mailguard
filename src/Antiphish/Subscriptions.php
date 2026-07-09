<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish;

use Itdatex\Mailguard\Installer;

/**
 * Subscriptions-View: gruppiert Newsletter-Mails pro Absender und liefert
 * Aggregate (Mail-Count, letzte Mail-ID, letzter Unsub-Versuch).
 *
 * Existiert, damit der User nicht jede einzelne Newsletter-Mail abmelden
 * muss — pro Sender 1× Abmeldebutton reicht, und alle Mails dieses Senders
 * werden als 'abgemeldet' markiert.
 *
 * Gruppierung auf from_addr (exakt) — die Absende-Adresse ist normalerweise
 * stabil pro Newsletter (z.B. newsletter@galaxus.de). Gruppierung auf Domain
 * waere ungenau, weil eine Domain mehrere unabhaengige Listen haben kann.
 */
final class Subscriptions {

	/**
	 * @return array{items:array<array<string,mixed>>,total:int}
	 */
	public static function list_for_customer( int $customer_id, int $page = 1, int $per_page = 50, int $account_id = 0 ) : array {
		global $wpdb;
		$m  = $wpdb->prefix . Installer::TABLE_MESSAGES;
		$u  = $wpdb->prefix . Installer::TABLE_UNSUBS;

		$scope   = $account_id > 0 ? ' AND account_id = %d' : '';
		$total_args = $account_id > 0 ? [ $customer_id, $account_id ] : [ $customer_id ];

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT from_addr) FROM {$m} WHERE customer_id = %d AND has_unsub = 1 AND from_addr != ''" . $scope,
			$total_args
		) );
		if ( $total === 0 ) {
			return [ 'items' => [], 'total' => 0 ];
		}

		$offset = max( 0, ( max( 1, $page ) - 1 ) * max( 1, $per_page ) );

		$rows_args = $account_id > 0
			? [ $customer_id, $account_id, $per_page, $offset ]
			: [ $customer_id, $per_page, $offset ];

		// Aggregat: pro from_addr ein Eintrag mit Count + neuester Mail-ID.
		// Last-Unsub-Status haengen wir nachtraeglich an (1 Subquery pro Row,
		// weil korrelierte Unter-Aggregation in MySQL fummelig ist).
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT
				from_addr,
				MAX(from_name) AS from_name,
				COUNT(*) AS msg_count,
				MAX(fetched_at) AS latest_at,
				SUBSTRING_INDEX(GROUP_CONCAT(id ORDER BY fetched_at DESC), ',', 1) AS latest_id
			FROM {$m}
			WHERE customer_id = %d AND has_unsub = 1 AND from_addr != ''" . $scope . "
			GROUP BY from_addr
			ORDER BY latest_at DESC
			LIMIT %d OFFSET %d",
			$rows_args
		), ARRAY_A );

		$items = [];
		foreach ( $rows ?: [] as $r ) {
			$from_addr = (string) $r['from_addr'];
			$last_unsub = $wpdb->get_row( $wpdb->prepare(
				"SELECT u.id, u.api_status, u.kind, u.created_at, u.dsn_status, u.api_detail
				 FROM {$u} u
				 JOIN {$m} mm ON u.message_id = mm.id
				 WHERE u.customer_id = %d AND mm.from_addr = %s
				 ORDER BY u.created_at DESC
				 LIMIT 1",
				$customer_id, $from_addr
			), ARRAY_A );

			$items[] = [
				'from_addr'    => $from_addr,
				'from_name'    => (string) ( $r['from_name'] ?? '' ),
				'msg_count'    => (int) $r['msg_count'],
				'latest_id'    => (int) $r['latest_id'],
				'latest_at'    => (string) $r['latest_at'],
				'last_unsub'   => $last_unsub ? [
					'id'         => (int) $last_unsub['id'],
					'api_status' => (string) $last_unsub['api_status'],
					'dsn_status' => (string) ( $last_unsub['dsn_status'] ?? '' ),
					'kind'       => (string) $last_unsub['kind'],
					'created_at' => (string) $last_unsub['created_at'],
					'manual_url' => self::extract_manual_url( (string) ( $last_unsub['api_detail'] ?? '' ) ),
				] : null,
			];
		}
		return [ 'items' => $items, 'total' => $total ];
	}

	/**
	 * Extrahiert manual_url aus dem persistierten api_detail-Blob.
	 * Wird gebraucht, damit die Newsletter-Liste einen 'Im Browser oeffnen'-Link
	 * ohne extra Round-Trip zeigen kann, wenn der Provider One-Click abgelehnt hat.
	 */
	public static function extract_manual_url( string $api_detail ) : string {
		if ( $api_detail === '' ) { return ''; }
		$decoded = json_decode( $api_detail, true );
		if ( ! is_array( $decoded ) ) { return ''; }
		if ( isset( $decoded['manual_url'] ) && is_string( $decoded['manual_url'] ) ) {
			return $decoded['manual_url'];
		}
		// Legacy-Struktur: raw-Body liegt eine Ebene tiefer.
		if ( isset( $decoded['raw']['manual_url'] ) && is_string( $decoded['raw']['manual_url'] ) ) {
			return $decoded['raw']['manual_url'];
		}
		return '';
	}

	/**
	 * Findet die jüngste noch nicht-abgemeldete Newsletter-Mail eines Absenders
	 * fuer einen Customer — Ziel-Mail fuer Bulk-Unsub.
	 */
	public static function latest_message_for_sender( int $customer_id, string $from_addr ) : ?int {
		$ids = self::messages_for_sender( $customer_id, $from_addr, 1 );
		return $ids[0] ?? null;
	}

	/**
	 * Alle Newsletter-Mails eines Absenders (neueste zuerst), maximal $limit.
	 *
	 * Wird für Bulk-Unsub-Fallback benutzt: wenn die neueste Mail einen
	 * abgelaufenen Token hat (404/410 auf den One-Click-Endpoint), versuchen
	 * wir dieselbe Aktion mit der zweit-, dritt-neuesten Mail — ältere
	 * Kampagnen haben oft noch gültige Tokens, und dem User ist egal, welche
	 * Mail den Abmelde-Klick auslöst.
	 *
	 * @return int[]
	 */
	public static function messages_for_sender( int $customer_id, string $from_addr, int $limit = 5 ) : array {
		global $wpdb;
		$m   = $wpdb->prefix . Installer::TABLE_MESSAGES;
		$lim = max( 1, min( 20, $limit ) );
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$m}
			 WHERE customer_id = %d AND has_unsub = 1 AND from_addr = %s
			 ORDER BY fetched_at DESC
			 LIMIT %d",
			$customer_id, $from_addr, $lim
		) );
		return array_map( 'intval', $rows ?: [] );
	}
}
