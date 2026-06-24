<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\License;

/**
 * Talks zur itdatex-shop License-API. Hartkodierter Endpoint, da der Shop
 * fix dort lebt, wo wir verkaufen.
 */
final class Client {

	public const SHOP_BASE = 'https://wp.itdatex.support/wp-json/itdatex-shop/v1';

	public static function validate( string $key )   : array { return self::call( 'validate',   $key ); }
	public static function activate( string $key )   : array { return self::call( 'activate',   $key ); }
	public static function deactivate( string $key ) : array { return self::call( 'deactivate', $key ); }

	private static function call( string $action, string $key ) : array {
		$key = strtoupper( trim( $key ) );
		if ( $key === '' ) { return [ 'ok' => false, 'error' => 'missing_license_key', 'http' => 0 ]; }
		$res = wp_remote_post( self::SHOP_BASE . '/license/' . $action, [
			'timeout' => 12,
			'body'    => [
				'license_key' => $key,
				'domain'      => self::current_domain(),
			],
		] );
		if ( is_wp_error( $res ) ) {
			return [ 'ok' => false, 'error' => 'network_error', 'detail' => $res->get_error_message(), 'http' => 0 ];
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) ) { return [ 'ok' => false, 'error' => 'bad_response', 'http' => $code ]; }
		$body['http'] = $code;
		return $body;
	}

	public static function current_domain() : string {
		$home = wp_parse_url( home_url() );
		return strtolower( (string) ( $home['host'] ?? '' ) );
	}
}
