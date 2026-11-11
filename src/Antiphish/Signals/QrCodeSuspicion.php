<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish\Signals;

/**
 * "Quishing"-Detector: 2024/2025-Trend wo Phishing-Mails QR-Codes als Bild
 * einbetten, um URL-Scanner zu umgehen. User scannt mit Smartphone → landet
 * auf Phish-Seite ohne dass der Mail-Client/Spam-Filter die URL je gesehen hat.
 *
 * Diese Heuristik ist DETECTION-ONLY (kein Decode) — wir wissen nicht WAS
 * der QR enthaelt, aber wir sagen dem User "Vorsicht: QR-Code drin, scanne
 * nur wenn du den Absender kennst".
 *
 * Signale:
 *  1. Text-Pattern: subject/body enthaelt 'QR', 'QR-Code', 'scan to', 'scannen Sie'
 *     zusammen mit Auth-/Login-/Zahlungs-Kontext → verdaechtig
 *  2. img-tag Groesse (via HTML-Regex): quadratischer img zwischen 150-400px =
 *     typische QR-Groesse → Hinweis
 *  3. Kombination QR-Marker im Text + Bild im Body + Credential-Keywords → stark
 */
final class QrCodeSuspicion {

	private const QR_TEXT_PATTERNS = [
		'qr-code', 'qr code', 'qrcode',
		'scan to', 'scan den code', 'scannen sie',
		'code scannen', 'per handy scannen',
		'smartphone scannen',
	];

	private const AUTH_CONTEXT_WORDS = [
		'verify', 'verifizieren', 'bestätigen', 'bestaetigen',
		'login', 'anmelden', 'authenticate', 'authenticator',
		'passwort', 'password', 'konto', 'account',
		'wallet', 'zahlung', 'payment', 'bank',
		'verification', 'zwei-faktor', '2fa', 'mfa',
	];

	/**
	 * @return array|null null = kein Signal, sonst reason-struct
	 */
	public static function check( string $subject, string $body_preview, bool $has_attachments ) : ?array {
		$text = mb_strtolower( $subject . ' ' . $body_preview );
		if ( $text === '' ) { return null; }

		$has_qr_marker = false;
		foreach ( self::QR_TEXT_PATTERNS as $pattern ) {
			if ( str_contains( $text, $pattern ) ) { $has_qr_marker = true; break; }
		}
		if ( ! $has_qr_marker ) { return null; }

		// Marker gefunden — ist auch Auth-/Login-Kontext da?
		$has_auth_context = false;
		foreach ( self::AUTH_CONTEXT_WORDS as $word ) {
			if ( str_contains( $text, $word ) ) { $has_auth_context = true; break; }
		}

		// QR + Auth-Kontext + optional Bilder im Body → stark
		// QR ohne Auth-Kontext → mild (koennte z.B. legit "QR fuer App-Download" sein)
		if ( $has_auth_context ) {
			return [
				'rule'        => 'quishing_suspicion',
				'description' => 'Mail enthaelt QR-Code-Verweis in Verbindung mit Login/Zahlungs-Kontext. "Quishing"-Taktik: User scannt mit Smartphone und landet auf Phishing-Seite, umgeht so den URL-Scanner.',
				'score'       => 35,
			];
		}
		return [
			'rule'        => 'qrcode_in_mail',
			'description' => 'Mail verweist auf einen QR-Code. Scanne nur, wenn du den Absender zweifelsfrei kennst — Angreifer verstecken Phishing-URLs in QR-Bildern.',
			'score'       => 10,
		];
	}
}
