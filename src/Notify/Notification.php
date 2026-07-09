<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Notify;

use Itdatex\Mailguard\Installer;

/**
 * In-App-Notification-Persistenz für die Toast/Badge-Anzeige im Portal +
 * Desktop-Client.
 *
 * Zweck: Wir fahren zwei parallele Kanäle. FCM-Push (Mobile + Web) läuft
 * unverändert weiter über PushService — Desktop-Windows dagegen hat keinen
 * verlässlichen Push-Weg und pollt stattdessen diese Tabelle. Ergo:
 * jedes Event, das der User sehen soll, wird zusätzlich hier persistiert;
 * Push ist ein Push-and-Forget, das In-App-Feed ist die Wahrheitsquelle.
 *
 * event-Slugs sind bewusst kurz und stabil, damit der Client sie ohne
 * lokale Übersetzungstabelle als CSS-Klasse verwenden kann.
 */
final class Notification {

	public const EVENT_DANGEROUS       = 'dangerous';
	public const EVENT_AUTO_QUARANTINE = 'auto_quarantine';
	public const EVENT_UNDO_EXPIRING   = 'undo_expiring';
	public const EVENT_UNSUB_BOUNCED   = 'unsub_bounced';

	public static function table() : string {
		global $wpdb;
		return $wpdb->prefix . Installer::TABLE_NOTIFICATIONS;
	}

	/**
	 * @param array{
	 *   event: string,
	 *   title: string,
	 *   body?: string,
	 *   route?: string,
	 *   message_id?: int,
	 *   action_id?: int,
	 * } $data
	 */
	public static function create( int $customer_id, array $data ) : int {
		global $wpdb;
		$ok = $wpdb->insert( self::table(), [
			'customer_id' => $customer_id,
			'event'       => mb_substr( (string) ( $data['event'] ?? '' ), 0, 40 ),
			'title'       => mb_substr( (string) ( $data['title'] ?? '' ), 0, 200 ),
			'body'        => mb_substr( (string) ( $data['body']  ?? '' ), 0, 1000 ),
			'route'       => mb_substr( (string) ( $data['route'] ?? '' ), 0, 200 ),
			'message_id'  => isset( $data['message_id'] ) ? (int) $data['message_id'] : null,
			'action_id'   => isset( $data['action_id'] )  ? (int) $data['action_id']  : null,
			'created_at'  => current_time( 'mysql', true ),
		] );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Notifications ab exclusive `since_id`, neueste zuerst. Optional nur
	 * ungelesene. Cap auf `$limit` Zeilen, damit ein lang offline gewesener
	 * Client den Server nicht mit einer riesigen Antwort belastet.
	 */
	public static function list_for_customer( int $customer_id, int $since_id = 0, int $limit = 50, bool $unread_only = false ) : array {
		global $wpdb;
		$limit = max( 1, min( 200, $limit ) );
		$where = [ 'customer_id = %d' ];
		$args  = [ $customer_id ];
		if ( $since_id > 0 ) { $where[] = 'id > %d';       $args[] = $since_id; }
		if ( $unread_only )  { $where[] = 'read_at IS NULL'; }
		$sql = 'SELECT * FROM ' . self::table()
			. ' WHERE ' . implode( ' AND ', $where )
			. ' ORDER BY id DESC LIMIT %d';
		$args[] = $limit;
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return array_map( [ __CLASS__, 'public_view' ], $rows ?: [] );
	}

	/** Nur die Anzahl ungelesener — billiger Endpoint für den Header-Badge. */
	public static function unread_count( int $customer_id ) : int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::table() . ' WHERE customer_id = %d AND read_at IS NULL',
			$customer_id
		) );
	}

	/**
	 * Alle bisher ungelesenen bis inkl. `$up_to_id` als gelesen markieren.
	 * Idempotent. Der Client ruft das nach einem Poll auf, um dem Server zu
	 * signalisieren "bis hier hab ich gesehen".
	 */
	public static function mark_seen( int $customer_id, int $up_to_id ) : int {
		global $wpdb;
		if ( $up_to_id <= 0 ) { return 0; }
		return (int) $wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table()
			. ' SET read_at = %s'
			. ' WHERE customer_id = %d AND id <= %d AND read_at IS NULL',
			current_time( 'mysql', true ), $customer_id, $up_to_id
		) );
	}

	private static function public_view( array $row ) : array {
		return [
			'id'         => (int) $row['id'],
			'event'      => (string) $row['event'],
			'title'      => (string) $row['title'],
			'body'       => (string) $row['body'],
			'route'      => (string) $row['route'],
			'message_id' => $row['message_id'] !== null ? (int) $row['message_id'] : null,
			'action_id'  => $row['action_id']  !== null ? (int) $row['action_id']  : null,
			'created_at' => (string) $row['created_at'],
			'read_at'    => $row['read_at'] ?: null,
		];
	}
}
