<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Rules;

use Itdatex\Mailguard\Installer;

/**
 * Persistenz fuer mg_postbox_rules. Multi-Bedingungs-Regeln mit Actions,
 * die nach dem Scan aber vor Auto-Quarantaene ausgewertet werden.
 *
 * Datenmodell:
 *  - conditions_json: JSON-Array von { field, op, value }
 *      field  : from_addr | from_domain | from_name | subject | body |
 *               verdict   | score       | has_unsub | has_attachments
 *      op     : equals | not_equals | contains | not_contains |
 *               starts_with | ends_with | gt | ge | lt | le | eq | ne
 *      value  : string oder number, je nach field/op
 *  - actions_json: JSON-Array von { type, ... }
 *      type=move  : {"type":"move",   "folder":"Newsletters"}
 *      type=delete: {"type":"delete"}   — IMAP EXPUNGE, kein Undo
 *      type=flag  : {"type":"flag",  "flag":"\\Seen"|"\\Flagged"}
 *  - match_op : "AND" (alle Bedingungen muessen matchen) oder "OR"
 *  - stop_processing: nach Match keine weiteren Regeln auswerten
 *  - priority : niedrige Zahl zuerst ausgewertet (Default 100)
 */
final class PostboxRule {

	public const FIELDS  = [
		'from_addr', 'from_domain', 'from_name',
		'subject', 'body', 'verdict', 'score',
		'has_unsub', 'has_attachments',
	];
	public const OPS     = [
		'equals', 'not_equals', 'contains', 'not_contains',
		'starts_with', 'ends_with',
		'gt', 'ge', 'lt', 'le', 'eq', 'ne',
	];
	public const ACTION_TYPES = [ 'move', 'delete', 'flag' ];
	public const MATCH_OPS    = [ 'AND', 'OR' ];

	public static function table() : string {
		global $wpdb;
		return $wpdb->prefix . Installer::TABLE_POSTBOX_RULES;
	}

