<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Notifications;

use Itdatex\Mailguard\Installer;
use Itdatex\Mailguard\Outbound\ResendClient;

/**
 * Taeglicher/woechentlicher Zusammenfassungs-Mailer.
 *
 * Fuer jeden Customer mit digest_frequency != 'off' und ablauf des Intervals
 * seit digest_last_sent_at: Aggregat-SQL ueber letzte Periode + Resend-Call.
 *
 * Cron-Hook: itdatex_mailguard_digest — laeuft hourly, checkt pro Customer
 * ob jetzt-faellig. Daily + weekly share denselben Hook, Logik via
 * digest_frequency + Zeitstempel-Diff.
 */
final class DigestService {

	public static function run_cron() : void {
		global $wpdb;
		$t_cust = $wpdb->prefix . Installer::TABLE_CUSTOMERS;
		$customers = $wpdb->get_results(
			"SELECT id, email, digest_frequency, digest_last_sent_at
			 FROM {$t_cust}
			 WHERE status = 'active' AND digest_frequency IN ('daily','weekly')
			   AND email_verified = 1",
			ARRAY_A
		);
		if ( ! $customers ) { return; }
		$now = time();
		foreach ( $customers as $c ) {
			$interval = $c['digest_frequency'] === 'daily' ? DAY_IN_SECONDS : 7 * DAY_IN_SECONDS;
			$last_ts  = $c['digest_last_sent_at'] ? strtotime( $c['digest_last_sent_at'] . ' UTC' ) : 0;
			if ( $last_ts && ( $now - $last_ts ) < ( $interval - 60 ) ) { continue; }
			try {
				self::send_for_customer( (int) $c['id'], (string) $c['email'], (string) $c['digest_frequency'] );
			} catch ( \Throwable $e ) {
				// Fehler loggen aber Cron weiterlaufen
				error_log( '[MailGuard digest] customer=' . $c['id'] . ' error=' . $e->getMessage() );
			}
		}
	}

	public static function send_for_customer( int $customer_id, string $email, string $frequency ) : array {
		if ( $email === '' ) { return [ 'ok' => false, 'error' => 'no_email' ]; }
		$period_days = $frequency === 'weekly' ? 7 : 1;
		$agg = self::aggregate( $customer_id, $period_days );

		// Keine Aktivitaet UND keine marginalen Faelle? Daily skippen.
		// Weekly wird trotzdem gesendet (System-Lebenszeichen).
		if ( $frequency === 'daily' && $agg['total_actions'] === 0 && empty( $agg['marginal'] ) ) {
			self::mark_sent( $customer_id );
			return [ 'ok' => true, 'skipped' => 'no_activity' ];
		}

		$subject = $frequency === 'weekly'
			? sprintf( 'MailGuard Wochen-Report — %d Bedrohungen abgefangen', $agg['total_actions'] )
			: sprintf( 'MailGuard Tages-Report — %d Bedrohungen abgefangen', $agg['total_actions'] );
		$html = self::render_html( $agg, $frequency );
		$text = self::render_text( $agg, $frequency );

		$res = ResendClient::send( [
			'to'        => $email,
			'subject'   => $subject,
			'body_html' => $html,
			'body_text' => $text,
		] );
		if ( ! empty( $res['ok'] ) ) {
			self::mark_sent( $customer_id );
		}
		return $res;
	}

