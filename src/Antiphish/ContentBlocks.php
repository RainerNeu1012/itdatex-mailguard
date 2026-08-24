<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish;

use Itdatex\Mailguard\Installer;

/**
 * Content-Blocklist pro Customer — Substring-Matches auf Subject/Body,
 * die eine Mail direkt beim Ingest per IMAP EXPUNGE loeschen (keine
 * Quarantaene, kein Undo).
 *
 * Design analog zu BlockedTlds:
 *  - Pattern wird beim Insert normalisiert (trim, optional lowercase).
 *  - Match: strpos/stripos je nach case_sensitive; optional whole_word
 *    per preg_match mit \b-Boundaries fuer Fehl-Positive-Vermeidung
 *    ("date" faengt sonst auch "update" mit).
 *  - Scope: 'subject' | 'body' | 'both'.
 *  - Hot Path: pro Pull-Cycle einmal list_patterns(), dann in-memory
 *    matches() pro Mail. Kein Query pro Mail.
 *  - Whitelist-Rules (kind=whitelist, match_type=from_addr) koennen die
 *    Wirkung NICHT aufheben, weil wir vor dem Ingest expungen — analog
 *    zur TLD-Sperre. Wer eine legitime Mail mit dem Muster erwartet,
 *    muss das Pattern feiner fassen (whole_word / case_sensitive) oder
 *    entfernen.
 */
final class ContentBlocks {

	public const SCOPES  = [ 'subject', 'body', 'both' ];

	private static function table() : string {
		global $wpdb;
		return $wpdb->prefix . Installer::TABLE_CONTENT_BLOCKS;
	}

	/**
	 * Normalisiert ein Pattern: trim, maximal 200 Zeichen. NICHT lowercased —
	 * das entscheidet erst der Match via case_sensitive-Flag.
	 */
	public static function normalize_pattern( string $pattern ) : ?string {
		$p = trim( $pattern );
		if ( $p === '' ) { return null; }
		return mb_substr( $p, 0, 200 );
	}

	public static function normalize_scope( string $scope ) : string {
		$s = strtolower( trim( $scope ) );
		return in_array( $s, self::SCOPES, true ) ? $s : 'both';
	}

	/**
	 * Prueft eine Mail gegen die Pattern-Liste. Erstes Match gewinnt.
	 * Erwartet die Liste im Format von list_patterns() (id, pattern, scope,
	 * case_sensitive, whole_word).
	 *
	 * @param array<int,array{id:int,pattern:string,scope:string,case_sensitive:bool,whole_word:bool}> $patterns
	 * @return array{id:int,pattern:string,scope:string}|null
	 */
	public static function matches( string $subject, string $body, array $patterns ) : ?array {
		if ( ! $patterns ) { return null; }
		foreach ( $patterns as $p ) {
			$pattern = (string) ( $p['pattern'] ?? '' );
			if ( $pattern === '' ) { continue; }
			$scope   = (string) ( $p['scope'] ?? 'both' );
			$cs      = ! empty( $p['case_sensitive'] );
			$ww      = ! empty( $p['whole_word'] );

			$targets = [];
			if ( $scope === 'subject' || $scope === 'both' ) { $targets[] = $subject; }
			if ( $scope === 'body'    || $scope === 'both' ) { $targets[] = $body; }

			foreach ( $targets as $t ) {
				if ( $t === '' ) { continue; }
				if ( self::hit( $t, $pattern, $cs, $ww ) ) {
					return [
						'id'      => (int) ( $p['id'] ?? 0 ),
						'pattern' => $pattern,
						'scope'   => $scope,
					];
				}
			}
		}
		return null;
	}

	private static function hit( string $haystack, string $needle, bool $case_sensitive, bool $whole_word ) : bool {
		if ( $whole_word ) {
			// preg_quote gegen Regex-Meta im User-Pattern. \b passt an Wort-
			// grenzen; \b hat mit Unicode-Word-Chars Grenzfaelle, aber die
			// haeufigen Spam-Wortstaemme (viagra, date, sex, bitcoin) sind
			// ASCII — der Pragmatismus schlaegt hier Perfektion.
			$flags = $case_sensitive ? '' : 'i';
			$re    = '/\b' . preg_quote( $needle, '/' ) . '\b/u' . $flags;
			return (bool) preg_match( $re, $haystack );
		}
		return $case_sensitive
			? str_contains( $haystack, $needle )
			: ( stripos( $haystack, $needle ) !== false );
	}

