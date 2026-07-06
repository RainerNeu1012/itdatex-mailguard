<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Admin;

use Itdatex\Mailguard\Customer\Account;
use Itdatex\Mailguard\Installer;
use Itdatex\Mailguard\Saas\Plans;

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
		if ( ! current_user_can( self::CAPABILITY ) ) { return; }

		// POST-Handler fuer den Plan-Editor. Vor der GET-Route eingebaut, damit
		// die Detail-Seite eigenstaendig gerendert werden kann (edit_plan-View
		// laeuft in render() weiter, wenn hier kein POST erkannt wurde).
		if ( $_SERVER['REQUEST_METHOD'] === 'POST'
			&& isset( $_POST['mg_action'] ) && $_POST['mg_action'] === 'save_plan'
			&& isset( $_POST['cid'] ) ) {
			$cid   = (int) $_POST['cid'];
			$nonce = (string) ( $_POST['_wpnonce'] ?? '' );
			if ( ! wp_verify_nonce( $nonce, 'mg_customer_save_plan_' . $cid ) ) {
				wp_die( esc_html__( 'Ungueltiger Nonce.', 'itdatex-mailguard' ) );
			}
			$row = Account::find_by_id( $cid );
			if ( ! $row ) {
				wp_safe_redirect( self::list_url( [ 'msg' => 'not_found' ] ) );
				exit;
			}
			$plan_slug = sanitize_key( (string) ( $_POST['plan_slug'] ?? '' ) );
			$plan      = Plans::get( $plan_slug );
			if ( ! $plan ) {
				wp_safe_redirect( self::list_url( [ 'msg' => 'plan_unknown' ] ) );
				exit;
			}
			$grace_raw = trim( (string) ( $_POST['grace_until'] ?? '' ) );
			$grace_ts  = null;
			if ( $grace_raw !== '' ) {
				// HTML5 date input format: YYYY-MM-DD → als UTC-Datum-Ende-des-Tags interpretieren.
				$ts = strtotime( $grace_raw . ' 23:59:59 UTC' );
				if ( $ts && $ts > time() ) { $grace_ts = $ts; }
			}
			Account::set_plan_manual( $cid, $plan, $grace_ts );
			if ( ! empty( $_POST['force_verify'] ) ) {
				Account::mark_email_verified( $cid );
			}
			wp_safe_redirect( self::list_url( [ 'msg' => 'plan_saved' ] ) );
			exit;
		}

		if ( ! isset( $_GET['action'], $_GET['cid'] ) ) { return; }

		$action = sanitize_key( (string) $_GET['action'] );
		if ( $action === 'edit_plan' ) { return; }  // Detail-View wird in render() gerendert

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
			case 'suspend':      Account::set_status( $cid, 'suspended' );     $msg = 'suspended';     break;
			case 'activate':     Account::set_status( $cid, 'active' );        $msg = 'activated';     break;
			case 'delete':       Account::purge( $cid );                       $msg = 'deleted';       break;
			case 'verify_email': Account::mark_email_verified( $cid );         $msg = 'email_verified'; break;
			default: return;
		}
		wp_safe_redirect( self::list_url( [ 'msg' => $msg ] ) );
		exit;
	}

	public static function render() : void {
		if ( ! current_user_can( self::CAPABILITY ) ) { return; }

		// Edit-Plan-Detail-View
		if ( isset( $_GET['action'], $_GET['cid'] ) && $_GET['action'] === 'edit_plan' ) {
			$row = Account::find_by_id( (int) $_GET['cid'] );
			if ( ! $row ) {
				echo '<div class="wrap"><h1>' . esc_html__( 'Konto nicht gefunden', 'itdatex-mailguard' ) . '</h1>';
				printf( '<p><a href="%s">%s</a></p></div>', esc_url( self::list_url() ), esc_html__( '← Zur Liste', 'itdatex-mailguard' ) );
				return;
			}
			self::render_plan_editor( $row );
			return;
		}

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

		self::render_license_banner();

		if ( $msg ) {
			$labels = [
				'suspended'      => __( 'Konto wurde gesperrt.',     'itdatex-mailguard' ),
				'activated'      => __( 'Konto wurde aktiviert.',    'itdatex-mailguard' ),
				'deleted'        => __( 'Konto wurde komplett geloescht (mit allen Daten).', 'itdatex-mailguard' ),
				'not_found'      => __( 'Konto nicht gefunden.',     'itdatex-mailguard' ),
				'plan_saved'     => __( 'Plan wurde gespeichert.',   'itdatex-mailguard' ),
				'plan_unknown'   => __( 'Unbekannter Plan-Slug.',    'itdatex-mailguard' ),
				'email_verified' => __( 'E-Mail wurde als verifiziert markiert.', 'itdatex-mailguard' ),
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
		echo '<th>ID</th><th>E-Mail</th><th>Status</th><th>Verifiziert</th><th>Plan</th><th>Postfächer</th><th>Mails</th><th>Erstellt</th><th>Letzter Login</th><th>Aktion</th>';
		echo '</tr></thead><tbody>';

		foreach ( $data['items'] as $row ) {
			$cid = (int) $row['id'];
			$status = (string) $row['status'];
			$pill = match ( $status ) {
				'active'    => '<span style="color:#00a32a;font-weight:600">aktiv</span>',
				'suspended' => '<span style="color:#d63638;font-weight:600">gesperrt</span>',
				default     => '<span style="color:#646970">pending</span>',
			};
			$plan_slug   = (string) ( $row['plan_slug'] ?? 'free' );
			$plan_status = (string) ( $row['plan_status'] ?? 'active' );
			$grace_until = (string) ( $row['plan_grace_until'] ?? '' );
			$plan_txt    = esc_html( $plan_slug );
			if ( $plan_status && $plan_status !== 'active' ) {
				$plan_txt .= ' <span style="color:#d63638">(' . esc_html( $plan_status ) . ')</span>';
			}
			if ( $grace_until ) {
				$plan_txt .= '<br><span style="color:#646970;font-size:11px">bis ' . esc_html( self::fmt_date( $grace_until ) ) . '</span>';
			}
			echo '<tr>';
			echo '<td>' . $cid . '</td>';
			echo '<td><code>' . esc_html( (string) $row['email'] ) . '</code></td>';
			echo '<td>' . $pill . '</td>';
			echo '<td>' . ( (int) $row['email_verified'] ? '✓' : '–' ) . '</td>';
			echo '<td>' . $plan_txt . '</td>';
			echo '<td>' . ( $acc_counts[ $cid ] ?? 0 ) . '</td>';
			echo '<td>' . ( $msg_counts[ $cid ] ?? 0 ) . '</td>';
			echo '<td>' . esc_html( self::fmt_date( (string) $row['created_at'] ) ) . '</td>';
			echo '<td>' . esc_html( $row['last_login_at'] ? self::fmt_date( (string) $row['last_login_at'] ) : '–' ) . '</td>';
			echo '<td>';
			printf(
				'<a href="%s" class="button">%s</a> ',
				esc_url( self::list_url( [ 'action' => 'edit_plan', 'cid' => $cid ] ) ),
				esc_html__( 'Plan', 'itdatex-mailguard' )
			);
			if ( ! (int) $row['email_verified'] ) {
				printf(
					'<a href="%s" class="button">%s</a> ',
					esc_url( self::action_url( 'verify_email', $cid ) ),
					esc_html__( 'E-Mail verifizieren', 'itdatex-mailguard' )
				);
			}
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

	private static function render_license_banner() : void {
		$status = \Itdatex\Mailguard\License\Guard::status();
		$state  = (string) ( $status['status'] ?? 'unset' );
		$settings_url = admin_url( 'admin.php?page=' . Settings::PAGE_SLUG );

		$tone_class = 'notice-info';
		$msg = '';

		switch ( $state ) {
			case 'active':
				$msg = '<strong>Lizenz aktiv.</strong>';
				if ( ! empty( $status['expires_at'] ) ) {
					$msg .= ' Gültig bis <code>' . esc_html( (string) $status['expires_at'] ) . '</code>.';
				}
				if ( ! empty( $status['cancel_at_period_end'] ) ) {
					$msg .= ' <em>Wird zum Periodenende gekündigt.</em>';
					$tone_class = 'notice-warning';
				} else {
					$tone_class = 'notice-success';
				}
				break;
			case 'past_due':
				$tone_class = 'notice-warning';
				$msg = '<strong>Zahlung überfällig.</strong> Die letzte Stripe-Abrechnung schlug fehl. Endkunden-Portal läuft im Grace, neue Registrierungen sind gesperrt. <a href="https://wp.itdatex.support/konto/">→ Zahlung im Kundenkonto prüfen</a>';
				break;
			case 'canceled':
			case 'expired':
				$tone_class = 'notice-error';
				$msg = '<strong>Lizenz nicht aktiv (' . esc_html( $state ) . ').</strong> Endkunden-Registrierung ist blockiert. <a href="' . esc_url( $settings_url ) . '">→ Neuen Schlüssel hinterlegen</a>';
				break;
			case 'invalid':
				$tone_class = 'notice-error';
				$msg = '<strong>Lizenzschlüssel ungültig.</strong> Bitte den Schlüssel in den <a href="' . esc_url( $settings_url ) . '">Einstellungen</a> prüfen.';
				break;
			case 'unknown':
				$tone_class = 'notice-warning';
				$msg = '<strong>Lizenz-Status unklar.</strong> Shop nicht erreichbar — wird beim nächsten Page-Load erneut probiert.';
				break;
			case 'unset':
			default:
				$tone_class = 'notice-warning';
				$msg = '<strong>Kein Lizenzschlüssel hinterlegt.</strong> Bitte in den <a href="' . esc_url( $settings_url ) . '">Einstellungen → Lizenz</a> eintragen. Endkunden-Registrierung ist bis dahin gesperrt.';
		}

		echo '<div class="notice ' . esc_attr( $tone_class ) . '" style="margin-top:1em"><p>' . wp_kses_post( $msg ) . '</p></div>';
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

	private static function render_plan_editor( array $row ) : void {
		$cid           = (int) $row['id'];
		$current_slug  = (string) ( $row['plan_slug'] ?? 'free' );
		$current_grace = (string) ( $row['plan_grace_until'] ?? '' );
		$current_grace_input = '';
		if ( $current_grace !== '' ) {
			$ts = strtotime( $current_grace . ' UTC' );
			if ( $ts ) { $current_grace_input = gmdate( 'Y-m-d', $ts ); }
		}
		$is_verified  = (int) ( $row['email_verified'] ?? 0 );
		$stripe_sub   = (string) ( $row['stripe_subscription_id'] ?? '' );

		echo '<div class="wrap">';
		printf(
			'<h1>%s</h1>',
			esc_html( sprintf(
				/* translators: %s = customer email */
				__( 'Plan verwalten — %s', 'itdatex-mailguard' ),
				(string) $row['email']
			) )
		);
		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( self::list_url() ),
			esc_html__( '← Zurück zur Liste', 'itdatex-mailguard' )
		);

		if ( $stripe_sub !== '' ) {
			echo '<div class="notice notice-warning inline"><p>';
			printf(
				esc_html__( 'Achtung: Dieser Kunde hat bereits ein Stripe-Abo (%s). Eine manuelle Plan-Zuweisung überschreibt die Plan-Daten, aber nicht das Stripe-Abo — bei der nächsten Zahlung setzt der Webhook den Plan wieder auf den Stripe-Stand.', 'itdatex-mailguard' ),
				'<code>' . esc_html( $stripe_sub ) . '</code>'
			);
			echo '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '">';
		wp_nonce_field( 'mg_customer_save_plan_' . $cid );
		printf( '<input type="hidden" name="mg_action" value="save_plan" />' );
		printf( '<input type="hidden" name="cid" value="%d" />', $cid );

		echo '<table class="form-table"><tbody>';

		// Plan-Dropdown
		echo '<tr><th scope="row"><label for="plan_slug">' . esc_html__( 'Plan', 'itdatex-mailguard' ) . '</label></th><td>';
		echo '<select id="plan_slug" name="plan_slug">';
		foreach ( Plans::all() as $slug => $plan ) {
			$sel = $slug === $current_slug ? ' selected' : '';
			$price = Plans::format_price( (int) $plan['price_cents'] );
			printf(
				'<option value="%s"%s>%s — %s (%d Postfächer, LLM %s)</option>',
				esc_attr( $slug ),
				$sel,
				esc_html( (string) $plan['name'] ),
				esc_html( $price ),
				(int) $plan['imap_quota'],
				! empty( $plan['llm_enabled'] ) ? 'an' : 'aus'
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'plan_status wird auf "active" gesetzt. LLM-Nutzung erfordert weiterhin einen gültigen Cloud-Consent des Kunden — ohne Consent bleibt LLM off, auch wenn der Plan es erlauben würde.', 'itdatex-mailguard' ) . '</p>';
		echo '</td></tr>';

		// Grace-Until
		echo '<tr><th scope="row"><label for="grace_until">' . esc_html__( 'Ablaufdatum', 'itdatex-mailguard' ) . '</label></th><td>';
		printf(
			'<input type="date" id="grace_until" name="grace_until" value="%s" />',
			esc_attr( $current_grace_input )
		);
		echo '<p class="description">' . esc_html__( 'Datum, ab dem der Plan endet (UTC, 23:59:59). Leer lassen für unbegrenzt. Nach Ablauf verhält sich der Kunde wie plan_grace_until überschritten — die eigentliche Downgrade-Logik läuft anderswo im Plan-Check.', 'itdatex-mailguard' ) . '</p>';
		echo '</td></tr>';

		// Force-Verify
		echo '<tr><th scope="row">' . esc_html__( 'E-Mail-Verifizierung', 'itdatex-mailguard' ) . '</th><td>';
		if ( $is_verified ) {
			echo '<span style="color:#00a32a">✓ ' . esc_html__( 'Bereits verifiziert.', 'itdatex-mailguard' ) . '</span>';
		} else {
			echo '<label><input type="checkbox" name="force_verify" value="1" /> ';
			echo esc_html__( 'Als verifiziert markieren (setzt email_verified=1 und status=active).', 'itdatex-mailguard' );
			echo '</label>';
		}
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Plan speichern', 'itdatex-mailguard' ) );
		echo '</form></div>';
	}
}
