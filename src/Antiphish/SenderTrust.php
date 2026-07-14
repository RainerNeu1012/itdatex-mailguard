<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish;

use Itdatex\Mailguard\Installer;

/**
 * Sender-Trust-Score: pro (customer_id, from_addr) akkumuliert MailGuard
 * jedes verwertbare Nutzer-Signal (Received-Count, Whitelist/Blacklist-
 * Klicks, Auto-Quarantaenen-Undo/Purge). ScanService liest daraus einen
 * negativen Score und laesst bekannte Absender selbst bei
 * suspicious-Verdict des LLM nicht mehr auto-quarantaenisieren.
 *
 * Design:
 *  - Signale werden idempotent hochgezaehlt; jede record_*-Methode ist
 *    ein reines INSERT ... ON DUPLICATE KEY UPDATE, also aus jedem
 *    Aufrufpfad heraus sicher (Pull, Rules, Quarantine).
 *  - Domain-Aggregate werden zur Read-Zeit berechnet (kein Denormalize),
 *    damit neue Sub-Absender einer bekannten Domain sofort Basis-Trust
 *    erben, ohne dass ein Cron neue Aggregate schreiben muss.
 *  - Score-Formel bewusst konservativ: max -60 aus reiner Historie —
 *    ein echter Phishing-Sender ohne Historie kann nicht ueber Trust
 *    "durchrutschen". ScanService clippt zusaetzlich, wenn hard signals
 *    (DNS-Fail/AV-Hit/Blacklist) da sind → dann greift Trust gar nicht.
 */
final class SenderTrust {

	public static function table() : string {
		global $wpdb;
		return $wpdb->prefix . Installer::TABLE_SENDER_TRUST;
	}

	private static function normalize_addr( string $addr ) : string {
		return mb_strtolower( trim( $addr ) );
	}

	private static function domain_of( string $addr ) : string {
		$pos = strrpos( $addr, '@' );
		return $pos === false ? '' : substr( $addr, $pos + 1 );
	}

	/**
	 * Zaehlt eine empfangene Mail hoch. Aufrufer: PullService beim Insert.
	 * $received_at optional — wir bevorzugen den Header-Zeitstempel, damit
	 * first_seen/last_seen realistisch bleiben und nicht am Fetch-Zeitpunkt
	 * kleben (Backfill wuerde sonst alle first_seen auf "heute" setzen).
	 */
	public static function record_received( int $customer_id, string $from_addr, ?string $received_at = null ) : void {
		$addr = self::normalize_addr( $from_addr );
		if ( $addr === '' ) { return; }
		$dom  = self::domain_of( $addr );
		$ts   = $received_at ?: current_time( 'mysql', true );
		self::upsert_delta( $customer_id, $addr, $dom, [
			'received_count' => 1,
		], $ts );
	}

	public static function record_whitelist( int $customer_id, string $from_addr ) : void {
		$addr = self::normalize_addr( $from_addr );
		if ( $addr === '' ) { return; }
		$dom  = self::domain_of( $addr );
		self::upsert_delta( $customer_id, $addr, $dom, [
			'whitelist_count' => 1,
		] );
	}

	public static function record_blacklist( int $customer_id, string $from_addr ) : void {
		$addr = self::normalize_addr( $from_addr );
		if ( $addr === '' ) { return; }
		$dom  = self::domain_of( $addr );
		self::upsert_delta( $customer_id, $addr, $dom, [
			'blacklist_count' => 1,
		] );
	}

	public static function record_quarantine_undo( int $customer_id, string $from_addr ) : void {
		$addr = self::normalize_addr( $from_addr );
		if ( $addr === '' ) { return; }
		$dom  = self::domain_of( $addr );
		self::upsert_delta( $customer_id, $addr, $dom, [
			'quarantine_undo_count' => 1,
		] );
	}

	public static function record_quarantine_kept( int $customer_id, string $from_addr ) : void {
		$addr = self::normalize_addr( $from_addr );
		if ( $addr === '' ) { return; }
		$dom  = self::domain_of( $addr );
		self::upsert_delta( $customer_id, $addr, $dom, [
			'quarantine_kept_count' => 1,
		] );
	}