	/**
	 * Hot-Path-Fetch: gib nur die Felder zurueck die matches() braucht.
	 *
	 * @return array<int,array{id:int,pattern:string,scope:string,case_sensitive:bool,whole_word:bool}>
	 */
	public static function list_patterns( int $customer_id ) : array {
		if ( $customer_id <= 0 ) { return []; }
		global $wpdb;
		$t = self::table();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, pattern, scope, case_sensitive, whole_word
			 FROM {$t} WHERE customer_id = %d",
			$customer_id
		), ARRAY_A );
		return array_map( static function( $r ) {
			return [
				'id'             => (int) $r['id'],
				'pattern'        => (string) $r['pattern'],
				'scope'          => (string) $r['scope'],
				'case_sensitive' => (bool) $r['case_sensitive'],
				'whole_word'     => (bool) $r['whole_word'],
			];
		}, $rows ?: [] );
	}

	/**
	 * Liste fuer Portal-Verwaltung, mit Hit-Statistik.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_for_customer( int $customer_id ) : array {
		if ( $customer_id <= 0 ) { return []; }
		global $wpdb;
		$t = self::table();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, pattern, scope, case_sensitive, whole_word,
			        created_at, last_hit_at, hit_count
			 FROM {$t} WHERE customer_id = %d ORDER BY id DESC",
			$customer_id
		), ARRAY_A );
		return array_map( static function( $r ) {
			return [
				'id'             => (int) $r['id'],
				'pattern'        => (string) $r['pattern'],
				'scope'          => (string) $r['scope'],
				'case_sensitive' => (bool) $r['case_sensitive'],
				'whole_word'     => (bool) $r['whole_word'],
				'created_at'     => (string) $r['created_at'],
				'last_hit_at'    => $r['last_hit_at'] !== null ? (string) $r['last_hit_at'] : null,
				'hit_count'      => (int) $r['hit_count'],
			];
		}, $rows ?: [] );
	}

	/**
	 * @return array{ok:bool,id?:int,pattern?:string,scope?:string,existed?:bool,error?:string}
	 */
	public static function add( int $customer_id, string $pattern, string $scope = 'both', bool $case_sensitive = false, bool $whole_word = false ) : array {
		if ( $customer_id <= 0 ) {
			return [ 'ok' => false, 'error' => 'bad_customer' ];
		}
		$p = self::normalize_pattern( $pattern );
		if ( $p === null ) {
			return [ 'ok' => false, 'error' => 'bad_pattern' ];
		}
		$s = self::normalize_scope( $scope );

		global $wpdb;
		$t = self::table();
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$t} WHERE customer_id = %d AND pattern = %s AND scope = %s LIMIT 1",
			$customer_id, $p, $s
		), ARRAY_A );
		if ( $existing ) {
			return [ 'ok' => true, 'id' => (int) $existing['id'], 'pattern' => $p, 'scope' => $s, 'existed' => true ];
		}
		$ok = $wpdb->insert( $t, [
			'customer_id'    => $customer_id,
			'pattern'        => $p,
			'scope'          => $s,
			'case_sensitive' => $case_sensitive ? 1 : 0,
			'whole_word'     => $whole_word ? 1 : 0,
			'hit_count'      => 0,
			'created_at'     => gmdate( 'Y-m-d H:i:s' ),
		], [ '%d', '%s', '%s', '%d', '%d', '%d', '%s' ] );
		if ( ! $ok ) {
			return [ 'ok' => false, 'error' => 'db_insert_failed' ];
		}
		return [ 'ok' => true, 'id' => (int) $wpdb->insert_id, 'pattern' => $p, 'scope' => $s, 'existed' => false ];
	}

	public static function remove( int $customer_id, int $id ) : bool {
		if ( $customer_id <= 0 || $id <= 0 ) { return false; }
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), [
			'id'          => $id,
			'customer_id' => $customer_id,
		], [ '%d', '%d' ] );
	}

	public static function record_hit( int $customer_id, int $id ) : void {
		if ( $customer_id <= 0 || $id <= 0 ) { return; }
		global $wpdb;
		$t = self::table();
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$t} SET hit_count = hit_count + 1, last_hit_at = %s
			 WHERE id = %d AND customer_id = %d",
			gmdate( 'Y-m-d H:i:s' ), $id, $customer_id
		) );
	}
}