	public static function list_for_customer( int $customer_id ) : array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() .
			' WHERE customer_id = %d ORDER BY priority ASC, id ASC',
			$customer_id
		), ARRAY_A );
		return array_map( [ __CLASS__, 'public_view' ], $rows ?: [] );
	}

	public static function list_enabled_for_customer( int $customer_id ) : array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() .
			' WHERE customer_id = %d AND enabled = 1 ORDER BY priority ASC, id ASC',
			$customer_id
		), ARRAY_A ) ?: [];
	}

	public static function find_for_customer( int $id, int $customer_id ) : ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE id = %d AND customer_id = %d LIMIT 1',
			$id, $customer_id
		), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * @return array{ok:bool, id?:int, error?:string, message?:string}
	 */
	public static function create( int $customer_id, array $data ) : array {
		$validated = self::validate_payload( $data );
		if ( ! empty( $validated['error'] ) ) { return $validated; }

		global $wpdb;
		$ok = $wpdb->insert( self::table(), [
			'customer_id'      => $customer_id,
			'name'             => $validated['name'],
			'priority'         => $validated['priority'],
			'enabled'          => $validated['enabled'],
			'match_op'         => $validated['match_op'],
			'conditions_json'  => $validated['conditions_json'],
			'actions_json'     => $validated['actions_json'],
			'stop_processing'  => $validated['stop_processing'],
			'created_at'       => current_time( 'mysql', true ),
			'updated_at'       => current_time( 'mysql', true ),
		] );
		if ( ! $ok ) { return [ 'ok' => false, 'error' => 'insert_failed' ]; }
		return [ 'ok' => true, 'id' => (int) $wpdb->insert_id ];
	}

	public static function update( int $id, int $customer_id, array $data ) : array {
		$existing = self::find_for_customer( $id, $customer_id );
		if ( ! $existing ) { return [ 'ok' => false, 'error' => 'not_found' ]; }
		$validated = self::validate_payload( $data );
		if ( ! empty( $validated['error'] ) ) { return $validated; }

		global $wpdb;
		$ok = $wpdb->update( self::table(), [
			'name'             => $validated['name'],
			'priority'         => $validated['priority'],
			'enabled'          => $validated['enabled'],
			'match_op'         => $validated['match_op'],
			'conditions_json'  => $validated['conditions_json'],
			'actions_json'     => $validated['actions_json'],
			'stop_processing'  => $validated['stop_processing'],
			'updated_at'       => current_time( 'mysql', true ),
		], [ 'id' => $id, 'customer_id' => $customer_id ] );
		if ( $ok === false ) { return [ 'ok' => false, 'error' => 'update_failed' ]; }
		return [ 'ok' => true, 'id' => $id ];
	}

	public static function delete( int $id, int $customer_id ) : bool {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), [ 'id' => $id, 'customer_id' => $customer_id ] );
	}

	public static function record_hit( int $id ) : void {
		global $wpdb;
		$t = self::table();
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$t} SET hit_count = hit_count + 1, last_hit_at = UTC_TIMESTAMP() WHERE id = %d",
			$id
		) );
	}

	/**
	 * Validierung: sichert dass conditions/actions ein sauberes Format haben.
	 * Wird auch bei Update aufgerufen — deshalb pure Validierung, kein DB-Zugriff.
	 */
	private static function validate_payload( array $data ) : array {
		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( $name === '' ) {
			return [ 'ok' => false, 'error' => 'empty_name', 'message' => 'Regel-Name fehlt.' ];
		}

		$match_op = in_array( ( $data['match_op'] ?? 'AND' ), self::MATCH_OPS, true )
			? (string) $data['match_op'] : 'AND';
		$conditions_raw = is_array( $data['conditions'] ?? null ) ? $data['conditions'] : [];
		$conditions = [];
		foreach ( $conditions_raw as $c ) {
			if ( ! is_array( $c ) ) { continue; }
			$f = (string) ( $c['field'] ?? '' );
			$o = (string) ( $c['op'] ?? '' );
			if ( ! in_array( $f, self::FIELDS, true ) ) { continue; }
			if ( ! in_array( $o, self::OPS, true ) )    { continue; }
			$v = $c['value'] ?? '';
			// Numerische Felder werden als Zahl gespeichert, Strings normal.
			if ( in_array( $f, [ 'score' ], true ) )                    { $v = (int) $v; }
			elseif ( in_array( $f, [ 'has_unsub', 'has_attachments' ], true ) ) { $v = $v ? 1 : 0; }
			else                                                         { $v = mb_substr( (string) $v, 0, 500 ); }
			$conditions[] = [ 'field' => $f, 'op' => $o, 'value' => $v ];
		}
		if ( empty( $conditions ) ) {
			return [ 'ok' => false, 'error' => 'no_conditions', 'message' => 'Mindestens eine Bedingung noetig.' ];
		}

		$actions_raw = is_array( $data['actions'] ?? null ) ? $data['actions'] : [];
		$actions = [];
		foreach ( $actions_raw as $a ) {
			if ( ! is_array( $a ) ) { continue; }
			$t = (string) ( $a['type'] ?? '' );
			if ( ! in_array( $t, self::ACTION_TYPES, true ) ) { continue; }
			if ( $t === 'move' ) {
				$folder = trim( (string) ( $a['folder'] ?? '' ) );
				if ( $folder === '' ) { continue; }
				$actions[] = [ 'type' => 'move', 'folder' => mb_substr( $folder, 0, 190 ) ];
			} elseif ( $t === 'flag' ) {
				$flag = trim( (string) ( $a['flag'] ?? '' ) );
				if ( $flag === '' ) { continue; }
				$actions[] = [ 'type' => 'flag', 'flag' => mb_substr( $flag, 0, 60 ) ];
			} elseif ( $t === 'delete' ) {
				$actions[] = [ 'type' => 'delete' ];
			}
		}
		if ( empty( $actions ) ) {
			return [ 'ok' => false, 'error' => 'no_actions', 'message' => 'Mindestens eine Aktion noetig.' ];
		}

		return [
			'ok'               => true,
			'name'             => mb_substr( $name, 0, 190 ),
			'priority'         => max( 0, min( 9999, (int) ( $data['priority'] ?? 100 ) ) ),
			'enabled'          => ! empty( $data['enabled'] ) ? 1 : 0,
			'match_op'         => $match_op,
			'conditions_json'  => wp_json_encode( $conditions ),
			'actions_json'     => wp_json_encode( $actions ),
			'stop_processing'  => ! empty( $data['stop_processing'] ) ? 1 : 0,
		];
	}

	public static function public_view( array $row ) : array {
		$conditions = json_decode( (string) $row['conditions_json'], true );
		$actions    = json_decode( (string) $row['actions_json'], true );
		return [
			'id'              => (int) $row['id'],
			'name'            => (string) $row['name'],
			'priority'        => (int) $row['priority'],
			'enabled'         => (int) $row['enabled'] === 1,
			'match_op'        => (string) $row['match_op'],
			'conditions'      => is_array( $conditions ) ? $conditions : [],
			'actions'         => is_array( $actions ) ? $actions : [],
			'stop_processing' => (int) $row['stop_processing'] === 1,
			'hit_count'       => (int) $row['hit_count'],
			'last_hit_at'     => $row['last_hit_at'],
			'created_at'      => (string) $row['created_at'],
			'updated_at'      => (string) $row['updated_at'],
		];
	}
}
