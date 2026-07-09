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

	public static function unsub_extract( array $payload, int $timeout = 10 ) {
		// Extract ist read-only → immer retry-fähig.
		return self::request( '/unsubscribe/extract', $payload, $timeout, true );
	}

	/**
	 * Execute für http one-click ist per RFC 8058 idempotent, für mailto NICHT
	 * (jeder Aufruf sendet eine Mail). Deshalb: Retry nur, wenn Aufrufer sicher
	 * ist. UnsubService entscheidet pro Kandidat.
	 */
	public static function unsub_execute( array $payload, int $timeout = 20, bool $allow_retry = false ) {
		return self::request( '/unsubscribe/execute', $payload, $timeout, $allow_retry );
	}

	public static function request_get( string $path, int $timeout = 8 ) {
		$base = rtrim( (string) Settings::get( 'antiphish_api_url', '' ), '/' );
		$key  = (string) Settings::get( 'antiphish_api_key', '' );
		if ( $base === '' || $key === '' ) {
			return new WP_Error( 'no_api_credentials', __( 'Antiphish-API nicht konfiguriert.', 'itdatex-mailguard' ) );
		}
		// GET ist idempotent → immer retryable.
		return self::do_request( 'GET', $base . $path, $key, null, $timeout, true );
	}

	/** Generischer POST-Helper (analog request_get). */
	public static function request_post( string $path, array $body, int $timeout = 25 ) {
		return self::request( $path, $body, $timeout );
	}

	private static function request( string $path, array $body, int $timeout, bool $allow_retry = false ) {
		$base = rtrim( (string) Settings::get( 'antiphish_api_url', '' ), '/' );
		$key  = (string) Settings::get( 'antiphish_api_key', '' );
		if ( $base === '' ) {
			return new WP_Error( 'no_api_url', __( 'Antiphish-API-URL nicht konfiguriert.', 'itdatex-mailguard' ) );
		}
		if ( $key === '' ) {
			return new WP_Error( 'no_api_key', __( 'Antiphish-API-Key nicht konfiguriert.', 'itdatex-mailguard' ) );
		}
		return self::do_request( 'POST', $base . $path, $key, wp_json_encode( $body ), $timeout, $allow_retry );
	}

	/**
	 * Führt HTTP aus, mit optionalem einmaligem Retry bei transienten Fehlern:
	 * WP-Error (Netz/Timeout), HTTP 429, HTTP 5xx.
	 *
	 * Ein Retry — mehr fressen wir nicht, sonst reißen wir das FPM-Budget für
	 * die Nutzerseite. Backoff 400ms damit ein hakelnder Upstream einatmen
	 * kann, ohne dass es wie ein UI-Freeze wirkt.
	 */
	private static function do_request( string $method, string $url, string $key, ?string $body, int $timeout, bool $allow_retry ) {
		$args = [
			'timeout' => $timeout,
			'headers' => [
				'Accept'    => 'application/json',
				'X-API-Key' => $key,
			],
		];
		if ( $body !== null ) {
			$args['body'] = $body;
			$args['headers']['Content-Type'] = 'application/json';
		}

		$attempts = $allow_retry ? 2 : 1;
		$last = null;
		for ( $i = 0; $i < $attempts; $i++ ) {
			$res = $method === 'GET'
				? wp_remote_get( $url, $args )
				: wp_remote_post( $url, $args );
			$last = $res;

			if ( is_wp_error( $res ) ) {
				if ( $i + 1 < $attempts ) { usleep( 400_000 ); continue; }
				return $res;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( ( $code === 429 || $code >= 500 ) && $i + 1 < $attempts ) {
				usleep( 400_000 );
				continue;
			}
			$raw  = (string) wp_remote_retrieve_body( $res );
			$json = json_decode( $raw, true );
			return [ 'status' => $code, 'body' => $json !== null ? $json : $raw ];
		}
		// Erreichbar nur, wenn Schleife ohne return endete (dürfte nicht passieren).
		if ( is_wp_error( $last ) ) { return $last; }
		$code = (int) wp_remote_retrieve_response_code( $last );
		$raw  = (string) wp_remote_retrieve_body( $last );
		$json = json_decode( $raw, true );
		return [ 'status' => $code, 'body' => $json !== null ? $json : $raw ];
	}
}
