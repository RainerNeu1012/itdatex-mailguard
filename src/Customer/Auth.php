<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Customer;

use Itdatex\Mailguard\Admin\Settings;

/**
 * Hochlevel-Auth-Flows: Register/Verify/Login/Logout/Forgot/Reset.
 * Liefert strukturierte Arrays mit {ok, error?, customer_id?, status?}.
 * HTTP-Code-Mapping macht der REST-Controller.
 */
final class Auth {

	public const ERR_RATE          = 'rate_limited';
	public const ERR_BAD_INPUT     = 'bad_input';
	public const ERR_DUPLICATE     = 'email_already_registered';
	public const ERR_INVALID_CREDS = 'invalid_credentials';
	public const ERR_UNVERIFIED    = 'email_not_verified';
	public const ERR_SUSPENDED     = 'account_suspended';
	public const ERR_TOKEN         = 'invalid_or_expired_token';

	public static function register( string $email, string $password ) : array {
		if ( ! (int) Settings::get( 'allow_registration', 1 ) ) {
			return [ 'ok' => false, 'error' => 'registration_disabled' ];
		}
		// Pro-Gate: ohne aktive Site-Owner-Lizenz keine neuen Endkunden.
		if ( ! \Itdatex\Mailguard\License\Guard::is_active() ) {
			return [ 'ok' => false, 'error' => 'license_required' ];
		}
		$rl = RateLimiter::check( RateLimiter::KEY_REGISTER );
		if ( ! $rl['allowed'] ) {
			return [ 'ok' => false, 'error' => self::ERR_RATE, 'retry_after' => $rl['retry_after'] ];
		}
		$email = strtolower( trim( $email ) );
		if ( ! is_email( $email ) ) {
			return [ 'ok' => false, 'error' => self::ERR_BAD_INPUT, 'field' => 'email' ];
		}
		if ( strlen( $password ) < 10 ) {
			return [ 'ok' => false, 'error' => self::ERR_BAD_INPUT, 'field' => 'password', 'reason' => 'min_length_10' ];
		}
		if ( Account::find_by_email( $email ) ) {
			return [ 'ok' => false, 'error' => self::ERR_DUPLICATE ];
		}

		$require_verif = (int) Settings::get( 'require_email_verification', 1 ) === 1;
		$verif_token   = $require_verif ? Token::random_token() : null;
		$hash          = password_hash( $password, PASSWORD_DEFAULT );

		$id = Account::create( $email, $hash, $verif_token );
		if ( ! $id ) {
			return [ 'ok' => false, 'error' => 'create_failed' ];
		}

		if ( $require_verif ) {
			Mailer::send_verification( $email, $verif_token );
		}

		return [
			'ok'                  => true,
			'customer_id'         => $id,
			'verification_sent'   => $require_verif,
		];
	}

	public static function verify_email( string $token ) : array {
		if ( strlen( $token ) !== 64 ) {
			return [ 'ok' => false, 'error' => self::ERR_TOKEN ];
		}
		$row = Account::find_by_verification_token( $token );
		if ( ! $row ) {
			return [ 'ok' => false, 'error' => self::ERR_TOKEN ];
		}
		$expires = strtotime( (string) $row['verification_expires'] . ' UTC' );
		if ( $expires && $expires < time() ) {
			return [ 'ok' => false, 'error' => self::ERR_TOKEN ];
		}
		Account::mark_email_verified( (int) $row['id'] );
		return [ 'ok' => true, 'customer_id' => (int) $row['id'] ];
	}

	public static function login( string $email, string $password ) : array {
		$rl = RateLimiter::check( RateLimiter::KEY_LOGIN );
		if ( ! $rl['allowed'] ) {
			return [ 'ok' => false, 'error' => self::ERR_RATE, 'retry_after' => $rl['retry_after'] ];
		}
		$email = strtolower( trim( $email ) );
		$row = Account::find_by_email( $email );
		if ( ! $row || ! password_verify( $password, (string) $row['password_hash'] ) ) {
			return [ 'ok' => false, 'error' => self::ERR_INVALID_CREDS ];
		}
		if ( (string) $row['status'] === 'suspended' ) {
			return [ 'ok' => false, 'error' => self::ERR_SUSPENDED ];
		}
		$require_verif = (int) Settings::get( 'require_email_verification', 1 ) === 1;
		if ( $require_verif && ! (int) $row['email_verified'] ) {
			return [ 'ok' => false, 'error' => self::ERR_UNVERIFIED ];
		}

		$id = (int) $row['id'];
		Account::touch_login( $id );
		Session::start( $id );

		return [ 'ok' => true, 'customer_id' => $id, 'email' => $row['email'] ];
	}

	public static function logout() : array {
		Session::destroy();
		return [ 'ok' => true ];
	}

	public static function forgot_password( string $email ) : array {
		$rl = RateLimiter::check( RateLimiter::KEY_RESET );
		if ( ! $rl['allowed'] ) {
			return [ 'ok' => false, 'error' => self::ERR_RATE, 'retry_after' => $rl['retry_after'] ];
		}
		$email = strtolower( trim( $email ) );
		$row = Account::find_by_email( $email );
		// Bewusst KEINE Info, ob die Adresse existiert (Account-Enumeration verhindern).
		if ( $row ) {
			$token = Token::random_token();
			Account::set_password_reset_token( (int) $row['id'], $token, 60 );
			Mailer::send_password_reset( (string) $row['email'], $token );
		}
		return [ 'ok' => true ];
	}

	public static function reset_password( string $token, string $new_password ) : array {
		if ( strlen( $token ) !== 64 ) {
			return [ 'ok' => false, 'error' => self::ERR_TOKEN ];
		}
		if ( strlen( $new_password ) < 10 ) {
			return [ 'ok' => false, 'error' => self::ERR_BAD_INPUT, 'field' => 'password', 'reason' => 'min_length_10' ];
		}
		$row = Account::find_by_reset_token( $token );
		if ( ! $row ) {
			return [ 'ok' => false, 'error' => self::ERR_TOKEN ];
		}
		$expires = strtotime( (string) $row['password_reset_expires'] . ' UTC' );
		if ( $expires && $expires < time() ) {
			return [ 'ok' => false, 'error' => self::ERR_TOKEN ];
		}
		Account::update_password( (int) $row['id'], password_hash( $new_password, PASSWORD_DEFAULT ) );
		return [ 'ok' => true, 'customer_id' => (int) $row['id'] ];
	}

	public static function current() : ?array {
		$id = Session::current_customer_id();
		if ( $id <= 0 ) {
			return null;
		}
		$row = Account::find_by_id( $id );
		if ( ! $row || (string) $row['status'] === 'suspended' ) {
			return null;
		}
		return [
			'customer_id'    => (int) $row['id'],
			'email'          => (string) $row['email'],
			'email_verified' => (int) $row['email_verified'] === 1,
			'status'         => (string) $row['status'],
			'created_at'     => (string) $row['created_at'],
			'last_login_at'  => $row['last_login_at'] ? (string) $row['last_login_at'] : null,
		];
	}
}
