<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard;

use Itdatex\Mailguard\Installer;
use Itdatex\Mailguard\Admin\Customers;
use Itdatex\Mailguard\Admin\Settings;
use Itdatex\Mailguard\Antiphish\ScanService;
use Itdatex\Mailguard\Imap\PullService;
use Itdatex\Mailguard\Portal\Rewrite;
use Itdatex\Mailguard\Rest\Controller as RestController;

final class Plugin {

	private static bool $booted = false;

	public static function boot() : void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'init',          [ __CLASS__, 'load_textdomain' ] );
		add_action( 'init',          [ Rewrite::class, 'register_rules' ] );
		add_filter( 'query_vars',    [ Rewrite::class, 'register_query_vars' ] );
		add_action( 'template_redirect', [ Rewrite::class, 'maybe_render' ] );

		add_action( 'admin_init',    [ Settings::class, 'register' ] );
		add_action( 'admin_menu',    [ Settings::class, 'add_menu' ] );
		add_action( 'admin_init',    [ Customers::class, 'handle_actions' ] );

		add_action( 'rest_api_init', [ RestController::class, 'register' ] );

		add_filter( 'cron_schedules', [ __CLASS__, 'register_cron_schedule' ] );
		add_action( Installer::CRON_PULL_HOOK, [ PullService::class, 'pull_all' ] );
		add_action( Installer::CRON_SCAN_HOOK, [ ScanService::class, 'scan_pending_batch' ] );
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
		return $schedules;
	}

	public static function load_textdomain() : void {
		load_plugin_textdomain( 'itdatex-mailguard', false, dirname( plugin_basename( ITDATEX_MAILGUARD_FILE ) ) . '/languages' );
	}
}
