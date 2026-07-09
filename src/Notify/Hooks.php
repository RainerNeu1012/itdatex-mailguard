<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Notify;

use Itdatex\Mailguard\Imap\Action as ImapAction;
use Itdatex\Mailguard\Imap\Message as ImapMessage;
use Itdatex\Mailguard\Installer;
use Itdatex\Mailguard\Notify\Notification;

/**
 * Zentraler Ort fuer alle do_action-Listener, die Push-Notifications ausloesen.
 * Business-Code (ScanService, QuarantineService, UnsubService, Cron) triggert
 * die Hooks, ohne PushService direkt zu kennen — das haelt den Business-Code
 * frei von Notify-Details.
 *
 * Hooks + Payload-Vertrag:
 *  - mailguard_scan_complete($message_id, $customer_id, $verdict, $score)
 *  - mailguard_quarantine_done($action_id, $customer_id, $actor, $message_id)
 *  - mailguard_undo_expiring_soon($action_id, $customer_id, $hours_left)
 *  - mailguard_unsub_bounced($unsub_id, $customer_id)
 */
final class Hooks {

	public static function register() : void {
		add_action( 'mailguard_scan_complete',       [ __CLASS__, 'on_scan_complete' ],   10, 4 );
		add_action( 'mailguard_quarantine_done',     [ __CLASS__, 'on_quarantine_done' ], 10, 4 );
		add_action( 'mailguard_undo_expiring_soon',  [ __CLASS__, 'on_undo_expiring' ],   10, 3 );
		add_action( 'mailguard_unsub_bounced',       [ __CLASS__, 'on_unsub_bounced' ],   10, 2 );

		// Cron-Hook fuer Undo-Ablauf.
		add_action( Installer::CRON_UNDO_EXPIRY_HOOK, [ __CLASS__, 'run_undo_expiry_check' ] );
	}

	public static function on_scan_complete( int $message_id, int $customer_id, string $verdict, int $score ) : void {
		if ( $verdict !== 'dangerous' ) { return; }
		$msg = ImapMessage::find_for_customer( $message_id, $customer_id );
		$from = $msg ? mb_substr( (string) ( $msg['from_name'] ?: $msg['from_addr'] ), 0, 60 ) : '';
		$title = 'Gefährliche Mail erkannt';
		$body  = $from !== '' ? "MailGuard hat eine Phishing-Mail von $from blockiert." : 'MailGuard hat eine Phishing-Mail blockiert.';
		Notification::create( $customer_id, [
			'event'      => Notification::EVENT_DANGEROUS,
			'title'      => $title,
			'body'       => $body,
			'route'      => 'inbox?verdict=dangerous',
			'message_id' => $message_id,
		] );
		PushService::notify_customer( $customer_id, Device::EVENT_DANGEROUS, [
			'title'     => $title,
			'body'      => $body,
			'deep_link' => '/portal/inbox?verdict=dangerous',
			'data'      => [ 'event' => 'scan_complete', 'message_id' => (string) $message_id, 'score' => (string) $score ],
		] );
	}

	public static function on_quarantine_done( int $action_id, int $customer_id, string $actor, int $message_id ) : void {
		if ( $actor !== ImapAction::ACTOR_AUTO ) { return; }
		// User-Quarantaenen loesen KEINEN Notify aus — der User hat's ja selbst geklickt.
		$msg = ImapMessage::find_for_customer( $message_id, $customer_id );
		$from = $msg ? mb_substr( (string) ( $msg['from_name'] ?: $msg['from_addr'] ), 0, 60 ) : '';
		$title = 'Mail automatisch in Quarantäne';
		$body  = $from !== '' ? "Auto-Quarantäne für Mail von $from — 7 Tage rückgängig möglich." : 'Eine Mail wurde automatisch in Quarantäne verschoben.';
		Notification::create( $customer_id, [
			'event'      => Notification::EVENT_AUTO_QUARANTINE,
			'title'      => $title,
			'body'       => $body,
			'route'      => 'actions',
			'message_id' => $message_id,
			'action_id'  => $action_id,
		] );
		PushService::notify_customer( $customer_id, Device::EVENT_QUARANTINE, [
			'title'     => $title,
			'body'      => $body,
			'deep_link' => '/portal/actions',
			'data'      => [ 'event' => 'quarantine_done', 'action_id' => (string) $action_id ],
		] );
	}

	public static function on_undo_expiring( int $action_id, int $customer_id, int $hours_left ) : void {
		$title = 'Undo-Fenster läuft ab';
		$body  = "In $hours_left Std. wird eine Quarantäne endgültig — jetzt prüfen oder rückgängig machen.";
		Notification::create( $customer_id, [
			'event'     => Notification::EVENT_UNDO_EXPIRING,
			'title'     => $title,
			'body'      => $body,
			'route'     => 'actions?filter=quarantine',
			'action_id' => $action_id,
		] );
		PushService::notify_customer( $customer_id, Device::EVENT_UNDO_EXPIRING, [
			'title'     => $title,
			'body'      => $body,
			'deep_link' => '/portal/actions?filter=quarantine',
			'data'      => [ 'event' => 'undo_expiring', 'action_id' => (string) $action_id ],
		] );
	}

	public static function on_unsub_bounced( int $unsub_id, int $customer_id ) : void {
		$title = 'Newsletter-Abmeldung fehlgeschlagen';
		$body  = 'Der Provider hat deinen Abmeldeversuch abgelehnt. Bitte manuell nachschauen.';
		Notification::create( $customer_id, [
			'event' => Notification::EVENT_UNSUB_BOUNCED,
			'title' => $title,
			'body'  => $body,
			'route' => 'newsletters?tab=history',
		] );
		PushService::notify_customer( $customer_id, Device::EVENT_UNSUB_BOUNCED, [
			'title'     => $title,
			'body'      => $body,
			'deep_link' => '/portal/newsletters?tab=history',
			'data'      => [ 'event' => 'unsub_bounced', 'unsub_id' => (string) $unsub_id ],
		] );
	}

	/**
	 * Cron: findet Quarantaene-Actions, deren undo_until in <24h ablaeuft und
	 * die noch nicht abgelaufen sind — pro Row wird der Notify-Hook getriggert.
	 * Wir markieren KEINE Row als "notified", weil der Cron nur 1x pro Tag laeuft
	 * und das Fenster nur 1 Tag ist — jede Row wird also genau einmal getroffen.
	 */
	public static function run_undo_expiry_check() : void {
		global $wpdb;
		$t = $wpdb->prefix . Installer::TABLE_ACTIONS;
		$rows = $wpdb->get_results(
			"SELECT id, customer_id, undo_until FROM {$t}
			 WHERE action = 'quarantine'
			   AND status = 'done'
			   AND undone_at IS NULL
			   AND undo_until IS NOT NULL
			   AND undo_until BETWEEN UTC_TIMESTAMP() AND DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR)",
			ARRAY_A
		);
		foreach ( $rows ?: [] as $r ) {
			$hours_left = max( 1, (int) round( ( strtotime( (string) $r['undo_until'] . ' UTC' ) - time() ) / 3600 ) );
			do_action( 'mailguard_undo_expiring_soon', (int) $r['id'], (int) $r['customer_id'], $hours_left );
		}
	}
}
