<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish\Signals;

/**
 * Homograph-/IDN-Spoofing-Erkennung: Angreifer registrieren Domains wie
 * "аpple.com" (mit kyrillischem а statt lateinischem a) oder
 * "paypa1.com" (mit Ziffer 1 statt L). Punycode-ähnliche Domains
 * (xn--*) sind auch ein starkes Phishing-Signal.
 *
 * Heuristiken:
 *  1. Domain beginnt mit "xn--" → Punycode-Domain (IDN) — meist Spoofing-Versuch.
 *  2. Domain enthält Mixed-Script (lateinisch + kyrillisch/griechisch) →
 *     fast immer Attack.
 *  3. Domain enthält Buchstaben-Ziffer-Verwechslungen (0/O, 1/l, 5/S, …)
 *     UND klingt phonetisch wie bekannte Marke → verdächtig.
 */
final class HomographDomain {

	private const SUSPICIOUS_LOOKALIKES = [
		// Pattern → Original (nur wenn die Pattern-Domain nicht die Original-Domain selbst ist)
		'paypa1' => 'paypal', 'paypai' => 'paypal',
		'amaz0n' => 'amazon', 'arnazon' => 'amazon',
		'micr0soft' => 'microsoft', 'rnicrosoft' => 'microsoft',
		'g00gle' => 'google', 'googie' => 'google',
		'app1e' => 'apple', 'appie' => 'apple',
		'faceb00k' => 'facebook',
		'netfl1x' => 'netflix', 'netf1ix' => 'netflix',
		'sparkass3' => 'sparkasse',
	];

	public static function check( string $from_addr ) : ?array {
		$addr = trim( mb_strtolower( $from_addr ) );
		if ( $addr === '' ) { return null; }
		$at = strrpos( $addr, '@' );
		if ( $at === false ) { return null; }
		$domain = substr( $addr, $at + 1 );
		if ( $domain === '' ) { return null; }

		// 1. Punycode-Domain
		if ( str_starts_with( $domain, 'xn--' ) || str_contains( $domain, '.xn--' ) ) {
			return [
				'rule'        => 'punycode_domain',
				'description' => sprintf(
					'Absender-Domain "%s" ist Punycode-codiert (IDN). Phisher nutzen das, um bekannte Marken mit kyrillischen/arabischen Lookalike-Zeichen zu faken.',
					$domain
				),
				'score'       => 50,
			];
		}

		// 2. Mixed-Script-Detection: lateinisch + ein anderes Script im selben Label
		$labels = explode( '.', $domain );
		foreach ( $labels as $label ) {
			$scripts = self::detect_scripts( $label );
			if ( count( $scripts ) >= 2 ) {
				return [
					'rule'        => 'mixed_script_domain',
					'description' => sprintf(
						'Absender-Domain "%s" mischt Schriftsysteme (%s) im Label "%s". Praktisch immer Lookalike-Phishing.',
						$domain,
						implode( ' + ', $scripts ),
						$label
					),
					'score'       => 60,
				];
			}
		}

		// 3. Bekannte Lookalike-Patterns
		foreach ( self::SUSPICIOUS_LOOKALIKES as $pattern => $original ) {
			if ( str_contains( $domain, $pattern ) && ! str_contains( $domain, $original ) ) {
				return [
					'rule'        => 'lookalike_domain',
					'description' => sprintf(
						'Absender-Domain "%s" enthaelt "%s" — vermutlich Verwechslungs-Trick statt "%s".',
						$domain, $pattern, $original
					),
					'score'       => 45,
				];
			}
		}

		return null;
	}

	/**
	 * Erkennt welche Schriftsysteme in einem String vorkommen.
	 * @return array<string> z.B. ['latin','cyrillic']
	 */
	private static function detect_scripts( string $s ) : array {
		$scripts = [];
		if ( preg_match( '/[a-z]/', $s ) ) { $scripts['latin'] = true; }
		if ( preg_match( '/[\x{0400}-\x{04FF}]/u', $s ) ) { $scripts['cyrillic'] = true; }
		if ( preg_match( '/[\x{0370}-\x{03FF}]/u', $s ) ) { $scripts['greek'] = true; }
		if ( preg_match( '/[\x{0590}-\x{05FF}]/u', $s ) ) { $scripts['hebrew'] = true; }
		if ( preg_match( '/[\x{0600}-\x{06FF}]/u', $s ) ) { $scripts['arabic'] = true; }
		if ( preg_match( '/[\x{4E00}-\x{9FFF}]/u', $s ) ) { $scripts['cjk'] = true; }
		return array_keys( $scripts );
	}
}
