<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap\Autoconfig;

/**
 * Microsoft Autodiscover (POX-Variante, das alte Outlook-Format).
 *
 * Greift bei Custom-Domains auf MS-Exchange (z.B. wenn Firma X ihre Mail
 * ueber Microsoft 365 hostet, aber auf @firma-x.de) und Thunderbird die
 * Domain nicht kennt.
 *
 * Probiert in Reihenfolge:
 *   1. https://autodiscover.{domain}/autodiscover/autodiscover.xml
 *   2. https://{domain}/autodiscover/autodiscover.xml
 *
 * Wenn Response Protocol Type "IMAP" enthält, extrahieren wir Host/Port/SSL.
 * Wenn nur EXPR/EXCH (kein IMAP) zurueckkommt, ist es ein klassisches
 * Exchange-Setup — wir liefern dann den Microsoft-OAuth-Hint.
 *
 * Cache: 7 Tage (positiv), 1 Stunde (negativ).
 */
final class MicrosoftAutodiscover {

	private const TIMEOUT      = 5;
	private const TTL_SECONDS  = 7 * DAY_IN_SECONDS;
	private const TTL_NEG_SECS = HOUR_IN_SECONDS;
	private const CACHE_PREFIX = 'mg_autoconfig_ad_';

	/**
	 * @return array{host?:string,port?:int,encryption?:string,oauth_provider?:string,note?:string,domain?:string,source?:string,confidence?:string}|null
	 */
	public static function lookup( string $email_or_domain ) : ?array {
		$domain = strtolower( trim( str_contains( $email_or_domain, '@' )
			? substr( $email_or_domain, strrpos( $email_or_domain, '@' ) + 1 )
			: $email_or_domain ) );
		if ( ! self::is_safe_domain( $domain ) ) { return null; }
		$key = self::CACHE_PREFIX . md5( $domain );
		$cached = get_transient( $key );
		if ( $cached === '__none__' ) { return null; }
		if ( is_array( $cached ) ) { return $cached; }

		$email = str_contains( $email_or_domain, '@' ) ? $email_or_domain : ( 'probe@' . $domain );

		foreach ( [ 'autodiscover.' . $domain, $domain ] as $host ) {
			$res = self::probe( $host, $email );
			if ( $res ) {
				$res['domain']     = $domain;
				$res['source']     = 'autodiscover';
				$res['confidence'] = 'medium';
				set_transient( $key, $res, self::TTL_SECONDS );
				return $res;
			}
		}
		set_transient( $key, '__none__', self::TTL_NEG_SECS );
		return null;
	}

	private static function probe( string $host, string $email ) : ?array {
		$url  = 'https://' . $host . '/autodiscover/autodiscover.xml';
		$body = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/outlook/requestschema/2006">
  <Request>
	<EMailAddress>{$email}</EMailAddress>
	<AcceptableResponseSchema>http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a</AcceptableResponseSchema>
  </Request>
</Autodiscover>
XML;
		$res = wp_remote_post( $url, [
			'timeout'    => self::TIMEOUT,
			'headers'    => [ 'Content-Type' => 'text/xml; charset=utf-8' ],
			'body'       => $body,
			'user-agent' => 'itdatex-mailguard/1.0 (autodiscover)',
			'redirection'=> 3,
		] );
		if ( is_wp_error( $res ) ) { return null; }
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 400 ) { return null; }
		$xml = (string) wp_remote_retrieve_body( $res );
		return self::parse( $xml );
	}

	private static function parse( string $xml ) : ?array {
		if ( $xml === '' ) { return null; }
		$prev = libxml_use_internal_errors( true );
		$doc  = simplexml_load_string( $xml );
		libxml_use_internal_errors( $prev );
		if ( $doc === false ) { return null; }

		// Namespace registrieren — Outlook-Schema
		$ns = 'http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a';
		$doc->registerXPathNamespace( 'o', $ns );
		$protocols = $doc->xpath( '//o:Account/o:Protocol' );

		$imap = null; $is_exchange = false;
		foreach ( $protocols ?? [] as $p ) {
			$type = strtoupper( (string) ( $p->Type ?? '' ) );
			if ( $type === 'IMAP' && $imap === null ) {
				$host = (string) ( $p->Server ?? '' );
				$port = (int)    ( $p->Port   ?? 0 );
				$ssl  = strtoupper( (string) ( $p->SSL ?? '' ) ) === 'ON';
				if ( $host !== '' && $port > 0 ) {
					$imap = [
						'host'       => $host,
						'port'       => $port,
						'encryption' => $ssl ? 'ssl' : ( $port === 993 ? 'ssl' : 'tls' ),
					];
				}
			}
			if ( in_array( $type, [ 'EXCH', 'EXPR', 'EXHTTP' ], true ) ) {
				$is_exchange = true;
			}
		}
		if ( $imap ) { return $imap; }
		if ( $is_exchange ) {
			// Reiner Exchange-Tenant ohne IMAP-Protocol — Microsoft-365-mässig.
			// Empfehlung: Microsoft-OAuth via outlook.office365.com.
			return [
				'host'           => 'outlook.office365.com',
				'port'           => 993,
				'encryption'     => 'ssl',
				'oauth_provider' => 'microsoft',
				'note'           => 'Exchange-Tenant erkannt — OAuth-Connect empfohlen.',
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
