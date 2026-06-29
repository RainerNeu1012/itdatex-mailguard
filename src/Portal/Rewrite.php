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

		$config = [
			'restUrl'   => $rest_url,
			'portalUrl' => $portal_url,
			'pluginUrl' => ITDATEX_MAILGUARD_URL,
			'siteName'  => get_bloginfo( 'name' ),
			'version'   => ITDATEX_MAILGUARD_VERSION,
		];

		[ $js, $css ] = self::asset_paths();

		echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<title>MailGuard SaaS — Portal</title>';
		echo '<meta name="robots" content="noindex, nofollow">';
		echo '<meta name="theme-color" content="#0d1117">';
		echo '<link rel="icon" type="image/svg+xml" href="' . esc_url( ITDATEX_MAILGUARD_URL . 'assets/img/mark.svg' ) . '">';
		// Webfonts — exakt der itdatex-Standard-Stack (Bebas Neue, IBM Plex Sans, JetBrains Mono).
		echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
		echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
		echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=IBM+Plex+Sans:wght@400;600&family=JetBrains+Mono:wght@400;500;700&display=swap">';
		if ( $css ) {
			foreach ( $css as $href ) {
				echo '<link rel="stylesheet" href="' . esc_url( ITDATEX_MAILGUARD_URL . 'build/' . $href ) . '">';
			}
		}
		echo '</head><body>';
		echo '<div id="mg-portal"></div>';
		echo '<script>window.itdatexMailguard=' . wp_json_encode( $config ) . ';</script>';
		if ( $js ) {
			echo '<script type="module" src="' . esc_url( ITDATEX_MAILGUARD_URL . 'build/' . $js ) . '"></script>';
		} else {
			echo '<noscript><p style="font-family:sans-serif;max-width:560px;margin:3rem auto;padding:0 1rem">Frontend-Build fehlt. Bitte <code>npm install &amp;&amp; npm run build</code> im Plugin-Verzeichnis ausführen.</p></noscript>';
		}
		echo '</body></html>';
		exit;
	}

	private static function asset_paths() : array {
		$manifest_file = ITDATEX_MAILGUARD_DIR . 'build/.vite/manifest.json';
		if ( ! file_exists( $manifest_file ) ) {
			return [ null, [] ];
		}
		$manifest = json_decode( (string) file_get_contents( $manifest_file ), true );
		if ( ! is_array( $manifest ) ) { return [ null, [] ]; }
		$entry = $manifest['assets/portal/main.jsx'] ?? null;
		if ( ! is_array( $entry ) ) { return [ null, [] ]; }
		$js  = $entry['file'] ?? null;
		$css = is_array( $entry['css'] ?? null ) ? $entry['css'] : [];
		return [ $js, $css ];
	}

	public static function slug() : string {
		$slug = (string) Settings::get( 'portal_slug', 'portal' );
		$slug = trim( $slug, '/' );
		return $slug !== '' ? $slug : 'portal';
	}
}
