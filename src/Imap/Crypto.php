<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap;

/**
 * AES-256-GCM at-rest Schutz fuer IMAP-Passwoerter pro Customer-Konto.
 * Key wird aus WP-Salts abgeleitet; bei DB-Dump ohne wp-config.php-Zugriff
 * nicht trivial lesbar. Schuetzt nicht gegen Filesystem-Lesezugriff.
 *
 * Identische Konstruktion wie itdatex-mailsec (siehe Inbox/Crypto.php),
 * aber eigener Domain-Separator im HKDF-Input — damit Schluessel aus
 * MailSec NICHT versehentlich MailGuard-Werte entschluesseln koennten.
 */
final class Crypto {

	private const CIPHER = 'aes-256-gcm';

	private static function key() : string {
		$salt = '';
		if ( defined( 'AUTH_KEY' ) )        { $salt .= AUTH_KEY; }
		if ( defined( 'SECURE_AUTH_KEY' ) ) { $salt .= SECURE_AUTH_KEY; }
		if ( defined( 'NONCE_KEY' ) )       { $salt .= NONCE_KEY; }
		if ( $salt === '' ) { $salt = 'itdatex-mailguard-fallback-key'; }
		return hash( 'sha256', 'itdatex-mailguard|imap|' . $salt, true );
	}

	public static function encrypt( string $plain ) : string {
		if ( $plain === '' ) { return ''; }
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( $plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( $ct === false ) {
			return '';
		}
		return 'v1:' . base64_encode( $iv . $tag . $ct );
	}

	public static function decrypt( string $stored ) : string {
		if ( $stored === '' ) { return ''; }
		if ( strncmp( $stored, 'v1:', 3 ) !== 0 ) { return ''; }
		$raw = base64_decode( substr( $stored, 3 ), true );
		if ( $raw === false || strlen( $raw ) < 28 ) { return ''; }
		$iv  = substr( $raw, 0,  12 );
		$tag = substr( $raw, 12, 16 );
		$ct  = substr( $raw, 28 );
		$pt  = openssl_decrypt( $ct, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		return $pt === false ? '' : $pt;
	}
}
