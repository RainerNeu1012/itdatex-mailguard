<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard;

use Itdatex\Mailguard\Admin\Settings;
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

		add_action( 'rest_api_init', [ RestController::class, 'register' ] );
	}

	public static function load_textdomain() : void {
		load_plugin_textdomain( 'itdatex-mailguard', false, dirname( plugin_basename( ITDATEX_MAILGUARD_FILE ) ) . '/languages' );
	}
}
