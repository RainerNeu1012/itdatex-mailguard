<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Portal;

use Itdatex\Mailguard\Admin\Settings;

/**
 * Mountet das Portal unter dem konfigurierten Slug (Default: /portal/...).
 * Liefert in Phase 1 nur einen Platzhalter aus — die echte SPA folgt in Phase 2.
 * Diese Klasse muss die Rewrite-Rules bei Plugin-Aktivierung registrieren
 * UND bei jedem WP-Boot wieder hinzufuegen, sonst verliert WP sie beim naechsten Flush.
 */
final class Rewrite {

	public const QUERY_VAR = 'itdatex_mg_portal';

	public static function register_rules() : void {
		$slug = self::slug();
		if ( $slug === '' ) {
			return;
		}
		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '([^&]+)' );
		add_rewrite_rule( '^' . preg_quote( $slug, '#' ) . '/?$',         'index.php?' . self::QUERY_VAR . '=', 'top' );
		add_rewrite_rule( '^' . preg_quote( $slug, '#' ) . '/(.+?)/?$',   'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	public static function register_query_vars( array $vars ) : array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function maybe_render() : void {
		$route = get_query_var( self::QUERY_VAR, null );
		if ( $route === null || $route === false ) {
			return;
		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );

		$portal_url = home_url( '/' . self::slug() . '/' );
		$rest_url   = esc_url_raw( rest_url( \Itdatex\Mailguard\Rest\Controller::NAMESPACE . '/' ) );

		// Phase 1: HTML-Platzhalter. Die echte React-SPA kommt in Phase 2.
		echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<title>' . esc_html( get_bloginfo( 'name' ) ) . ' — Portal</title>';
		echo '<meta name="robots" content="noindex, nofollow">';
		echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:560px;margin:3rem auto;padding:0 1rem;color:#1d2327}h1{font-size:1.4rem}code{background:#f6f7f7;padding:0.15rem 0.4rem;border-radius:3px}</style>';
		echo '</head><body>';
		echo '<h1>MailGuard Portal</h1>';
		echo '<p>Aktuelle Route: <code>' . esc_html( (string) $route ) . '</code></p>';
		echo '<p><strong>Phase 1:</strong> Auth-Backend ist verdrahtet, aber die SPA folgt in Phase 2. Solange nur per <code>curl</code> testbar:</p>';
		echo '<pre style="background:#f6f7f7;padding:0.75rem;border-radius:4px;font-size:0.85rem;overflow:auto">';
		echo 'POST ' . esc_html( $rest_url ) . "register      {email, password}\n";
		echo 'POST ' . esc_html( $rest_url ) . "login         {email, password}\n";
		echo 'POST ' . esc_html( $rest_url ) . "logout\n";
		echo 'POST ' . esc_html( $rest_url ) . "verify-email  {token}\n";
		echo 'POST ' . esc_html( $rest_url ) . "forgot-password {email}\n";
		echo 'POST ' . esc_html( $rest_url ) . "reset-password  {token, password}\n";
		echo 'GET  ' . esc_html( $rest_url ) . "me            (Cookie)\n";
		echo '</pre>';
		echo '<p>Portal-URL: <code>' . esc_html( $portal_url ) . '</code></p>';
		echo '</body></html>';
		exit;
	}

	public static function slug() : string {
		$slug = (string) Settings::get( 'portal_slug', 'portal' );
		$slug = trim( $slug, '/' );
		return $slug !== '' ? $slug : 'portal';
	}
}
