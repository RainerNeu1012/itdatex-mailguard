<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish\Signals;

/**
 * Age-of-Domain-Check via RDAP. Frisch registrierte Domains (< 30 Tage)
 * sind fast immer fuer kurzlebige Spam-/Phishing-Kampagnen genutzt —
 * Scammer registrieren Domains bulk, nutzen sie 2-14 Tage und werfen sie weg.
 *
 * Datenquelle: RDAP (RFC 7482) — moderner Nachfolger von WHOIS, HTTP-basiert,
 * strukturiertes JSON. Keine externe Abhaengigkeit, keine API-Keys.
 *
 * Flow:
 *  1. Extrahiere Parent-Domain aus from_addr (mail.example.com → example.com)
 *  2. Via IANA RDAP-Bootstrap (https://data.iana.org/rdap/dns.json) den
 *     passenden RDAP-Server fuer die TLD finden
 *  3. GET {rdap-server}/domain/{domain} → JSON mit `events` Array
 *  4. Finde "registration"-Event → Alter in Tagen berechnen
 *
 * Caching:
 *  - Bootstrap: 24h (TLD-Zuordnung aendert sich selten)
 *  - Pro Domain: 7 Tage (Alter wandert langsam)
 *  - Fehler/Unknown: ebenfalls 7 Tage cachen damit wir broken RDAP-Server
 *    nicht in Dauerschleife fragen
 *
 * Score:
 *  - < 7 Tage: +40 (quasi-sicher Fake)
 *  - 7-30 Tage: +25 (verdaechtig, aber manche Startups legit)
 *  - 30+ Tage: no-op
 */
final class YoungDomain {

	private const CACHE_TTL              = 7 * DAY_IN_SECONDS;
	private const BOOTSTRAP_CACHE_TTL    = 24 * HOUR_IN_SECONDS;
	private const BOOTSTRAP_URL          = 'https://data.iana.org/rdap/dns.json';
	private const API_TIMEOUT            = 4;
	private const YOUNG_THRESHOLD_DAYS   = 30;
	private const VERY_YOUNG_THRESHOLD   = 7;

	/**
	 * Zusammengesetzte TLDs die zweiteilig sind (fuer korrekte Parent-Domain-
	 * Extraktion). Nicht vollstaendig — deckt die Top 95% ab.
	 */
	private const TWO_PART_TLDS = [
		'co.uk', 'org.uk', 'me.uk', 'ac.uk', 'gov.uk',
		'com.au', 'net.au', 'org.au', 'edu.au',
		'co.jp', 'ne.jp', 'or.jp', 'ac.jp',
		'co.nz', 'org.nz', 'co.za',
		'com.br', 'com.mx', 'com.ar', 'com.tr',
		'co.in', 'co.kr',
	];

	public static function check( string $from_addr ) : ?array {
		$at = strrpos( $from_addr, '@' );
		if ( $at === false ) { return null; }
		$fqdn = strtolower( trim( substr( $from_addr, $at + 1 ) ) );
		if ( $fqdn === '' ) { return null; }

		$domain = self::extract_parent_domain( $fqdn );
		if ( $domain === '' ) { return null; }

		$age_days = self::lookup_age_days( $domain );
		if ( $age_days === null ) { return null; }
		if ( $age_days >= self::YOUNG_THRESHOLD_DAYS ) { return null; }

		$score = $age_days < self::VERY_YOUNG_THRESHOLD ? 40 : 25;
		$age_label = $age_days === 0
			? 'heute'
			: ( $age_days === 1 ? 'gestern' : 'vor ' . $age_days . ' Tagen' );

		return [
			'rule'        => 'young_domain',
			'description' => sprintf(
				'Absender-Domain "%s" wurde erst %s registriert. Frische Domains werden fast immer fuer kurzlebige Spam-/Phishing-Kampagnen verwendet.',
				$domain, $age_label
			),
			'score'       => $score,
		];
	}

