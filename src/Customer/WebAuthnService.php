<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Customer;

use Itdatex\Mailguard\Installer;
use lbuchs\WebAuthn\WebAuthn;

/**
 * WebAuthn / Passkeys-Service — Wrapper um lbuchs/webauthn.
 *
 * Koexistenz mit TOTP: Customer kann beides haben. Login-Flow:
 *  1. Email+Password
 *  2. Wenn Passkey registriert → auth.webauthn_options liefert Challenge
 *     + allowed credentials, Client ruft navigator.credentials.get()
 *  3. Alternativ TOTP wenn aktiv (gleicher two_fa_code-Path)
 *
 * Registrierung:
 *  1. me.webauthn.register_options → Challenge + User-Entity, Client ruft
 *     navigator.credentials.create()
 *  2. me.webauthn.register_verify → processCreate, persist
 *
 * Challenges liegen 5 Min im Transient, pro Customer eine.
 * RP-ID = Host ohne Port, RP-Name = Site-Name.
 */
final class WebAuthnService {

	public const TRANSIENT_PREFIX_REG   = 'mg_wa_reg_';
	public const TRANSIENT_PREFIX_AUTH  = 'mg_wa_auth_';
	public const CHALLENGE_TTL          = 5 * MINUTE_IN_SECONDS;

	private static function webauthn() : WebAuthn {
		static $instance = null;
		if ( $instance === null ) {
			$rp_id   = self::rp_id();
			$rp_name = (string) get_bloginfo( 'name' );
			// 'none'-Attestation akzeptieren — wir vertrauen Browser-side auth.
			// 4. Param useBase64UrlEncoding=true: ByteBuffer serialisiert zu plain
			// base64url-Strings statt zum legacy '=?BINARY?B?...?=' Wrapper. Unser
			// JS-Client erwartet base64url und decodiert via atob() → ArrayBuffer.
			$instance = new WebAuthn( $rp_name !== '' ? $rp_name : 'MailGuard', $rp_id, [ 'none' ], true );
		}
		return $instance;
	}

	private static function rp_id() : string {
		$url = (string) home_url();
		$host = (string) parse_url( $url, PHP_URL_HOST );
		return $host !== '' ? $host : 'localhost';
	}

	/**
	 * Schritt 1 Registrierung: Options fuer navigator.credentials.create() liefern.
	 * Challenge wird im Transient gespeichert bis zum verify-Call.
	 *
	 * @return array JSON-strukturiert fuer den Client. Der `challenge` ist base64url.
	 */
	public static function register_options( int $customer_id, string $email ) : array {
		$wa = self::webauthn();
		// User-Handle = 32 Byte random. Bleibt stabil per Customer damit mehrere
		// Credentials auf denselben Customer ausgestellt werden koennen, aber Platt-
		// form-Authenticators (zB iCloud-Keychain) nicht das PK ueberschreiben.
		$user_handle = self::get_or_create_user_handle( $customer_id );
		// Existierende Credential-IDs ausschliessen damit User nicht denselben
		// Authenticator doppelt registriert.
		$existing = self::list_credential_ids( $customer_id );
		$exclude_bins = array_map( static fn( $b64 ) => base64_decode( (string) $b64, true ) ?: '', $existing );

		$args = $wa->getCreateArgs(
			$user_handle,       // userId (binary)
			$email,             // userName
			$email,             // displayName
			60,                 // timeout seconds
			false,              // requireResidentKey — false = Server-side credentials (keine Resident-Pflicht)
			'preferred',        // requireUserVerification — biometrie wenn verfuegbar
			null,               // crossPlatformAttachment — null = Platform UND Cross-Platform erlaubt
			$exclude_bins
		);
		// lbuchs returnt StdClass mit `challenge` als ByteBuffer. Der Client erwartet
		// das JSON direkt. Challenge persistieren fuer verify.
		$challenge = $wa->getChallenge()->getBinaryString();
		set_transient( self::TRANSIENT_PREFIX_REG . $customer_id, base64_encode( $challenge ), self::CHALLENGE_TTL );
		return self::prepare_json( $args );
	}

