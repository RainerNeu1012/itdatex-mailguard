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
		return [ 'ok' => true, 'customer_id' => (int) $row['id'], 'email' => (string) $row['email'] ];
	}

	public static function login( string $email, string $password, string $two_fa_code = '', bool $remember_device = false, ?array $webauthn = null ) : array {
		$rl = RateLimiter::check( RateLimiter::KEY_LOGIN );
		if ( ! $rl['allowed'] ) {
			return [ 'ok' => false, 'error' => self::ERR_RATE, 'retry_after' => $rl['retry_after'] ];
		}
		$email = strtolower( trim( $email ) );
		$row = Account::find_by_email( $email );
		if ( ! $row || ! password_verify( $password, (string) $row['password_hash'] ) ) {
			AuditLog::emit( $row ? (int) $row['id'] : null, 'auth.login_failed', AuditLog::SEV_WARN, [ 'email' => $email ] );
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

		// 2FA-Check: wenn aktiv, braucht der Call einen gueltigen Code — ausser
		// wenn ein gueltiges Trust-Device-Cookie vorliegt (dann skippen) ODER
		// eine erfolgreich verifizierte WebAuthn-Assertion dabei ist.
		$trust_hit = false;
		$totp_active = \Itdatex\Mailguard\Customer\TwoFA::is_enabled_for_customer( $id );
		$webauthn_active = WebAuthnService::has_credentials( $id );

		if ( $totp_active || $webauthn_active ) {
			if ( TrustDevice::verify_for_customer( $id ) ) {
				$trust_hit = true;
			} elseif ( $webauthn !== null && ! empty( $webauthn['credential_id'] ) ) {
				// WebAuthn-Zweitfaktor
				$ok = WebAuthnService::login_verify(
					$email,
					(string) $webauthn['credential_id'],
					(string) ( $webauthn['client_data_json']   ?? '' ),
					(string) ( $webauthn['authenticator_data'] ?? '' ),
					(string) ( $webauthn['signature']          ?? '' )
				);
				if ( ! $ok ) {
					AuditLog::emit( $id, 'auth.webauthn_failed', AuditLog::SEV_WARN );
					return [ 'ok' => false, 'error' => 'webauthn_invalid' ];
				}
			} elseif ( $totp_active && $two_fa_code !== '' ) {
				if ( ! \Itdatex\Mailguard\Customer\TwoFA::verify_login_code( $id, $two_fa_code ) ) {
					AuditLog::emit( $id, 'auth.two_fa_failed', AuditLog::SEV_WARN );
					return [ 'ok' => false, 'error' => 'two_fa_invalid' ];
				}
			} else {
				// Noch kein Zweit-Faktor-Response geliefert. Signal welche Methoden offen stehen.
				return [
					'ok' => false,
					'error' => 'two_fa_required',
					'methods' => [
						'totp'     => $totp_active,
						'webauthn' => $webauthn_active,
					],
				];
			}
		}

		Account::touch_login( $id );
		Session::start( $id );
		$audit_meta = [];
		if ( $trust_hit ) { $audit_meta['trust_device_hit'] = true; }
		if ( $webauthn !== null && ! empty( $webauthn['credential_id'] ) ) { $audit_meta['method'] = 'webauthn'; }
		AuditLog::emit( $id, 'auth.login_success', AuditLog::SEV_INFO, $audit_meta );
		// Trust-Cookie: nur bei fresh factor-step (nicht bei trust_hit), und nur wenn gesetzt
		$fresh_factor = ( $two_fa_code !== '' ) || ( $webauthn !== null && ! empty( $webauthn['credential_id'] ) );
		if ( $remember_device && ! $trust_hit && $fresh_factor ) {
			TrustDevice::issue( $id );
		}

		return [ 'ok' => true, 'customer_id' => $id, 'email' => $row['email'] ];
	}

	public static function logout() : array {
		$cid = Session::current_customer_id();
		Session::destroy();
		if ( $cid > 0 ) {
			AuditLog::emit( $cid, 'auth.logout' );
		}
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
			AuditLog::emit( (int) $row['id'], 'password.reset_request' );
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
		$cid = (int) $row['id'];
		Account::update_password( $cid, password_hash( $new_password, PASSWORD_DEFAULT ) );
		Session::destroy_all_for_customer( $cid );
		AuditLog::emit( $cid, 'password.reset_confirm', AuditLog::SEV_WARN );
		AuditLog::emit( $cid, 'sessions.revoked_all', AuditLog::SEV_WARN, [ 'reason' => 'password_reset' ] );
		return [ 'ok' => true, 'customer_id' => $cid ];
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
		$plan_has_llm   = self::plan_has_llm( (string) ( $row['plan_slug'] ?? 'free' ) );
		$consent_at     = $row['cloud_consent_at'] ? (string) $row['cloud_consent_at'] : null;
		// Bestands-Customer mit llm_enabled=1 aber NULL consent_at brauchen einen
		// Modal-Prompt. Frische Free-/Solo-Pre-LLM-Customer haben plan_has_llm=false
		// und sollen nicht belästigt werden.
		$consent_needed = $plan_has_llm && $consent_at === null;
		return [
			'customer_id'      => (int) $row['id'],
			'email'            => (string) $row['email'],
			'email_verified'   => (int) $row['email_verified'] === 1,
			'status'           => (string) $row['status'],
			'created_at'       => (string) $row['created_at'],
			'last_login_at'    => $row['last_login_at'] ? (string) $row['last_login_at'] : null,
			'plan_slug'        => (string) ( $row['plan_slug'] ?? 'free' ),
			'plan_status'      => (string) ( $row['plan_status'] ?? 'active' ),
			'imap_quota'       => (int) ( $row['imap_quota'] ?? 1 ),
			'llm_enabled'      => (int) ( $row['llm_enabled'] ?? 0 ) === 1,
			'has_stripe_sub'   => ! empty( $row['stripe_subscription_id'] ),
			'plan_grace_until' => $row['plan_grace_until'] ? (string) $row['plan_grace_until'] : null,
			'cloud_consent_at' => $consent_at,
			'cloud_consent_required' => $consent_needed,
			'digest_frequency' => (string) ( $row['digest_frequency'] ?? 'off' ),
			'two_fa_enabled'   => ! empty( $row['two_fa_enabled_at'] ),
			'webauthn_enabled' => WebAuthnService::has_credentials( (int) $row['id'] ),
		];
	}

	/**
	 * Liefert true wenn der angegebene Plan-Slug die Cloud-LLM-Tiefenanalyse
	 * beinhaltet — also vor LLM-Aktivierung eine DSGVO-Einwilligung verlangt.
	 */
	public static function plan_has_llm( string $slug ) : bool {
		$plan = \Itdatex\Mailguard\Saas\Plans::get( $slug );
		return $plan && ! empty( $plan['llm_enabled'] );
	}
}
