<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard;

use Itdatex\Mailguard\Installer;
use Itdatex\Mailguard\Admin\Customers;
use Itdatex\Mailguard\Admin\Settings;
use Itdatex\Mailguard\Antiphish\ScanService;
use Itdatex\Mailguard\Antiphish\UnsubService;
use Itdatex\Mailguard\Imap\PullService;
use Itdatex\Mailguard\Notify\Hooks as NotifyHooks;
use Itdatex\Mailguard\Portal\Rewrite;
use Itdatex\Mailguard\Rest\Controller as RestController;
use Itdatex\Mailguard\Rest\Cors as RestCors;
use Itdatex\Mailguard\Saas\Onboard as SaasOnboard;
use Itdatex\Mailguard\Saas\Webhook as SaasWebhook;
use Itdatex\Mailguard\Saas\BillingPortal as SaasBillingPortal;

final class Plugin {

	private static bool $booted = false;

	public static function boot() : void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'plugins_loaded', [ Installer::class, 'migrate_db' ] );

		add_action( 'init',          [ __CLASS__, 'load_textdomain' ] );
		add_action( 'init',          [ Rewrite::class, 'register_rules' ] );
		add_filter( 'query_vars',    [ Rewrite::class, 'register_query_vars' ] );
		add_action( 'template_redirect', [ Rewrite::class, 'maybe_render' ] );

		add_action( 'init',          [ SaasOnboard::class, 'register_rules' ] );
		add_filter( 'query_vars',    [ SaasOnboard::class, 'register_query_var' ] );
		add_action( 'template_redirect', [ SaasOnboard::class, 'maybe_handle' ] );

		add_action( 'admin_init',    [ Settings::class, 'register' ] );
		add_action( 'admin_menu',    [ Settings::class, 'add_menu' ] );
		add_action( 'admin_init',    [ Customers::class, 'handle_actions' ] );
		add_action( 'admin_post_itdatex_mg_clamav_ping', [ Settings::class, 'handle_clamav_ping' ] );

		add_action( 'rest_api_init', [ RestController::class, 'register' ] );
		add_action( 'rest_api_init', [ SaasWebhook::class, 'register' ] );
		add_action( 'rest_api_init', [ SaasBillingPortal::class, 'register' ] );

		// CORS + Push-Notify-Hooks registrieren.
		RestCors::register();
		NotifyHooks::register();

		add_filter( 'cron_schedules', [ __CLASS__, 'register_cron_schedule' ] );
		add_action( Installer::CRON_PULL_HOOK, [ PullService::class, 'pull_all' ] );
		add_action( Installer::CRON_SCAN_HOOK, [ ScanService::class, 'scan_pending_batch' ] );
		add_action( Installer::CRON_UNSUB_POLL_HOOK, [ UnsubService::class, 'poll_pending_dsn' ] );

		// Selbst-Heilung: falls die Aktivierung damals ohne Unsub-Poll lief
		// (Migration einer bestehenden Installation), Schedule bei plugins_loaded
		// nachträglich anlegen. Kein Extra-Migration-Step nötig.
		add_action( 'plugins_loaded', [ __CLASS__, 'ensure_unsub_poll_scheduled' ], 20 );
	}

	public static function ensure_unsub_poll_scheduled() : void {
		if ( ! wp_next_scheduled( Installer::CRON_UNSUB_POLL_HOOK ) ) {
			wp_schedule_event( time() + 300, Installer::CRON_UNSUB_POLL_SCHEDULE, Installer::CRON_UNSUB_POLL_HOOK );
		}
	}

	public static function register_cron_schedule( array $schedules ) : array {
		if ( ! isset( $schedules[ Installer::CRON_PULL_SCHEDULE ] ) ) {
			$schedules[ Installer::CRON_PULL_SCHEDULE ] = [
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Alle 15 Minuten (MailGuard IMAP-Pull)', 'itdatex-mailguard' ),
			];
		}
		if ( ! isset( $schedules[ Installer::CRON_SCAN_SCHEDULE ] ) ) {
			$schedules[ Installer::CRON_SCAN_SCHEDULE ] = [
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Alle 5 Minuten (MailGuard Phishing-Scan)', 'itdatex-mailguard' ),
			];
		}
		if ( ! isset( $schedules[ Installer::CRON_UNSUB_POLL_SCHEDULE ] ) ) {
			$schedules[ Installer::CRON_UNSUB_POLL_SCHEDULE ] = [
				'interval' => 10 * MINUTE_IN_SECONDS,
				'display'  => __( 'Alle 10 Minuten (MailGuard Unsub-DSN-Poll)', 'itdatex-mailguard' ),
			];
		}
		return $schedules;
	}

	public static function load_textdomain() : void {
		load_plugin_textdomain( 'itdatex-mailguard', false, dirname( plugin_basename( ITDATEX_MAILGUARD_FILE ) ) . '/languages' );
	}
}
