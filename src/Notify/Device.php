<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Notify;

use Itdatex\Mailguard\Installer;

/**
 * DB-Layer fuer mg_push_devices. Ein Device gehoert immer einem Customer
 * (denormalisiert customer_id), damit Push-Broadcast keinen JOIN braucht.
 * fcm_token ist unique — der Push-Provider identifiziert eine App-Installation
 * eindeutig, mehrere Rows fuer denselben Device wuerden Duplikat-Pushes senden.
 */
final class Device {

	// Event-Bitmaske. Bit-Zuordnung MUSS synchron zu Notify\Hooks bleiben.
	public const EVENT_DANGEROUS      = 1;
	public const EVENT_QUARANTINE     = 2;
	public const EVENT_UNDO_EXPIRING  = 4;
	public const EVENT_UNSUB_BOUNCED  = 8;
	public const EVENT_ALL            = 15;

	public static function table() : string {
		global $wpdb;
		return $wpdb->prefix . Installer::TABLE_PUSH_DEVICES;
	}

	/**
	 * Upsert per fcm_token: bestehendes Device wird aktualisiert
	 * (customer_id, platform, events_mask, last_seen_at), sonst insert.
	 */
	public static function upsert( int $customer_id, array $payload ) : int {
		global $wpdb;
		$fcm      = trim( (string) ( $payload['fcm_token'] ?? '' ) );
		$platform = self::sanitize_platform( (string) ( $payload['platform'] ?? '' ) );
		$label    = mb_substr( sanitize_text_field( (string) ( $payload['device_label'] ?? '' ) ), 0, 120 );
		$mask     = isset( $payload['events_mask'] ) ? (int) $payload['events_mask'] & self::EVENT_ALL : self::EVENT_ALL;
		if ( $fcm === '' || strlen( $fcm ) > 512 ) { return 0; }

		$now = gmdate( 'Y-m-d H:i:s' );
		$existing = $wpdb->get_row( $wpdb->prepare(
			'SELECT id FROM ' . self::table() . ' WHERE fcm_token = %s LIMIT 1',
			$fcm
		), ARRAY_A );

		if ( $existing ) {
			$wpdb->update( self::table(),
				[
					'customer_id'  => $customer_id,
					'platform'     => $platform,
					'device_label' => $label,
					'events_mask'  => $mask,
					'last_seen_at' => $now,
				],
				[ 'id' => (int) $existing['id'] ]
			);
			return (int) $existing['id'];
		}

		$wpdb->insert( self::table(), [
			'customer_id'  => $customer_id,
			'platform'     => $platform,
			'fcm_token'    => $fcm,
			'device_label' => $label,
			'events_mask'  => $mask,
			'last_seen_at' => $now,
			'created_at'   => $now,
		] );
		return (int) $wpdb->insert_id;
	}

	public static function delete( int $id, int $customer_id ) : bool {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), [ 'id' => $id, 'customer_id' => $customer_id ] );
	}

	public static function delete_by_token( string $fcm_token ) : void {
		global $wpdb;
		$wpdb->delete( self::table(), [ 'fcm_token' => $fcm_token ] );
	}

	public static function list_for_customer( int $customer_id ) : array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, platform, device_label, events_mask, last_seen_at, created_at
			 FROM ' . self::table() . ' WHERE customer_id = %d ORDER BY created_at DESC',
			$customer_id
		), ARRAY_A );
		return array_map( static function ( $r ) {
			return [
				'id'           => (int) $r['id'],
				'platform'     => (string) $r['platform'],
				'device_label' => (string) $r['device_label'],
				'events_mask'  => (int) $r['events_mask'],
				'last_seen_at' => $r['last_seen_at'] ?: null,
				'created_at'   => (string) $r['created_at'],
			];
		}, $rows ?: [] );
	}

	/**
	 * Alle Devices eines Customers, die auf das gegebene Event-Bit hoeren.
	 *
	 * @return list<array{id:int,fcm_token:string,platform:string}>
	 */
	public static function for_customer_event( int $customer_id, int $event_bit ) : array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, fcm_token, platform FROM ' . self::table() . '
			 WHERE customer_id = %d AND (events_mask & %d) <> 0',
			$customer_id, $event_bit
		), ARRAY_A );
		return $rows ?: [];
	}

	private static function sanitize_platform( string $p ) : string {
		$p = strtolower( trim( $p ) );
		return in_array( $p, [ 'ios', 'android', 'web', 'windows', 'macos', 'linux' ], true ) ? $p : 'unknown';
	}
}
