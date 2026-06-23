<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Rest;

use Itdatex\Mailguard\Customer\Auth;
use Itdatex\Mailguard\Imap\Account as ImapAccount;
use Itdatex\Mailguard\Imap\Crypto as ImapCrypto;
use Itdatex\Mailguard\Imap\ImapClient;
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

		register_rest_route( self::NAMESPACE, '/accounts', [
			[
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => [ __CLASS__, 'accounts_list' ],
			],
			[
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => [ __CLASS__, 'accounts_create' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/accounts/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => [ __CLASS__, 'accounts_get' ],
			],
			[
				'methods'             => 'PATCH',
				'permission_callback' => '__return_true',
				'callback'            => [ __CLASS__, 'accounts_update' ],
			],
			[
				'methods'             => 'DELETE',
				'permission_callback' => '__return_true',
				'callback'            => [ __CLASS__, 'accounts_delete' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/accounts/(?P<id>\d+)/test', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'accounts_test' ],
		] );
	}

	/**
	 * Cookie-basierter Customer-Auth-Check. Liefert customer_id oder WP_Error 401.
	 */
	private static function require_customer() {
		$me = Auth::current();
		if ( ! $me ) {
			return new WP_Error( 'not_authenticated', __( 'Anmeldung erforderlich.', 'itdatex-mailguard' ), [ 'status' => 401 ] );
		}
		return (int) $me['customer_id'];
	}

	public static function accounts_list( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		return new WP_REST_Response( [
			'ok'    => true,
			'items' => ImapAccount::list_for_customer( $cid ),
		], 200 );
	}

	public static function accounts_get( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$row = ImapAccount::find_for_customer( (int) $req['id'], $cid );
		if ( ! $row ) { return new WP_Error( 'not_found', '', [ 'status' => 404 ] ); }
		return new WP_REST_Response( [ 'ok' => true, 'item' => ImapAccount::public_view( $row ) ], 200 );
	}

	public static function accounts_create( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$json = (array) $req->get_json_params();
		$err = self::validate_account_payload( $json, true );
		if ( $err ) { return $err; }
		$id = ImapAccount::create( $cid, $json );
		if ( ! $id ) { return new WP_Error( 'create_failed', __( 'Anlegen fehlgeschlagen.', 'itdatex-mailguard' ), [ 'status' => 500 ] ); }
		$row = ImapAccount::find_for_customer( $id, $cid );
		return new WP_REST_Response( [ 'ok' => true, 'item' => ImapAccount::public_view( $row ) ], 201 );
	}

	public static function accounts_update( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$id  = (int) $req['id'];
		$row = ImapAccount::find_for_customer( $id, $cid );
		if ( ! $row ) { return new WP_Error( 'not_found', '', [ 'status' => 404 ] ); }
		$json = (array) $req->get_json_params();
		$err = self::validate_account_payload( $json, false );
		if ( $err ) { return $err; }
		ImapAccount::update( $id, $cid, $json );
		$row = ImapAccount::find_for_customer( $id, $cid );
		return new WP_REST_Response( [ 'ok' => true, 'item' => ImapAccount::public_view( $row ) ], 200 );
	}

	public static function accounts_delete( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$id  = (int) $req['id'];
		if ( ! ImapAccount::find_for_customer( $id, $cid ) ) {
			return new WP_Error( 'not_found', '', [ 'status' => 404 ] );
		}
		ImapAccount::delete( $id, $cid );
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public static function accounts_test( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$id  = (int) $req['id'];
		$row = ImapAccount::find_for_customer( $id, $cid );
		if ( ! $row ) { return new WP_Error( 'not_found', '', [ 'status' => 404 ] ); }
		$plain = ImapCrypto::decrypt( (string) $row['password_enc'] );
		if ( $plain === '' ) {
			ImapAccount::record_test( $id, $cid, false, 'no_password_stored' );
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'no_password_stored' ], 400 );
		}
		$client = new ImapClient(
			(string) $row['host'],
			(int)    $row['port'],
			(string) $row['encryption'],
			(string) $row['folder'],
			(string) $row['username'],
			$plain
		);
		try {
			$probe = $client->probe();
			ImapAccount::record_test( $id, $cid, true, 'ok · messages=' . (int) $probe['messages'] );
			return new WP_REST_Response( [ 'ok' => true, 'probe' => $probe ], 200 );
		} catch ( \Throwable $e ) {
			ImapAccount::record_test( $id, $cid, false, $e->getMessage() );
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'connect_failed', 'detail' => $e->getMessage() ], 200 );
		}
	}

	private static function validate_account_payload( array $json, bool $is_create ) {
		if ( $is_create ) {
			foreach ( [ 'host', 'username', 'password' ] as $k ) {
				if ( empty( $json[ $k ] ) ) {
					return new WP_Error( 'bad_input', sprintf( __( 'Feld %s ist erforderlich.', 'itdatex-mailguard' ), $k ), [ 'status' => 400 ] );
				}
			}
		}
		if ( isset( $json['encryption'] ) && ! in_array( $json['encryption'], [ 'ssl', 'tls', 'none' ], true ) ) {
			return new WP_Error( 'bad_input', __( 'Ungueltige Encryption.', 'itdatex-mailguard' ), [ 'status' => 400 ] );
		}
		if ( isset( $json['port'] ) ) {
			$p = (int) $json['port'];
			if ( $p < 1 || $p > 65535 ) {
				return new WP_Error( 'bad_input', __( 'Ungueltiger Port.', 'itdatex-mailguard' ), [ 'status' => 400 ] );
			}
		}
		return null;
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