	private static function mark_sent( int $customer_id ) : void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . Installer::TABLE_CUSTOMERS,
			[ 'digest_last_sent_at' => gmdate( 'Y-m-d H:i:s' ) ],
			[ 'id' => $customer_id ]
		);
	}

	/**
	 * @return array{
	 *   purges:int, quarantines:int, unsubs:int, undone:int,
	 *   total_actions:int, top_senders:array<int,array{from:string,count:int}>,
	 *   total_scanned:int, clean:int, suspicious:int, dangerous:int,
	 *   period_days:int
	 * }
	 */
	public static function aggregate( int $customer_id, int $period_days ) : array {
		global $wpdb;
		$t_act = $wpdb->prefix . Installer::TABLE_ACTIONS;
		$t_msg = $wpdb->prefix . Installer::TABLE_MESSAGES;
		$t_uns = $wpdb->prefix . Installer::TABLE_UNSUBS;
		$since = gmdate( 'Y-m-d H:i:s', time() - $period_days * DAY_IN_SECONDS );

		$actions = $wpdb->get_results( $wpdb->prepare(
			"SELECT action, COUNT(*) AS cnt FROM {$t_act}
			 WHERE customer_id = %d AND created_at >= %s AND status = 'done'
			 GROUP BY action",
			$customer_id, $since
		), ARRAY_A );
		$by_action = [];
		foreach ( $actions as $a ) { $by_action[ (string) $a['action'] ] = (int) $a['cnt']; }
		$purges      = (int) ( $by_action['purge']      ?? 0 );
		$quarantines = (int) ( $by_action['quarantine'] ?? 0 );
		$undone      = (int) ( $by_action['undo_quarantine'] ?? 0 );

		$unsubs = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t_uns} WHERE customer_id = %d AND created_at >= %s AND api_status = 'unsubscribed'",
			$customer_id, $since
		) );

		$top = $wpdb->get_results( $wpdb->prepare(
			"SELECT from_addr_snap AS fa, COUNT(*) AS cnt FROM {$t_act}
			 WHERE customer_id = %d AND created_at >= %s
			   AND action IN ('purge','quarantine') AND status = 'done'
			   AND from_addr_snap != ''
			 GROUP BY from_addr_snap ORDER BY cnt DESC LIMIT 10",
			$customer_id, $since
		), ARRAY_A );

		$scan = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS total,
			        SUM(scan_verdict='clean') AS clean,
			        SUM(scan_verdict='suspicious') AS susp,
			        SUM(scan_verdict='dangerous') AS danger
			 FROM {$t_msg}
			 WHERE customer_id = %d AND fetched_at >= %s AND scan_status='done'",
			$customer_id, $since
		), ARRAY_A );

		// Grauzone: Mails mit Score 30-49 aus der Periode. Das sind die
		// Faelle wo die Heuristik sich am meisten vertan haben koennte —
		// User sollte die kurz checken. Max 5, damit die Mail lesbar bleibt.
		$marginal = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, subject, from_addr, scan_score, scan_verdict, fetched_at
			 FROM {$t_msg}
			 WHERE customer_id = %d AND fetched_at >= %s AND scan_status='done'
			   AND scan_score BETWEEN 30 AND 49
			 ORDER BY ABS(scan_score - 40), fetched_at DESC
			 LIMIT 5",
			$customer_id, $since
		), ARRAY_A );

		return [
			'purges'        => $purges,
			'quarantines'   => $quarantines,
			'undone'        => $undone,
			'unsubs'        => $unsubs,
			'total_actions' => $purges + $quarantines + $unsubs,
			'top_senders'   => array_map( static fn( $r ) => [ 'from' => (string) $r['fa'], 'count' => (int) $r['cnt'] ], $top ?: [] ),
			'total_scanned' => (int) ( $scan['total']  ?? 0 ),
			'clean'         => (int) ( $scan['clean']  ?? 0 ),
			'suspicious'    => (int) ( $scan['susp']   ?? 0 ),
			'dangerous'     => (int) ( $scan['danger'] ?? 0 ),
			'period_days'   => $period_days,
			'marginal'      => array_map( static fn( $r ) => [
				'id'         => (int) $r['id'],
				'subject'    => (string) $r['subject'],
				'from_addr'  => (string) $r['from_addr'],
				'score'      => (int) $r['scan_score'],
				'verdict'    => (string) $r['scan_verdict'],
				'fetched_at' => (string) $r['fetched_at'],
			], $marginal ?: [] ),
		];
	}

	private static function render_html( array $a, string $freq ) : string {
		$label = $freq === 'weekly' ? 'Woche' : 'Tag';
		$top_html = '';
		if ( $a['top_senders'] ) {
			$top_html = '<h2 style="font-size:14px;margin:20px 0 6px;color:#555;text-transform:uppercase;letter-spacing:.08em">Top-Blocked Absender</h2><ul style="padding:0;margin:0;list-style:none;font-family:ui-monospace,Menlo,monospace;font-size:12px">';
			foreach ( $a['top_senders'] as $t ) {
				$top_html .= '<li style="padding:4px 0;border-bottom:1px solid #eee"><strong>' . esc_html( $t['from'] ) . '</strong> · ' . (int) $t['count'] . '×</li>';
			}
			$top_html .= '</ul>';
		}
		$row = function( $label, $value, $color = '#111' ) {
			return '<tr><td style="padding:10px 0;color:#666;font-size:13px">' . esc_html( $label ) . '</td><td style="padding:10px 0;text-align:right;color:' . $color . ';font-weight:700;font-size:18px;font-family:ui-monospace,Menlo,monospace">' . (int) $value . '</td></tr>';
		};
		return '<!doctype html><html><body style="margin:0;padding:24px;background:#f5f5f5;font-family:ui-sans-serif,system-ui">
<div style="max-width:560px;margin:0 auto;background:#fff;padding:28px;border-radius:12px;border:1px solid #e5e5e5">
<div style="font-size:11px;color:#888;letter-spacing:.14em;text-transform:uppercase;margin-bottom:4px">MailGuard · Report</div>
<h1 style="font-size:22px;margin:0 0 4px;color:#111">Deine letzte ' . esc_html( $label ) . '</h1>
<p style="color:#666;margin:0 0 18px;font-size:14px">' . (int) $a['total_actions'] . ' Aktionen · ' . (int) $a['total_scanned'] . ' Mails gescannt</p>
<table style="width:100%;border-collapse:collapse;border-top:1px solid #eee">' .
	$row( 'In Quarantäne verschoben', $a['quarantines'], '#d29922' ) .
	$row( 'Endgültig gelöscht', $a['purges'], '#d94848' ) .
	$row( 'Newsletter abgemeldet', $a['unsubs'], '#3fb950' ) .
	$row( 'Davon rückgängig gemacht', $a['undone'], '#888' ) .
'</table>
<h2 style="font-size:14px;margin:24px 0 6px;color:#555;text-transform:uppercase;letter-spacing:.08em">Scan-Verteilung</h2>
<table style="width:100%;border-collapse:collapse;border-top:1px solid #eee">' .
	$row( 'Sauber', $a['clean'], '#3fb950' ) .
	$row( 'Verdächtig', $a['suspicious'], '#d29922' ) .
	$row( 'Gefährlich', $a['dangerous'], '#d94848' ) .
'</table>' .
$top_html .
self::render_marginal_html( $a['marginal'] ?? [] ) .
'<p style="color:#888;font-size:12px;margin:24px 0 0;line-height:1.5">
Dieser Report kommt ' . ( $freq === 'weekly' ? 'wöchentlich' : 'täglich' ) . '. Frequenz ändern oder abschalten:
<a href="https://wp.itdatex.support/portal/plan" style="color:#2563eb">Portal → Einstellungen</a>.
</p>
</div></body></html>';
	}

	private static function render_marginal_html( array $cases ) : string {
		if ( ! $cases ) { return ''; }
		$out = '<h2 style="font-size:14px;margin:24px 0 6px;color:#555;text-transform:uppercase;letter-spacing:.08em">Grauzonen-Check (Score 30-49)</h2>';
		$out .= '<p style="color:#888;font-size:12px;margin:0 0 10px">Heuristik unsicher — ein kurzer Blick lohnt. Klick auf einen Eintrag oeffnet ihn im Portal.</p>';
		$out .= '<table style="width:100%;border-collapse:collapse;border-top:1px solid #eee;font-size:13px">';
		foreach ( $cases as $c ) {
			$score_color = $c['score'] >= 40 ? '#d29922' : '#9a6700';
			$from_short  = esc_html( mb_substr( (string) $c['from_addr'], 0, 36 ) );
			$subj_short  = esc_html( mb_substr( (string) $c['subject'], 0, 60 ) );
			$url         = 'https://wp.itdatex.support/portal/inbox?msg_id=' . (int) $c['id'];
			$out .= '<tr><td style="padding:8px 0;border-bottom:1px solid #f0f0f0">
				<a href="' . esc_url( $url ) . '" style="color:#111;text-decoration:none">
					<strong>' . $subj_short . '</strong><br>
					<span style="color:#888;font-size:11px">von ' . $from_short . '</span>
				</a>
			</td><td style="padding:8px 0;border-bottom:1px solid #f0f0f0;text-align:right;color:' . $score_color . ';font-family:ui-monospace,Menlo,monospace;font-weight:700">' . (int) $c['score'] . '</td></tr>';
		}
		$out .= '</table>';
		return $out;
	}

	private static function render_text( array $a, string $freq ) : string {
		$label = $freq === 'weekly' ? 'WOCHEN-REPORT' : 'TAGES-REPORT';
		$lines = [
			'MailGuard · ' . $label,
			'',
			'  In Quarantäne verschoben: ' . $a['quarantines'],
			'  Endgültig gelöscht:        ' . $a['purges'],
			'  Newsletter abgemeldet:     ' . $a['unsubs'],
			'  Davon rückgängig gemacht:  ' . $a['undone'],
			'',
			'Scan-Verteilung (' . $a['total_scanned'] . ' Mails):',
			'  Sauber:      ' . $a['clean'],
			'  Verdächtig:  ' . $a['suspicious'],
			'  Gefährlich:  ' . $a['dangerous'],
		];
		if ( $a['top_senders'] ) {
			$lines[] = '';
			$lines[] = 'Top-Blocked Absender:';
			foreach ( $a['top_senders'] as $t ) {
				$lines[] = sprintf( '  %-40s %dx', $t['from'], $t['count'] );
			}
		}
		if ( ! empty( $a['marginal'] ) ) {
			$lines[] = '';
			$lines[] = 'Grauzonen-Check (Score 30-49):';
			foreach ( $a['marginal'] as $c ) {
				$lines[] = sprintf( '  [%d] %s · von %s', $c['score'], mb_substr( $c['subject'], 0, 50 ), mb_substr( $c['from_addr'], 0, 30 ) );
				$lines[] = '      https://wp.itdatex.support/portal/inbox?msg_id=' . $c['id'];
			}
		}
		$lines[] = '';
		$lines[] = 'Frequenz ändern: https://wp.itdatex.support/portal/plan';
		return implode( "\n", $lines );
	}
}
