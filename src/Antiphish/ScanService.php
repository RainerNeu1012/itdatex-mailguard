<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish;

use Itdatex\Mailguard\Admin\Settings;
use Itdatex\Mailguard\Installer;
use Itdatex\Mailguard\Rules\Engine as RulesEngine;

/**
 * Asynchron-Scan-Worker: nimmt scan_status='pending' Mails aus mg_messages,
 * schickt sie an antiphish-API, schreibt Verdict/Score/Reasons zurueck.
 *
 * Scope:
 *  - Cron alle 5 min, max BATCH_SIZE pro Run (Default 10)
 *  - Heuristik-only (deep=false); Deep-Mode kann per Setting eingeschaltet werden
 *  - Bei API-Fehler: scan_status='error' (kein Auto-Retry, manuell via Rescan)
 *  - Race-Schutz: optimistic SELECT … UPDATE-Loop mit `scan_status='scanning'` Marker
 */
final class ScanService {

	public const BATCH_SIZE_DEFAULT = 10;

	public static function scan_pending_batch( int $limit = 0 ) : array {
		global $wpdb;
		$limit = $limit > 0 ? $limit : (int) Settings::get( 'scan_batch_size', self::BATCH_SIZE_DEFAULT );
		$limit = max( 1, min( 100, $limit ) );

		$t = $wpdb->prefix . Installer::TABLE_MESSAGES;

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$t} WHERE scan_status = 'pending' ORDER BY id ASC LIMIT %d",
			$limit
		) );
		if ( ! $ids ) {
			return [ 'scanned' => 0, 'errors' => 0 ];
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$t} SET scan_status = 'scanning' WHERE id IN ({$placeholders})",
			$ids
		) );

		$ok = 0; $err = 0;
		foreach ( $ids as $id ) {
			$res = self::scan_message( (int) $id );
			if ( $res['ok'] ?? false ) { $ok++; } else { $err++; }
		}
		return [ 'scanned' => $ok, 'errors' => $err ];
	}

	public static function scan_message( int $id ) : array {
		global $wpdb;
		$t = $wpdb->prefix . Installer::TABLE_MESSAGES;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) { return [ 'ok' => false, 'error' => 'not_found' ]; }

		$deep = (int) Settings::get( 'scan_deep', 0 ) === 1;
		$payload = [
			'subject'   => (string) $row['subject'],
			'body'      => (string) $row['body_preview'],
			'from_addr' => $row['from_addr'] ?: null,
			'headers'   => [
				'List-Unsubscribe'      => (string) ( $row['list_unsub_raw'] ?? '' ),
				'List-Unsubscribe-Post' => (string) ( $row['list_unsub_post'] ?? '' ),
			],
			'deep'      => $deep,
		];
		$res = Client::scan_email( $payload );

		if ( is_wp_error( $res ) ) {
			self::mark_error( $id, $res->get_error_message() );
			return [ 'ok' => false, 'error' => $res->get_error_code() ];
		}
		$status = (int) ( $res['status'] ?? 500 );
		if ( $status >= 400 ) {
			$body = is_array( $res['body'] ?? null ) ? $res['body'] : [];
			self::mark_error( $id, sprintf( 'HTTP %d %s', $status, $body['message'] ?? $body['detail'] ?? '' ) );
			return [ 'ok' => false, 'error' => 'http_' . $status ];
		}

		$body    = is_array( $res['body'] ?? null ) ? $res['body'] : [];
		$verdict = (string) ( $body['verdict'] ?? '' );
		$score   = (int)    ( $body['score']   ?? 0 );
		$reasons = is_array( $body['reasons'] ?? null ) ? $body['reasons'] : [];

		// Customer-Regeln koennen das Verdict ueberschreiben (Blacklist > Whitelist).
		$override = RulesEngine::apply( (int) $row['customer_id'], $row );
		if ( $override ) {
			$verdict = $override['verdict'];
			$score   = $override['score'];
			array_unshift( $reasons, $override['reason'] );
		}

		$wpdb->update( $t, [
			'scan_status'  => 'done',
			'scan_verdict' => mb_substr( $verdict, 0, 20 ),
			'scan_score'   => max( 0, min( 100, $score ) ),
			'scan_reasons' => wp_json_encode( $reasons ),
			'scanned_at'   => current_time( 'mysql', true ),
		], [ 'id' => $id ] );

		return [ 'ok' => true, 'verdict' => $verdict, 'score' => $score, 'override' => $override ? true : false ];
	}

	private static function mark_error( int $id, string $detail ) : void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . Installer::TABLE_MESSAGES,
			[
				'scan_status'  => 'error',
				'scan_reasons' => wp_json_encode( [ [ 'rule' => 'scan_error', 'description' => mb_substr( $detail, 0, 500 ), 'score' => 0 ] ] ),
				'scanned_at'   => current_time( 'mysql', true ),
			],
			[ 'id' => $id ]
		);
	}

	/**
	 * Per-Customer Quota fuer manuelle Scans (URL + Email).
	 * Default 50 / 24h, ueber Settings einstellbar.
	 */
	public static function consume_manual_quota( int $customer_id ) : array {
		$rate   = max( 1, (int) Settings::get( 'manual_scan_quota', 50 ) );
		$window = DAY_IN_SECONDS;
		$key    = 'itdatex_mg_quota_' . $customer_id;
		$now    = time();
		$entries = (array) get_transient( $key );
		$entries = array_values( array_filter( $entries, static fn( $t ) => is_int( $t ) && ( $now - $t ) < $window ) );
		if ( count( $entries ) >= $rate ) {
			$oldest = (int) min( $entries );
			return [ 'allowed' => false, 'retry_after' => max( 1, ( $oldest + $window ) - $now ), 'limit' => $rate, 'remaining' => 0 ];
		}
		$entries[] = $now;
		set_transient( $key, $entries, $window );
		return [ 'allowed' => true, 'limit' => $rate, 'remaining' => $rate - count( $entries ) ];
	}

	public static function reset_to_pending( int $id, int $customer_id ) : bool {
		global $wpdb;
		$t = $wpdb->prefix . Installer::TABLE_MESSAGES;
		return (bool) $wpdb->update( $t, [
			'scan_status' => 'pending',
			'scan_verdict'=> '',
			'scan_score'  => null,
			'scan_reasons'=> null,
			'scanned_at'  => null,
		], [ 'id' => $id, 'customer_id' => $customer_id ] );
	}
}
