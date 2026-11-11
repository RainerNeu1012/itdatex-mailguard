<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish\Signals;

/**
 * URL-Reputation gegen URLhaus (abuse.ch). Kostenlose API, kein Key
 * noetig. Deckt vor allem Malware-Distribution-URLs ab — komplementaer
 * zu Google Safe Browsing, das staerker auf Phishing fokussiert ist.
 *
 * API: https://urlhaus-api.abuse.ch/v1/url/ (POST form, "url" field)
 *
 * Caching: pro URL 24h Transient. Kein Daily-Limit bei URLhaus, aber
 * wir wollen trotzdem nicht die gleiche Spam-URL 50x pro Tag abfragen.
 */
final class UrlhausCheck {

	private const CACHE_TTL   = 24 * HOUR_IN_SECONDS;
	private const MAX_URLS    = 10;      // max pro Mail
	private const API_TIMEOUT = 4;
	private const API_URL     = 'https://urlhaus-api.abuse.ch/v1/url/';

	/**
	 * Extrahiert URLs aus body, prueft jede gegen URLhaus und liefert
	 * reason-structs fuer Treffer.
	 *
	 * @return array<int,array{rule:string,description:string,score:int}>
	 */
	public static function reasons_for_body( string $body ) : array {
		$urls = self::extract_urls( $body );
		if ( ! $urls ) { return []; }
		$reasons = [];
		$seen_hosts = [];
		foreach ( array_slice( $urls, 0, self::MAX_URLS ) as $url ) {
			$verdict = self::check_url( $url );
			if ( ! $verdict ) { continue; }
			$host = parse_url( $url, PHP_URL_HOST ) ?: $url;
			if ( isset( $seen_hosts[ $host ] ) ) { continue; }  // dedupe nach Host
			$seen_hosts[ $host ] = true;
			$reasons[] = [
				'rule'        => 'urlhaus_hit',
				'description' => sprintf(
					'Link auf "%s" ist in der URLhaus-Datenbank (abuse.ch) als Malware-Distribution eingetragen (Status: %s, Threat: %s).',
					$host,
					(string) ( $verdict['url_status'] ?? '?' ),
					(string) ( $verdict['threat'] ?? '?' )
				),
				'score'       => 50,
			];
		}
		return $reasons;
	}

	/**
	 * @return array|null null bei Fehler/No-Hit, sonst URLhaus-Response-Teilmenge
	 */
	private static function check_url( string $url ) : ?array {
		$cache_key = 'mg_urlhaus_' . md5( $url );
		$cached = get_transient( $cache_key );
		if ( $cached !== false ) {
			return $cached === 'clean' ? null : (array) $cached;
		}
		$resp = wp_remote_post( self::API_URL, [
			'timeout' => self::API_TIMEOUT,
			'body'    => [ 'url' => $url ],
			'headers' => [ 'Accept' => 'application/json' ],
		] );
		if ( is_wp_error( $resp ) ) { return null; }
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code !== 200 ) { return null; }
		$json = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $json ) ) { return null; }
		// URLhaus: query_status=no_results bei Clean, query_status=ok bei Treffer
		if ( ( $json['query_status'] ?? '' ) === 'ok' ) {
			set_transient( $cache_key, $json, self::CACHE_TTL );
			return $json;
		}
		// Clean markieren damit wir nicht re-queryen
		set_transient( $cache_key, 'clean', self::CACHE_TTL );
		return null;
	}

	/**
	 * Extrahiert alle http/https URLs aus einem Text-Body.
	 * @return array<string>
	 */
	private static function extract_urls( string $body ) : array {
		if ( $body === '' ) { return []; }
		preg_match_all( '#\bhttps?://[^\s<>"\'\]\)]+#i', $body, $m );
		$urls = array_unique( $m[0] ?? [] );
		return array_values( $urls );
	}
}
