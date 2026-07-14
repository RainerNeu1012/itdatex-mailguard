<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish;

/**
 * Content-Fingerprint fuer Newsletter-/Massenmail-Kampagnen.
 * Ziel: derselbe Fingerprint fuer alle Mails einer Kampagne, auch
 * wenn Vorname/Kundennummer/Betrag im Subject variiert.
 *
 * Formel:
 *   fp = substr( sha256( from_addr + '|' + norm(subject) + '|' + link_domains ), 0, 16 )
 *
 * norm(subject):
 *   - lowercase
 *   - alle Ziffernsequenzen → '#'
 *   - Emails → '@'
 *   - alles was nicht Buchstaben/Whitespace/#/@ ist → Leerzeichen
 *   - Mehrfach-Whitespace → einzelne Leerzeichen, trim
 *
 * link_domains:
 *   - alle http(s)-Hosts aus dem body_preview extrahieren
 *   - lowercase, dedupliziert, sortiert, comma-joined
 *   - leer wenn keine Links → das ist okay (viele Mails haben keine)
 *
 * 16 hex chars = 64 bit. Bei 12k Mails ist die Kollisionswahrscheinlichkeit
 * praktisch null; die Group-by-Query mit Index bleibt schnell.
 */
final class Fingerprint {

	public static function compute( string $from_addr, string $subject, string $body_preview = '' ) : string {
		$key = strtolower( trim( $from_addr ) )
			. '|' . self::normalize_subject( $subject )
			. '|' . implode( ',', self::link_domains( $body_preview ) );
		return substr( hash( 'sha256', $key ), 0, 16 );
	}

	public static function normalize_subject( string $subject ) : string {
		$s = mb_strtolower( $subject );
		// Emails → '@' (vor der Digit-Substitution, sonst frisst die die Zahlen im Local-Part)
		$s = preg_replace( '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', '@', $s ) ?? $s;
		// Ziffernsequenzen (auch mit .,-/) → '#'
		$s = preg_replace( '/[0-9]+(?:[.,\-\/][0-9]+)*/', '#', $s ) ?? $s;
		// Alles was nicht Buchstabe/Ziffer(0 nach subst.)/#/@/Whitespace ist → Leerzeichen
		$s = preg_replace( '/[^\p{L}\s#@]+/u', ' ', $s ) ?? $s;
		// Whitespace kollabieren
		$s = preg_replace( '/\s+/', ' ', $s ) ?? $s;
		return trim( $s );
	}

	/**
	 * Extrahiert unique, sortierte Link-Hosts (nur die Domain, keine Pfade/Queries).
	 *
	 * @return string[]
	 */
	public static function link_domains( string $body ) : array {
		$hosts = [];
		if ( preg_match_all( '#https?://([^/\s"\'<>)\]]+)#i', $body, $m ) ) {
			foreach ( $m[1] as $host ) {
				$host = strtolower( trim( $host ) );
				// Port abtrennen
				if ( ( $c = strpos( $host, ':' ) ) !== false ) { $host = substr( $host, 0, $c ); }
				if ( $host !== '' ) { $hosts[ $host ] = true; }
			}
		}
		$hosts = array_keys( $hosts );
		sort( $hosts );
		return $hosts;
	}
}
