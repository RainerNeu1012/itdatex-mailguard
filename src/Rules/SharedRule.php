<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Rules;

use Itdatex\Mailguard\Installer;

/**
 * Operator-managed globale Spam-Regeln (mg_shared_rules).
 *
 * Analog zu PostboxRule, aber:
 *  - Keine customer_id — Regel gilt fuer alle Kunden
 *  - Kunde kann per mg_shared_rule_optouts einzelne Regeln deaktivieren
 *  - Zusaetzliches `description`-Feld fuer UI-Anzeige ("Warum gibt es diese Regel?")
 *
 * Nur Operator (Settings::operator_customer_ids) darf Shared-Rules anlegen/
 * bearbeiten/loeschen. Jeder Kunde darf sie lesen und opt-out setzen.
 */
final class SharedRule {

	public const FIELDS       = PostboxRule::FIELDS;
	public const OPS          = PostboxRule::OPS;
	public const ACTION_TYPES = PostboxRule::ACTION_TYPES;
	public const MATCH_OPS    = PostboxRule::MATCH_OPS;

	public static function table() : string {
		global $wpdb;
		return $wpdb->prefix . Installer::TABLE_SHARED_RULES;
	}

	public static function list_all() : array {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT * FROM ' . self::table() . ' ORDER BY priority ASC, id ASC',
			ARRAY_A
		);
		return array_map( [ __CLASS__, 'public_view' ], $rows ?: [] );
	}

	/**
	 * Shared Rules, die fuer einen Kunden aktiv sind: enabled=1 UND nicht in Opt-outs.
	 * Rueckgabe: Raw-Rows wie list_enabled_for_customer in PostboxRule.
	 */
	public static function list_active_for_customer( int $customer_id ) : array {
		global $wpdb;
		$t = self::table();
		$o = $wpdb->prefix . Installer::TABLE_SHARED_RULE_OPTOUTS;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT r.* FROM {$t} r
			 LEFT JOIN {$o} o ON o.shared_rule_id = r.id AND o.customer_id = %d
			 WHERE r.enabled = 1 AND o.customer_id IS NULL
			 ORDER BY r.priority ASC, r.id ASC",
			$customer_id
		), ARRAY_A ) ?: [];
	}

	public static function find( int $id ) : ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1',
			$id
		), ARRAY_A );
		return $row ?: null;
	}

	public static function create( array $data ) : array {
		$validated = self::validate_payload( $data );
		if ( ! empty( $validated['error'] ) ) { return $validated; }

		global $wpdb;
		$ok = $wpdb->insert( self::table(), [
			'name'             => $validated['name'],
			'description'      => $validated['description'],
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

	public static function update( int $id, array $data ) : array {
		$existing = self::find( $id );
		if ( ! $existing ) { return [ 'ok' => false, 'error' => 'not_found' ]; }
		$validated = self::validate_payload( $data );
		if ( ! empty( $validated['error'] ) ) { return $validated; }

		global $wpdb;
		$ok = $wpdb->update( self::table(), [
			'name'             => $validated['name'],
			'description'      => $validated['description'],
			'priority'         => $validated['priority'],
			'enabled'          => $validated['enabled'],
			'match_op'         => $validated['match_op'],
			'conditions_json'  => $validated['conditions_json'],
			'actions_json'     => $validated['actions_json'],
			'stop_processing'  => $validated['stop_processing'],
			'updated_at'       => current_time( 'mysql', true ),
		], [ 'id' => $id ] );
		if ( $ok === false ) { return [ 'ok' => false, 'error' => 'update_failed' ]; }
		return [ 'ok' => true, 'id' => $id ];
	}

	public static function delete( int $id ) : bool {
		global $wpdb;
		// Opt-outs mit cascade loeschen, damit kein Waisen-Status zurueckbleibt.
		$wpdb->delete( $wpdb->prefix . Installer::TABLE_SHARED_RULE_OPTOUTS, [ 'shared_rule_id' => $id ] );
		return (bool) $wpdb->delete( self::table(), [ 'id' => $id ] );
	}

	public static function record_hit( int $id ) : void {
		global $wpdb;
		$t = self::table();
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$t} SET hit_count = hit_count + 1, last_hit_at = UTC_TIMESTAMP() WHERE id = %d",
			$id
		) );
	}

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
			'description'      => mb_substr( (string) ( $data['description'] ?? '' ), 0, 500 ),
			'priority'         => max( 0, min( 9999, (int) ( $data['priority'] ?? 100 ) ) ),
			'enabled'          => ! empty( $data['enabled'] ) ? 1 : 0,
			'match_op'         => $match_op,
			'conditions_json'  => wp_json_encode( $conditions ),
			'actions_json'     => wp_json_encode( $actions ),
			'stop_processing'  => ! empty( $data['stop_processing'] ) ? 1 : 0,
		];
	}

	/**
	 * Public-view fuer Clients. $customer_id wird genutzt um `opted_out` zu
	 * berechnen (jeder Kunde kann die Rule individuell deaktivieren).
	 */
	public static function public_view( array $row, ?int $customer_id = null ) : array {
		$conditions = json_decode( (string) $row['conditions_json'], true );
		$actions    = json_decode( (string) $row['actions_json'], true );
		$view = [
			'id'              => (int) $row['id'],
			'name'            => (string) $row['name'],
			'description'     => (string) ( $row['description'] ?? '' ),
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
		if ( $customer_id !== null ) {
			$view['opted_out'] = SharedRuleOptout::is_opted_out( $customer_id, (int) $row['id'] );
		}
		return $view;
	}
}
