<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Oauth;

use Itdatex\Mailguard\Admin\Settings;

/**
 * Google Identity Platform — OAuth 2.0 Authorization Code Flow für Gmail-IMAP.
 *
 * Endpoints:
 *   - Authorize: https://accounts.google.com/o/oauth2/v2/auth
 *   - Token:     https://oauth2.googleapis.com/token
 *
 * Eigenheiten gegenüber Microsoft:
 *
 *  1. Refresh-Token kommt NUR beim ersten Consent zurück, und nur wenn
 *     `access_type=offline` UND `prompt=consent` mitgegeben werden.
 *     Ohne `prompt=consent` re-used Google den bestehenden Grant und
 *     gibt nie wieder ein Refresh-Token raus — Account wäre nach 1h tot.
 *  2. Scope `https://mail.google.com/` ist "restricted" — bedeutet Google
 *     verlangt App Verification für > 100 Test-User. Im Test-Modus limitiert
 *     auf 100 explizit eingetragene Tester.
 *  3. ID-Token (für Email-Extraction) ist nicht garantiert dabei — bei rein
 *     mail-scope-Apps kommt evtl. nur access_token. Fallback: Userinfo-Endpoint
 *     https://openidconnect.googleapis.com/v1/userinfo mit Bearer-Token.
 */
final class GoogleClient {

	private const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	private const USER_URL  = 'https://openidconnect.googleapis.com/v1/userinfo';
	private const SCOPES    = 'https://mail.google.com/ openid email profile';

	public static function is_configured() : bool {
		return self::client_id() !== '' && self::client_secret() !== '';
	}

	public static function client_id() : string {
		return trim( (string) Settings::get( 'oauth_google_client_id', '' ) );
	}

	public static function client_secret() : string {
		return trim( (string) Settings::get( 'oauth_google_client_secret', '' ) );
	}

	public static function redirect_uri() : string {
		return rest_url( 'itdatex-mailguard/v1/oauth/google/callback' );
	}

	public static function authorize_url( string $state, string $login_hint = '' ) : string {
		$params = [
			'client_id'              => self::client_id(),
			'response_type'          => 'code',
			'redirect_uri'           => self::redirect_uri(),
			'scope'                  => self::SCOPES,
			'state'                  => $state,
			'access_type'            => 'offline',
			'prompt'                 => 'consent',
			'include_granted_scopes' => 'true',
		];
		if ( $login_hint !== '' ) {
			$params['login_hint'] = $login_hint;
		}
		return self::AUTH_URL . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	public static function exchange_code( string $code ) : array {
		$res = self::token_request( [
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'redirect_uri'  => self::redirect_uri(),
			'client_id'     => self::client_id(),
			'client_secret' => self::client_secret(),
		] );
		if ( ! empty( $res['ok'] ) && empty( $res['email'] ) ) {
			// id_token enthielt keine Email-Claim — Fallback auf Userinfo-Endpoint
			$res['email'] = self::fetch_email( (string) $res['access_token'] );
		}
		return $res;
	}

	public static function refresh( string $refresh_token ) : array {
		return self::token_request( [
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh_token,
			'client_id'     => self::client_id(),
			'client_secret' => self::client_secret(),
		] );
	}

	private static function token_request( array $body ) : array {
		$res = wp_remote_post( self::TOKEN_URL, [
			'timeout' => 20,
			'headers' => [ 'Accept' => 'application/json' ],
			'body'    => $body,
		] );
		if ( is_wp_error( $res ) ) {
			return [ 'ok' => false, 'error' => 'http_error', 'detail' => $res->get_error_message() ];
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $json ) ) {
			return [ 'ok' => false, 'error' => 'bad_response', 'detail' => 'HTTP ' . $code ];
		}
		if ( $code >= 400 || empty( $json['access_token'] ) ) {
			return [
				'ok'     => false,
				'error'  => (string) ( $json['error'] ?? 'token_error' ),
				'detail' => (string) ( $json['error_description'] ?? ( 'HTTP ' . $code ) ),
			];
		}
		$email = '';
		if ( ! empty( $json['id_token'] ) ) {
			$claims = self::decode_id_token_claims( (string) $json['id_token'] );
			$email  = (string) ( $claims['email'] ?? '' );
		}
		return [
			'ok'            => true,
			'access_token'  => (string) $json['access_token'],
			'refresh_token' => (string) ( $json['refresh_token'] ?? '' ),
			'expires_in'    => (int) ( $json['expires_in'] ?? 3600 ),
			'scope'         => (string) ( $json['scope'] ?? self::SCOPES ),
			'email'         => $email,
		];
	}

	private static function fetch_email( string $access_token ) : string {
		if ( $access_token === '' ) { return ''; }
		$res = wp_remote_get( self::USER_URL, [
			'timeout' => 10,
			'headers' => [
				'Authorization' => 'Bearer ' . $access_token,
				'Accept'        => 'application/json',
			],
		] );
		if ( is_wp_error( $res ) ) { return ''; }
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return is_array( $json ) ? (string) ( $json['email'] ?? '' ) : '';
	}

	private static function decode_id_token_claims( string $jwt ) : array {
		$parts = explode( '.', $jwt );
		if ( count( $parts ) < 2 ) { return []; }
		$pad = strlen( $parts[1] ) % 4;
		if ( $pad > 0 ) { $parts[1] .= str_repeat( '=', 4 - $pad ); }
		$json = base64_decode( strtr( $parts[1], '-_', '+/' ), true );
		if ( $json === false ) { return []; }
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : [];
	}
}
