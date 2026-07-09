<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Rest;

use Itdatex\Mailguard\Admin\Settings;

/**
 * CORS-Handling nur fuer den Plugin-Namespace itdatex-mailguard/v1.
 *
 * Warum nicht Allow-Origin: * — weil die App Bearer-Tokens sendet;
 * ein Wildcard ist mit credentials-tragenden Requests unpraktisch und
 * verhindert Wildcard-Missbrauch von Third-Party-Seiten. Wir whitelisten
 * exakte Origins (Capacitor default: capacitor://localhost, ionic://localhost,
 * plus optionale Custom-Origins fuer Dev).
 */
final class Cors {

	public const DEFAULT_ORIGINS = [
		'capacitor://localhost',
		'ionic://localhost',
		'http://localhost',
		'http://localhost:3000',
		'http://localhost:5173',
		// Tauri WebView2 (Windows Desktop-Client). "tauri.localhost" ist die
		// Origin, mit der Windows-Builds Requests senden; "tauri://localhost"
		// wird im Dev-Modus mancher älterer Tauri-Versionen benutzt.
		'https://tauri.localhost',
		'tauri://localhost',
	];

	public static function register() : void {
		add_action( 'rest_api_init', [ __CLASS__, 'send_headers' ], 15 );
		// Preflight-OPTIONS werden von WP-Core normalerweise mit 404 beantwortet,
		// weil unsere Routes nur GET/POST/DELETE registriert sind. Wir kapern
		// den Request frueh und antworten mit 204.
		add_action( 'init', [ __CLASS__, 'handle_preflight' ], 1 );
	}

	public static function send_headers() : void {
		$origin = self::request_origin();
		if ( $origin === '' ) { return; }
		if ( ! self::is_allowed_origin( $origin ) ) { return; }
		if ( ! self::is_plugin_route() ) { return; }

		header( 'Access-Control-Allow-Origin: ' . $origin );
		header( 'Vary: Origin' );
		header( 'Access-Control-Allow-Credentials: false' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With' );
		header( 'Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS' );
		header( 'Access-Control-Max-Age: 600' );
	}

	public static function handle_preflight() : void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'OPTIONS' ) { return; }
		if ( ! self::is_plugin_route() ) { return; }
		$origin = self::request_origin();
		if ( ! self::is_allowed_origin( $origin ) ) { return; }

		header( 'Access-Control-Allow-Origin: ' . $origin );
		header( 'Vary: Origin' );
		header( 'Access-Control-Allow-Credentials: false' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With' );
		header( 'Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS' );
		header( 'Access-Control-Max-Age: 600' );
		status_header( 204 );
		exit;
	}

	public static function allowed_origins() : array {
		$raw = trim( (string) Settings::get( 'mobile_origins', '' ) );
		$extra = $raw === ''
			? []
			: array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ?: [] ) ) );
		return array_values( array_unique( array_merge( self::DEFAULT_ORIGINS, $extra ) ) );
	}

	private static function is_allowed_origin( string $origin ) : bool {
		if ( $origin === '' ) { return false; }
		return in_array( $origin, self::allowed_origins(), true );
	}

	private static function request_origin() : string {
		return isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
	}

	private static function is_plugin_route() : bool {
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		if ( ! is_string( $uri ) ) { return false; }
		return str_contains( $uri, '/wp-json/itdatex-mailguard/v1/' )
			|| str_contains( $uri, '?rest_route=/itdatex-mailguard/v1/' );
	}
}
