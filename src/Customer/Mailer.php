<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Customer;

use Itdatex\Mailguard\Admin\Settings;
use Itdatex\Mailguard\Portal\Rewrite;

/**
 * Versendet Verification- und Reset-Mails ueber wp_mail().
 * Plaintext + minimales HTML, From-Header aus Settings.
 */
final class Mailer {

	public static function send_verification( string $email, string $token ) : bool {
		$url = self::portal_url( 'verify-email?token=' . rawurlencode( $token ) );
		$subject = sprintf( __( '[%s] Bitte E-Mail-Adresse bestaetigen', 'itdatex-mailguard' ), get_bloginfo( 'name' ) );
		$body = sprintf(
			__( "Willkommen!\n\nBitte bestaetige deine E-Mail-Adresse mit dem folgenden Link (gueltig 24 Stunden):\n\n%s\n\nFalls du diese Registrierung nicht angefordert hast, ignoriere diese Mail.", 'itdatex-mailguard' ),
			$url
		);
		return self::send( $email, $subject, $body );
	}

	public static function send_password_reset( string $email, string $token ) : bool {
		$url = self::portal_url( 'reset-password?token=' . rawurlencode( $token ) );
		$subject = sprintf( __( '[%s] Passwort zuruecksetzen', 'itdatex-mailguard' ), get_bloginfo( 'name' ) );
		$body = sprintf(
			__( "Du hast ein neues Passwort angefordert.\n\nKlicke innerhalb von 60 Minuten auf den folgenden Link, um ein neues Passwort zu setzen:\n\n%s\n\nFalls du das nicht warst, ignoriere diese Mail — dein bisheriges Passwort bleibt gueltig.", 'itdatex-mailguard' ),
			$url
		);
		return self::send( $email, $subject, $body );
	}

	private static function send( string $to, string $subject, string $body ) : bool {
		$headers = [];
		$from_name = trim( (string) Settings::get( 'mail_from_name', 'MailGuard' ) );
		$from_addr = trim( (string) Settings::get( 'mail_from_address', '' ) );
		if ( $from_addr !== '' ) {
			$headers[] = sprintf( 'From: %s <%s>', $from_name ?: 'MailGuard', $from_addr );
		}
		return (bool) wp_mail( $to, $subject, $body, $headers );
	}

	private static function portal_url( string $path ) : string {
		$slug = trim( (string) Settings::get( 'portal_slug', 'portal' ), '/' );
		return home_url( '/' . $slug . '/' . ltrim( $path, '/' ) );
	}
}
