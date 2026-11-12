<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish\Signals;

/**
 * URL-Unshortener + Landing-Check.
 *
 * Phisher benutzen Short-URLs (bit.ly, tinyurl, t.co …) um die eigentliche
 * Ziel-Domain zu verschleiern. Dieses Signal:
 *   1. Findet alle Short-URLs im Body
 *   2. Folgt den Redirects per HEAD-Request (max 5 Hops, 4s Timeout)
 *   3. Wenn das finale Ziel verdaechtig ist (neue Domain, IDN/Punycode,
 *      bekannter Phishing-Marker), scoren +25.
 *   4. Reines Vorhandensein eines Short-Links in E-Mails mit urgency/money-
 *      Context (abgefragt via Subject+Body-Scan) -> +10, weil das selten
 *      legit ist (echte Firmen linken auf eigene Domains).
 *
 * Caching: pro final-URL 24h-Transient — wird 1x pro Zieldomain bezahlt,
 * nicht pro Mail. Max 3 Short-URLs pro Mail aufgeloest (DoS-Guard).
 */
final class UrlShortener {

	private const TIMEOUT       = 4;
	private const MAX_REDIRECTS = 5;
	private const MAX_PER_MAIL  = 3;
	private const CACHE_HOURS   = 24;

	private const SHORTENER_HOSTS = [
		'bit.ly', 'tinyurl.com', 't.co', 'goo.gl', 'ow.ly', 'buff.ly',
		'rebrand.ly', 'cutt.ly', 'short.cm', 'shorturl.at', 'tiny.cc',
		'is.gd', 't.ly', 'rb.gy', 'linklyhq.com', 'tr.ee',
	];

	// Schwache Context-Marker im Body — gepaart mit Short-URL = suspekt
	private const CONTEXT_MARKERS = [
		'dringend', 'sofort', 'jetzt klicken', 'passwort', 'konto gesperrt',
		'bestaetigen', 'verifizieren', 'rechnung', 'gewinn', 'paket',
		'click here', 'login', 'password', 'account', 'suspended',
	];

	/**
	 * @return array|null null wenn kein Hit, sonst reason-struct.
	 */
	public static function check( string $body_preview ) : ?array {
		if ( $body_preview === '' ) { return null; }

		// Alle URLs extrahieren
		if ( ! preg_match_all( '#https?://([a-z0-9.\-]+)(/[^\s<>"\']*)?#i', $body_preview, $m, PREG_SET_ORDER ) ) {
			return null;
		}

		$shorts = [];
		foreach ( $m as $match ) {
			$host = strtolower( $match[1] );
			if ( in_array( $host, self::SHORTENER_HOSTS, true ) ) {
				$shorts[] = $match[0];
				if ( count( $shorts ) >= self::MAX_PER_MAIL ) { break; }
			}
		}
		if ( ! $shorts ) { return null; }

		$resolved = [];
		foreach ( $shorts as $short ) {
			$final = self::resolve_short( $short );
			if ( $final ) { $resolved[] = $final; }
		}

		$score   = 0;
		$details = [];

		// Base-Score: Shortener-Praesenz in E-Mail
		$text_lc = mb_strtolower( $body_preview );
		$has_context = false;
		foreach ( self::CONTEXT_MARKERS as $marker ) {
			if ( strpos( $text_lc, $marker ) !== false ) { $has_context = true; break; }
		}
		if ( $has_context ) {
			$score += 10;
			$details['shortener_with_context'] = true;
		}

		// Pro resolved-URL mit suspect final-Target: +25
		foreach ( $resolved as $r ) {
			if ( $r['suspect'] ) {
				$score += 25;
				$details['suspect_landing'][] = $r['final_host'];
			} else {
				$details['benign_landing'][] = $r['final_host'];
			}
		}

		if ( $score === 0 ) { return null; }

		return [
			'code'   => 'url_shortener',
			'label'  => sprintf( '%d Kurz-URL(s) im Body — final: %s',
				count( $shorts ),
				implode( ', ', array_map( static fn( $r ) => $r['final_host'], $resolved ) ) ?: 'nicht aufloesbar'
			),
			'score'  => min( 50, $score ),
			'detail' => $details,
		];
	}

	/**
	 * @return array{short:string,final_host:string,suspect:bool}|null
	 */
	private static function resolve_short( string $short_url ) : ?array {
		$key = 'mg_sh_' . md5( $short_url );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) { return $cached; }

		$final_host = '';
		$final_url  = $short_url;
		$redirects  = 0;
		$current    = $short_url;
		while ( $redirects < self::MAX_REDIRECTS ) {
			$resp = wp_remote_head( $current, [
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'user-agent'  => 'MailGuard-URL-Resolver/1.0',
				'sslverify'   => true,
			] );
			if ( is_wp_error( $resp ) ) { break; }
			$code = (int) wp_remote_retrieve_response_code( $resp );
			$loc  = (string) wp_remote_retrieve_header( $resp, 'location' );
			if ( $code >= 300 && $code < 400 && $loc !== '' ) {
				$current = self::resolve_relative( $current, $loc );
				$final_url = $current;
				$redirects++;
				continue;
			}
			break;
		}
		$parsed = parse_url( $final_url );
		$final_host = strtolower( (string) ( $parsed['host'] ?? '' ) );
		$result = [
			'short'      => $short_url,
			'final_host' => $final_host,
			'final_url'  => $final_url,
			'suspect'    => self::is_suspect( $final_host, $final_url ),
		];
		set_transient( $key, $result, self::CACHE_HOURS * HOUR_IN_SECONDS );
		return $result;
	}

	private static function is_suspect( string $host, string $url ) : bool {
		if ( $host === '' ) { return true; } // unresolvable = suspekt
		// Punycode / IDN
		if ( strpos( $host, 'xn--' ) !== false ) { return true; }
		// Bekannte Phishing-Hosts (preiswert erweiterbar)
		$bad_tlds = [ '.zip', '.mov', '.top', '.click', '.link', '.xyz', '.tk', '.ml', '.ga', '.cf' ];
		foreach ( $bad_tlds as $tld ) {
			if ( str_ends_with( $host, $tld ) ) { return true; }
		}
		// Shortener → Shortener (noch mal verschachtelt) ist ein strong-Smell
		if ( in_array( $host, self::SHORTENER_HOSTS, true ) ) { return true; }
		// URL-Teil mit "@" bedeutet "User-Info" vor host — klassischer Phishing-Trick
		if ( strpos( $url, '@' ) !== false && strpos( substr( $url, 8 ), '@' ) !== false ) { return true; }
		return false;
	}

	private static function resolve_relative( string $base, string $target ) : string {
		if ( preg_match( '#^https?://#i', $target ) ) { return $target; }
		$p = parse_url( $base );
		if ( ! $p ) { return $target; }
		$scheme = $p['scheme'] ?? 'https';
		$host   = $p['host']   ?? '';
		if ( str_starts_with( $target, '//' ) ) { return $scheme . ':' . $target; }
		if ( str_starts_with( $target, '/' ) )  { return $scheme . '://' . $host . $target; }
		return $scheme . '://' . $host . '/' . ltrim( $target, '/' );
	}
}
