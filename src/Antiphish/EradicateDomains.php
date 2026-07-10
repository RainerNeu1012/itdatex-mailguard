<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish;

use Itdatex\Mailguard\Installer;

/**
 * Persistente Absender-Domain-Auto-Vernichtung.
 *
 * Sobald eine Domain hier eingetragen ist, filtert PullService jede
 * neu eintreffende Mail vom passenden Absender direkt beim IMAP-Fetch
 * heraus — kein Ingest in mg_messages, kein UI-Rauschen, und die Mail
 * fliegt per EXPUNGE aus dem IMAP-Konto.
 *
 * Das Feature ergaenzt UnsubService::eradicate_sender: eradicate_sender
 * loescht einmalig die Historie; ein Eintrag in dieser Tabelle sorgt
 * dafuer, dass auch zukuenftige Mails der Domain automatisch verschwinden.
 */
final class EradicateDomains {

	private static function table() : string {
		global $wpdb;
		return $wpdb->prefix . Installer::TABLE_ERADICATE_DOMAINS;
	}

	/**
	 * Extrahiert die Domain aus einer From-Adresse. Erwartet die bereits
	 * vom IMAP-Parser bereinigte Rein-Adresse (ohne Display-Name).
	 * Gibt null zurueck, wenn keine sinnvolle Domain gefunden werden konnte.
	 */
	public static function extract_domain( string $from_addr ) : ?string {
		$from_addr = strtolower( trim( $from_addr ) );
		if ( $from_addr === '' || ! str_contains( $from_addr, '@' ) ) {
			return null;
		}
		$parts = explode( '@', $from_addr );
		$domain = trim( end( $parts ) );
		// Punycode/Umlaut-Handling koennen wir spaeter machen, wenn's aufkommt.
		// Fuer jetzt: pragmatisch, ASCII-Domain reicht fuer die 99 %.
		if ( $domain === '' || ! str_contains( $domain, '.' ) ) {
			return null;
		}
		return $domain;
	}

	/**
	 * Prueft, ob eine Domain fuer den Kunden auf der Auto-Vernichten-Liste
	 * steht. Hot Path (PullService ruft das pro eingehender Mail), deshalb
	 * schlanke Query auf den UNIQUE-Index.
	 */
	public static function is_active( int $customer_id, string $domain ) : bool {
		$domain = strtolower( trim( $domain ) );
		if ( $customer_id <= 0 || $domain === '' ) {
			return false;
		}
		global $wpdb;
		$t = self::table();
		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t} WHERE customer_id = %d AND domain = %s LIMIT 1",
			$customer_id, $domain
		) );
		return ! empty( $id );
	}

	/**
	 * @return array{ok:bool,id?:int,domain?:string,existed?:bool,error?:string}
	 */
	public static function add( int $customer_id, string $domain ) : array {
		$domain = strtolower( trim( $domain ) );
		if ( $customer_id <= 0 ) {
			return [ 'ok' => false, 'error' => 'bad_customer' ];
		}
		if ( $domain === '' || ! str_contains( $domain, '.' ) ) {
			return [ 'ok' => false, 'error' => 'bad_domain' ];
		}
		// Der User koennte versehentlich eine vollstaendige E-Mail eintippen.
		// Wir extrahieren die Domain freundlich statt einen Fehler zu werfen.
		if ( str_contains( $domain, '@' ) ) {
			$extracted = self::extract_domain( $domain );
			if ( ! $extracted ) {
				return [ 'ok' => false, 'error' => 'bad_domain' ];
			}
			$domain = $extracted;
		}

		global $wpdb;
		$t = self::table();
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$t} WHERE customer_id = %d AND domain = %s LIMIT 1",
			$customer_id, $domain
		), ARRAY_A );
		if ( $existing ) {
			return [ 'ok' => true, 'id' => (int) $existing['id'], 'domain' => $domain, 'existed' => true ];
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$inserted = $wpdb->insert( $t, [
			'customer_id' => $customer_id,
			'domain'      => $domain,
			'created_at'  => $now,
			'hit_count'   => 0,
		], [ '%d', '%s', '%s', '%d' ] );
		if ( ! $inserted ) {
			return [ 'ok' => false, 'error' => 'db_insert_failed' ];
		}
		return [ 'ok' => true, 'id' => (int) $wpdb->insert_id, 'domain' => $domain, 'existed' => false ];
	}

	/**
	 * Loescht einen Eintrag anhand seiner id. Scoped auf customer_id, damit
	 * kein User fremde Eintraege loeschen kann.
	 */
	public static function remove( int $customer_id, int $id ) : bool {
		if ( $customer_id <= 0 || $id <= 0 ) {
			return false;
		}
		global $wpdb;
		$affected = $wpdb->delete( self::table(), [
			'id'          => $id,
			'customer_id' => $customer_id,
		], [ '%d', '%d' ] );
		return $affected > 0;
	}

	/**
	 * Liste aller Eintraege fuer einen Kunden, jung nach alt, mit Hit-Stats.
	 * Wird vom Portal fuer die Verwaltungs-Ansicht aufgerufen.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_for_customer( int $customer_id ) : array {
		if ( $customer_id <= 0 ) { return []; }
		global $wpdb;
		$t = self::table();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, domain, created_at, last_hit_at, hit_count
			 FROM {$t} WHERE customer_id = %d ORDER BY id DESC",
			$customer_id
		), ARRAY_A );
		return array_map( static function( $r ) {
			return [
				'id'          => (int) $r['id'],
				'domain'      => (string) $r['domain'],
				'created_at'  => (string) $r['created_at'],
				'last_hit_at' => $r['last_hit_at'] !== null ? (string) $r['last_hit_at'] : null,
				'hit_count'   => (int) $r['hit_count'],
			];
		}, $rows ?: [] );
	}

	/**
	 * Zaehlt einen Auto-Vernichten-Treffer. Wird vom PullService gerufen,
	 * wenn eine eingehende Mail wegen dieser Domain direkt verworfen wurde.
	 * Fire-and-forget: ein fehlgeschlagenes UPDATE darf den Pull nicht stoppen.
	 */
	public static function record_hit( int $customer_id, string $domain ) : void {
		$domain = strtolower( trim( $domain ) );
		if ( $customer_id <= 0 || $domain === '' ) { return; }
		global $wpdb;
		$t = self::table();
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$t} SET hit_count = hit_count + 1, last_hit_at = %s
			 WHERE customer_id = %d AND domain = %s",
			$now, $customer_id, $domain
		) );
	}
}
