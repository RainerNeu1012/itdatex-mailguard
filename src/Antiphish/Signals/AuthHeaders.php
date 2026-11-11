<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish\Signals;

/**
 * Prueft SPF/DKIM/DMARC-Ergebnisse die der empfangende Mailserver bereits
 * in die Authentication-Results-Header geschrieben hat. Plus Reply-To-
 * vs-From-Mismatch-Check (klassischer Phishing-Red-Flag).
 *
 * Wir fuehren KEINE eigene SPF/DKIM-Verifikation durch — das haben Outlook/
 * iCloud/Gmail schon gemacht. Wir parsen nur das Ergebnis.
 *
 * Score-Modell:
 *  - dmarc=fail  → +50 (sehr starkes Signal, DMARC-Fail bedeutet Alignment broken)
 *  - spf=fail    → +25
 *  - dkim=fail   → +20 (wenn Mail als Massversand erwartet signed ist)
 *  - reply_to != from_domain → +30 (klassischer Phishing-Trick)
 *
 * Wenn mehrere Fails zusammenkommen (z.B. spf+dkim+dmarc), capped bei +60
 * gesamt — sonst explodiert der Score und wir blockieren legitime Mails
 * nur weil ein temp. DNS-Issue DKIM gebrochen hat.
 */
final class AuthHeaders {

	/**
	 * @param string $auth_headers_json aus mg_messages.auth_headers (JSON-Blob mit ar/spf/reply_to)
	 * @param string $from_addr         aus mg_messages.from_addr
	 * @return array<int,array{rule:string,description:string,score:int}>
	 */
	public static function check( string $auth_headers_json, string $from_addr ) : array {
		if ( $auth_headers_json === '' ) { return []; }
		$data = json_decode( $auth_headers_json, true );
		if ( ! is_array( $data ) ) { return []; }

		$ar  = strtolower( (string) ( $data['ar']  ?? '' ) );  // Authentication-Results
		$spf_hdr = strtolower( (string) ( $data['spf'] ?? '' ) ); // Received-SPF
		$reply_to = (string) ( $data['reply_to'] ?? '' );

		$reasons = [];
		$total_auth_score = 0;

		// DMARC-Fail: sehr hartes Signal
		if ( preg_match( '/dmarc\s*=\s*fail/', $ar ) ) {
			$reasons[] = [
				'rule'        => 'auth_dmarc_fail',
				'description' => 'DMARC-Pruefung fehlgeschlagen. Die Absender-Domain hat eine DMARC-Policy veroeffentlicht, und diese Mail passt nicht dazu — praktisch sicher Spoofing.',
				'score'       => 50,
			];
			$total_auth_score += 50;
		}
		// SPF-Fail: Absender-IP ist nicht autorisiert
		elseif ( preg_match( '/spf\s*=\s*fail/', $ar ) || preg_match( '/^(?:spf=)?fail/', $spf_hdr ) ) {
			$reasons[] = [
				'rule'        => 'auth_spf_fail',
				'description' => 'SPF-Pruefung fehlgeschlagen: die sendende IP-Adresse ist nicht autorisiert, fuer diese Domain Mails zu verschicken.',
				'score'       => 25,
			];
			$total_auth_score += 25;
		}
		// DKIM-Fail: Signatur ungueltig
		if ( preg_match( '/dkim\s*=\s*fail/', $ar ) ) {
			$reasons[] = [
				'rule'        => 'auth_dkim_fail',
				'description' => 'DKIM-Signatur ungueltig. Die Mail wurde entweder unterwegs manipuliert oder faelscht den Absender.',
				'score'       => 20,
			];
			$total_auth_score += 20;
		}

		// Reply-To-Mismatch: der Antwort-Weg laeuft an einen ganz anderen Empfaenger
		if ( $reply_to !== '' && $from_addr !== '' ) {
			$from_domain  = self::domain_of( $from_addr );
			$reply_domain = self::domain_of( $reply_to );
			if ( $from_domain !== '' && $reply_domain !== '' && $from_domain !== $reply_domain ) {
				// Nur hochstufen wenn Reply-To keine offensichtliche Umleitung ist
				// (z.B. Support-Tickets lenken absichtlich auf anderen Empfaenger).
				// Ein vollstaendig unverbundener Domain-Switch ist das Red-Flag.
				$reasons[] = [
					'rule'        => 'reply_to_mismatch',
					'description' => sprintf(
						'Reply-To-Domain "%s" unterscheidet sich von From-Domain "%s". Antworten gehen an einen anderen Empfaenger — typisch bei Phishing, das die Opfer-Antwort umleitet.',
						$reply_domain, $from_domain
					),
					'score'       => 30,
				];
			}
		}

		// Cap bei 60 damit ein Mehrfach-Fail nicht sofort auf 100 geht
		if ( $total_auth_score > 60 ) {
			$overshoot = $total_auth_score - 60;
			// Reduziere das letzte Reason proportional (nur dessen Score)
			for ( $i = count( $reasons ) - 1; $i >= 0 && $overshoot > 0; $i-- ) {
				if ( ! str_starts_with( (string) $reasons[ $i ]['rule'], 'auth_' ) ) { continue; }
				$take = min( $overshoot, (int) $reasons[ $i ]['score'] );
				$reasons[ $i ]['score'] -= $take;
				$overshoot -= $take;
			}
		}

		return $reasons;
	}

	private static function domain_of( string $addr ) : string {
		$addr = strtolower( trim( $addr ) );
		// Reply-To kann "Name <addr>" sein — extract bracketed, sonst raw
		if ( preg_match( '/<([^>]+)>/', $addr, $m ) ) { $addr = $m[1]; }
		$at = strrpos( $addr, '@' );
		return $at !== false ? substr( $addr, $at + 1 ) : '';
	}
}
