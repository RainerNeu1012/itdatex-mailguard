<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap\Autoconfig;

use Itdatex\Mailguard\Antiphish\Client as AntiphishClient;

/**
 * 5. Discovery-Stufe: fragt das antiphish-API-Backend, ob das LLM
 * (GLM-5.2 in der Cloud) die IMAP-Settings einer Domain kennt.
 *
 * Privacy: NUR die Domain wird an die Cloud-LLM uebermittelt — keine
 * Mailadresse, keine Mail-Inhalte. Cache 7 Tage (positiv) / 1h (negativ)
 * via WP-Transient.
 *
 * Greift nach ProviderRegistry/Mozilla/Autodiscover/SRV als letzter
 * Fallback fuer Exoten-Hoster, fuer die niemand SRV-Records gepflegt hat
 * und die nicht in Mozilla-ISPDB stehen.
 */
final class LlmDiscovery {

	private const TTL_SECONDS   = 7 * DAY_IN_SECONDS;
	private const TTL_NEG_SECS  = HOUR_IN_SECONDS;
	private const CACHE_PREFIX  = 'mg_autoconfig_llm_';

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

		$res = AntiphishClient::request_post( '/discover/imap', [ 'domain' => $domain ] );
		if ( is_wp_error( $res ) || empty( $res['body'] ) || ! is_array( $res['body'] ) ) {
			set_transient( $key, '__none__', self::TTL_NEG_SECS );
			return null;
		}
		$body = $res['body'];
		if ( empty( $body['found'] ) ) {
			set_transient( $key, '__none__', self::TTL_NEG_SECS );
			return null;
		}
		$hit = [
			'host'       => (string) $body['host'],
			'port'       => (int)    $body['port'],
			'encryption' => (string) $body['encryption'],
			'note'       => (string) ( $body['note'] ?? '' ),
			'domain'     => $domain,
			'source'     => 'llm',
			'confidence' => 'low',
		];
		set_transient( $key, $hit, self::TTL_SECONDS );
		return $hit;
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
