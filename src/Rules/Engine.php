<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Rules;

/**
 * Wendet Customer-Regeln auf eine Mail an.
 *
 * Reihenfolge:
 *  1. Blacklist (gewinnt vor Whitelist — Sicherheit zuerst)
 *  2. Whitelist (uebersteuert API-Verdict, falls API "dangerous" sagt)
 *
 * Liefert ein Override-Array oder null wenn keine Regel greift.
 *  - { verdict: 'dangerous', score: 100, reason: { rule, description, score } }
 *  - { verdict: 'clean',     score: 0,   reason: { ... } }
 */
final class Engine {

	public static function apply( int $customer_id, array $row ) : ?array {
		$rules = Rule::list_for_customer( $customer_id );
		if ( ! $rules ) { return null; }

		$from = strtolower( (string) ( $row['from_addr'] ?? '' ) );
		$subj = (string) ( $row['subject'] ?? '' );

		$hit_black = self::match_any( array_filter( $rules, static fn( $r ) => $r['kind'] === 'blacklist' ), $from, $subj );
		if ( $hit_black ) {
			return [
				'verdict' => 'dangerous',
				'score'   => 100,
				'reason'  => [
					'rule'        => 'customer_blacklist',
					'description' => sprintf( 'Blacklist-Regel #%d (%s: %s)', $hit_black['id'], $hit_black['match_type'], $hit_black['pattern'] ),
					'score'       => 100,
				],
			];
		}

		$hit_white = self::match_any( array_filter( $rules, static fn( $r ) => $r['kind'] === 'whitelist' ), $from, $subj );
		if ( $hit_white ) {
			return [
				'verdict' => 'clean',
				'score'   => 0,
				'reason'  => [
					'rule'        => 'customer_whitelist',
					'description' => sprintf( 'Whitelist-Regel #%d (%s: %s)', $hit_white['id'], $hit_white['match_type'], $hit_white['pattern'] ),
					'score'       => 0,
				],
			];
		}
		return null;
	}

	private static function match_any( array $rules, string $from, string $subj ) : ?array {
		foreach ( $rules as $r ) {
			$pattern = (string) $r['pattern'];
			switch ( $r['match_type'] ) {
				case 'from_addr':
					if ( $from !== '' && $from === $pattern ) { return $r; }
					break;
				case 'from_domain':
					if ( $from === '' ) break;
					$at = strrpos( $from, '@' );
					$dom = $at !== false ? substr( $from, $at + 1 ) : $from;
					if ( $pattern !== '' && $pattern[0] === '.' ) {
						// ".example.com" matched Sub-Domains + Domain selbst
						$suffix = substr( $pattern, 1 );
						if ( $dom === $suffix || str_ends_with( $dom, '.' . $suffix ) ) { return $r; }
					} else {
						if ( $dom === $pattern ) { return $r; }
					}
					break;
				case 'subject_contains':
					if ( $subj !== '' && stripos( $subj, $pattern ) !== false ) { return $r; }
					break;
			}
		}
		return null;
	}
}
