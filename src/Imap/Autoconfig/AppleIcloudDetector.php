<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap\Autoconfig;

/**
 * Erkennt iCloud+ Custom-Domains ueber MX-Records.
 *
 * Wenn ein User @meinedomain.de nutzt und die MX-Records auf
 * mx01.mail.icloud.com / mx02.mail.icloud.com zeigen, liegt das Postfach
 * bei Apple iCloud+ Custom Domain — der IMAP-Zugriff ist dann identisch
 * zu @icloud.com: imap.mail.me.com:993 SSL mit App-spezifischem Passwort.
 *
 * Cache: 1 Tag (positiv), 1 Stunde (negativ).
 */
final class AppleIcloudDetector {

	private const TTL_SECONDS  = 1 * DAY_IN_SECONDS;
	private const TTL_NEG_SECS = HOUR_IN_SECONDS;
	private const CACHE_PREFIX = 'mg_autoconfig_apple_';

	/**
	 * @return array{host:string,port:int,encryption:string,note:string,domain:string,source:string,confidence:string}|null
	 */
	public static function lookup( string $domain ) : ?array {
		$domain = strtolower( trim( $domain ) );
		if ( ! self::is_safe_domain( $domain ) ) { return null; }

		$key    = self::CACHE_PREFIX . md5( $domain );
		$cached = get_transient( $key );
		if ( $cached === '__none__' ) { return null; }
		if ( is_array( $cached ) ) { return $cached; }

		if ( ! self::mx_points_to_icloud( $domain ) ) {
			set_transient( $key, '__none__', self::TTL_NEG_SECS );
			return null;
		}
		$hit = [
			'host'       => 'imap.mail.me.com',
			'port'       => 993,
			'encryption' => 'ssl',
			'note'       => 'iCloud+ Custom Domain erkannt — App-spezifisches Passwort noetig (appleid.apple.com).',
			'domain'     => $domain,
			'source'     => 'mx-icloud',
			'confidence' => 'high',
		];
		set_transient( $key, $hit, self::TTL_SECONDS );
		return $hit;
	}

	private static function mx_points_to_icloud( string $domain ) : bool {
		if ( ! function_exists( 'dns_get_record' ) ) { return false; }
		$records = @dns_get_record( $domain, DNS_MX );
		if ( ! is_array( $records ) ) { return false; }
		foreach ( $records as $r ) {
			$target = strtolower( rtrim( (string) ( $r['target'] ?? '' ), '.' ) );
			if ( $target === '' ) { continue; }
			// Apple nutzt fuer iCloud+ Custom-Domain mx01/mx02.mail.icloud.com.
			if ( $target === 'mail.icloud.com' || str_ends_with( $target, '.mail.icloud.com' ) ) {
				return true;
			}
		}
		return false;
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
