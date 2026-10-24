<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish;

use Itdatex\Mailguard\Admin\Settings;

/**
 * URL-Reputations-Check gegen Google Safe Browsing API v4.
 *
 * Rate-Limits:
 *  - Free Tier: 10.000 Lookups pro Tag, 100 URLs pro Request (threatMatches:find)
 *
 * Caching: Treffer + Clean-Status werden pro URL fuer 24h in einem Transient
 * gespeichert, damit wiederkehrende URLs (News-Links, Tracking-Links) uns
 * nicht das Daily-Budget auffressen.
 *
 * API-Key wird in wp_options via Admin\Settings['safe_browsing_api_key']
 * verwaltet. Fehlender Key -> Service ist no-op (score +0, keine Reasons).
 */
final class UrlReputationService {

	private const CACHE_PREFIX = 'mg_sb_';
	private const CACHE_TTL    = 24 * HOUR_IN_SECONDS;
	private const API_URL      = 'https://safebrowsing.googleapis.com/v4/threatMatches:find';
	private const MAX_URLS     = 50;

	public static function enabled() : bool {
		$key = trim( (string) Settings::get( 'safe_browsing_api_key', '' ) );
		$on  = (int) Settings::get( 'safe_browsing_enabled', 0 );
		return $on === 1 && $key !== '';
	}

	/**
	 * Extrahiert bis zu MAX_URLS vollständige http(s)-URLs aus einem Text,
	 * deduped und normalized (trailing punct abgeschnitten).
	 */
	public static function extract_urls( string $text ) : array {
		if ( $text === '' ) { return []; }
		if ( ! preg_match_all( '#https?://[^\s"\'<>)]+#i', $text, $m ) ) { return []; }
		$seen = [];
		foreach ( $m[0] as $u ) {
			$u = rtrim( $u, ".,;:!?)]}'\"" );
			if ( $u === '' ) { continue; }
			$seen[ strtolower( $u ) ] = $u;
			if ( count( $seen ) >= self::MAX_URLS ) { break; }
		}
		return array_values( $seen );
	}

	/**
	 * Prueft URLs via Safe Browsing. Rueckgabe: assoziatives Array
	 * [ url => threat_type ] nur fuer Hits (MALWARE / SOCIAL_ENGINEERING /
	 * UNWANTED_SOFTWARE / POTENTIALLY_HARMFUL_APPLICATION). Clean-URLs
	 * tauchen nicht im Rueckgabe-Array auf.
	 *
	 * Bei Netzwerk-/API-Fehlern: leeres Array (fail-open, Scanner laeuft
	 * mit anderen Signalen weiter).
	 */
	public static function check( array $urls ) : array {
		if ( ! self::enabled() || empty( $urls ) ) { return []; }

		// Cache-Partitionierung: schon bekannte URLs raus, nur neue anfragen.
		$hits        = [];
		$to_check    = [];
		foreach ( $urls as $u ) {
			$cached = get_transient( self::CACHE_PREFIX . md5( $u ) );
			if ( $cached === null || $cached === false ) {
				$to_check[] = $u;
				continue;
			}
			if ( is_array( $cached ) && ! empty( $cached['hit'] ) ) {
				$hits[ $u ] = (string) $cached['threat'];
			}
			// cached clean = tue nichts
		}
		if ( empty( $to_check ) ) { return $hits; }

		$key = trim( (string) Settings::get( 'safe_browsing_api_key', '' ) );
		$body = [
			'client'     => [ 'clientId' => 'itdatex-mailguard', 'clientVersion' => '1.0' ],
			'threatInfo' => [
				'threatTypes'      => [ 'MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE', 'POTENTIALLY_HARMFUL_APPLICATION' ],
				'platformTypes'    => [ 'ANY_PLATFORM' ],
				'threatEntryTypes' => [ 'URL' ],
				'threatEntries'    => array_map( static fn( $u ) => [ 'url' => $u ], $to_check ),
			],
		];
		$resp = wp_remote_post( self::API_URL . '?key=' . rawurlencode( $key ), [
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $body ),
			'timeout' => 10,
		] );
		if ( is_wp_error( $resp ) ) {
			error_log( 'itdatex-mailguard SafeBrowsing error: ' . $resp->get_error_message() );
			return $hits;
		}
		$code = wp_remote_retrieve_response_code( $resp );
		if ( $code !== 200 ) {
			error_log( 'itdatex-mailguard SafeBrowsing HTTP ' . $code . ': ' . wp_remote_retrieve_body( $resp ) );
			return $hits;
		}

		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		$matches = is_array( $data['matches'] ?? null ) ? $data['matches'] : [];

		// Hits mappen + cachen
		$hit_set = [];
		foreach ( $matches as $m ) {
			$u = (string) ( $m['threat']['url'] ?? '' );
			$t = (string) ( $m['threatType'] ?? 'UNKNOWN' );
			if ( $u === '' ) { continue; }
			$hits[ $u ]     = $t;
			$hit_set[ $u ]  = $t;
			set_transient( self::CACHE_PREFIX . md5( $u ), [ 'hit' => true, 'threat' => $t ], self::CACHE_TTL );
		}
		// Clean-URLs auch cachen damit wir sie nicht erneut anfragen
		foreach ( $to_check as $u ) {
			if ( ! isset( $hit_set[ $u ] ) ) {
				set_transient( self::CACHE_PREFIX . md5( $u ), [ 'hit' => false ], self::CACHE_TTL );
			}
		}
		return $hits;
	}

	/**
	 * Convenience: Mail-Body -> Reasons-Array fuer ScanService.
	 */
	public static function reasons_for_body( string $body_preview ) : array {
		if ( ! self::enabled() ) { return []; }
		$urls = self::extract_urls( $body_preview );
		if ( empty( $urls ) ) { return []; }
		$hits = self::check( $urls );
		if ( empty( $hits ) ) { return []; }

		$reasons = [];
		foreach ( $hits as $url => $threat ) {
			$label = match ( $threat ) {
				'MALWARE'                         => 'Malware',
				'SOCIAL_ENGINEERING'              => 'Phishing / Social Engineering',
				'UNWANTED_SOFTWARE'               => 'Unerwuenschte Software',
				'POTENTIALLY_HARMFUL_APPLICATION' => 'Potenziell schaedliche App',
				default                           => $threat,
			};
			$reasons[] = [
				'rule'        => 'url_reputation',
				'description' => sprintf(
					'Google Safe Browsing meldet "%s" fuer URL %s. Klassisches Phishing-/Malware-Signal.',
					$label, $url
				),
				'score'       => 40,
			];
		}
		return $reasons;
	}
}
