<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Customer;

use Itdatex\Mailguard\Installer;

/**
 * TOTP-basiertes 2FA nach RFC 6238 (kompatibel mit Google Authenticator, Authy,
 * 1Password, Bitwarden etc.). Reine PHP-Implementierung, keine Composer-
 * Abhaengigkeit — Secret = Base32-String, Hash via hash_hmac(SHA1).
 *
 * Flow:
 *   1. POST /me/2fa/setup  → Server generiert Secret, gibt otpauth:// URL + QR-URL
 *   2. User scant QR in Authenticator-App
 *   3. POST /me/2fa/activate {code} → Server verifiziert, speichert enabled_at
 *   4. Login: wenn two_fa_enabled_at gesetzt, braucht Login zusaetzlich 'code'-Feld
 *   5. POST /me/2fa/disable {code} → Secret loeschen
 *
 * Backup-Codes: 10 x 8-stellige One-Time-Codes werden bei activate generiert.
 * Werden nach Nutzung als "used" markiert (removed aus JSON-Array).
 */
final class TwoFA {

	private const TIME_STEP = 30;
	private const DIGITS    = 6;
	private const WINDOW    = 1; // ±30s Toleranz

	public static function generate_secret() : string {
		// 20 bytes = 160 bit, als Base32 → 32 chars (die authenticator-Apps lieben)
		$bytes  = random_bytes( 20 );
		return self::base32_encode( $bytes );
	}

