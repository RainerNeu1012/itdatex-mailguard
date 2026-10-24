<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Rules;

use Itdatex\Mailguard\Installer;

/**
 * Opt-out-Join-Tabelle: Kunde X deaktiviert Shared-Rule Y.
 */
final class SharedRuleOptout {

	public static function table() : string {
		global $wpdb;
		return $wpdb->prefix . Installer::TABLE_SHARED_RULE_OPTOUTS;
	}

	public static function is_opted_out( int $customer_id, int $shared_rule_id ) : bool {
		global $wpdb;
		$row = $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM ' . self::table() . ' WHERE customer_id = %d AND shared_rule_id = %d LIMIT 1',
			$customer_id, $shared_rule_id
		) );
		return (bool) $row;
	}

	public static function set( int $customer_id, int $shared_rule_id ) : bool {
		global $wpdb;
		// INSERT IGNORE via composite PK — Doppeleintraege sind silent no-op.
		$t = self::table();
		return (bool) $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$t} (customer_id, shared_rule_id, created_at) VALUES (%d, %d, UTC_TIMESTAMP())",
			$customer_id, $shared_rule_id
		) );
	}

	public static function remove( int $customer_id, int $shared_rule_id ) : bool {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), [
			'customer_id'     => $customer_id,
			'shared_rule_id'  => $shared_rule_id,
		] );
	}

	public static function list_for_customer( int $customer_id ) : array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT shared_rule_id FROM ' . self::table() . ' WHERE customer_id = %d',
			$customer_id
		) );
		return array_map( 'intval', $ids ?: [] );
	}
}
