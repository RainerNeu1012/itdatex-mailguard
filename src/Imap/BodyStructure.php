<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap;

/**
 * Minimaler Parser fuer IMAP BODYSTRUCTURE (RFC 3501 §7.4.2).
 *
 * Wir extrahieren nur was wir brauchen — type/subtype/encoding pro Leaf-Part
 * und die Verschachtelung. Keine Param-Decoding, kein Disposition, kein
 * Language/Location.
 *
 * Beispiel Single-Part:
 *   ("text" "plain" ("charset" "utf-8") NIL NIL "quoted-printable" 1234 56 NIL NIL NIL)
 *
 * Beispiel Multipart:
 *   (("text" "plain" ...) ("text" "html" ...) "alternative" (...))
 *
 * Output-Schema:
 *   - Leaf:  ['type'=>'text','subtype'=>'plain','encoding'=>'quoted-printable']
 *   - Multi: ['type'=>'multipart','subtype'=>'alternative','parts'=>[ … ]]
 */
final class BodyStructure {

	/** @return array{type?:string,subtype?:string,encoding?:string,parts?:array}|null */
	public static function parse( string $raw ) : ?array {
		$pos = 0;
		$tok = self::next_token( $raw, $pos );
		if ( $tok !== '(' ) { return null; }
		$node = self::parse_body( $raw, $pos );
		return is_array( $node ) ? $node : null;
	}

	/**
	 * Parst einen Body-Eintrag bis zur passenden schliessenden ')'.
	 * Verbraucht das eingangs offene '(' NICHT (Caller hat es schon geholt).
	 */
	private static function parse_body( string $raw, int &$pos ) : array {
		// Peek: ist der naechste Token wieder '(' -> Multipart (Liste von Subparts);
		// sonst Single-Part (beginnt mit "type" "subtype" ...).
		$saved = $pos;
		$t     = self::next_token( $raw, $pos );
		if ( $t === '(' ) {
			$parts = [];
			// Erste Subpart einlesen
			$parts[] = self::parse_body( $raw, $pos );
			// Weitere Subparts, solange wieder '(' folgt
			while ( true ) {
				$peek = self::peek_token( $raw, $pos );
				if ( $peek === '(' ) {
					$pos = self::skip_one( $raw, $pos ); // consume '('
					$parts[] = self::parse_body( $raw, $pos );
					continue;
				}
				break;
			}
			// Multipart-Subtype
			$subtype = self::next_token( $raw, $pos );
			// Rest bis zur schliessenden ')' wegwerfen
			self::skip_until_close( $raw, $pos );
			return [
				'type'    => 'multipart',
				'subtype' => is_string( $subtype ) ? strtolower( $subtype ) : '',
				'parts'   => $parts,
			];
		}
		// Single-Part — t ist der erste String, also der Haupt-Type
		$type      = is_string( $t ) ? strtolower( $t ) : '';
		$subtype_t = self::next_token( $raw, $pos );
		$subtype   = is_string( $subtype_t ) ? strtolower( $subtype_t ) : '';
		// params (entweder NIL oder Liste) ueberspringen
		self::skip_value( $raw, $pos );
		// content-id (NIL/string), content-desc (NIL/string)
		self::skip_value( $raw, $pos );
		self::skip_value( $raw, $pos );
		// encoding (string)
		$enc_t   = self::next_token( $raw, $pos );
		$encoding = is_string( $enc_t ) ? strtolower( $enc_t ) : '';
		// rest bis ')' wegwerfen
		self::skip_until_close( $raw, $pos );
		return [
			'type'     => $type,
			'subtype'  => $subtype,
			'encoding' => $encoding,
		];
	}

	private static function next_token( string $raw, int &$pos ) {
		$len = strlen( $raw );
		while ( $pos < $len && ctype_space( $raw[ $pos ] ) ) { $pos++; }
		if ( $pos >= $len ) { return null; }
		$c = $raw[ $pos ];
		if ( $c === '(' || $c === ')' ) {
			$pos++;
			return $c;
		}
		if ( $c === '"' ) {
			$pos++; $start = $pos;
			$out = '';
			while ( $pos < $len ) {
				$ch = $raw[ $pos ];
				if ( $ch === '\\' && $pos + 1 < $len ) {
					$out .= $raw[ $pos + 1 ];
					$pos += 2;
					continue;
				}
				if ( $ch === '"' ) { $pos++; return $out; }
				$out .= $ch;
				$pos++;
			}
			return $out;
		}
		// Atom (NIL, number, oder ungequotetes Wort)
		$start = $pos;
		while ( $pos < $len && ! ctype_space( $raw[ $pos ] ) && $raw[ $pos ] !== '(' && $raw[ $pos ] !== ')' ) {
			$pos++;
		}
		$atom = substr( $raw, $start, $pos - $start );
		return $atom === 'NIL' ? null : $atom;
	}

	private static function peek_token( string $raw, int $pos ) {
		return self::next_token( $raw, $pos );
	}

	private static function skip_one( string $raw, int $pos ) : int {
		$len = strlen( $raw );
		while ( $pos < $len && ctype_space( $raw[ $pos ] ) ) { $pos++; }
		if ( $pos < $len ) { $pos++; }
		return $pos;
	}

	/** Ueberspringt einen kompletten Wert: NIL, "...", atom, oder Liste (...). */
	private static function skip_value( string $raw, int &$pos ) : void {
		$len = strlen( $raw );
		while ( $pos < $len && ctype_space( $raw[ $pos ] ) ) { $pos++; }
		if ( $pos >= $len ) { return; }
		if ( $raw[ $pos ] === '(' ) {
			$depth = 0;
			while ( $pos < $len ) {
				$c = $raw[ $pos ];
				if ( $c === '"' ) {
					$pos++;
					while ( $pos < $len ) {
						if ( $raw[ $pos ] === '\\' && $pos + 1 < $len ) { $pos += 2; continue; }
						if ( $raw[ $pos ] === '"' ) { $pos++; break; }
						$pos++;
					}
					continue;
				}
				if ( $c === '(' ) { $depth++; $pos++; continue; }
				if ( $c === ')' ) { $depth--; $pos++; if ( $depth === 0 ) { return; } continue; }
				$pos++;
			}
			return;
		}
		// nicht-Liste: einen Token konsumieren
		self::next_token( $raw, $pos );
	}

	private static function skip_until_close( string $raw, int &$pos ) : void {
		$len = strlen( $raw );
		$depth = 1;
		while ( $pos < $len && $depth > 0 ) {
			$c = $raw[ $pos ];
			if ( $c === '"' ) {
				$pos++;
				while ( $pos < $len ) {
					if ( $raw[ $pos ] === '\\' && $pos + 1 < $len ) { $pos += 2; continue; }
					if ( $raw[ $pos ] === '"' ) { $pos++; break; }
					$pos++;
				}
				continue;
			}
			if ( $c === '(' ) { $depth++; $pos++; continue; }
			if ( $c === ')' ) { $depth--; $pos++; continue; }
			$pos++;
		}
	}
}
