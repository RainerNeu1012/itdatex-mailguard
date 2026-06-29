<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap\Autoconfig;

/**
 * Statische DB bekannter Mail-Provider mit IMAP-Settings.
 *
 * Wird als erstes vom {@see Resolver} konsultiert — deckt ~95% der
 * DE-Postfaecher offline ab.
 *
 * Schema pro Eintrag:
 *   host             — IMAP-Server
 *   port             — meist 993
 *   encryption       — ssl|tls|none
 *   oauth_provider   — wenn gesetzt, ist OAuth bevorzugt (zeigen wir als Hint)
 *   note             — User-facing Hinweis (z.B. "App-Passwort + 2FA noetig")
 *   no_imap          — true: Provider unterstuetzt KEIN klassisches IMAP (ProtonMail, Apple Hide-My-Email)
 */
final class ProviderRegistry {

	/** @return array<string,array{host?:string,port?:int,encryption?:string,oauth_provider?:string,note?:string,no_imap?:bool}> */
	public static function map() : array {
		return [
			// Google
			'gmail.com'        => [ 'host' => 'imap.gmail.com', 'port' => 993, 'encryption' => 'ssl', 'oauth_provider' => 'google', 'note' => 'OAuth-Connect bevorzugt; sonst App-Passwort + 2FA noetig.' ],
			'googlemail.com'   => [ 'host' => 'imap.gmail.com', 'port' => 993, 'encryption' => 'ssl', 'oauth_provider' => 'google', 'note' => 'OAuth-Connect bevorzugt; sonst App-Passwort + 2FA noetig.' ],

			// Microsoft
			'outlook.com'  => [ 'host' => 'outlook.office365.com', 'port' => 993, 'encryption' => 'ssl', 'oauth_provider' => 'microsoft', 'note' => 'OAuth-Connect bevorzugt — Basic Auth seit 2022 deaktiviert.' ],
			'hotmail.com'  => [ 'host' => 'outlook.office365.com', 'port' => 993, 'encryption' => 'ssl', 'oauth_provider' => 'microsoft', 'note' => 'OAuth-Connect bevorzugt — Basic Auth seit 2022 deaktiviert.' ],
			'live.com'     => [ 'host' => 'outlook.office365.com', 'port' => 993, 'encryption' => 'ssl', 'oauth_provider' => 'microsoft', 'note' => 'OAuth-Connect bevorzugt — Basic Auth seit 2022 deaktiviert.' ],
			'live.de'      => [ 'host' => 'outlook.office365.com', 'port' => 993, 'encryption' => 'ssl', 'oauth_provider' => 'microsoft', 'note' => 'OAuth-Connect bevorzugt — Basic Auth seit 2022 deaktiviert.' ],
			'msn.com'      => [ 'host' => 'outlook.office365.com', 'port' => 993, 'encryption' => 'ssl', 'oauth_provider' => 'microsoft', 'note' => 'OAuth-Connect bevorzugt — Basic Auth seit 2022 deaktiviert.' ],
			'outlook.de'   => [ 'host' => 'outlook.office365.com', 'port' => 993, 'encryption' => 'ssl', 'oauth_provider' => 'microsoft', 'note' => 'OAuth-Connect bevorzugt — Basic Auth seit 2022 deaktiviert.' ],
			'hotmail.de'   => [ 'host' => 'outlook.office365.com', 'port' => 993, 'encryption' => 'ssl', 'oauth_provider' => 'microsoft', 'note' => 'OAuth-Connect bevorzugt — Basic Auth seit 2022 deaktiviert.' ],

			// United Internet (GMX/Web.de/1&1)
			'gmx.de'   => [ 'host' => 'imap.gmx.net', 'port' => 993, 'encryption' => 'ssl', 'note' => 'POP3/IMAP-Zugang in den GMX-Einstellungen aktivieren.' ],
			'gmx.net'  => [ 'host' => 'imap.gmx.net', 'port' => 993, 'encryption' => 'ssl', 'note' => 'POP3/IMAP-Zugang in den GMX-Einstellungen aktivieren.' ],
			'gmx.com'  => [ 'host' => 'imap.gmx.com', 'port' => 993, 'encryption' => 'ssl', 'note' => 'POP3/IMAP-Zugang in den GMX-Einstellungen aktivieren.' ],
			'gmx.at'   => [ 'host' => 'imap.gmx.net', 'port' => 993, 'encryption' => 'ssl' ],
			'gmx.ch'   => [ 'host' => 'imap.gmx.net', 'port' => 993, 'encryption' => 'ssl' ],
			'web.de'   => [ 'host' => 'imap.web.de', 'port' => 993, 'encryption' => 'ssl', 'note' => 'POP3/IMAP-Zugang in den Web.de-Einstellungen aktivieren.' ],
			'mail.de'  => [ 'host' => 'imap.mail.de', 'port' => 993, 'encryption' => 'ssl' ],

			// Deutsche Telekom
			't-online.de' => [ 'host' => 'secureimap.t-online.de', 'port' => 993, 'encryption' => 'ssl', 'note' => 'E-Mail-Passwort separat im Telekom-Kundencenter setzen.' ],
			'magenta.de'  => [ 'host' => 'secureimap.t-online.de', 'port' => 993, 'encryption' => 'ssl' ],

			// IONOS / Strato
			'ionos.de'  => [ 'host' => 'imap.ionos.de', 'port' => 993, 'encryption' => 'ssl' ],
			'1und1.de'  => [ 'host' => 'imap.ionos.de', 'port' => 993, 'encryption' => 'ssl' ],
			'strato.de' => [ 'host' => 'imap.strato.de', 'port' => 993, 'encryption' => 'ssl' ],

			// Datenschutz-Provider
			'mailbox.org' => [ 'host' => 'imap.mailbox.org', 'port' => 993, 'encryption' => 'ssl' ],
			'posteo.de'   => [ 'host' => 'posteo.de', 'port' => 993, 'encryption' => 'ssl' ],
			'posteo.net'  => [ 'host' => 'posteo.de', 'port' => 993, 'encryption' => 'ssl' ],
			'posteo.org'  => [ 'host' => 'posteo.de', 'port' => 993, 'encryption' => 'ssl' ],
			'tuta.com'    => [ 'no_imap' => true, 'note' => 'Tuta unterstuetzt kein klassisches IMAP (E2E-Verschluesselung).' ],
			'tutanota.com'=> [ 'no_imap' => true, 'note' => 'Tutanota unterstuetzt kein klassisches IMAP (E2E-Verschluesselung).' ],
			'protonmail.com' => [ 'no_imap' => true, 'note' => 'ProtonMail braucht die "Bridge"-Software (Plus-Plan), die einen lokalen IMAP-Proxy bereitstellt.' ],
			'proton.me'      => [ 'no_imap' => true, 'note' => 'ProtonMail braucht die "Bridge"-Software (Plus-Plan), die einen lokalen IMAP-Proxy bereitstellt.' ],

			// Yahoo
			'yahoo.com' => [ 'host' => 'imap.mail.yahoo.com', 'port' => 993, 'encryption' => 'ssl', 'note' => 'App-Passwort noetig (Yahoo-Konto → Account Security).' ],
			'yahoo.de'  => [ 'host' => 'imap.mail.yahoo.com', 'port' => 993, 'encryption' => 'ssl', 'note' => 'App-Passwort noetig (Yahoo-Konto → Account Security).' ],
			'ymail.com' => [ 'host' => 'imap.mail.yahoo.com', 'port' => 993, 'encryption' => 'ssl' ],

			// Apple
			'icloud.com' => [ 'host' => 'imap.mail.me.com', 'port' => 993, 'encryption' => 'ssl', 'note' => 'App-spezifisches Passwort noetig (appleid.apple.com).' ],
			'me.com'     => [ 'host' => 'imap.mail.me.com', 'port' => 993, 'encryption' => 'ssl', 'note' => 'App-spezifisches Passwort noetig (appleid.apple.com).' ],
			'mac.com'    => [ 'host' => 'imap.mail.me.com', 'port' => 993, 'encryption' => 'ssl' ],
			'privaterelay.appleid.com' => [ 'no_imap' => true, 'note' => 'Apple "Hide My Email" ist read-only und ohne IMAP.' ],

			// FastMail
			'fastmail.com' => [ 'host' => 'imap.fastmail.com', 'port' => 993, 'encryption' => 'ssl', 'note' => 'App-Passwort in den FastMail-Settings erstellen.' ],
			'fastmail.fm'  => [ 'host' => 'imap.fastmail.com', 'port' => 993, 'encryption' => 'ssl' ],

			// Zoho
			'zoho.com' => [ 'host' => 'imap.zoho.com', 'port' => 993, 'encryption' => 'ssl' ],
			'zoho.eu'  => [ 'host' => 'imap.zoho.eu',  'port' => 993, 'encryption' => 'ssl' ],

			// Yandex
			'yandex.com' => [ 'host' => 'imap.yandex.com', 'port' => 993, 'encryption' => 'ssl' ],
			'yandex.ru'  => [ 'host' => 'imap.yandex.ru',  'port' => 993, 'encryption' => 'ssl' ],

			// AOL
			'aol.com' => [ 'host' => 'imap.aol.com', 'port' => 993, 'encryption' => 'ssl', 'note' => 'App-Passwort noetig.' ],
		];
	}

	/**
	 * Suche per Email — extrahiert Domain (case-insensitive) und schaut nach.
	 *
	 * @return array{host?:string,port?:int,encryption?:string,oauth_provider?:string,note?:string,no_imap?:bool,domain?:string,source?:string,confidence?:string}|null
	 */
	public static function lookup_by_email( string $email ) : ?array {
		$pos = strrpos( $email, '@' );
		if ( $pos === false ) { return null; }
		$domain = strtolower( substr( $email, $pos + 1 ) );
		return self::lookup_by_domain( $domain );
	}

	public static function lookup_by_domain( string $domain ) : ?array {
		$map = self::map();
		$key = strtolower( $domain );
		if ( ! isset( $map[ $key ] ) ) { return null; }
		$hit = $map[ $key ];
		$hit['domain']     = $key;
		$hit['source']     = 'static';
		$hit['confidence'] = 'high';
		return $hit;
	}
}