	/**
	 * Schritt 2 Registrierung: Attestation vom Browser verifizieren und persistieren.
	 *
	 * @param int $customer_id
	 * @param string $client_data_json base64url-encoded ClientDataJSON
	 * @param string $attestation_object base64url-encoded AttestationObject
	 * @param string $name Label fuer das Credential ("MacBook Touch ID" o.ae.)
	 * @return array{id:int,name:string}
	 * @throws \RuntimeException
	 */
	public static function register_verify( int $customer_id, string $client_data_json, string $attestation_object, string $name = '' ) : array {
		$challenge_b64 = (string) get_transient( self::TRANSIENT_PREFIX_REG . $customer_id );
		if ( $challenge_b64 === '' ) {
			throw new \RuntimeException( 'no_pending_challenge' );
		}
		$challenge = base64_decode( $challenge_b64, true );
		$wa = self::webauthn();
		$data = $wa->processCreate(
			self::b64url_decode( $client_data_json ),
			self::b64url_decode( $attestation_object ),
			$challenge,
			false,      // requireUserVerification
			true        // requireUserPresent
		);
		delete_transient( self::TRANSIENT_PREFIX_REG . $customer_id );

		// lbuchs liefert `credentialId` als binary, `credentialPublicKey` als binary
		$credential_id_bin = $data->credentialId;
		$public_key_bin    = $data->credentialPublicKey;

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . Installer::TABLE_WEBAUTHN_CREDENTIALS, [
			'customer_id'   => $customer_id,
			'credential_id' => base64_encode( $credential_id_bin ),
			'public_key'    => base64_encode( $public_key_bin ),
			'counter'       => 0,
			'name'          => mb_substr( $name !== '' ? $name : self::default_label(), 0, 100 ),
			'transports'    => '',
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
		] );
		$id = (int) $wpdb->insert_id;
		AuditLog::emit( $customer_id, 'webauthn.register', AuditLog::SEV_WARN, [ 'credential_id' => $id ] );
		return [ 'id' => $id, 'name' => $name !== '' ? $name : self::default_label() ];
	}

	/**
	 * Schritt 1 Login: Options fuer navigator.credentials.get() liefern.
	 * Allowed-Credentials = alle Keys des Customers (per Email identifiziert).
	 * Challenge im Transient pro Email-Hash.
	 */
	public static function login_options( string $email ) : ?array {
		$email = strtolower( trim( $email ) );
		$row = Account::find_by_email( $email );
		if ( ! $row ) { return null; }
		$cid = (int) $row['id'];
		$credential_ids_b64 = self::list_credential_ids( $cid );
		if ( ! $credential_ids_b64 ) { return null; }
		$ids_bin = array_map( static fn( $b64 ) => base64_decode( (string) $b64, true ) ?: '', $credential_ids_b64 );

		$wa = self::webauthn();
		$args = $wa->getGetArgs(
			$ids_bin,
			60,       // timeout
			true,     // allowUsb
			true,     // allowNfc
			true,     // allowBle
			true,     // allowHybrid
			true,     // allowInternal (Platform Authenticators)
			false     // requireUserVerification
		);
		$challenge = $wa->getChallenge()->getBinaryString();
		set_transient( self::TRANSIENT_PREFIX_AUTH . md5( $email ), [ 'challenge' => base64_encode( $challenge ), 'cid' => $cid ], self::CHALLENGE_TTL );
		return self::prepare_json( $args );
	}

	/**
	 * Schritt 2 Login: Assertion vom Browser verifizieren.
	 * Bei Erfolg: Counter in DB bumpen, true zurueckgeben.
	 *
	 * @return bool
	 */
	public static function login_verify(
		string $email,
		string $credential_id_b64url,
		string $client_data_json,
		string $authenticator_data,
		string $signature
	) : bool {
		$email = strtolower( trim( $email ) );
		$pending = get_transient( self::TRANSIENT_PREFIX_AUTH . md5( $email ) );
		if ( ! is_array( $pending ) || empty( $pending['challenge'] ) ) { return false; }
		$challenge = base64_decode( (string) $pending['challenge'], true );
		$expected_cid = (int) $pending['cid'];

		// Credential nachladen
		$cred_bin = self::b64url_decode( $credential_id_b64url );
		global $wpdb;
		$t = $wpdb->prefix . Installer::TABLE_WEBAUTHN_CREDENTIALS;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, customer_id, public_key, counter FROM {$t} WHERE credential_id = %s LIMIT 1",
			base64_encode( $cred_bin )
		), ARRAY_A );
		if ( ! $row || (int) $row['customer_id'] !== $expected_cid ) { return false; }

		$wa = self::webauthn();
		try {
			$ok = $wa->processGet(
				self::b64url_decode( $client_data_json ),
				self::b64url_decode( $authenticator_data ),
				self::b64url_decode( $signature ),
				base64_decode( (string) $row['public_key'], true ),
				$challenge,
				(int) $row['counter'],
				false,   // requireUserVerification
				true     // requireUserPresent
			);
		} catch ( \Throwable $e ) {
			error_log( '[MG webauthn] verify_fail: ' . $e->getMessage() );
			return false;
		}
		if ( ! $ok ) { return false; }

		// Counter aus lbuchs holen und persistieren
		$new_counter = $wa->getSignatureCounter();
		$wpdb->update( $t, [
			'counter'      => $new_counter ?: (int) $row['counter'] + 1,
			'last_used_at' => gmdate( 'Y-m-d H:i:s' ),
		], [ 'id' => (int) $row['id'] ] );

		delete_transient( self::TRANSIENT_PREFIX_AUTH . md5( $email ) );
		return true;
	}

	public static function has_credentials( int $customer_id ) : bool {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}" . Installer::TABLE_WEBAUTHN_CREDENTIALS . " WHERE customer_id = %d",
			$customer_id
		) ) > 0;
	}

	public static function list_for_customer( int $customer_id ) : array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, transports, created_at, last_used_at
			 FROM {$wpdb->prefix}" . Installer::TABLE_WEBAUTHN_CREDENTIALS . "
			 WHERE customer_id = %d ORDER BY created_at DESC",
			$customer_id
		), ARRAY_A );
		return array_map( static function( $r ) {
			$r['id'] = (int) $r['id'];
			return $r;
		}, $rows ?: [] );
	}

	public static function revoke( int $id, int $customer_id ) : bool {
		global $wpdb;
		$n = (int) $wpdb->delete(
			$wpdb->prefix . Installer::TABLE_WEBAUTHN_CREDENTIALS,
			[ 'id' => $id, 'customer_id' => $customer_id ]
		);
		if ( $n > 0 ) {
			AuditLog::emit( $customer_id, 'webauthn.revoke', AuditLog::SEV_WARN, [ 'credential_id' => $id ] );
		}
		return $n > 0;
	}

	private static function list_credential_ids( int $customer_id ) : array {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT credential_id FROM {$wpdb->prefix}" . Installer::TABLE_WEBAUTHN_CREDENTIALS . " WHERE customer_id = %d",
			$customer_id
		) );
		return $rows ?: [];
	}

	private static function get_or_create_user_handle( int $customer_id ) : string {
		$opt_key = 'mg_wa_uh_' . $customer_id;
		$cached = get_option( $opt_key, '' );
		if ( $cached !== '' ) { return base64_decode( (string) $cached, true ); }
		$handle = random_bytes( 32 );
		update_option( $opt_key, base64_encode( $handle ), false );
		return $handle;
	}

	/**
	 * lbuchs returnt StdClass mit ByteBuffer-Instanzen. Diese muessen zu
	 * base64url-Strings konvertiert werden damit sie als JSON an den Browser
	 * koennen. navigator.credentials erwartet ArrayBuffer — der Client-JS-Code
	 * muss base64url → ArrayBuffer selbst decoden.
	 */
	private static function prepare_json( $args ) : array {
		return json_decode( json_encode( $args, JSON_UNESCAPED_SLASHES ), true );
	}

	private static function b64url_decode( string $s ) : string {
		$s = str_replace( [ '-', '_' ], [ '+', '/' ], $s );
		$pad = strlen( $s ) % 4;
		if ( $pad ) { $s .= str_repeat( '=', 4 - $pad ); }
		return (string) base64_decode( $s, true );
	}

	private static function default_label() : string {
		$ua = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );
		if ( preg_match( '/(iPhone|iPad|Android|Macintosh|Windows|Linux)/', $ua, $m ) ) {
			return 'Passkey · ' . $m[1];
		}
		return 'Passkey';
	}
}
