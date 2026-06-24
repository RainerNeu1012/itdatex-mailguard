<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Admin;

use Itdatex\Mailguard\Customer\Account;
use Itdatex\Mailguard\Installer;

/**
 * WP-Admin → MailGuard → Endkunden.
 * Liste, Such, Suspend/Activate, Loeschen.
 */
final class Customers {

	public const PAGE_SLUG = 'itdatex-mailguard-customers';
	public const CAPABILITY = 'manage_options';

	public static function add_menu() : void {
		add_submenu_page(
			Settings::PAGE_SLUG,
			__( 'Endkunden', 'itdatex-mailguard' ),
			__( 'Endkunden', 'itdatex-mailguard' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ __CLASS__, 'render' ]
		);
	}

	public static function handle_actions() : void {
		if ( ! is_admin() ) { return; }
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== self::PAGE_SLUG ) { return; }
		if ( ! isset( $_GET['action'], $_GET['cid'] ) ) { return; }
		if ( ! current_user_can( self::CAPABILITY ) ) { return; }

		$action = sanitize_key( (string) $_GET['action'] );
		$cid    = (int) $_GET['cid'];
		$nonce  = (string) ( $_GET['_wpnonce'] ?? '' );
		if ( ! wp_verify_nonce( $nonce, 'mg_customer_' . $action . '_' . $cid ) ) {
			wp_die( esc_html__( 'Ungueltiger Nonce.', 'itdatex-mailguard' ) );
		}
		$row = Account::find_by_id( $cid );
		if ( ! $row ) {
			wp_safe_redirect( self::list_url( [ 'msg' => 'not_found' ] ) );
			exit;
		}
		switch ( $action ) {
			case 'suspend':  Account::set_status( $cid, 'suspended' ); $msg = 'suspended'; break;
			case 'activate': Account::set_status( $cid, 'active' );    $msg = 'activated'; break;
			case 'delete':   Account::purge( $cid );                   $msg = 'deleted';   break;
			default: return;
		}
		wp_safe_redirect( self::list_url( [ 'msg' => $msg ] ) );
		exit;
	}

	public static function render() : void {
		if ( ! current_user_can( self::CAPABILITY ) ) { return; }
		global $wpdb;

		$per_page = 20;
		$page     = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$search   = trim( (string) ( $_GET['s'] ?? '' ) );

		$data   = Account::list_paginated( $page, $per_page, $search );
		$counts = Account::counts_by_status();
		$msg    = sanitize_key( (string) ( $_GET['msg'] ?? '' ) );

		$t_acc = $wpdb->prefix . Installer::TABLE_IMAP_ACCOUNTS;
		$t_msg = $wpdb->prefix . Installer::TABLE_MESSAGES;
		$ids   = array_map( static fn( $r ) => (int) $r['id'], $data['items'] );
		$acc_counts = $msg_counts = [];
		if ( $ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT customer_id, COUNT(*) c FROM {$t_acc} WHERE customer_id IN ({$placeholders}) GROUP BY customer_id", $ids ) ) as $r ) {
				$acc_counts[ (int) $r->customer_id ] = (int) $r->c;
			}
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT customer_id, COUNT(*) c FROM {$t_msg} WHERE customer_id IN ({$placeholders}) GROUP BY customer_id", $ids ) ) as $r ) {
				$msg_counts[ (int) $r->customer_id ] = (int) $r->c;
			}
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'MailGuard — Endkunden', 'itdatex-mailguard' ) . '</h1>';

		if ( $msg ) {
			$labels = [
				'suspended' => __( 'Konto wurde gesperrt.',     'itdatex-mailguard' ),
				'activated' => __( 'Konto wurde aktiviert.',    'itdatex-mailguard' ),
				'deleted'   => __( 'Konto wurde komplett geloescht (mit allen Daten).', 'itdatex-mailguard' ),
				'not_found' => __( 'Konto nicht gefunden.',     'itdatex-mailguard' ),
			];
			$label = $labels[ $msg ] ?? '';
			if ( $label ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $label ) . '</p></div>';
			}
		}

		echo '<p>';
		printf(
			esc_html__( 'Gesamt: %1$d · Aktiv: %2$d · Pending: %3$d · Gesperrt: %4$d', 'itdatex-mailguard' ),
			$counts['total'], $counts['active'], $counts['pending'], $counts['suspended']
		);
		echo '</p>';

		echo '<form method="get" style="margin-bottom:1em">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'E-Mail suchen …', 'itdatex-mailguard' ) . '" />';
		submit_button( __( 'Suchen', 'itdatex-mailguard' ), '', '', false );
		echo '</form>';

		if ( ! $data['items'] ) {
			echo '<p>' . esc_html__( 'Keine Endkunden.', 'itdatex-mailguard' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="wp-list-table widefat striped"><thead><tr>';
		echo '<th>ID</th><th>E-Mail</th><th>Status</th><th>Verifiziert</th><th>Postfächer</th><th>Mails</th><th>Erstellt</th><th>Letzter Login</th><th>Aktion</th>';
		echo '</tr></thead><tbody>';

		foreach ( $data['items'] as $row ) {
			$cid = (int) $row['id'];
			$status = (string) $row['status'];
			$pill = match ( $status ) {
				'active'    => '<span style="color:#00a32a;font-weight:600">aktiv</span>',
				'suspended' => '<span style="color:#d63638;font-weight:600">gesperrt</span>',
				default     => '<span style="color:#646970">pending</span>',
			};
			echo '<tr>';
			echo '<td>' . $cid . '</td>';
			echo '<td><code>' . esc_html( (string) $row['email'] ) . '</code></td>';
			echo '<td>' . $pill . '</td>';
			echo '<td>' . ( (int) $row['email_verified'] ? '✓' : '–' ) . '</td>';
			echo '<td>' . ( $acc_counts[ $cid ] ?? 0 ) . '</td>';
			echo '<td>' . ( $msg_counts[ $cid ] ?? 0 ) . '</td>';
			echo '<td>' . esc_html( self::fmt_date( (string) $row['created_at'] ) ) . '</td>';
			echo '<td>' . esc_html( $row['last_login_at'] ? self::fmt_date( (string) $row['last_login_at'] ) : '–' ) . '</td>';
			echo '<td>';
			if ( $status === 'suspended' ) {
				printf( '<a href="%s" class="button">%s</a> ', esc_url( self::action_url( 'activate', $cid ) ), esc_html__( 'Aktivieren', 'itdatex-mailguard' ) );
			} else {
				printf( '<a href="%s" class="button">%s</a> ', esc_url( self::action_url( 'suspend', $cid ) ), esc_html__( 'Sperren', 'itdatex-mailguard' ) );
			}
			$confirm = esc_attr__( 'Wirklich KOMPLETT löschen (mit allen Postfächern, Mails, Regeln)? Nicht umkehrbar.', 'itdatex-mailguard' );
			printf(
				'<a href="%1$s" class="button button-link-delete" onclick="return confirm(\'%2$s\')">%3$s</a>',
				esc_url( self::action_url( 'delete', $cid ) ),
				$confirm,
				esc_html__( 'Löschen', 'itdatex-mailguard' )
			);
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		$total_pages = max( 1, (int) ceil( $data['total'] / $per_page ) );
		if ( $total_pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo paginate_links( [
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $page,
				'total'     => $total_pages,
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
			] );
			echo '</div></div>';
		}
		echo '</div>';
	}

	private static function action_url( string $action, int $cid ) : string {
		return wp_nonce_url(
			add_query_arg( [ 'page' => self::PAGE_SLUG, 'action' => $action, 'cid' => $cid ], admin_url( 'admin.php' ) ),
			'mg_customer_' . $action . '_' . $cid
		);
	}

	public static function list_url( array $extra = [] ) : string {
		return add_query_arg( array_merge( [ 'page' => self::PAGE_SLUG ], $extra ), admin_url( 'admin.php' ) );
	}

	private static function fmt_date( string $sql_gmt ) : string {
		$ts = strtotime( $sql_gmt . ' UTC' );
		if ( ! $ts ) { return $sql_gmt; }
		return wp_date( get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ), $ts );
	}
}
