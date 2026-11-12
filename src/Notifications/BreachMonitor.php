<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Notifications;

use Itdatex\Mailguard\Installer;

/**
 * Dark-Web-Breach-Monitoring via XposedOrNot API (kostenlos, kein Key).
 *
 * Fuer jede aktive Customer-Email + jede IMAP-Account-Email pro Customer
 * wird taeglich geprueft, ob sie in einem neu bekannt gewordenen Breach
 * auftaucht. Neue Breaches landen in mg_breach_alerts (UNIQUE pro
 * customer+email+breach) und werden im Portal + Digest-Email angezeigt.
 *
 * Rate-Limit: XON erlaubt ~60 req/hour. Wir checken daily, max N emails
 * pro Lauf. Transient-Cache pro Email 24h damit Mehrfach-Trigger nicht
 * doppelt callen.
 */
final class BreachMonitor {

	private const API_URL     = 'https://api.xposedornot.com/v1/breach-analytics';
	private const API_TIMEOUT = 8;
	private const MAX_EMAILS_PER_RUN = 50;

	public static function run_cron() : void {
		$emails = self::collect_customer_emails();
		if ( ! $emails ) { return; }

		$checked = 0;
		foreach ( $emails as $ce ) {
			if ( $checked >= self::MAX_EMAILS_PER_RUN ) { break; }
			if ( self::is_cached_recent( $ce['email'] ) ) { continue; }
			try {
				$breaches = self::fetch_breaches( $ce['email'] );
				self::persist_breaches( (int) $ce['customer_id'], $ce['email'], $breaches );
			} catch ( \Throwable $e ) {
				error_log( '[MG breach] email=' . $ce['email'] . ' err=' . $e->getMessage() );
			}
			$checked++;
			// Kleine Pause damit XON uns nicht throttled
			usleep( 150000 );
		}
	}

