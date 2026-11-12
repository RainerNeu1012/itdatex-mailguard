<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish\Signals;

/**
 * Business-Email-Compromise-Heuristik (CEO-Fraud).
 *
 * Erkennt Social-Engineering-Mails ohne Links/Anhaenge: Angreifer gibt sich
 * als Geschaeftsfuehrer / Vorstand aus und bittet um sofortige Ueberweisung,
 * Zahlungsauftrag, Gutschein-Kauf o.ae. Legit CEO-Mails passieren selten
 * mit Freemail-Absender UND Urgency UND Money UND ohne Links.
 *
 * Score-Logik:
 *  - Jedes Match-Pattern = +15
 *  - Trigger-Mindestmenge: 2 (unter 2 Matches zaehlt der Signal gar nicht)
 *  - Max +45 (nicht alleine ausreichend fuer dangerous=70+, aber mit 1-2
 *    anderen Signalen zusammen schon)
 *
 * Nicht trigger bei:
 *  - Links oder Anhaengen (dann greifen URLhaus, SafeBrowsing, Attachment-Scan)
 *  - From-Addr auf derselben Domain wie die Empfaenger-Mailbox (Intra-Firma)
 */
final class BecHeuristic {

	// Typische CEO-Fraud-Display-Namen (Leetspeak egal, lower-case Match)
	private const AUTHORITY_TOKENS = [
		'ceo', 'geschaeftsfuehrer', 'geschäftsführer', 'vorstand', 'chef', 'director',
		'managing director', 'inhaber', 'owner', 'president', 'praesident',
		'finanzvorstand', 'cfo', 'buchhaltung', 'finance',
	];

	// Urgency-Indikatoren (Subject oder Body)
	private const URGENCY_PATTERNS = [
		'dringend', 'sofort', 'jetzt sofort', 'noch heute', 'ganz schnell',
		'urgent', 'asap', 'immediate', 'right now', 'eilig', 'zeitkritisch',
	];

	// Money/Transfer-Indikatoren
	private const MONEY_PATTERNS = [
		'ueberweisung', 'überweisung', 'ueberweisen', 'überweisen', 'transfer',
		'wire transfer', 'wire', 'payment', 'zahlung', 'rechnung bezahlen',
		'gift card', 'gutschein', 'amazon-gutschein', 'steam-karte', 'itunes-karte',
		'iban', 'bic', 'swift',
	];

	// Isolation-Indikatoren ("nicht anrufen, nur Mail")
	private const ISOLATION_PATTERNS = [
		'in einer besprechung', 'gerade in einem meeting', 'nicht erreichbar',
		'kann nicht telefonieren', 'bitte nur per mail', 'vertraulich',
		'diskret', 'niemand davon erzaehlen', 'confidential',
	];

	// Freemail-Domains die bei "Chef-Mail" verdaechtig sind
	private const FREEMAIL_DOMAINS = [
		'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'hotmail.de',
		'live.com', 'live.de', 'yahoo.com', 'yahoo.de', 'gmx.de', 'gmx.net', 'gmx.at',
		'web.de', 't-online.de', 'freenet.de', 'mail.de', 'protonmail.com', 'proton.me',
		'posteo.de', 'yandex.com', 'icloud.com', 'me.com',
	];

	/**
	 * @param string $from_name Display-Name des Absenders
	 * @param string $from_addr E-Mail-Adresse des Absenders
	 * @param string $subject Betreff
	 * @param string $body_preview Body (gestripped, max ~2000 chars)
	 * @param string $recipient_domain Domain der empfangenden Mailbox (fuer Intra-Check)
	 * @param bool   $has_links Enthaelt Body Links?
	 * @param bool   $has_attachments Enthaelt Mail Anhaenge?
	 * @return array|null null wenn kein BEC, sonst reason-struct.
	 */
	public static function check(
		string $from_name,
		string $from_addr,
		string $subject,
		string $body_preview,
		string $recipient_domain = '',
		bool $has_links = false,
		bool $has_attachments = false
	) : ?array {
		// Kein BEC-Signal wenn Links oder Anhaenge — andere Scanner greifen
		if ( $has_links || $has_attachments ) { return null; }

		$from_addr = strtolower( trim( $from_addr ) );
		$from_name = mb_strtolower( trim( $from_name ) );
		$text      = mb_strtolower( $subject . "\n" . $body_preview );

		if ( $from_addr === '' ) { return null; }

		// Intra-Firma: gleiche Domain → kein BEC
		$from_domain = self::extract_domain( $from_addr );
		if ( $recipient_domain !== '' && $from_domain === strtolower( $recipient_domain ) ) {
			return null;
		}

		$indicators = [];

		// 1. Authority-Display-Name (CEO / Chef im Display-Name)
		foreach ( self::AUTHORITY_TOKENS as $tok ) {
			if ( strpos( $from_name, $tok ) !== false ) {
				$indicators[] = 'authority_display_name';
				break;
			}
		}

		// 2. Freemail-Absender
		if ( in_array( $from_domain, self::FREEMAIL_DOMAINS, true ) ) {
			$indicators[] = 'freemail_sender';
		}

		// 3. Urgency im Subject/Body
		foreach ( self::URGENCY_PATTERNS as $pat ) {
			if ( strpos( $text, $pat ) !== false ) {
				$indicators[] = 'urgency';
				break;
			}
		}

		// 4. Money-Transfer-Vokabular
		foreach ( self::MONEY_PATTERNS as $pat ) {
			if ( strpos( $text, $pat ) !== false ) {
				$indicators[] = 'money_request';
				break;
			}
		}

		// 5. Isolation-Phrasen ("nicht anrufen")
		foreach ( self::ISOLATION_PATTERNS as $pat ) {
			if ( strpos( $text, $pat ) !== false ) {
				$indicators[] = 'isolation';
				break;
			}
		}

		// Trigger-Mindestmenge: 2 Indikatoren insgesamt UND mindestens einer der
		// "aktionsorientierten" (urgency|money|isolation). Reine Context-Pairs
		// wie "Chef aus Gmail schreibt Hallo" sind zu schwach fuer einen Hit.
		$actions = array_intersect( $indicators, [ 'urgency', 'money_request', 'isolation' ] );
		if ( count( $indicators ) < 2 || count( $actions ) === 0 ) { return null; }

		// Score: +15 pro Match, max +45
		$score = min( 45, count( $indicators ) * 15 );

		return [
			'code'   => 'bec_heuristic',
			'label'  => 'Business-Email-Compromise-Verdacht (CEO-Fraud-Muster)',
			'score'  => $score,
			'detail' => [
				'indicators'  => $indicators,
				'count'       => count( $indicators ),
				'from_domain' => $from_domain,
			],
		];
	}

	private static function extract_domain( string $addr ) : string {
		$at = strrpos( $addr, '@' );
		return $at !== false ? substr( $addr, $at + 1 ) : '';
	}
}