	/**
	 * Null wenn RDAP keinen Lookup moeglich macht (kein TLD-Mapping, API down,
	 * Domain existiert nicht). Kein Signal in dem Fall — DNS-Check faengt
	 * nicht-existente Domains eh ab.
	 */
	private static function lookup_age_days( string $domain ) : ?int {
		$cache_key = 'mg_young_dom_' . md5( $domain );
		$cached = get_transient( $cache_key );
		if ( $cached !== false ) {
			return $cached === 'unknown' ? null : (int) $cached;
		}

		$rdap_url = self::rdap_url_for_domain( $domain );
		if ( ! $rdap_url ) {
			set_transient( $cache_key, 'unknown', self::CACHE_TTL );
			return null;
		}

		$resp = wp_remote_get( $rdap_url, [
			'timeout' => self::API_TIMEOUT,
			'headers' => [ 'Accept' => 'application/rdap+json' ],
		] );
		if ( is_wp_error( $resp ) ) {
			set_transient( $cache_key, 'unknown', self::CACHE_TTL );
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code !== 200 ) {
			set_transient( $cache_key, 'unknown', self::CACHE_TTL );
			return null;
		}

		$json = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $json ) ) {
			set_transient( $cache_key, 'unknown', self::CACHE_TTL );
			return null;
		}

		$reg_date = '';
		foreach ( (array) ( $json['events'] ?? [] ) as $e ) {
			if ( ( $e['eventAction'] ?? '' ) === 'registration' ) {
				$reg_date = (string) ( $e['eventDate'] ?? '' );
				break;
			}
		}
		if ( $reg_date === '' ) {
			set_transient( $cache_key, 'unknown', self::CACHE_TTL );
			return null;
		}

		$ts = strtotime( $reg_date );
		if ( ! $ts ) {
			set_transient( $cache_key, 'unknown', self::CACHE_TTL );
			return null;
		}
		$age_days = (int) max( 0, floor( ( time() - $ts ) / DAY_IN_SECONDS ) );
		set_transient( $cache_key, (string) $age_days, self::CACHE_TTL );
		return $age_days;
	}

	/**
	 * Findet den RDAP-Server fuer die TLD via IANA-Bootstrap.
	 * @return string|null URL wie "https://rdap.example.org/domain/foo.com"
	 */
	private static function rdap_url_for_domain( string $domain ) : ?string {
		$parts = explode( '.', $domain );
		if ( count( $parts ) < 2 ) { return null; }
		$tld = end( $parts );

		$bootstrap = self::get_bootstrap();
		if ( ! $bootstrap ) { return null; }

		foreach ( (array) ( $bootstrap['services'] ?? [] ) as $svc ) {
			$tlds = (array) ( $svc[0] ?? [] );
			$urls = (array) ( $svc[1] ?? [] );
			if ( in_array( $tld, $tlds, true ) && ! empty( $urls ) ) {
				return rtrim( (string) $urls[0], '/' ) . '/domain/' . $domain;
			}
		}
		return null;
	}

	private static function get_bootstrap() : ?array {
		$cached = get_transient( 'mg_rdap_bootstrap' );
		if ( $cached ) { return (array) $cached; }
		$resp = wp_remote_get( self::BOOTSTRAP_URL, [ 'timeout' => self::API_TIMEOUT ] );
		if ( is_wp_error( $resp ) ) { return null; }
		if ( (int) wp_remote_retrieve_response_code( $resp ) !== 200 ) { return null; }
		$json = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $json ) ) { return null; }
		set_transient( 'mg_rdap_bootstrap', $json, self::BOOTSTRAP_CACHE_TTL );
		return $json;
	}

	/**
	 * Extrahiert die registrierbare Parent-Domain aus einem FQDN.
	 * Beispiele:
	 *   mail.example.com   → example.com
	 *   news.bbc.co.uk     → bbc.co.uk
	 *   evil-fake.xyz      → evil-fake.xyz
	 */
	private static function extract_parent_domain( string $fqdn ) : string {
		$parts = explode( '.', $fqdn );
		$n = count( $parts );
		if ( $n < 2 ) { return $fqdn; }
		$last_two = $parts[ $n - 2 ] . '.' . $parts[ $n - 1 ];
		if ( in_array( $last_two, self::TWO_PART_TLDS, true ) ) {
			return $n >= 3 ? ( $parts[ $n - 3 ] . '.' . $last_two ) : $fqdn;
		}
		return $last_two;
	}
}
