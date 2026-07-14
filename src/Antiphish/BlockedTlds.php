<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish;

use Itdatex\Mailguard\Installer;

/**
 * TLD-Block (Geo-/Endungs-Filter) pro Customer.
 *
 * Sobald eine TLD hier eingetragen ist, filtert PullService jede
 * neu eintreffende Mail deren Absender-Domain auf `.<tld>` endet
 * direkt beim IMAP-Fetch heraus — kein Ingest in mg_messages, per
 * EXPUNGE aus dem IMAP-Konto entfernt.
 *
 * Design:
 *  - `tld` speichert das Muster OHNE fuehrenden Punkt: `tm`, `co.uk`, `tk`.
 *  - Match: Absender-Domain endet auf `.$tld` (case-insensitive).
 *  - Mehr-Segment-TLDs (`co.uk`, `com.tr`) werden trivial mitgetroffen.
 *  - Whitelist-Rules (kind=whitelist, match_type=from_addr) koennen einen
 *    konkreten Absender wieder freischalten — greift NICHT hier, weil wir
 *    hart vor dem Ingest filtern. Wer einen einzelnen `.tm`-Absender lesen
 *    will, muss die TLD-Sperre auflockern (den Eintrag loeschen).
 *
 * Hot Path (PullService pro eingehender Mail): wir laden pro Pull-Cycle
 * einmal die Liste in-memory (typisch < 20 Eintraege pro Customer) und
 * matchen in PHP. Kein Query pro Mail.
 */
final class BlockedTlds {

	private static function table() : string {
		global $wpdb;
		return $wpdb->prefix . Installer::TABLE_BLOCKED_TLDS;
	}

	/**
	 * Normalisiert ein TLD-Muster: Kleinbuchstaben, trim, fuehrender Punkt
	 * abgeschnitten, Whitespace weg. Gibt null zurueck bei ungueltigem Muster.
	 */
	public static function normalize( string $tld ) : ?string {
		$tld = strtolower( trim( $tld ) );
		if ( $tld === '' ) { return null; }
		// Fuehrende Punkte tolerieren: User tippt manchmal `.tm`, wir speichern `tm`.
		while ( str_starts_with( $tld, '.' ) ) { $tld = substr( $tld, 1 ); }
		// Domain-Format-Check: nur Buchstaben/Ziffern/Punkte/Bindestriche.
		if ( ! preg_match( '/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/', $tld ) ) { return null; }
		// Reine Ziffern-"TLDs" gibt es nicht.
		if ( ctype_digit( str_replace( '.', '', $tld ) ) ) { return null; }
		return $tld;
	}

	/**
	 * Extrahiert die Domain aus einer From-Adresse (delegiert an EradicateDomains,
	 * damit wir die Logik nicht doppeln).
	 */
	public static function extract_domain( string $from_addr ) : ?string {
		return EradicateDomains::extract_domain( $from_addr );
	}

	/**
	 * Prueft, ob die Domain auf einer der geblockten TLDs des Customers endet.
	 * Erwartet die bereits normalisierte Liste (siehe list_tlds()); Hot Path.
	 *
	 * @param string[] $tlds
	 */
	public static function matches( string $domain, array $tlds ) : ?string {
		$domain = strtolower( trim( $domain ) );
		if ( $domain === '' ) { return null; }
		foreach ( $tlds as $tld ) {
			if ( $tld === '' ) { continue; }
			// Endet auf `.tld` ODER die Domain IST die tld (nur .tm ohne Punkte davor).
			if ( str_ends_with( $domain, '.' . $tld ) || $domain === $tld ) {
				return $tld;
			}
		}
		return null;
	}

	/**
	 * Fetch: reine TLD-Liste des Customers. Wird vom Pull einmal pro Cycle
	 * aufgerufen und dann pro Mail gegen matches() geprueft.
	 *
	 * @return string[]
	 */
	public static function list_tlds( int $customer_id ) : array {
		if ( $customer_id <= 0 ) { return []; }
		global $wpdb;
		$t = self::table();
		$col = $wpdb->get_col( $wpdb->prepare(
			"SELECT tld FROM {$t} WHERE customer_id = %d",
			$customer_id
		) );
		return $col ?: [];
	}

	/**
	 * @return array{ok:bool,id?:int,tld?:string,existed?:bool,error?:string}
	 */
	public static function add( int $customer_id, string $tld ) : array {
		if ( $customer_id <= 0 ) {
			return [ 'ok' => false, 'error' => 'bad_customer' ];
		}
		$norm = self::normalize( $tld );
		if ( $norm === null ) {
			return [ 'ok' => false, 'error' => 'bad_tld' ];
		}
		global $wpdb;
		$t = self::table();
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$t} WHERE customer_id = %d AND tld = %s LIMIT 1",
			$customer_id, $norm
		), ARRAY_A );
		if ( $existing ) {
			return [ 'ok' => true, 'id' => (int) $existing['id'], 'tld' => $norm, 'existed' => true ];
		}
		$ok = $wpdb->insert( $t, [
			'customer_id' => $customer_id,
			'tld'         => $norm,
			'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			'hit_count'   => 0,
		], [ '%d', '%s', '%s', '%d' ] );
		if ( ! $ok ) {
			return [ 'ok' => false, 'error' => 'db_insert_failed' ];
		}
		return [ 'ok' => true, 'id' => (int) $wpdb->insert_id, 'tld' => $norm, 'existed' => false ];
	}

	public static function remove( int $customer_id, int $id ) : bool {
		if ( $customer_id <= 0 || $id <= 0 ) { return false; }
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), [
			'id'          => $id,
			'customer_id' => $customer_id,
		], [ '%d', '%d' ] );
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
			"SELECT id, tld, created_at, last_hit_at, hit_count
			 FROM {$t} WHERE customer_id = %d ORDER BY id DESC",
			$customer_id
		), ARRAY_A );
		return array_map( static function( $r ) {
			return [
				'id'          => (int) $r['id'],
				'tld'         => (string) $r['tld'],
				'created_at'  => (string) $r['created_at'],
				'last_hit_at' => $r['last_hit_at'] !== null ? (string) $r['last_hit_at'] : null,
				'hit_count'   => (int) $r['hit_count'],
			];
		}, $rows ?: [] );
	}

	public static function record_hit( int $customer_id, string $tld ) : void {
		if ( $customer_id <= 0 || $tld === '' ) { return; }
		global $wpdb;
		$t = self::table();
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$t} SET hit_count = hit_count + 1, last_hit_at = %s
			 WHERE customer_id = %d AND tld = %s",
			gmdate( 'Y-m-d H:i:s' ), $customer_id, $tld
		) );
	}
}
