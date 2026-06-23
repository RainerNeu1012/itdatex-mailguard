<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Rest;

use Itdatex\Mailguard\Customer\Auth;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

final class Controller {

	public const NAMESPACE = 'itdatex-mailguard/v1';

	public static function register() : void {
		register_rest_route( self::NAMESPACE, '/register', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'register_customer' ],
		] );

		register_rest_route( self::NAMESPACE, '/login', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'login' ],
		] );

		register_rest_route( self::NAMESPACE, '/logout', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'logout' ],
		] );

		register_rest_route( self::NAMESPACE, '/verify-email', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'verify_email' ],
		] );

		register_rest_route( self::NAMESPACE, '/forgot-password', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'forgot_password' ],
		] );

		register_rest_route( self::NAMESPACE, '/reset-password', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'reset_password' ],
		] );

		register_rest_route( self::NAMESPACE, '/me', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'me' ],
		] );
	}

	public static function register_customer( WP_REST_Request $req ) {
		$json     = (array) $req->get_json_params();
		$email    = (string) ( $json['email'] ?? '' );
		$password = (string) ( $json['password'] ?? '' );
		$res = Auth::register( $email, $password );
		return self::respond( $res );
	}

	public static function login( WP_REST_Request $req ) {
		$json     = (array) $req->get_json_params();
		$email    = (string) ( $json['email'] ?? '' );
		$password = (string) ( $json['password'] ?? '' );
		$res = Auth::login( $email, $password );
		return self::respond( $res );
	}

	public static function logout( WP_REST_Request $req ) {
		return self::respond( Auth::logout() );
	}

	public static function verify_email( WP_REST_Request $req ) {
		$json  = (array) $req->get_json_params();
		$token = (string) ( $json['token'] ?? '' );
		return self::respond( Auth::verify_email( $token ) );
	}

	public static function forgot_password( WP_REST_Request $req ) {
		$json  = (array) $req->get_json_params();
		$email = (string) ( $json['email'] ?? '' );
		return self::respond( Auth::forgot_password( $email ) );
	}

	public static function reset_password( WP_REST_Request $req ) {
		$json     = (array) $req->get_json_params();
		$token    = (string) ( $json['token'] ?? '' );
		$password = (string) ( $json['password'] ?? '' );
		return self::respond( Auth::reset_password( $token, $password ) );
	}

	public static function me( WP_REST_Request $req ) {
		$me = Auth::current();
		if ( ! $me ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'not_authenticated' ], 401 );
		}
		return new WP_REST_Response( [ 'ok' => true, 'customer' => $me ], 200 );
	}

	private static function respond( array $res ) : WP_REST_Response {
		$status = self::status_for( $res );
		$resp = new WP_REST_Response( $res, $status );
		if ( $status === 429 && isset( $res['retry_after'] ) ) {
			$resp->header( 'Retry-After', (string) (int) $res['retry_after'] );
		}
		return $resp;
	}

	private static function status_for( array $res ) : int {
		if ( ! empty( $res['ok'] ) ) {
			return 200;
		}
		return match ( $res['error'] ?? '' ) {
			Auth::ERR_RATE                                 => 429,
			Auth::ERR_DUPLICATE                            => 409,
			Auth::ERR_INVALID_CREDS, Auth::ERR_UNVERIFIED  => 401,
			Auth::ERR_SUSPENDED                            => 403,
			Auth::ERR_TOKEN                                => 410,
			Auth::ERR_BAD_INPUT, 'registration_disabled', 'create_failed' => 400,
			default                                        => 500,
		};
	}
}
