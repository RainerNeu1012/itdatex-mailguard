<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Notify;

use Itdatex\Mailguard\Admin\Settings;

/**
 * FCM v1 HTTP API-Client fuer Push-Notifications.
 *
 * Ein einziger Serviceaccount deckt Android + iOS (APNs) + Web — deshalb
 * FCM v1 statt getrenntes APNs-Setup. Der Serviceaccount-JSON wird im
 * Admin-Panel eingetragen (push_fcm_service_account) und liefert
 * project_id + private_key + client_email.
 *
 * Signatur-Flow:
 *  1. JWT signieren mit dem RSA-Private-Key (openssl_sign).
 *  2. JWT gegen https://oauth2.googleapis.com/token austauschen → access_token
 *     (55 min gueltig, cached als Transient).
 *  3. POST an FCM v1 mit Bearer-Header + Message-Payload.
 *
 * Wenn kein Serviceaccount konfiguriert ist, sind alle notify_*-Aufrufe
 * silent No-Ops → das Backend kann ohne FCM-Setup deployen und der
 * Site-Owner konfiguriert Push spaeter.
 */
final class PushService {

	private const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
	private const FCM_SEND_URL    = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';
	private const OAUTH_SCOPE     = 'https://www.googleapis.com/auth/firebase.messaging';
	private const TOKEN_TRANSIENT = 'mg_fcm_access_token';

	public static function is_configured() : bool {
		$creds = self::credentials();
		return $creds !== null;
	}

	/**
	 * Sendet an alle Devices des Customers, die auf $event_bit hoeren.
	 *
	 * @param array{title:string,body:string,deep_link?:string,data?:array<string,string>} $payload
	 * @return array{sent:int,failed:int,skipped:int}
	 */
	public static function notify_customer( int $customer_id, int $event_bit, array $payload ) : array {
		$out = [ 'sent' => 0, 'failed' => 0, 'skipped' => 0 ];
		if ( ! self::is_configured() ) { $out['skipped']++; return $out; }

		$devices = Device::for_customer_event( $customer_id, $event_bit );
		if ( ! $devices ) { return $out; }

		$access = self::access_token();
		if ( ! $access ) { $out['skipped'] = count( $devices ); return $out; }
		$creds  = self::credentials();
		$url    = sprintf( self::FCM_SEND_URL, $creds['project_id'] );

		foreach ( $devices as $d ) {
			$msg = self::build_message( (string) $d['fcm_token'], (string) $d['platform'], $payload );
			$res = wp_remote_post( $url, [
				'timeout' => 8,
				'headers' => [
					'Authorization' => 'Bearer ' . $access,
					'Content-Type'  => 'application/json; charset=UTF-8',
				],
				'body'    => wp_json_encode( [ 'message' => $msg ] ),
			] );
			if ( is_wp_error( $res ) ) { $out['failed']++; continue; }
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( $code >= 200 && $code < 300 ) {
				$out['sent']++;
				continue;
			}
			// Unregistered → Device aus DB entfernen (FCM verwirft's ohnehin).
			if ( $code === 404 || $code === 410 ) {
				Device::delete_by_token( (string) $d['fcm_token'] );
			}
			$out['failed']++;
		}
		return $out;
	}

	private static function build_message( string $fcm_token, string $platform, array $payload ) : array {
		$data = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : [];
		if ( ! empty( $payload['deep_link'] ) ) {
			$data['deep_link'] = (string) $payload['deep_link'];
		}
		// FCM data-values muessen Strings sein.
		$data = array_map( 'strval', $data );

		$msg = [
			'token'        => $fcm_token,
			'notification' => [
				'title' => (string) $payload['title'],
				'body'  => (string) $payload['body'],
			],
			'data'         => $data,
		];
		// iOS-spezifisch: aps.sound damit die App auch klingelt.
		if ( $platform === 'ios' ) {
			$msg['apns'] = [
				'payload' => [
					'aps' => [ 'sound' => 'default', 'badge' => 1 ],
				],
			];
		}
		return $msg;
	}

	private static function access_token() : ?string {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && $cached !== '' ) { return $cached; }

		$creds = self::credentials();
		if ( ! $creds ) { return null; }

		$now = time();
		$jwt = self::sign_jwt( [
			'iss'   => $creds['client_email'],
			'scope' => self::OAUTH_SCOPE,
			'aud'   => self::OAUTH_TOKEN_URL,
			'iat'   => $now,
			'exp'   => $now + 3600,
		], $creds['private_key'] );
		if ( ! $jwt ) { return null; }

		$res = wp_remote_post( self::OAUTH_TOKEN_URL, [
			'timeout' => 8,
			'body'    => [
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			],
		] );
		if ( is_wp_error( $res ) ) { return null; }
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) || empty( $body['access_token'] ) ) { return null; }
		// Google-Token ist 3600s gueltig — wir cachen 3300s (Sicherheitspuffer).
		set_transient( self::TOKEN_TRANSIENT, (string) $body['access_token'], 3300 );
		return (string) $body['access_token'];
	}

	private static function sign_jwt( array $claims, string $private_key_pem ) : ?string {
		$header  = self::b64url( (string) wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
		$payload = self::b64url( (string) wp_json_encode( $claims ) );
		$signing_input = $header . '.' . $payload;
		$signature = '';
		$ok = @openssl_sign( $signing_input, $signature, $private_key_pem, OPENSSL_ALGO_SHA256 );
		if ( ! $ok ) { return null; }
		return $signing_input . '.' . self::b64url( $signature );
	}

	private static function b64url( string $bin ) : string {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	/**
	 * @return array{project_id:string,client_email:string,private_key:string}|null
	 */
	private static function credentials() : ?array {
		$json = trim( (string) Settings::get( 'push_fcm_service_account', '' ) );
		if ( $json === '' ) { return null; }
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) { return null; }
		$pid = (string) ( $data['project_id'] ?? Settings::get( 'push_fcm_project_id', '' ) );
		$email = (string) ( $data['client_email'] ?? '' );
		$key   = (string) ( $data['private_key'] ?? '' );
		if ( $pid === '' || $email === '' || $key === '' ) { return null; }
		return [
			'project_id'   => $pid,
			'client_email' => $email,
			'private_key'  => $key,
		];
	}
}
