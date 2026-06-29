<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Oauth;

use Itdatex\Mailguard\Admin\Settings;

/**
 * Microsoft Identity Platform v2.0 — Authorization Code Flow + Refresh.
 *
 * Endpoints: https://login.microsoftonline.com/{tenant}/oauth2/v2.0/{authorize,token}
 *
 * Tenant 'common' erlaubt sowohl Business-Tenants als auch persoenliche
 * Microsoft-Konten (outlook.com / hotmail / live / Microsoft 365 Family
 * mit eigener Domain). Fuer reine Tenant-Apps statt 'common' die Tenant-ID
 * eintragen.
 */
final class MicrosoftClient {

	private const AUTH_BASE  = 'https://login.microsoftonline.com';
	private const SCOPES     = 'offline_access https://outlook.office.com/IMAP.AccessAsUser.All openid email profile';

	public static function is_configured() : bool {
		return self::client_id() !== '' && self::client_secret() !== '';
	}

	public static function client_id() : string {
		return trim( (string) Settings::get( 'oauth_microsoft_client_id', '' ) );
	}

	public static function client_secret() : string {
		return trim( (string) Settings::get( 'oauth_microsoft_client_secret', '' ) );
	}

	public static function tenant() : string {
		$t = trim( (string) Settings::get( 'oauth_microsoft_tenant', 'common' ) );
		return $t === '' ? 'common' : $t;
	}

	public static function redirect_uri() : string {
		return rest_url( 'itdatex-mailguard/v1/oauth/microsoft/callback' );
	}

	/**
	 * Browser-Redirect-URL fuer den Consent-Schritt.
	 */
	public static function authorize_url( string $state, string $login_hint = '' ) : string {
		$params = [
			'client_id'     => self::client_id(),
			'response_type' => 'code',
			'redirect_uri'  => self::redirect_uri(),
			'response_mode' => 'query',
			'scope'         => self::SCOPES,
			'state'         => $state,
			'prompt'        => 'select_account',
		];
		if ( $login_hint !== '' ) {
			$params['login_hint'] = $login_hint;
		}
		return self::AUTH_BASE . '/' . rawurlencode( self::tenant() ) . '/oauth2/v2.0/authorize?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Code -> access_token + refresh_token + id_token-claims.
	 *
	 * @return array{ok:bool,access_token?:string,refresh_token?:string,expires_in?:int,scope?:string,email?:string,error?:string,detail?:string}
	 */
	public static function exchange_code( string $code ) : array {
		return self::token_request( [
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'redirect_uri'  => self::redirect_uri(),
			'client_id'     => self::client_id(),
			'client_secret' => self::client_secret(),
			'scope'         => self::SCOPES,
		] );
	}

	public static function refresh( string $refresh_token ) : array {
		return self::token_request( [
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh_token,
			'client_id'     => self::client_id(),
			'client_secret' => self::client_secret(),
			'scope'         => self::SCOPES,
		] );
	}

	private static function token_request( array $body ) : array {
		$url = self::AUTH_BASE . '/' . rawurlencode( self::tenant() ) . '/oauth2/v2.0/token';
		$res = wp_remote_post( $url, [
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
			$email  = (string) ( $claims['email'] ?? $claims['preferred_username'] ?? $claims['upn'] ?? '' );
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

	/**
	 * Parst id_token-Claims OHNE Signaturpruefung — wir vertrauen dem TLS-Kanal
	 * zu login.microsoftonline.com. Wir nutzen die Claims nur fuer den Email-Hint;
	 * Token-Authentizitaet steckt im access_token gegen Outlook-IMAP.
	 */
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
