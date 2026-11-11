<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish\Signals;

use Itdatex\Mailguard\Installer;

/**
 * Bayesian-Light-Learning aus mg_llm_feedback: wenn der User fuer einen
 * Sender wiederholt LLM-Verdicts bestaetigt (👎 = "ja, Phishing richtig",
 * oder 👍 = "nein, eigentlich clean"), lernen wir die Sender-Policy.
 *
 * Signal (per from_addr aggregiert, letzte 180 Tage):
 *   - 👎-Count (verdict stimmt) = Phishing-Bestaetigung → Boost
 *   - 👍-Count (verdict falsch) = Clean-Korrektur → Dampen
 *
 * Score-Modifier (gecapt bei ±40):
 *   score += (down_count * 10) - (up_count * 15)
 *   up haeufiger gewichtet als down, damit 1 "falsche Alarm"-Markierung
 *   mehr Vertrauensgewinn bringt als 1 "ja das war Phishing" (das wussten
 *   wir schon, deswegen hat das LLM es ja markiert).
 *
 * Cache: Transient pro from_addr, 6h TTL. Feedback-Rate ist niedrig genug.
 */
final class UserFeedbackTrust {

	private const CACHE_TTL      = 6 * HOUR_IN_SECONDS;
	private const LOOKBACK_DAYS  = 180;
	private const UP_WEIGHT      = -15;   // negatives Delta = Clean-Signal
	private const DOWN_WEIGHT    = 10;    // positives Delta = Phish-Signal
	private const SCORE_CAP      = 40;

	public static function check( int $customer_id, string $from_addr ) : ?array {
		$from = strtolower( trim( $from_addr ) );
		if ( $customer_id <= 0 || $from === '' || ! str_contains( $from, '@' ) ) { return null; }

		$cache_key = 'mg_ufb_' . $customer_id . '_' . md5( $from );
		$cached = get_transient( $cache_key );
		if ( $cached !== false ) {
			if ( $cached === 'none' ) { return null; }
			return (array) $cached;
		}

		global $wpdb;
		$t = $wpdb->prefix . Installer::TABLE_LLM_FEEDBACK;
		$since = gmdate( 'Y-m-d H:i:s', time() - self::LOOKBACK_DAYS * DAY_IN_SECONDS );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT SUM(thumbs='up') AS ups, SUM(thumbs='down') AS downs
			 FROM {$t}
			 WHERE customer_id = %d AND LOWER(from_addr_snap) = %s AND created_at >= %s",
			$customer_id, $from, $since
		), ARRAY_A );

		$ups   = (int) ( $row['ups']   ?? 0 );
		$downs = (int) ( $row['downs'] ?? 0 );
		if ( $ups + $downs < 1 ) {
			set_transient( $cache_key, 'none', self::CACHE_TTL );
			return null;
		}

		$delta = ( $downs * self::DOWN_WEIGHT ) + ( $ups * self::UP_WEIGHT );
		if ( $delta > self::SCORE_CAP )  { $delta = self::SCORE_CAP; }
		if ( $delta < -self::SCORE_CAP ) { $delta = -self::SCORE_CAP; }
		if ( $delta === 0 ) {
			set_transient( $cache_key, 'none', self::CACHE_TTL );
			return null;
		}

		$description = $delta > 0
			? sprintf(
				'Du hast %d LLM-Phishing-Verdict(e) fuer diesen Sender bestaetigt (👎). Score entsprechend erhoeht (+%d).',
				$downs, $delta
			)
			: sprintf(
				'Du hast %d LLM-Phishing-Verdict(e) fuer diesen Sender als falschen Alarm markiert (👍). Score entsprechend gesenkt (%d).',
				$ups, $delta
			);

		$reason = [
			'rule'        => $delta > 0 ? 'user_feedback_boost' : 'user_feedback_trust',
			'description' => $description,
			'score'       => $delta,
		];
		set_transient( $cache_key, $reason, self::CACHE_TTL );
		return $reason;
	}
}
