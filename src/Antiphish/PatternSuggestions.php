<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish;

use Itdatex\Mailguard\Installer;

/**
 * Pattern-Vorschlaege: leitet aus den letzten N Stunden mg_messages
 * Cluster ab, fuer die eine einzelne Regel N Mails auf einmal wegraeumt.
 *
 * Cluster-Arten:
 *  - domain       : mehrere unterschiedliche Absender aus derselben Domain,
 *                   alle suspicious/dangerous, keiner whitelisted.
 *  - from_name    : identischer Anzeigename ueber mehrere Domains hinweg
 *                   (typischer Absender-Spoof: "Sparkasse" von 5 Fantasy-Domains).
 *  - subject      : identischer body_fingerprint = identischer Mail-Inhalt
 *                   von unterschiedlichen Absendern (Kampagne).
 *
 * Filter:
 *  - Existiert bereits eine passende Blacklist- oder Whitelist-Regel fuer
 *    Pattern+Match-Typ, wird der Vorschlag unterdrueckt. Sonst kaeme z.B.
 *    fuer eine bereits geblacklistete Domain ein Duplikat.
 *  - Ein Sender, den der User schon whitelisted hat, kickt seinen Cluster
 *    komplett raus.
 */
final class PatternSuggestions {

	private const DEFAULT_WINDOW_HOURS = 72;
	private const MIN_CLUSTER_SIZE     = 3;
	private const MIN_SCORE_FOR_HINT   = 30;
	private const MAX_SUGGESTIONS      = 15;