	public static function count_unread( int $customer_id ) : int {
		global $wpdb;
		$t = $wpdb->prefix . Installer::TABLE_BREACH_ALERTS;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t} WHERE customer_id = %d AND read_at IS NULL",
			$customer_id
		) );
	}

	public static function list_for_customer( int $customer_id, int $limit = 50 ) : array {
		global $wpdb;
		$t = $wpdb->prefix . Installer::TABLE_BREACH_ALERTS;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, email, breach_name, breach_date, pwned_count, data_classes, created_at, read_at
			 FROM {$t} WHERE customer_id = %d
			 ORDER BY created_at DESC LIMIT %d",
			$customer_id, $limit
		), ARRAY_A );
		return array_map( static function( $r ) {
			$r['id']          = (int) $r['id'];
			$r['pwned_count'] = $r['pwned_count'] !== null ? (int) $r['pwned_count'] : null;
			return $r;
		}, $rows ?: [] );
	}

	public static function mark_all_read( int $customer_id ) : int {
		global $wpdb;
		$t = $wpdb->prefix . Installer::TABLE_BREACH_ALERTS;
		return (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$t} SET read_at = %s WHERE customer_id = %d AND read_at IS NULL",
			gmdate( 'Y-m-d H:i:s' ), $customer_id
		) );
	}

	/**
	 * @return array<array{customer_id:int, email:string}>
	 */
	private static function collect_customer_emails() : array {
		global $wpdb;
		$t_c = $wpdb->prefix . Installer::TABLE_CUSTOMERS;
		$t_a = $wpdb->prefix . Installer::TABLE_IMAP_ACCOUNTS;
		// Customer-Login-Email
		$list = $wpdb->get_results(
			"SELECT id AS customer_id, email FROM {$t_c} WHERE status = 'active' AND email_verified = 1",
			ARRAY_A
		);
		// Plus angebundene IMAP-Account-Usernames (= echte Posteingangs-Emails)
		$acc = $wpdb->get_results(
			"SELECT customer_id, username AS email FROM {$t_a} WHERE status = 'active'",
			ARRAY_A
		);
		$merged = array_merge( $list ?: [], $acc ?: [] );
		// Dedupe (customer_id, email) damit wir nicht fuer Rainer mehrfach checken
		$seen = [];
		$out = [];
		foreach ( $merged as $e ) {
			$key = $e['customer_id'] . '|' . strtolower( $e['email'] );
			if ( isset( $seen[ $key ] ) ) { continue; }
			$seen[ $key ] = true;
			$out[] = [ 'customer_id' => (int) $e['customer_id'], 'email' => strtolower( (string) $e['email'] ) ];
		}
		return $out;
	}

	private static function is_cached_recent( string $email ) : bool {
		$key = 'mg_brh_' . md5( strtolower( $email ) );
		return get_transient( $key ) !== false;
	}

	/**
	 * @return array<array{name:string,date:string,count:?int,data_classes:string}>
	 */
	private static function fetch_breaches( string $email ) : array {
		$resp = wp_remote_get( self::API_URL . '?email=' . rawurlencode( $email ), [
			'timeout' => self::API_TIMEOUT,
			'headers' => [ 'Accept' => 'application/json', 'User-Agent' => 'MailGuard/1.0 (+https://wp.itdatex.support)' ],
		] );
		// Markiere als "frisch gefragt" egal was returnt wurde (reduziert Rate-Load)
		set_transient( 'mg_brh_' . md5( strtolower( $email ) ), 1, DAY_IN_SECONDS );
		if ( is_wp_error( $resp ) ) { return []; }
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code !== 200 ) { return []; }
		$json = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $json ) ) { return []; }

		// XON antwortet mit {ExposedBreaches: {breaches_details: [{breach,xposed_date,xposed_records,xposed_data,industry,domain,...}]}}
		// Keine Treffer: {Error: 'Not found'}
		$details = $json['ExposedBreaches']['breaches_details'] ?? null;
		if ( ! is_array( $details ) ) { return []; }

		$out = [];
		foreach ( $details as $b ) {
			$name = (string) ( $b['breach'] ?? '' );
			if ( $name === '' ) { continue; }
			$date = self::normalize_breach_date(
				(string) ( $b['xposed_date'] ?? '' ),
				(string) ( $b['added'] ?? '' )
			);
			$count = isset( $b['xposed_records'] ) ? (int) $b['xposed_records'] : null;
			$dc = (string) ( $b['xposed_data'] ?? '' );
			$out[] = [
				'name'         => $name,
				'date'         => $date,
				'count'        => $count,
				'data_classes' => $dc,
			];
		}
		return $out;
	}

	/**
	 * XposedOrNot liefert `xposed_date` meist als Jahr ("2025"),
	 * manchmal als YYYY-MM oder YYYY-MM-DD. `added` ist ein voller ISO-Timestamp.
	 * Rueckgabe: YYYY-MM-DD oder null.
	 */
	private static function normalize_breach_date( string $xposed, string $added ) : ?string {
		$x = trim( $xposed );
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $x, $m ) ) {
			return $m[1] . '-' . $m[2] . '-' . $m[3];
		}
		if ( preg_match( '/^(\d{4})-(\d{2})$/', $x, $m ) ) {
			return $m[1] . '-' . $m[2] . '-01';
		}
		if ( preg_match( '/^(\d{4})$/', $x, $m ) ) {
			return $m[1] . '-01-01';
		}
		// Fallback: `added` ist ISO 8601 (z.B. "2026-06-15T05:23:45+00:00")
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $added, $m ) ) {
			return $m[1];
		}
		return null;
	}

	private static function persist_breaches( int $customer_id, string $email, array $breaches ) : void {
		if ( ! $breaches ) { return; }
		global $wpdb;
		$t = $wpdb->prefix . Installer::TABLE_BREACH_ALERTS;
		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( $breaches as $b ) {
			// UNIQUE key macht das idempotent — alter Treffer wird nicht neu geloggt
			if ( $b['date'] !== null ) {
				$wpdb->query( $wpdb->prepare(
					"INSERT IGNORE INTO {$t}
					 (customer_id, email, breach_name, breach_date, pwned_count, data_classes, created_at)
					 VALUES (%d, %s, %s, %s, %d, %s, %s)",
					$customer_id,
					$email,
					mb_substr( $b['name'], 0, 190 ),
					$b['date'],
					$b['count'] ?? 0,
					mb_substr( $b['data_classes'], 0, 500 ),
					$now
				) );
			} else {
				$wpdb->query( $wpdb->prepare(
					"INSERT IGNORE INTO {$t}
					 (customer_id, email, breach_name, pwned_count, data_classes, created_at)
					 VALUES (%d, %s, %s, %d, %s, %s)",
					$customer_id,
					$email,
					mb_substr( $b['name'], 0, 190 ),
					$b['count'] ?? 0,
					mb_substr( $b['data_classes'], 0, 500 ),
					$now
				) );
			}
		}
	}
}
