<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish;

use Itdatex\Mailguard\Admin\Settings;
use WP_Error;

/**
 * Thin HTTP-Client gegen die antiphish-API (mailsec.itdatex.support).
 * Settings: antiphish_api_url + antiphish_api_key (Header X-API-Key).
 */
final class Client {

	public static function scan_url( string $url, int $timeout = 12 ) {
		return self::request( '/scan/url', [ 'url' => $url ], $timeout );
	}

	public static function scan_email( array $payload, int $timeout = 30 ) {
		return self::request( '/scan/email', $payload, $timeout );
	}

	public static function unsub_extract( array $payload, int $timeout = 12 ) {
		return self::request( '/unsubscribe/extract', $payload, $timeout );
	}

	public static function unsub_execute( array $payload, int $timeout = 30 ) {
		return self::request( '/unsubscribe/execute', $payload, $timeout );
	}

	public static function request_get( string $path, int $timeout = 8 ) {
		$base = rtrim( (string) Settings::get( 'antiphish_api_url', '' ), '/' );
		$key  = (string) Settings::get( 'antiphish_api_key', '' );
		if ( $base === '' || $key === '' ) {
			return new WP_Error( 'no_api_credentials', __( 'Antiphish-API nicht konfiguriert.', 'itdatex-mailguard' ) );
		}
		$res = wp_remote_get( $base . $path, [
			'timeout' => $timeout,
			'headers' => [ 'Accept' => 'application/json', 'X-API-Key' => $key ],
		] );
		if ( is_wp_error( $res ) ) { return $res; }
		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );
		$json = json_decode( $raw, true );
		return [ 'status' => $code, 'body' => $json !== null ? $json : $raw ];
	}

	private static function request( string $path, array $body, int $timeout ) {
		$base = rtrim( (string) Settings::get( 'antiphish_api_url', '' ), '/' );
		$key  = (string) Settings::get( 'antiphish_api_key', '' );
		if ( $base === '' ) {
			return new WP_Error( 'no_api_url', __( 'Antiphish-API-URL nicht konfiguriert.', 'itdatex-mailguard' ) );
		}
		if ( $key === '' ) {
			return new WP_Error( 'no_api_key', __( 'Antiphish-API-Key nicht konfiguriert.', 'itdatex-mailguard' ) );
		}

		$res = wp_remote_post( $base . $path, [
			'timeout' => $timeout,
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'X-API-Key'    => $key,
			],
			'body' => wp_json_encode( $body ),
		] );

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );
		$json = json_decode( $raw, true );
		return [
			'status' => $code,
			'body'   => $json !== null ? $json : $raw,
		];
	}
}
