<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Rules;

use Itdatex\Mailguard\Installer;
use Itdatex\Mailguard\Saas\Plans;

/**
 * Persistenz fuer mg_rules. Whitelist + Blacklist pro Customer.
 *
 * Match-Typen:
 *  - from_addr       : exakter Match auf From-E-Mail-Adresse (case-insensitive)
 *  - from_domain     : Match auf Domain-Teil der From-Adresse (oder Subdomains, falls beginnt mit ".")
 *  - subject_contains: Substring-Match im Subject (case-insensitive)
 */
final class Rule {

	public const KINDS  = [ 'whitelist', 'blacklist' ];
	public const TYPES  = [ 'from_addr', 'from_domain', 'subject_contains' ];

	public static function table() : string {
		global $wpdb;
		return $wpdb->prefix . Installer::TABLE_RULES;
	}

	public static function list_for_customer( int $customer_id ) : array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE customer_id = %d ORDER BY id DESC',
			$customer_id
		), ARRAY_A );
		return array_map( [ __CLASS__, 'public_view' ], $rows ?: [] );
	}

	public static function find_for_customer( int $id, int $customer_id ) : ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE id = %d AND customer_id = %d LIMIT 1',
			$id, $customer_id
		), ARRAY_A );
		return $row ?: null;
	}

	public static function create( int $customer_id, array $data ) : array {
		$kind    = in_array( $data['kind'] ?? '', self::KINDS, true ) ? $data['kind'] : 'whitelist';
		$type    = in_array( $data['match_type'] ?? '', self::TYPES, true ) ? $data['match_type'] : 'from_addr';
		$pattern = trim( (string) ( $data['pattern'] ?? '' ) );
		if ( $pattern === '' ) {
			return [ 'ok' => false, 'error' => 'empty_pattern' ];
		}
		if ( $type === 'from_addr' || $type === 'from_domain' ) {
			$pattern = strtolower( $pattern );
		}

		// Plan-Limit: Free bekommt max. N Regeln.
		$limit = Plans::customer_limit( $customer_id, 'rules_limit' );
		if ( $limit > 0 ) {
			global $wpdb;
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . ' WHERE customer_id = %d',
				$customer_id
			) );
			if ( $count >= $limit ) {
				return [
					'ok'           => false,
					'error'        => 'plan_limit_reached',
					'limit'        => $limit,
					'used'         => $count,
					'feature'      => 'rules',
					'upgrade_hint' => 'Free-Plan erlaubt maximal ' . $limit . ' Regeln. Upgrade auf Solo/Plus/Pro fuer unbegrenzt.',
				];
			}
		}

		global $wpdb;
		$ok = $wpdb->insert( self::table(), [
			'customer_id' => $customer_id,
			'kind'        => $kind,
			'match_type'  => $type,
			'pattern'     => mb_substr( $pattern, 0, 500 ),
			'note'        => sanitize_text_field( (string) ( $data['note'] ?? '' ) ),
			'created_at'  => current_time( 'mysql', true ),
		] );
		if ( ! $ok ) { return [ 'ok' => false, 'error' => 'insert_failed' ]; }
		return [ 'ok' => true, 'id' => (int) $wpdb->insert_id ];
	}

	public static function delete( int $id, int $customer_id ) : bool {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), [ 'id' => $id, 'customer_id' => $customer_id ] );
	}

	public static function public_view( array $row ) : array {
		return [
			'id'         => (int) $row['id'],
			'kind'       => (string) $row['kind'],
			'match_type' => (string) $row['match_type'],
			'pattern'    => (string) $row['pattern'],
			'note'       => (string) $row['note'],
			'created_at' => (string) $row['created_at'],
		];
	}
}