	/**
	 * Zentraler Upsert: legt die Row bei Bedarf neu an (dann received=0 etc.),
	 * addiert die uebergebenen Delta-Werte, und aktualisiert last_seen/updated.
	 * Statt raw-SQL zu bauen benutzen wir wpdb->query() mit einer expliziten
	 * Column-Liste, damit nur die im $delta enthaltenen Counter erhoeht werden.
	 */
	private static function upsert_delta( int $customer_id, string $addr, string $dom, array $delta, ?string $seen_at = null ) : void {
		global $wpdb;
		$t = self::table();
		$seen_at = $seen_at ?: current_time( 'mysql', true );

		$col_names = [ 'customer_id', 'from_addr', 'from_domain',
			'received_count', 'whitelist_count', 'blacklist_count',
			'quarantine_undo_count', 'quarantine_kept_count',
			'first_seen_at', 'last_seen_at', 'updated_at' ];
		$col_vals = [
			(string) $customer_id,
			$wpdb->_real_escape( $addr ),
			$wpdb->_real_escape( $dom ),
			(string) ( (int) ( $delta['received_count']        ?? 0 ) ),
			(string) ( (int) ( $delta['whitelist_count']       ?? 0 ) ),
			(string) ( (int) ( $delta['blacklist_count']       ?? 0 ) ),
			(string) ( (int) ( $delta['quarantine_undo_count'] ?? 0 ) ),
			(string) ( (int) ( $delta['quarantine_kept_count'] ?? 0 ) ),
			$wpdb->_real_escape( $seen_at ),
			$wpdb->_real_escape( $seen_at ),
			$wpdb->_real_escape( current_time( 'mysql', true ) ),
		];

		$update_parts = [];
		foreach ( [ 'received_count', 'whitelist_count', 'blacklist_count',
					'quarantine_undo_count', 'quarantine_kept_count' ] as $col ) {
			$inc = (int) ( $delta[ $col ] ?? 0 );
			if ( $inc !== 0 ) {
				$update_parts[] = "{$col} = {$col} + {$inc}";
			}
		}
		$update_parts[] = "last_seen_at = GREATEST(last_seen_at, '" . $wpdb->_real_escape( $seen_at ) . "')";
		$update_parts[] = "updated_at   = '" . $wpdb->_real_escape( current_time( 'mysql', true ) ) . "'";

		$sql  = "INSERT INTO {$t} (" . implode( ',', $col_names ) . ") "
			  . "VALUES ('" . implode( "','", $col_vals ) . "') "
			  . 'ON DUPLICATE KEY UPDATE ' . implode( ', ', $update_parts );
		$wpdb->query( $sql );
	}

	/**
	 * Liest Score fuer eine From-Adresse. Returns:
	 *  [
	 *    'score'   => int          negative Werte = trusted, positive = misstrauisch,
	 *    'signals' => string[],    Kurztexte fuer scan_reasons.description,
	 *    'row'     => array|null,  raw DB-Row wenn vorhanden, sonst null,
	 *  ]
	 *
	 * Formel:
	 *  - Adress-Trust:
	 *      received >= 10 → -10, >= 50 → -20 (kumulativ nicht)
	 *      whitelist_count >= 1 → -30
	 *      quarantine_undo_count → -20 pro Undo, max -40
	 *      quarantine_kept_count >= 2 → +30 (User bestaetigte Verdict)
	 *  - Domain-Trust (nur wenn Adress-Trust > -20, sonst wirkt eh schon):
	 *      SUM(received_count) fuer Domain >= 20 → -10 extra
	 *  - Untergrenze -60, damit LLM-dangerous nicht komplett kompensiert wird.
	 */
	public static function get_score( int $customer_id, string $from_addr ) : array {
		$addr = self::normalize_addr( $from_addr );
		if ( $addr === '' ) {
			return [ 'score' => 0, 'signals' => [], 'row' => null ];
		}
		$dom = self::domain_of( $addr );

		global $wpdb;
		$t = self::table();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE customer_id = %d AND from_addr = %s LIMIT 1",
			$customer_id, $addr
		), ARRAY_A );

		$score   = 0;
		$signals = [];

		if ( $row ) {
			$received  = (int) $row['received_count'];
			$whitelist = (int) $row['whitelist_count'];
			$undo      = (int) $row['quarantine_undo_count'];
			$kept      = (int) $row['quarantine_kept_count'];

			if ( $received >= 50 ) {
				$score -= 20;
				$signals[] = sprintf( 'Stammabsender (%d fruehere Mails)', $received );
			} elseif ( $received >= 10 ) {
				$score -= 10;
				$signals[] = sprintf( 'Regelmaessiger Absender (%d fruehere Mails)', $received );
			}
			if ( $whitelist >= 1 ) {
				$score -= 30;
				$signals[] = 'als sicher markiert';
			}
			if ( $undo >= 1 ) {
				$boost = min( 40, 20 * $undo );
				$score -= $boost;
				$signals[] = sprintf( 'Auto-Quarantaene %s zurueckgeholt', $undo === 1 ? 'einmal' : $undo . 'x' );
			}
			if ( $kept >= 2 ) {
				$score += 30;
				$signals[] = sprintf( 'Auto-Quarantaene %dx bestaetigt (endgueltig geloescht)', $kept );
			}
		}

		if ( $dom !== '' && $score > -20 ) {
			$dom_received = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COALESCE(SUM(received_count),0) FROM {$t}
				 WHERE customer_id = %d AND from_domain = %s",
				$customer_id, $dom
			) );
			if ( $dom_received >= 20 ) {
				$score -= 10;
				$signals[] = sprintf( 'Bekannte Domain @%s (%d fruehere Mails)', $dom, $dom_received );
			}
		}

		if ( $score < -60 ) { $score = -60; }

		return [
			'score'   => $score,
			'signals' => $signals,
			'row'     => $row ?: null,
		];
	}
}
