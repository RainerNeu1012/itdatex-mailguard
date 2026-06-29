<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap\Autoconfig;

/**
 * RFC 6186 — DNS SRV records für Mail-Service-Discovery.
 *
 * Sucht in Reihenfolge:
 *   _imaps._tcp.{domain}  → IMAP über SSL
 *   _imap._tcp.{domain}   → IMAP über STARTTLS oder plain
 *
 * Liefert den Eintrag mit der niedrigsten priority (höchster Priorität).
 *
 * Letzter Fallback: viele Hoster (Plesk, cPanel, Mittwald) liefern
 * korrekte SRV-Records, ohne in Mozilla-ISPDB oder Autodiscover gelistet
 * zu sein.
 */
final class SrvDiscovery {

	private const TTL_SECONDS  = 1 * DAY_IN_SECONDS;
	private const TTL_NEG_SECS = HOUR_IN_SECONDS;
	private const CACHE_PREFIX = 'mg_autoconfig_srv_';

	/**
	 * @return array{host?:string,port?:int,encryption?:string,note?:string,domain?:string,source?:string,confidence?:string}|null
	 */
	public static function lookup( string $domain ) : ?array {
		$domain = strtolower( trim( $domain ) );
		if ( ! self::is_safe_domain( $domain ) ) { return null; }
		$key = self::CACHE_PREFIX . md5( $domain );
		$cached = get_transient( $key );
		if ( $cached === '__none__' ) { return null; }
		if ( is_array( $cached ) ) { return $cached; }

		// _imaps zuerst (SSL), dann _imap (STARTTLS/plain)
		$hit = self::query_srv( '_imaps._tcp.' . $domain, 'ssl' )
			?? self::query_srv( '_imap._tcp.' . $domain, 'tls' );

		if ( ! $hit ) {
			set_transient( $key, '__none__', self::TTL_NEG_SECS );
			return null;
		}
		$hit['domain']     = $domain;
		$hit['source']     = 'srv';
		$hit['confidence'] = 'medium';
		set_transient( $key, $hit, self::TTL_SECONDS );
		return $hit;
	}

	private static function query_srv( string $name, string $encryption ) : ?array {
		if ( ! function_exists( 'dns_get_record' ) ) { return null; }
		// dns_get_record kann bei vielen Fehlern Warnings emitten — silencen
		$records = @dns_get_record( $name, DNS_SRV );
		if ( ! is_array( $records ) || ! $records ) { return null; }
		// SRV-Records: niedrigster pri zuerst, dann höchster weight
		usort( $records, static function ( $a, $b ) {
			$pa = (int) ( $a['pri'] ?? 65535 );
			$pb = (int) ( $b['pri'] ?? 65535 );
			if ( $pa !== $pb ) { return $pa <=> $pb; }
			return (int) ( $b['weight'] ?? 0 ) <=> (int) ( $a['weight'] ?? 0 );
		} );
		foreach ( $records as $r ) {
			$host = (string) ( $r['target'] ?? '' );
			$port = (int)    ( $r['port']   ?? 0 );
			if ( $host === '' || $host === '.' || $port < 1 ) { continue; }  // "." = explizit kein Service
			return [
				'host'       => rtrim( $host, '.' ),
				'port'       => $port,
				'encryption' => $encryption,
			];
		}
		return null;
	}

	private static function is_safe_domain( string $d ) : bool {
		if ( $d === '' || strlen( $d ) > 253 ) { return false; }
		if ( ! preg_match( '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/i', $d ) ) {
			return false;
		}
		if ( in_array( $d, [ 'localhost', 'local' ], true ) ) { return false; }
		if ( filter_var( $d, FILTER_VALIDATE_IP ) ) { return false; }
		return true;
	}
}
