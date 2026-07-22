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
 *  - { verdict: 'dangerous', score: 100, action: 'quarantine'|'purge', reason: {...} }
 *  - { verdict: 'clean',     score: 0,   action: 'quarantine',         reason: {...} }
 */
final class Engine {

	public static function apply( int $customer_id, array $row ) : ?array {
		$rules = Rule::list_for_customer( $customer_id );
		if ( ! $rules ) { return null; }

		$from = strtolower( (string) ( $row['from_addr'] ?? '' ) );
		$name = (string) ( $row['from_name']    ?? '' );
		$subj = (string) ( $row['subject']      ?? '' );
		$body = (string) ( $row['body_preview'] ?? '' );

		$hit_black = self::match_any( array_filter( $rules, static fn( $r ) => $r['kind'] === 'blacklist' ), $from, $name, $subj, $body );
		if ( $hit_black ) {
			$action = ( $hit_black['action'] ?? 'quarantine' ) === 'purge' ? 'purge' : 'quarantine';
			return [
				'verdict' => 'dangerous',
				'score'   => 100,
				'action'  => $action,
				'reason'  => [
					'rule'        => 'customer_blacklist',
					'description' => sprintf( 'Blacklist-Regel #%d (%s: %s)', $hit_black['id'], $hit_black['match_type'], $hit_black['pattern'] ),
					'score'       => 100,
					'action'      => $action,
					'rule_id'     => (int) $hit_black['id'],
				],
			];
		}

		$hit_white = self::match_any( array_filter( $rules, static fn( $r ) => $r['kind'] === 'whitelist' ), $from, $name, $subj, $body );
		if ( $hit_white ) {
			return [
				'verdict' => 'clean',
				'score'   => 0,
				'action'  => 'quarantine',
				'reason'  => [
					'rule'        => 'customer_whitelist',
					'description' => sprintf( 'Whitelist-Regel #%d (%s: %s)', $hit_white['id'], $hit_white['match_type'], $hit_white['pattern'] ),
					'score'       => 0,
				],
			];
		}
		return null;
	}

	private static function match_any( array $rules, string $from, string $name, string $subj, string $body ) : ?array {
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
				case 'from_name_contains':
					if ( $name !== '' && $pattern !== '' && stripos( $name, $pattern ) !== false ) { return $r; }
					break;
				case 'subject_contains':
					if ( $subj !== '' && $pattern !== '' && stripos( $subj, $pattern ) !== false ) { return $r; }
					break;
				case 'body_contains':
					if ( $body !== '' && $pattern !== '' && stripos( $body, $pattern ) !== false ) { return $r; }
					break;
			}
		}
		return null;
	}
}
