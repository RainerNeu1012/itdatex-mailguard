<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Portal;

use Itdatex\Mailguard\Antiphish\BlockedTlds;
use Itdatex\Mailguard\Antiphish\Signals\UrlhausCheck;
use Itdatex\Mailguard\Antiphish\UrlReputationService;

/**
 * Click-Protection: wenn der User im Portal auf einen Link in einer Mail
 * klickt, geht er zuerst durch /portal/go?u=<url>&m=<msg-id>. Dort pruefen
 * wir die URL gegen Google Safe Browsing, URLhaus, Blocked-TLDs und DNS
 * — Treffer blockt mit Interstitial-Warnseite, Clean leitet weiter.
 *
 * Side-effect: jeder Click wird geloggt (Audit), ohne Byte-Content.
 */
final class LinkGuard {

	public static function handle_click( string $raw_url, string $msg_ref = '' ) : void {
		$url = self::sanitize_url( $raw_url );
		if ( $url === '' ) {
			self::render_error( 'Ungueltige URL', 'Die aufgerufene URL war leer oder in unerwartetem Format.' );
			return;
		}

		$check = self::check( $url );
		if ( $check['block'] ) {
			self::render_interstitial( $url, $check, $msg_ref );
			return;
		}
		// Clean → 302 redirect
		nocache_headers();
		wp_safe_redirect( $url, 302 );
		// wp_safe_redirect blocks off-host. Fuer externe Links nutzen wir wp_redirect.
		// → wenn wp_safe_redirect nicht umleitet (gleicher host fehlt), fallback:
		if ( ! headers_sent() ) {
			header( 'Location: ' . $url, true, 302 );
		}
		exit;
	}

	/**
	 * @return array{block:bool, verdict:string, reasons:array<int,string>}
	 */
	public static function check( string $url ) : array {
		$reasons = [];
		$host = parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return [ 'block' => true, 'verdict' => 'bad_format', 'reasons' => [ 'URL-Format ungueltig' ] ];
		}

		// Blocked-TLDs (global customer scope 0 → nur Operator-Level)
		$tlds = BlockedTlds::list_tlds( 0 );
		if ( $tlds && BlockedTlds::matches( $host, $tlds ) !== null ) {
			$reasons[] = 'Host-TLD ist im Operator-Blocklist';
		}

		// URLhaus
		$uh = UrlhausCheck::reasons_for_body( $url );
		if ( ! empty( $uh ) ) {
			$reasons[] = 'URLhaus (abuse.ch) meldet diese URL als Malware-Distribution';
		}

		// Google Safe Browsing (nutzt interne URL-Extraction + Cache)
		$sb = UrlReputationService::reasons_for_body( $url );
		if ( ! empty( $sb ) ) {
			$reasons[] = 'Google Safe Browsing meldet diese URL als Phishing/Malware';
		}

		// DNS-Check: Host muss resolvable sein
		if ( ! self::host_resolvable( $host ) ) {
			$reasons[] = sprintf( 'Host "%s" ist im DNS nicht auffindbar', $host );
		}

