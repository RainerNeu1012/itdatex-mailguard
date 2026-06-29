<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap\Autoconfig;

/**
 * Thunderbird ISPDB: https://autoconfig.thunderbird.net/v1.1/{domain}
 *
 * Liefert XML mit allen Mail-Protokollen (IMAP/POP/SMTP) für viele hundert
 * Provider. Wir parsen nur den ersten IMAP-Server und ignorieren POP/SMTP.
 *
 * Cache: 7 Tage pro Domain (Transient) — die ISPDB ändert sich selten,
 * und wir wollen Thunderbird nicht zumüllen.
 */
final class MozillaAutoconfig {

	private const ENDPOINT      = 'https://autoconfig.thunderbird.net/v1.1/';
	private const TTL_SECONDS   = 7 * DAY_IN_SECONDS;
	private const TTL_NEG_SECS  = HOUR_IN_SECONDS;  // 404 nur 1h cachen, falls Provider nachträglich aufgenommen wird
	private const TIMEOUT       = 5;
	private const CACHE_PREFIX  = 'mg_autoconfig_moz_';

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

		$res = wp_remote_get( self::ENDPOINT . rawurlencode( $domain ), [
			'timeout' => self::TIMEOUT,
			'headers' => [ 'Accept' => 'text/xml,application/xml' ],
			'user-agent' => 'itdatex-mailguard/1.0 (autoconfig)',
		] );
		if ( is_wp_error( $res ) ) { return null; }
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code === 404 ) {
			set_transient( $key, '__none__', self::TTL_NEG_SECS );
			return null;
		}
		if ( $code >= 400 ) { return null; }

		$body = (string) wp_remote_retrieve_body( $res );
		$parsed = self::parse_xml( $body );
		if ( ! $parsed ) {
			set_transient( $key, '__none__', self::TTL_NEG_SECS );
			return null;
		}
		$parsed['domain']     = $domain;
		$parsed['source']     = 'mozilla';
		$parsed['confidence'] = 'high';
		set_transient( $key, $parsed, self::TTL_SECONDS );
		return $parsed;
	}

	/** Minimaler XML-Parser auf den ersten <incomingServer type="imap">. */
	private static function parse_xml( string $xml ) : ?array {
		if ( $xml === '' ) { return null; }
		$prev = libxml_use_internal_errors( true );
		$doc  = simplexml_load_string( $xml );
		libxml_use_internal_errors( $prev );
		if ( $doc === false ) { return null; }

		foreach ( $doc->emailProvider->incomingServer ?? [] as $srv ) {
			if ( (string) $srv['type'] !== 'imap' ) { continue; }
			$host = (string) ( $srv->hostname ?? '' );
			$port = (int)    ( $srv->port ?? 0 );
			$sock = strtolower( (string) ( $srv->socketType ?? '' ) );
			if ( $host === '' || $port < 1 ) { return null; }
			$encryption = match ( $sock ) {
				'ssl'      => 'ssl',
				'starttls' => 'tls',
				'plain'    => 'none',
				default    => $port === 993 ? 'ssl' : 'tls',
			};
			return [
				'host'       => $host,
				'port'       => $port,
				'encryption' => $encryption,
			];
		}
		return null;
	}

	/**
	 * Sehr konservatives Whitelisting: ASCII-Domain ohne Sonderzeichen,
	 * mind. 1 Punkt, keine IP-Adressen, kein localhost. Schuetzt vor
	 * URL-Injection in den Mozilla-Endpoint-Path.
	 */
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