	/**
	 * @return array<int,array{
	 *   id:string, kind:string, match_type:string, pattern:string,
	 *   sample_count:int, verdict_dist:array<string,int>,
	 *   sample_senders:array<int,string>, sample_subjects:array<int,string>,
	 *   window_hours:int, reason_text:string
	 * }>
	 */
	public static function for_customer( int $customer_id, int $window_hours = self::DEFAULT_WINDOW_HOURS ) : array {
		global $wpdb;
		$window_hours = max( 6, min( 24 * 30, $window_hours ) );

		$t_msg   = $wpdb->prefix . Installer::TABLE_MESSAGES;
		$t_rule  = $wpdb->prefix . Installer::TABLE_RULES;
		$t_trust = $wpdb->prefix . Installer::TABLE_SENDER_TRUST;

		// Alle Mails im Fenster, die "verdaechtig" sind. Fetched_at fuer die
		// Zeitgrenze, damit auch Mails mit fehlendem Header-Datum reinfallen.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, from_addr, from_name, subject, scan_verdict, scan_score,
					body_fingerprint,
					LOWER(TRIM(SUBSTRING_INDEX(from_addr, '@', -1))) AS from_domain
			 FROM {$t_msg}
			 WHERE customer_id = %d
			   AND fetched_at >= (UTC_TIMESTAMP() - INTERVAL %d HOUR)
			   AND (scan_score IS NULL OR scan_score >= %d)
			   AND scan_verdict IN ('suspicious','dangerous','')
			   AND from_addr <> ''",
			$customer_id, $window_hours, self::MIN_SCORE_FOR_HINT
		), ARRAY_A ) ?: [];

		if ( empty( $rows ) ) { return []; }

		// Whitelist-Adressen des Users vorziehen — wir wollen die Cluster nicht
		// verwaessern (User hat sich schon entschieden).
		$wl_addrs = self::user_whitelisted_addrs( $customer_id );

		$by_domain  = [];
		$by_name    = [];
		$by_fp      = [];

		foreach ( $rows as $r ) {
			$addr = mb_strtolower( trim( (string) $r['from_addr'] ) );
			if ( $addr === '' || isset( $wl_addrs[ $addr ] ) ) { continue; }
			$dom  = (string) $r['from_domain'];
			$name = mb_strtolower( trim( (string) $r['from_name'] ) );
			$fp   = (string) $r['body_fingerprint'];
			$verd = (string) $r['scan_verdict'];
			if ( $verd === '' ) { $verd = 'suspicious'; }
			$subj = (string) $r['subject'];

			if ( $dom !== '' ) {
				$by_domain[ $dom ]['senders'][ $addr ]      = true;
				$by_domain[ $dom ]['msg_ids'][]             = (int) $r['id'];
				$by_domain[ $dom ]['verdicts'][ $verd ]     = ( $by_domain[ $dom ]['verdicts'][ $verd ] ?? 0 ) + 1;
				$by_domain[ $dom ]['subjects'][]            = $subj;
			}
			if ( $name !== '' && $dom !== '' ) {
				$by_name[ $name ]['senders'][ $addr ]       = true;
				$by_name[ $name ]['domains'][ $dom ]        = true;
				$by_name[ $name ]['msg_ids'][]              = (int) $r['id'];
				$by_name[ $name ]['verdicts'][ $verd ]      = ( $by_name[ $name ]['verdicts'][ $verd ] ?? 0 ) + 1;
				$by_name[ $name ]['subjects'][]             = $subj;
			}
			if ( $fp !== '' ) {
				$by_fp[ $fp ]['senders'][ $addr ]           = true;
				$by_fp[ $fp ]['msg_ids'][]                  = (int) $r['id'];
				$by_fp[ $fp ]['verdicts'][ $verd ]          = ( $by_fp[ $fp ]['verdicts'][ $verd ] ?? 0 ) + 1;
				$by_fp[ $fp ]['subjects'][]                 = $subj;
			}
		}

		$existing = self::existing_rule_patterns( $customer_id );

		$out = [];
		$claimed_msg_ids = [];

		// Reihenfolge bewusst: erst Domain (breitester Effekt pro Regel),
		// dann Anzeigename-Spoof (schwerer zu ersetzen), dann Fingerprint
		// (schmalster Match). Sobald eine Nachricht von einem staerkeren
		// Cluster beansprucht ist, faellt sie fuer schwaechere Cluster raus,
		// damit wir dem User keine drei redundanten Vorschlaege zeigen.

		foreach ( $by_domain as $dom => $c ) {
			$senders  = array_keys( $c['senders'] );
			if ( count( $senders ) < self::MIN_CLUSTER_SIZE )       { continue; }
			if ( isset( $existing['from_domain'][ $dom ] ) )        { continue; }
			$msg_count = count( $c['msg_ids'] );
			$verd_pretty = self::pretty_verdicts( $c['verdicts'] );
			$out[] = [
				'id'              => 'domain:' . $dom,
				'kind'            => 'blacklist',
				'match_type'      => 'from_domain',
				'pattern'         => $dom,
				'sample_count'    => $msg_count,
				'sender_count'    => count( $senders ),
				'verdict_dist'    => $c['verdicts'],
				'sample_senders'  => array_slice( $senders, 0, 5 ),
				'sample_subjects' => array_values( array_unique( array_slice( $c['subjects'], 0, 3 ) ) ),
				'window_hours'    => $window_hours,
				'reason_text'     => sprintf(
					'%d Mails von %d verschiedenen Absendern aus @%s in den letzten %dh (%s). Domain blockieren?',
					$msg_count, count( $senders ), $dom, $window_hours, $verd_pretty
				),
			];
			foreach ( $c['msg_ids'] as $mid ) { $claimed_msg_ids[ $mid ] = true; }
		}

		foreach ( $by_name as $name => $c ) {
			$senders = array_keys( $c['senders'] );
			$domains = array_keys( $c['domains'] );
			$remaining_msgs = array_filter( $c['msg_ids'], fn( $mid ) => ! isset( $claimed_msg_ids[ $mid ] ) );
			if ( count( $senders )        < self::MIN_CLUSTER_SIZE ) { continue; }
			if ( count( $domains )        < 2 )                      { continue; } // Ohne Multi-Domain kein Spoof-Signal, dann reicht der Domain-Cluster.
			if ( count( $remaining_msgs ) < self::MIN_CLUSTER_SIZE ) { continue; }
			if ( isset( $existing['from_name_contains'][ $name ] ) ) { continue; }
			$out[] = [
				'id'              => 'name:' . md5( $name ),
				'kind'            => 'blacklist',
				'match_type'      => 'from_name_contains',
				'pattern'         => $name,
				'sample_count'    => count( $remaining_msgs ),
				'sender_count'    => count( $senders ),
				'verdict_dist'    => $c['verdicts'],
				'sample_senders'  => array_slice( $senders, 0, 5 ),
				'sample_subjects' => array_values( array_unique( array_slice( $c['subjects'], 0, 3 ) ) ),
				'window_hours'    => $window_hours,
				'reason_text'     => sprintf(
					'Anzeigename "%s" taucht bei %d verschiedenen Absendern aus %d Domains auf — typischer Absender-Spoof.',
					$name, count( $senders ), count( $domains )
				),
			];
			foreach ( $remaining_msgs as $mid ) { $claimed_msg_ids[ $mid ] = true; }
		}

		foreach ( $by_fp as $fp => $c ) {
			$senders        = array_keys( $c['senders'] );
			$remaining_msgs = array_filter( $c['msg_ids'], fn( $mid ) => ! isset( $claimed_msg_ids[ $mid ] ) );
			// Ein einzelner Sender, der 4x dasselbe schickt, ist ein normaler
			// Newsletter — kein Kampagnen-Signal. Der Cluster lohnt nur, wenn
			// mehrere Sender denselben Inhalt streuen (Spoof-Kampagne).
			if ( count( $senders )        < 2 )                      { continue; }
			if ( count( $remaining_msgs ) < self::MIN_CLUSTER_SIZE ) { continue; }
			// Fuer Fingerprint-Cluster nutzen wir den ersten Subject als
			// menschenlesbaren Vorschlag, aber matchen ueber body_contains
			// mit einem Subject-Snippet — der User kann in der App nachbessern.
			$sample_subj = trim( (string) ( $c['subjects'][0] ?? '' ) );
			$snippet     = mb_substr( $sample_subj, 0, 60 );
			if ( $snippet === '' )                                 { continue; }
			if ( isset( $existing['subject_contains'][ mb_strtolower( $snippet ) ] ) ) { continue; }
			$out[] = [
				'id'              => 'fp:' . $fp,
				'kind'            => 'blacklist',
				'match_type'      => 'subject_contains',
				'pattern'         => $snippet,
				'sample_count'    => count( $remaining_msgs ),
				'sender_count'    => count( $senders ),
				'verdict_dist'    => $c['verdicts'],
				'sample_senders'  => array_slice( $senders, 0, 5 ),
				'sample_subjects' => [ $sample_subj ],
				'window_hours'    => $window_hours,
				'reason_text'     => sprintf(
					'%d Mails mit identischem Inhalt von %d Absendern — Kampagne. Betreff "%s" blocken?',
					count( $remaining_msgs ), count( $senders ), $snippet
				),
			];
		}

		// Ranked: hoehere sample_count zuerst — mehr Wirkung pro Klick.
		usort( $out, fn( $a, $b ) => $b['sample_count'] <=> $a['sample_count'] );
		return array_slice( $out, 0, self::MAX_SUGGESTIONS );
	}

	/**
	 * @return array<string,true>
	 */
	private static function user_whitelisted_addrs( int $customer_id ) : array {
		global $wpdb;
		$t = $wpdb->prefix . Installer::TABLE_RULES;
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT LOWER(pattern) FROM {$t}
			 WHERE customer_id = %d AND kind = 'whitelist' AND match_type = 'from_addr'",
			$customer_id
		) ) ?: [];
		$out = [];
		foreach ( $rows as $p ) { $out[ (string) $p ] = true; }
		return $out;
	}

	/**
	 * Index bestehender Blacklist/Whitelist-Regeln nach match_type + normalisiertem
	 * Pattern, damit wir Duplikat-Vorschlaege verhindern koennen.
	 *
	 * @return array<string,array<string,true>>
	 */
	private static function existing_rule_patterns( int $customer_id ) : array {
		global $wpdb;
		$t = $wpdb->prefix . Installer::TABLE_RULES;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT match_type, LOWER(pattern) AS pattern FROM {$t}
			 WHERE customer_id = %d",
			$customer_id
		), ARRAY_A ) ?: [];
		$out = [];
		foreach ( $rows as $r ) {
			$out[ (string) $r['match_type'] ][ (string) $r['pattern'] ] = true;
		}
		return $out;
	}

	private static function pretty_verdicts( array $dist ) : string {
		$parts = [];
		if ( ! empty( $dist['dangerous'] ) )  { $parts[] = $dist['dangerous']  . 'x gefaehrlich'; }
		if ( ! empty( $dist['suspicious'] ) ) { $parts[] = $dist['suspicious'] . 'x verdaechtig'; }
		return $parts ? implode( ', ', $parts ) : 'ohne klares Verdict';
	}
}
