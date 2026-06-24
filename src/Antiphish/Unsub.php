<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish;

use Itdatex\Mailguard\Installer;

/**
 * Persistenz fuer mg_unsubs. Alle Queries tenant-scoped.
 */
final class Unsub {

	public static function table() : string {
		global $wpdb;
		return $wpdb->prefix . Installer::TABLE_UNSUBS;
	}

	public static function find_for_customer( int $id, int $customer_id ) : ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE id = %d AND customer_id = %d LIMIT 1',
			$id, $customer_id
		), ARRAY_A );
		return $row ?: null;
	}

	public static function find_by_message( int $message_id, int $customer_id ) : ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE message_id = %d AND customer_id = %d ORDER BY id DESC LIMIT 1',
			$message_id, $customer_id
		), ARRAY_A );
		return $row ?: null;
	}

	public static function list_for_customer( int $customer_id, int $page = 1, int $per_page = 25 ) : array {
		global $wpdb;
		$per_page = max( 1, min( 100, $per_page ) );
		$page     = max( 1, $page );
		$offset   = ( $page - 1 ) * $per_page;
		$t        = self::table();
		$m        = $wpdb->prefix . Installer::TABLE_MESSAGES;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT u.*, m.subject AS msg_subject, m.from_addr AS msg_from_addr, m.from_name AS msg_from_name
			 FROM {$t} u
			 LEFT JOIN {$m} m ON m.id = u.message_id
			 WHERE u.customer_id = %d
			 ORDER BY u.id DESC LIMIT %d OFFSET %d",
			$customer_id, $per_page, $offset
		), ARRAY_A );

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t} WHERE customer_id = %d", $customer_id
		) );

		return [
			'items'    => array_map( [ __CLASS__, 'public_view' ], $rows ?: [] ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		];
	}

	public static function create( int $customer_id, int $message_id, array $option, array $api ) : int {
		global $wpdb;
		$ok = $wpdb->insert( self::table(), [
			'customer_id'     => $customer_id,
			'message_id'      => $message_id,
			'kind'            => mb_substr( (string) ( $option['kind'] ?? '' ), 0, 10 ),
			'target'          => mb_substr( (string) ( $option['url'] ?? $option['mailto_to'] ?? '' ), 0, 2048 ),
			'one_click'       => ! empty( $option['one_click'] ) ? 1 : 0,
			'api_status'      => mb_substr( (string) ( $api['status'] ?? '' ), 0, 40 ),
			'api_http_status' => $api['http_status'] ?? null,
			'api_message_id'  => mb_substr( (string) ( $api['message_id'] ?? '' ), 0, 255 ),
			'api_detail'      => wp_json_encode( $api['raw'] ?? $api ),
			'created_at'      => current_time( 'mysql', true ),
		] );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function update_dsn( int $id, int $customer_id, array $status ) : void {
		global $wpdb;
		$wpdb->update( self::table(), [
			'dsn_status'  => mb_substr( (string) ( $status['label'] ?? '' ), 0, 20 ),
			'dsn_detail'  => wp_json_encode( $status['raw'] ?? $status ),
			'updated_at'  => current_time( 'mysql', true ),
		], [ 'id' => $id, 'customer_id' => $customer_id ] );
	}

	public static function public_view( array $row ) : array {
		$detail = $row['api_detail'] ? json_decode( (string) $row['api_detail'], true ) : null;
		$dsn    = $row['dsn_detail'] ? json_decode( (string) $row['dsn_detail'], true ) : null;
		return [
			'id'              => (int) $row['id'],
			'message_id'      => (int) $row['message_id'],
			'msg_subject'     => (string) ( $row['msg_subject']   ?? '' ),
			'msg_from_addr'   => (string) ( $row['msg_from_addr'] ?? '' ),
			'msg_from_name'   => (string) ( $row['msg_from_name'] ?? '' ),
			'kind'            => (string) $row['kind'],
			'target'          => (string) $row['target'],
			'one_click'       => (int) $row['one_click'],
			'api_status'      => (string) $row['api_status'],
			'api_http_status' => $row['api_http_status'] !== null ? (int) $row['api_http_status'] : null,
			'api_message_id'  => (string) $row['api_message_id'],
			'api_detail'      => $detail,
			'dsn_status'      => (string) $row['dsn_status'],
			'dsn_detail'      => $dsn,
			'created_at'      => (string) $row['created_at'],
			'updated_at'      => $row['updated_at'] ?: null,
		];
	}
}
