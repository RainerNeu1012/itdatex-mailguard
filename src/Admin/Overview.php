<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Admin;

use Itdatex\Mailguard\Installer;

/**
 * Operator-Dashboard fuer den Site-Owner. Zeigt Health aller Customers,
 * Scanner-Pipeline-Status, Cron-Health, Server-Load auf einen Blick.
 * Keine Endkunden-Feature — nur in WP-Admin unter MailGuard → Overview.
 */
final class Overview {

	public const PAGE_SLUG = 'itdatex-mailguard-overview';

	public static function add_menu() : void {
		add_submenu_page(
			Customers::PAGE_SLUG,
			__( 'Overview', 'itdatex-mailguard' ),
			__( 'Overview', 'itdatex-mailguard' ),
			'manage_options',
			self::PAGE_SLUG,
			[ __CLASS__, 'render' ]
		);
	}

	public static function render() : void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'nope' ); }
		global $wpdb;
		$t_msg = $wpdb->prefix . Installer::TABLE_MESSAGES;
		$t_act = $wpdb->prefix . Installer::TABLE_ACTIONS;
		$t_cust = $wpdb->prefix . Installer::TABLE_CUSTOMERS;
		$t_acc = $wpdb->prefix . Installer::TABLE_IMAP_ACCOUNTS;

		// Global counters
		$total_customers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_cust} WHERE status = 'active'" );
		$total_accounts  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_acc} WHERE status = 'active'" );
		$total_messages  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_msg}" );

		$today = $wpdb->get_row(
			"SELECT COUNT(*) AS total,
			        SUM(scan_verdict='dangerous') AS danger,
			        SUM(scan_verdict='suspicious') AS susp
			 FROM {$t_msg}
			 WHERE fetched_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
			ARRAY_A
		);
		$purges_24h = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$t_act}
			 WHERE action='purge' AND status='done' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
		);
		$pending_scan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_msg} WHERE scan_status = 'pending'" );
		$scan_errors  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_msg} WHERE scan_status = 'error'" );

		// Per-customer summary
		$per_cust = $wpdb->get_results(
			"SELECT c.id, c.email, c.plan_slug, c.digest_frequency,
			        (SELECT COUNT(*) FROM {$t_acc} WHERE customer_id = c.id AND status='active') AS accts,
			        (SELECT COUNT(*) FROM {$t_msg} WHERE customer_id = c.id AND fetched_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS mails_7d,
			        (SELECT COUNT(*) FROM {$t_act} WHERE customer_id = c.id AND action='purge' AND status='done' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS purges_7d,
			        (SELECT MAX(fetched_at) FROM {$t_msg} WHERE customer_id = c.id) AS last_fetch
			 FROM {$t_cust} c
			 WHERE c.status = 'active'
			 ORDER BY last_fetch DESC",
			ARRAY_A
		);

		// Cron health
		$crons = _get_cron_array() ?: [];
		$next_cron = [];
		foreach ( $crons as $ts => $events ) {
			foreach ( (array) $events as $hook => $v ) {
				if ( str_starts_with( $hook, 'itdatex_mailguard' ) ) {
					$next_cron[ $hook ] = $ts;
				}
			}
			if ( count( $next_cron ) >= 6 ) break;
		}

		// Server load
		$load = function_exists( 'sys_getloadavg' ) ? sys_getloadavg() : [ 0, 0, 0 ];
		$free_mem = '';
		if ( is_readable( '/proc/meminfo' ) ) {
			$mem = file_get_contents( '/proc/meminfo' );
			if ( preg_match( '/MemAvailable:\s+(\d+)/', $mem, $m ) ) {
				$free_mem = round( (int) $m[1] / 1024 / 1024, 1 ) . ' GB';
			}
		}

		?>
		<div class="wrap">
			<h1>MailGuard · Overview</h1>
			<p style="color:#666;margin-top:-6px">Live-Snapshot aller MailGuard-Metriken. Auto-refresh alle 60s empfohlen.</p>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:20px 0">
				<?php
				self::stat_card( 'Aktive Kunden', $total_customers );
				self::stat_card( 'IMAP-Konten', $total_accounts );
				self::stat_card( 'Mails gesamt', number_format( $total_messages, 0, ',', '.' ) );
				self::stat_card( 'Mails 24h', (int) ( $today['total'] ?? 0 ) );
				self::stat_card( 'Threats 24h', (int) ( ( $today['danger'] ?? 0 ) + ( $today['susp'] ?? 0 ) ), 'warn' );
				self::stat_card( 'Vernichtet 24h', $purges_24h, 'ok' );
				self::stat_card( 'Pending-Scan', $pending_scan, $pending_scan > 50 ? 'warn' : '' );
				self::stat_card( 'Scan-Errors', $scan_errors, $scan_errors > 0 ? 'err' : '' );
				?>
			</div>

			<h2>Server-Load</h2>
			<p style="font-family:ui-monospace,Menlo,monospace">
				Load 1/5/15: <strong><?php echo esc_html( sprintf( '%.2f / %.2f / %.2f', $load[0], $load[1], $load[2] ) ); ?></strong>
				<?php if ( $free_mem !== '' ) : ?>&nbsp;·&nbsp;Frei: <strong><?php echo esc_html( $free_mem ); ?></strong><?php endif; ?>
				&nbsp;·&nbsp;PHP <?php echo esc_html( PHP_VERSION ); ?>
			</p>

			<h2>Cron-Hooks</h2>
			<table class="wp-list-table widefat striped">
				<thead><tr><th>Hook</th><th>Next-Run</th><th>In</th></tr></thead>
				<tbody>
					<?php foreach ( $next_cron as $hook => $ts ) :
						$delta = $ts - time();
						if ( $delta < 0 ) {
							$in = '<span style="color:red">überfällig</span>';
						} elseif ( $delta < 60 ) {
							$in = $delta . 's';
						} elseif ( $delta < 3600 ) {
							$in = round( $delta / 60 ) . ' min';
						} else {
							$in = round( $delta / 3600, 1 ) . ' h';
						}
					?>
					<tr>
						<td><code><?php echo esc_html( $hook ); ?></code></td>
						<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', $ts ) ); ?> UTC</td>
						<td><?php echo $in; ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2>Kunden (letzte 7 Tage)</h2>
			<table class="wp-list-table widefat striped">
				<thead><tr><th>Email</th><th>Plan</th><th>Digest</th><th>Konten</th><th>Mails 7d</th><th>Purges 7d</th><th>Letzter Fetch</th></tr></thead>
				<tbody>
					<?php foreach ( $per_cust as $c ) : ?>
					<tr>
						<td><?php echo esc_html( $c['email'] ); ?></td>
						<td><code><?php echo esc_html( $c['plan_slug'] ); ?></code></td>
						<td><?php echo esc_html( $c['digest_frequency'] ?: 'off' ); ?></td>
						<td><?php echo (int) $c['accts']; ?></td>
						<td><?php echo number_format( (int) $c['mails_7d'], 0, ',', '.' ); ?></td>
						<td><?php echo (int) $c['purges_7d']; ?></td>
						<td><?php echo $c['last_fetch'] ? esc_html( $c['last_fetch'] ) : '—'; ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<meta http-equiv="refresh" content="60">
		<?php
	}

	private static function stat_card( string $label, $value, string $tone = '' ) : void {
		$color = $tone === 'warn' ? '#d29922' : ( $tone === 'err' ? '#d94848' : ( $tone === 'ok' ? '#3fb950' : '#111' ) );
		echo '<div style="border:1px solid #ddd;padding:12px 16px;border-radius:6px;background:#fff">';
		echo '<div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#888">' . esc_html( $label ) . '</div>';
		echo '<div style="font-size:1.8rem;font-weight:700;color:' . $color . ';margin-top:2px">' . esc_html( (string) $value ) . '</div>';
		echo '</div>';
	}
}
