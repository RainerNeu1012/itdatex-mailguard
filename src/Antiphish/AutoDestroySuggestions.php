<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish;

use Itdatex\Mailguard\Installer;

/**
 * Auto-Vernichten-Vorschlaege: leitet aus mg_actions ab, welche Absender-Domains
 * der User konsistent purge-t und nie zurueckholt. Diese Domains sind hochsichere
 * Kandidaten fuer die Eradicate-Liste (Pre-Ingest-Filter im IMAP-Pull).
 *
 * Signal-Formel:
 *  - Purge-Count pro Domain (action='purge', status='done')
 *  - Undo-Count pro Domain (action='quarantine' + status='undone'
 *                            ODER action='undo_quarantine' + status='done')
 *  - Vorschlagswert, wenn purges >= 3 UND undos = 0.
 *
 * Filter:
 *  - Domain steht schon in mg_eradicate_domains → skip.
 *  - Es existiert bereits eine Blacklist-Regel mit action='purge' und
 *    match_type='from_domain' auf diese Domain → skip.
 */
final class AutoDestroySuggestions {

	private const DEFAULT_WINDOW_DAYS = 90;
	private const MIN_PURGES          = 3;
	// Ein einzelner Absender einer bekannten Domain (z.B. `spam@microsoft.com`)
	// darf nicht dazu fuehren, dass die ganze Domain gesperrt wird — dann waere
	// jede legitime microsoft.com-Mail auch weg. Nur wenn >= 2 verschiedene
	// Absender aus derselben Domain systematisch gepurgt werden, sehen wir das
	// als Domain-Signal.
	private const MIN_DISTINCT_SENDERS = 2;
	private const MAX_SUGGESTIONS     = 10;

	/**
	 * @return array<int,array{
	 *   id:string, domain:string,
	 *   purge_count:int, distinct_senders:int,
	 *   first_purge_at:?string, last_purge_at:?string,
	 *   window_days:int, reason_text:string
	 * }>
	 */
	public static function for_customer( int $customer_id, int $window_days = self::DEFAULT_WINDOW_DAYS ) : array {
		global $wpdb;
		$window_days = max( 7, min( 365, $window_days ) );

		$t_act = $wpdb->prefix . Installer::TABLE_ACTIONS;
		$t_erd = $wpdb->prefix . Installer::TABLE_ERADICATE_DOMAINS;
		$t_rul = $wpdb->prefix . Installer::TABLE_RULES;

		// Purge-Aggregate pro Domain. from_domain wird aus from_addr_snap extrahiert.
		$purges = $wpdb->get_results( $wpdb->prepare(
			"SELECT LOWER(SUBSTRING_INDEX(from_addr_snap, '@', -1)) AS domain,
					COUNT(*)                                        AS purge_count,
					COUNT(DISTINCT LOWER(from_addr_snap))            AS distinct_senders,
					MIN(created_at)                                  AS first_purge_at,
					MAX(created_at)                                  AS last_purge_at
			 FROM {$t_act}
			 WHERE customer_id = %d
			   AND action = 'purge'
			   AND status = 'done'
			   AND from_addr_snap <> ''
			   AND created_at >= (UTC_TIMESTAMP() - INTERVAL %d DAY)
			 GROUP BY domain
			 HAVING purge_count >= %d AND distinct_senders >= %d",
			$customer_id, $window_days, self::MIN_PURGES, self::MIN_DISTINCT_SENDERS
		), ARRAY_A ) ?: [];

		if ( empty( $purges ) ) { return []; }

		// Undo-Signale pro Domain im selben Zeitraum. Beide Wege einer Rueckholung
		// werden gezaehlt: eine urspruenglich quarantaenisierte Mail, die 'undone'
		// wurde, plus explizite 'undo_quarantine'-Aktionen.
		$undo_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT LOWER(SUBSTRING_INDEX(from_addr_snap, '@', -1)) AS domain,
					COUNT(*) AS undo_count
			 FROM {$t_act}
			 WHERE customer_id = %d
			   AND ((action = 'quarantine'      AND status = 'undone')
				 OR (action = 'undo_quarantine' AND status = 'done'))
			   AND from_addr_snap <> ''
			   AND created_at >= (UTC_TIMESTAMP() - INTERVAL %d DAY)
			 GROUP BY domain",
			$customer_id, $window_days
		), ARRAY_A ) ?: [];
		$undo_by_domain = [];
		foreach ( $undo_rows as $r ) { $undo_by_domain[ (string) $r['domain'] ] = (int) $r['undo_count']; }

		// Bereits eingetragene Eradicate-Domains — nicht doppelt vorschlagen.
		$existing_erad = array_flip( array_map( 'strval', $wpdb->get_col( $wpdb->prepare(
			"SELECT LOWER(domain) FROM {$t_erd} WHERE customer_id = %d",
			$customer_id
		) ) ?: [] ) );

		// Domains mit bereits vorhandener Purge-Blacklist-Regel — der Effekt
		// waere derselbe; Eradicate ist nur schneller (Pre-Ingest).
		$existing_purge_rules = array_flip( array_map( 'strval', $wpdb->get_col( $wpdb->prepare(
			"SELECT LOWER(pattern) FROM {$t_rul}
			 WHERE customer_id = %d
			   AND kind = 'blacklist'
			   AND match_type = 'from_domain'
			   AND action = 'purge'",
			$customer_id
		) ) ?: [] ) );

		$out = [];
		foreach ( $purges as $p ) {
			$domain = (string) $p['domain'];
			if ( $domain === '' )                            { continue; }
			if ( isset( $existing_erad[ $domain ] ) )        { continue; }
			if ( isset( $existing_purge_rules[ $domain ] ) ) { continue; }
			if ( ( $undo_by_domain[ $domain ] ?? 0 ) > 0 )   { continue; }

			$purge_count = (int) $p['purge_count'];
			$senders     = (int) $p['distinct_senders'];
			$out[] = [
				'id'               => 'ad:' . $domain,
				'domain'           => $domain,
				'purge_count'      => $purge_count,
				'distinct_senders' => $senders,
				'first_purge_at'   => (string) $p['first_purge_at'],
				'last_purge_at'    => (string) $p['last_purge_at'],
				'window_days'      => $window_days,
				'reason_text'      => sprintf(
					'%dx gepurgt, %d verschiedene Absender aus @%s in den letzten %d Tagen — 0 Rueckholungen. Domain auto-vernichten?',
					$purge_count, $senders, $domain, $window_days
				),
			];
		}

		usort( $out, fn( $a, $b ) => $b['purge_count'] <=> $a['purge_count'] );
		return array_slice( $out, 0, self::MAX_SUGGESTIONS );
	}
}
