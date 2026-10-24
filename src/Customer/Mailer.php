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
		$url_web = self::portal_url( 'verify-email?token=' . rawurlencode( $token ) );
		$url_app = self::app_deep_link( 'verify', $token );
		$subject = sprintf( __( '[%s] Bitte E-Mail-Adresse bestaetigen', 'itdatex-mailguard' ), get_bloginfo( 'name' ) );
		$text = sprintf(
			__( "Willkommen bei MailGuard!\n\nBitte bestaetige deine E-Mail-Adresse mit einem der folgenden Links (gueltig 24 Stunden):\n\nIn der MailGuard-Desktop-App (falls installiert — meldet dich direkt an):\n%s\n\nOder im Browser:\n%s\n\nFalls du diese Registrierung nicht angefordert hast, ignoriere diese Mail.", 'itdatex-mailguard' ),
			$url_app,
			$url_web
		);
		$html = MailTemplate::render(
			'Bestätige deine E-Mail — der Link ist 24 Stunden gültig.',
			'EMAIL-VERIFICATION',
			'Fast geschafft',
			'Willkommen bei <strong>MailGuard</strong>. Klick den Button, um deine E-Mail-Adresse zu bestätigen. Der Link ist <strong>24 Stunden</strong> gültig.',
			[ 'label' => 'E-Mail bestätigen', 'url' => $url_web ],
			[
				'DESKTOP-APP · ' . esc_html( $url_app ),
			],
			'Falls du diese Registrierung nicht angefordert hast, ignoriere diese Mail — es wurde kein Konto aktiviert.'
		);
		return self::send( $email, $subject, $text, $html );
	}

	/**
	 * Welcome-Mail nach SaaS-Onboarding.
	 *
	 * Enthält bei Bedarf einen direkten Set-Password-Link (siehe Account::set_password_reset_token).
	 * Damit muss der frisch angelegte Customer NICHT erst "Passwort vergessen" klicken.
	 *
	 * Bei Free-Plan zusätzlich der Verification-Link (Email-Ownership noch nicht bewiesen).
	 * Bei Paid-Plan ist die Email durch Stripe-Checkout (Empfangs-Bestätigung) bereits verifiziert.
	 */
	public static function send_saas_welcome( string $email, array $plan, string $set_password_token = '', string $verification_token = '' ) : bool {
		$portal = self::portal_url( '' );
		$plan_line = $plan['is_paid']
			? sprintf( '%s · %d Postfächer · LLM-Deep-Scan aktiviert', $plan['name'], (int) $plan['imap_quota'] )
			: sprintf( '%s · %d Postfach · Heuristik-only', $plan['name'], (int) $plan['imap_quota'] );

		$lines = [];
		$lines[] = __( 'Willkommen bei MailGuard!', 'itdatex-mailguard' );
		$lines[] = '';
		$lines[] = sprintf( __( 'Dein Plan: %s', 'itdatex-mailguard' ), $plan_line );
		$lines[] = '';

		if ( $set_password_token !== '' ) {
			$set_url_web = self::portal_url( 'reset-password?token=' . rawurlencode( $set_password_token ) );
			$set_url_app = self::app_deep_link( 'reset', $set_password_token );
			$lines[] = __( 'Passwort setzen (gültig 7 Tage):', 'itdatex-mailguard' );
			$lines[] = __( '· In der MailGuard-Desktop-App (falls installiert):', 'itdatex-mailguard' );
			$lines[] = '  ' . $set_url_app;
			$lines[] = __( '· Im Browser:', 'itdatex-mailguard' );
			$lines[] = '  ' . $set_url_web;
			$lines[] = '';
		}

		if ( $verification_token !== '' ) {
			$ver_url = self::portal_url( 'verify-email?token=' . rawurlencode( $verification_token ) );
			$lines[] = __( 'Email bestätigen (gültig 24 Stunden):', 'itdatex-mailguard' );
			$lines[] = $ver_url;
			$lines[] = '';
		}

		$lines[] = __( 'Danach einloggen und Postfach verbinden:', 'itdatex-mailguard' );
		$lines[] = $portal;
		$lines[] = '';
		$lines[] = __( "So geht's weiter:", 'itdatex-mailguard' );
		$lines[] = __( '1. Passwort über den Link oben setzen.', 'itdatex-mailguard' );
		$lines[] = __( '2. Im Tab "Postfächer" dein IMAP-Konto einrichten (Host, Port, Benutzer, Passwort).', 'itdatex-mailguard' );
		$lines[] = __( '3. MailGuard pullt alle 15 Minuten neue Mails und scannt automatisch.', 'itdatex-mailguard' );
		$lines[] = '';
		$lines[] = __( 'Plan wechseln oder kündigen: im Portal unter "Plan".', 'itdatex-mailguard' );
		$lines[] = '';
		$lines[] = __( 'Fragen? Antworte einfach auf diese Mail.', 'itdatex-mailguard' );

		$subject = sprintf( __( '[%s] Willkommen — dein MailGuard ist bereit', 'itdatex-mailguard' ), get_bloginfo( 'name' ) );
		$text = implode( "\n", $lines );

		$cta = [];
		$details = [ 'PLAN · ' . esc_html( $plan_line ) ];
		$intro = 'Dein <strong>MailGuard</strong>-Konto ist angelegt. Setz dein Passwort und verbind dein erstes Postfach — das dauert zusammen etwa 2 Minuten.';
		if ( $set_password_token !== '' ) {
			$set_url_web = self::portal_url( 'reset-password?token=' . rawurlencode( $set_password_token ) );
			$cta = [ 'label' => 'Passwort setzen', 'url' => $set_url_web ];
			$details[] = 'PASSWORT-TOKEN &middot; GÜLTIG 7 TAGE';
		} elseif ( $verification_token !== '' ) {
			$ver_url = self::portal_url( 'verify-email?token=' . rawurlencode( $verification_token ) );
			$cta = [ 'label' => 'E-Mail bestätigen', 'url' => $ver_url ];
			$details[] = 'VERIFICATION-TOKEN &middot; GÜLTIG 24 STUNDEN';
		} else {
			$cta = [ 'label' => 'Zum Portal', 'url' => $portal ];
		}
		$html = MailTemplate::render(
			'Dein MailGuard-Konto ist bereit — Plan: ' . $plan['name'],
			'ACCOUNT-ONBOARDING',
			'Willkommen',
			$intro,
			$cta,
			$details,
			'Fragen? Antworte einfach auf diese Mail.'
		);
		return self::send( $email, $subject, $text, $html );
	}

	public static function send_password_reset( string $email, string $token ) : bool {
		$url_web = self::portal_url( 'reset-password?token=' . rawurlencode( $token ) );
		$url_app = self::app_deep_link( 'reset', $token );
		$subject = sprintf( __( '[%s] Passwort zuruecksetzen', 'itdatex-mailguard' ), get_bloginfo( 'name' ) );
		$text = sprintf(
			__( "Du hast ein neues Passwort angefordert.\n\nDu hast 60 Minuten Zeit — waehle einen der Wege:\n\nDirekt in der MailGuard-Desktop-App (falls installiert):\n%s\n\nOder im Browser:\n%s\n\nFalls du das nicht warst, ignoriere diese Mail — dein bisheriges Passwort bleibt gueltig.", 'itdatex-mailguard' ),
			$url_app,
			$url_web
		);
		$html = MailTemplate::render(
			'Passwort-Reset angefordert — Link ist 60 Minuten gültig.',
			'PASSWORD-RESET',
			'Neues Passwort',
			'Du hast einen Passwort-Reset angefordert. Klick den Button, um ein neues Passwort zu setzen. Der Link ist <strong>60 Minuten</strong> gültig.',
			[ 'label' => 'Passwort zurücksetzen', 'url' => $url_web ],
			[
				'DESKTOP-APP · ' . esc_html( $url_app ),
			],
			'Falls du keinen Reset angefordert hast, ignoriere diese Mail — dein bisheriges Passwort bleibt gültig.'
		);
		return self::send( $email, $subject, $text, $html );
	}

	/**
	 * Baut Deep-Links fuer die MailGuard-Desktop-App (mailguard://…).
	 * Die App registriert das URI-Scheme im Windows-Installer (siehe
	 * itdatex-mailguard-app/src-tauri/tauri.conf.json plugins.deep-link).
	 * Ist die App nicht installiert, springt Windows auf ein Auswahl-Dialog
	 * bzw. der Link wird als broken gemeldet — der zweite Link (Portal) im
	 * gleichen Mail-Body funktioniert dann als Fallback.
	 */
	private static function app_deep_link( string $action, string $token ) : string {
		return 'mailguard://' . $action . '?token=' . rawurlencode( $token );
	}

	/**
	 * Sendet Plaintext + optional HTML als multipart/alternative. Mail-Clients
	 * waehlen die beste Variante selber (Terminal/CLI nimmt text, Gmail/Outlook
	 * HTML). Wenn $html leer ist, laeuft es als reine Text-Mail wie frueher.
	 */
	private static function send( string $to, string $subject, string $text, string $html = '' ) : bool {
		$headers = [];
		$from_name = trim( (string) Settings::get( 'mail_from_name', 'MailGuard' ) );
		$from_addr = trim( (string) Settings::get( 'mail_from_address', '' ) );
		if ( $from_addr !== '' ) {
			$headers[] = sprintf( 'From: %s <%s>', $from_name ?: 'MailGuard', $from_addr );
		}
		if ( $html === '' ) {
			return (bool) wp_mail( $to, $subject, $text, $headers );
		}
		// Multipart via phpmailer_init filter — macht aus text + html einen
		// alternative-Body. Nach dem Send Filter wieder abmelden damit andere
		// wp_mail-Aufrufe davon nichts mitkriegen.
		$alt_text = $text;
		$filter = static function ( $phpmailer ) use ( $alt_text ) {
			$phpmailer->isHTML( true );
			$phpmailer->AltBody = $alt_text;
			$phpmailer->CharSet = 'UTF-8';
		};
		add_action( 'phpmailer_init', $filter );
		$ok = (bool) wp_mail( $to, $subject, $html, $headers );
		remove_action( 'phpmailer_init', $filter );
		return $ok;
	}

	private static function portal_url( string $path ) : string {
		$slug = trim( (string) Settings::get( 'portal_slug', 'portal' ), '/' );
		return home_url( '/' . $slug . '/' . ltrim( $path, '/' ) );
	}
}
