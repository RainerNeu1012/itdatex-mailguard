<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish\Signals;

/**
 * Display-Name-Spoofing: Phishing-Mails tragen oft einen From-Name wie
 * "PayPal", "Amazon", "Sparkasse" — aber die from_addr gehört zu einer
 * fremden Domain (z.B. "PayPal <noreply@foo-bar.xyz>"). Legit Dienste
 * haben ihre Marke in der Domain selbst.
 *
 * Heuristik: wenn display_name eine bekannte Marke enthält, aber die
 * from-Domain diese Marke NICHT enthält, ist das ein starkes Phishing-
 * Signal (+40 Score).
 *
 * Marken-Liste ist bewusst kurz und decken die häufigsten Phishing-Opfer
 * ab — Erweiterung einfach via array-append möglich.
 */
final class DisplayNameSpoof {

	/**
	 * Marken → Domain-Tokens die legit vorkommen müssen. Mehrere Tokens
	 * bedeuten "einer davon muss matchen" (z.B. Amazon: amazon.com, amazon.de).
	 */
	private const BRANDS = [
		'paypal'      => [ 'paypal.' ],
		'amazon'      => [ 'amazon.', 'awstrack.' ],
		'microsoft'   => [ 'microsoft.', 'outlook.', 'office.', 'accountprotection.microsoft.com' ],
		'apple'       => [ 'apple.com', 'me.com', 'icloud.com' ],
		'google'      => [ 'google.com', 'gmail.com', 'googlemail.com', 'notify.cloudflare.com' ],
		'facebook'    => [ 'facebook.com', 'facebookmail.com', 'fb.com' ],
		'instagram'   => [ 'instagram.com', 'mail.instagram.com' ],
		'linkedin'    => [ 'linkedin.com' ],
		'netflix'     => [ 'netflix.com', 'members.netflix.com' ],
		'spotify'     => [ 'spotify.com', 'email.spotify.com' ],
		'dhl'         => [ 'dhl.de', 'dhl.com' ],
		'deutsche post' => [ 'deutschepost.de', 'deutschepost.com' ],
		'sparkasse'   => [ 'sparkasse.de', 'sparkasse-', 'sparkasse.at' ],
		'commerzbank' => [ 'commerzbank.de', 'commerzbank.com' ],
		'deutsche bank' => [ 'deutsche-bank.de', 'db.com' ],
		'volksbank'   => [ 'volksbank.', 'vr.de' ],
		'ing'         => [ 'ing.de', 'ing-diba.de' ],
		'coinbase'    => [ 'coinbase.com' ],
		'binance'     => [ 'binance.com' ],
		'stripe'      => [ 'stripe.com' ],
		'ebay'        => [ 'ebay.de', 'ebay.com' ],
		'klarna'      => [ 'klarna.com', 'klarna.de' ],
		'schufa'      => [ 'schufa.de' ],
		'telekom'     => [ 'telekom.de', 't-online.de' ],
		'vodafone'    => [ 'vodafone.de', 'vodafone.com', 'kundenservice.vodafone.com' ],
		'o2'          => [ 'o2online.de', 'cpw.o2.co.uk' ],
	];

	/**
	 * @return array|null null wenn kein Spoof, sonst reason-struct.
	 */
	public static function check( string $from_name, string $from_addr ) : ?array {
		$name = trim( mb_strtolower( $from_name ) );
		$addr = trim( mb_strtolower( $from_addr ) );
		if ( $name === '' || $addr === '' ) { return null; }

		$at  = strrpos( $addr, '@' );
		if ( $at === false ) { return null; }
		$domain = substr( $addr, $at + 1 );
		if ( $domain === '' ) { return null; }

		foreach ( self::BRANDS as $brand => $allowed_tokens ) {
			// Brand im Display-Name erwähnt?
			if ( ! str_contains( $name, $brand ) ) { continue; }

			// Prüfen ob irgendein erlaubter Token in der Domain vorkommt.
			$legit = false;
			foreach ( $allowed_tokens as $token ) {
				if ( str_contains( $domain, $token ) ) { $legit = true; break; }
			}
			if ( $legit ) { return null; }

			return [
				'rule'        => 'display_name_spoof',
				'description' => sprintf(
					'Absender-Name behauptet "%s" zu sein, aber die E-Mail-Domain "%s" gehoert nicht zur %s-Marke. Klassischer Phishing-Trick.',
					self::prettyBrand( $brand ),
					$domain,
					self::prettyBrand( $brand )
				),
				'score'       => 40,
			];
		}
		return null;
	}

	private static function prettyBrand( string $slug ) : string {
		return ucwords( $slug );
	}
}