	public static function generate_backup_codes( int $count = 10 ) : array {
		$codes = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$codes[] = sprintf( '%08d', random_int( 10000000, 99999999 ) );
		}
		return $codes;
	}

	public static function otpauth_uri( string $email, string $secret, string $issuer = 'MailGuard' ) : string {
		$label = rawurlencode( $issuer . ':' . $email );
		return 'otpauth://totp/' . $label
			. '?secret=' . rawurlencode( $secret )
			. '&issuer=' . rawurlencode( $issuer )
			. '&digits=' . self::DIGITS
			. '&period=' . self::TIME_STEP
			. '&algorithm=SHA1';
	}

	public static function qr_url( string $otpauth ) : string {
		// Kostenloser Public-QR-Generator (kein Key noetig)
		return 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&margin=4&data='
			. rawurlencode( $otpauth );
	}

	public static function verify_code( string $secret, string $code ) : bool {
		$code = preg_replace( '/\D/', '', $code );
		if ( strlen( $code ) !== self::DIGITS ) { return false; }
		$now = time();
		for ( $w = -self::WINDOW; $w <= self::WINDOW; $w++ ) {
			$ts = (int) floor( $now / self::TIME_STEP ) + $w;
			if ( hash_equals( self::totp_at( $secret, $ts ), $code ) ) { return true; }
		}
		return false;
	}

	public static function enable_for_customer( int $customer_id, string $secret ) : array {
		$backup = self::generate_backup_codes();
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . Installer::TABLE_CUSTOMERS,
			[
				'two_fa_secret'       => $secret,
				'two_fa_enabled_at'   => gmdate( 'Y-m-d H:i:s' ),
				'two_fa_backup_codes' => wp_json_encode( $backup ),
			],
			[ 'id' => $customer_id ]
		);
		return $backup;
	}

	public static function disable_for_customer( int $customer_id ) : void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . Installer::TABLE_CUSTOMERS,
			[ 'two_fa_secret' => null, 'two_fa_enabled_at' => null, 'two_fa_backup_codes' => null ],
			[ 'id' => $customer_id ]
		);
	}

	/**
	 * Prueft TOTP-Code oder Backup-Code fuer Customer beim Login.
	 * Verbrauchte Backup-Codes werden entfernt.
	 */
	public static function verify_login_code( int $customer_id, string $code ) : bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT two_fa_secret, two_fa_backup_codes FROM {$wpdb->prefix}mg_customers WHERE id = %d",
			$customer_id
		), ARRAY_A );
		if ( ! $row || empty( $row['two_fa_secret'] ) ) { return true; } // 2FA nicht aktiv

		if ( self::verify_code( (string) $row['two_fa_secret'], $code ) ) { return true; }

		// Backup-Codes
		$backup = json_decode( (string) ( $row['two_fa_backup_codes'] ?? '[]' ), true );
		if ( ! is_array( $backup ) ) { return false; }
		$needle = preg_replace( '/\D/', '', $code );
		$key = array_search( $needle, $backup, true );
		if ( $key === false ) { return false; }
		// Verbraucht → entfernen + persistieren
		unset( $backup[ $key ] );
		$wpdb->update(
			$wpdb->prefix . Installer::TABLE_CUSTOMERS,
			[ 'two_fa_backup_codes' => wp_json_encode( array_values( $backup ) ) ],
			[ 'id' => $customer_id ]
		);
		return true;
	}

	public static function is_enabled_for_customer( int $customer_id ) : bool {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare(
			"SELECT two_fa_enabled_at FROM {$wpdb->prefix}mg_customers WHERE id = %d",
			$customer_id
		) );
		return $v !== null && $v !== '';
	}

	/**
	 * "2FA verloren" — Flow Schritt 1: Code per Mail an die hinterlegte Login-Adresse schicken.
	 * Gibt immer true zurueck (keine Account-Enumeration). Rate-Limit kommt vom Controller.
	 * Code = 6 Digits, Transient 15 Min TTL. Nur gesendet wenn Customer existiert UND 2FA aktiv.
	 */
	public static function request_reset( string $email ) : void {
		$row = Account::find_by_email( strtolower( trim( $email ) ) );
		if ( ! $row ) { return; }
		$cid = (int) $row['id'];
		if ( ! self::is_enabled_for_customer( $cid ) ) { return; }
		$code = sprintf( '%06d', random_int( 0, 999999 ) );
		set_transient( 'mg_2fa_reset_' . $cid, $code, 15 * MINUTE_IN_SECONDS );
		Mailer::send_2fa_reset( (string) $row['email'], $code );
	}

	/**
	 * "2FA verloren" — Flow Schritt 2: Code aus Mail + Email pruefen, 2FA ausschalten.
	 * Rueckgabe false = Code falsch/abgelaufen oder Email unbekannt.
	 */
	public static function confirm_reset( string $email, string $code ) : bool {
		$row = Account::find_by_email( strtolower( trim( $email ) ) );
		if ( ! $row ) { return false; }
		$cid = (int) $row['id'];
		$stored = (string) get_transient( 'mg_2fa_reset_' . $cid );
		$code   = preg_replace( '/\D/', '', $code );
		if ( $stored === '' || strlen( $code ) !== 6 ) { return false; }
		if ( ! hash_equals( $stored, $code ) ) { return false; }
		delete_transient( 'mg_2fa_reset_' . $cid );
		self::disable_for_customer( $cid );
		return true;
	}

	// --- Internal helpers ---

	private static function totp_at( string $base32_secret, int $time_slice ) : string {
		$key = self::base32_decode( $base32_secret );
		if ( $key === '' ) { return ''; }
		$binary_time = pack( 'N*', 0, $time_slice );
		$hash = hash_hmac( 'sha1', $binary_time, $key, true );
		$offset = ord( $hash[ strlen( $hash ) - 1 ] ) & 0x0F;
		$value = ( ( ord( $hash[ $offset ] )     & 0x7F ) << 24 )
		       | ( ( ord( $hash[ $offset + 1 ] ) & 0xFF ) << 16 )
		       | ( ( ord( $hash[ $offset + 2 ] ) & 0xFF ) << 8 )
		       | (   ord( $hash[ $offset + 3 ] ) & 0xFF );
		$code = $value % ( 10 ** self::DIGITS );
		return str_pad( (string) $code, self::DIGITS, '0', STR_PAD_LEFT );
	}

	private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	private static function base32_encode( string $bytes ) : string {
		$bin = '';
		for ( $i = 0; $i < strlen( $bytes ); $i++ ) {
			$bin .= str_pad( decbin( ord( $bytes[ $i ] ) ), 8, '0', STR_PAD_LEFT );
		}
		$chunks = str_split( $bin, 5 );
		$out = '';
		foreach ( $chunks as $c ) {
			$out .= self::B32[ bindec( str_pad( $c, 5, '0', STR_PAD_RIGHT ) ) ];
		}
		return $out;
	}

	private static function base32_decode( string $s ) : string {
		$s = strtoupper( preg_replace( '/[^A-Z2-7]/', '', $s ) );
		if ( $s === '' ) { return ''; }
		$bin = '';
		for ( $i = 0; $i < strlen( $s ); $i++ ) {
			$pos = strpos( self::B32, $s[ $i ] );
			if ( $pos === false ) { continue; }
			$bin .= str_pad( decbin( $pos ), 5, '0', STR_PAD_LEFT );
		}
		$out = '';
		for ( $i = 0; $i + 8 <= strlen( $bin ); $i += 8 ) {
			$out .= chr( bindec( substr( $bin, $i, 8 ) ) );
		}
		return $out;
	}
}