		return [
			'block'   => ! empty( $reasons ),
			'verdict' => empty( $reasons ) ? 'clean' : 'dangerous',
			'reasons' => $reasons,
		];
	}

	private static function sanitize_url( string $raw ) : string {
		$url = trim( (string) wp_unslash( $raw ) );
		if ( $url === '' ) { return ''; }
		if ( strlen( $url ) > 4096 ) { return ''; }
		if ( ! preg_match( '#^https?://#i', $url ) ) { return ''; }
		return esc_url_raw( $url );
	}

	private static function host_resolvable( string $host ) : bool {
		$cache_key = 'mg_dns_' . md5( $host );
		$cached = get_transient( $cache_key );
		if ( $cached !== false ) { return $cached === 'ok'; }
		$mx = @dns_get_record( $host, DNS_MX );
		$a  = @dns_get_record( $host, DNS_A );
		$ok = ( is_array( $mx ) && $mx ) || ( is_array( $a ) && $a );
		set_transient( $cache_key, $ok ? 'ok' : 'bad', 6 * HOUR_IN_SECONDS );
		return $ok;
	}

	private static function render_interstitial( string $url, array $check, string $msg_ref ) : void {
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		$host     = (string) ( parse_url( $url, PHP_URL_HOST ) ?: '?' );
		$reasons_html = '';
		foreach ( $check['reasons'] as $r ) {
			$reasons_html .= '<li>' . esc_html( $r ) . '</li>';
		}
		$display_url = esc_html( mb_strimwidth( $url, 0, 240, '…' ) );
		$back_js = esc_js( 'history.length>1 ? history.back() : window.close()' );
		$proceed_url = esc_url( $url );

		echo <<<HTML
<!doctype html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MailGuard · Warnung</title>
<style>
  :root{color-scheme:light dark;--fg:#0b0b0b;--muted:#6b6b6b;--bg:#fff;--warn:#d94848;--border:#ddd;--surface:#f6f6f6;}
  @media(prefers-color-scheme:dark){:root{--fg:#f3f3f3;--muted:#9a9a9a;--bg:#0b0b0b;--border:#2a2a2a;--surface:#141414;}}
  html,body{margin:0;background:var(--bg);color:var(--fg);font-family:ui-sans-serif,-apple-system,"Segoe UI",system-ui;line-height:1.5;}
  .wrap{max-width:640px;margin:8vh auto;padding:28px;background:var(--surface);border:1px solid var(--border);border-radius:12px;}
  h1{font-size:1.4rem;margin:0 0 .6rem;color:var(--warn);}
  h2{font-size:.9rem;color:var(--muted);margin:1.4rem 0 .4rem;letter-spacing:.08em;text-transform:uppercase;}
  .url{font-family:ui-monospace,Menlo,monospace;font-size:.85rem;word-break:break-all;background:var(--bg);border:1px solid var(--border);padding:10px 12px;border-radius:6px;color:var(--fg);}
  ul{margin:.2rem 0 0;padding-left:1.2rem;}li{margin:.3rem 0;}
  .actions{display:flex;gap:10px;margin-top:1.4rem;flex-wrap:wrap;}
  button,a.btn{appearance:none;border:1px solid var(--border);background:var(--bg);color:var(--fg);padding:.6rem 1rem;border-radius:6px;cursor:pointer;font:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:.4rem;}
  .btn-danger{color:var(--warn);border-color:var(--warn);}
  .brand{font-size:.72rem;color:var(--muted);letter-spacing:.14em;text-transform:uppercase;margin-bottom:.6rem;}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">MailGuard · Click-Protection</div>
  <h1>⚠ Diese URL ist als gefaehrlich markiert</h1>
  <p>Ein in einer Mail eingebetteter Link wurde beim Klick geprueft und als verdaechtig eingestuft.</p>
  <h2>Ziel</h2>
  <div class="url">{$display_url}</div>
  <h2>Grund</h2>
  <ul>{$reasons_html}</ul>
  <p style="color:var(--muted);font-size:.85rem;margin-top:1.2rem;">Host: <strong>{$host}</strong></p>
  <div class="actions">
    <button onclick="{$back_js}">↶ Zurueck</button>
    <a class="btn btn-danger" href="{$proceed_url}" rel="noopener nofollow">Trotzdem fortfahren →</a>
  </div>
</div>
</body></html>
HTML;
		exit;
	}

	private static function render_error( string $title, string $body ) : void {
		status_header( 400 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo '<!doctype html><html lang="de"><meta charset=UTF-8><title>' . esc_html( $title ) . '</title><body style="font-family:system-ui;max-width:500px;margin:8vh auto;padding:20px;"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $body ) . '</p></body></html>';
		exit;
	}
}
