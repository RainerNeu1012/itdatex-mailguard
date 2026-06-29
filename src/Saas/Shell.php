<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Saas;

/**
 * Shared HTML-Shell für alle SaaS-Pages (Onboard-Form, Status-Pages).
 *
 * Liefert das Standard-itdatex-Design konsistent: Dark Theme, Bebas Neue,
 * IBM Plex Sans, Accent #2f81f7, sticky Header mit Logo + Nav, gemeinsamer
 * Legal-Footer. Pendant zur statischen guard.itdatex.support Landing-Page.
 *
 * Usage:
 *   Shell::open( 'Page Title', [ 'kicker' => '// onboard' ] );
 *   echo '<main><div class="wrap">…content…</div></main>';
 *   Shell::close();
 */
final class Shell {

	public static function open( string $title, array $opts = [] ) : void {
		$site = 'MailGuard SaaS';
		$noindex = ! empty( $opts['noindex'] );
		?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $title ); ?> | <?php echo esc_html( $site ); ?></title>
<?php if ( $noindex ): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<meta name="theme-color" content="#0d1117">
<link rel="icon" type="image/svg+xml" href="<?php echo esc_url( ITDATEX_MAILGUARD_URL . 'assets/img/mark.svg' ); ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=IBM+Plex+Sans:wght@400;600&family=JetBrains+Mono:wght@400;500;700&display=swap">
<style><?php echo self::css(); ?></style>
</head>
<body>
<header class="site-header">
  <div class="wrap">
    <a class="brand" href="https://guard.itdatex.support/">
      <img src="<?php echo esc_url( ITDATEX_MAILGUARD_URL . 'assets/img/mark-white.svg' ); ?>" alt="!tdatex">
      <span>
        <span class="brand-text">MAIL<span class="accent">GUARD</span></span>
        <span class="brand-sub">// saas</span>
      </span>
    </a>
    <nav class="site-nav">
      <a href="https://guard.itdatex.support/#plans">Pläne</a>
      <a href="https://guard.itdatex.support/#features">Features</a>
      <a href="https://guard.itdatex.support/#faq">FAQ</a>
      <a href="<?php echo esc_url( home_url( '/portal/login/' ) ); ?>">Login</a>
    </nav>
  </div>
</header>
		<?php
	}

	public static function close() : void {
		?>
<footer class="site-footer">
  <div class="wrap">
    <span>© !tdatex 2026 · <a href="https://itdatex.support/">itdatex.support</a></span>
    <span>
      <a href="https://wp.itdatex.support/impressum/">Impressum</a> ·
      <a href="https://wp.itdatex.support/datenschutz/">Datenschutz</a> ·
      <a href="https://wp.itdatex.support/agb/">AGB</a> ·
      <a href="https://wp.itdatex.support/widerruf/">Widerruf</a> ·
      <a href="https://wp.itdatex.support/kuendigen/">Kündigen</a>
    </span>
  </div>
</footer>
</body>
</html>
		<?php
	}

	/**
	 * Standard-CSS — exakt synchron mit guard.itdatex.support/index.html, damit
	 * der visuelle Bruch zwischen Landing und Onboard-Flow nicht auffällt.
	 */
	public static function css() : string {
		return <<<'CSS'
:root {
  --bg: #0d1117;
  --surface: #161b22;
  --surface-2: #21262d;
  --border: #30363d;
  --text: #e6edf3;
  --text-muted: #8b949e;
  --text-subtle: #6e7681;
  --accent: #2f81f7;
  --accent-soft: #58a6ff;
  --success: #3fb950;
  --warning: #d29922;
  --danger: #f85149;
}
* { box-sizing: border-box; }
html { background: var(--bg); }
body {
  font-family: 'IBM Plex Sans', system-ui, -apple-system, sans-serif;
  background: var(--bg);
  color: var(--text);
  margin: 0;
  line-height: 1.6;
  font-size: 1rem;
}
a { color: var(--accent-soft); text-decoration: none; }
a:hover { color: var(--accent); }

.wrap { max-width: 1200px; margin: 0 auto; padding: 0 1.5rem; }

.site-header {
  position: sticky; top: 0; z-index: 100;
  background: rgba(13, 17, 23, 0.88);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  border-bottom: 1px solid var(--border);
  padding: 0.85rem 0;
}
.site-header .wrap {
  display: flex; justify-content: space-between; align-items: center; gap: 1rem;
}
.brand { display: inline-flex; align-items: center; gap: 0.75rem; text-decoration: none; color: var(--text); }
.brand:hover { color: var(--text); }
.brand img { width: 28px; height: auto; display: block; }
.brand-text { font-family: 'Bebas Neue', 'Arial Narrow', sans-serif; font-size: 1.6rem; letter-spacing: 0.08em; line-height: 1; }
.brand-text .accent { color: var(--accent); }
.brand-sub {
  display: block; font-family: 'JetBrains Mono', ui-monospace, Menlo, monospace; font-size: 1rem;
  color: var(--text-subtle); letter-spacing: 0.06em; margin-top: 0.1rem;
}
.site-nav { display: flex; gap: 1.5rem; font-family: 'JetBrains Mono', ui-monospace, Menlo, monospace; font-size: 1rem; }
.site-nav a { color: var(--text-muted); }
.site-nav a:hover { color: var(--text); }

main { padding: 3rem 0 4rem; }
.content { max-width: 640px; margin: 0 auto; padding: 0 1.5rem; }

.kicker {
  font-family: 'JetBrains Mono', ui-monospace, Menlo, monospace; font-size: 1rem;
  color: var(--text-subtle); letter-spacing: 0.1em; text-transform: uppercase;
  margin: 0 0 0.6rem;
}
h1 {
  font-family: 'Bebas Neue', 'Arial Narrow', sans-serif; font-weight: 400;
  letter-spacing: 0.04em; line-height: 1;
  font-size: clamp(2.5rem, 6vw, 4.5rem);
  margin: 0 0 1.25rem;
}
h2 {
  font-family: 'Bebas Neue', 'Arial Narrow', sans-serif; font-weight: 400;
  font-size: clamp(1.8rem, 3.5vw, 2.6rem); letter-spacing: 0.04em;
  margin: 0 0 1rem; line-height: 1.15;
}
p { margin: 0 0 1em; }
.back { color: var(--text-muted); font-family: 'JetBrains Mono', ui-monospace, Menlo, monospace; font-size: 1rem; }

.card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 10px; padding: 1.75rem;
}
.summary {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 10px; padding: 1.5rem; margin: 1.5rem 0;
}
.summary .price {
  font-family: 'Bebas Neue', 'Arial Narrow', sans-serif; font-size: 2rem;
  margin: 0; line-height: 1;
}
.summary .price .unit {
  font-family: 'IBM Plex Sans', sans-serif; font-size: 1rem;
  color: var(--text-muted); letter-spacing: 0;
}
.summary p { margin: 0.5rem 0 0; color: var(--text-muted); }

form label { display: block; margin-bottom: 1.25rem; }
form label .label-text { display: block; font-size: 1rem; color: var(--text-muted); margin-bottom: 0.35rem; }
input[type="email"], input[type="text"], input[type="password"] {
  width: 100%; background: var(--surface-2); border: 1px solid var(--border);
  border-radius: 6px; padding: 0.7rem 0.85rem; color: var(--text);
  font-family: inherit; font-size: 1rem; box-sizing: border-box;
}
input[type="email"]:focus, input[type="text"]:focus, input[type="password"]:focus {
  outline: none; border-color: var(--accent);
}

.consent {
  background: var(--bg); border: 1px solid var(--border); border-radius: 6px;
  padding: 0.85rem 1rem; margin: 0 0 1rem;
  display: flex; gap: 0.6rem; align-items: flex-start; cursor: pointer;
  font-size: 1rem; color: #c9d1d9; line-height: 1.55;
}
.consent input { margin-top: 0.25rem; flex-shrink: 0; }

.btn, button[type="submit"] {
  display: inline-block; background: var(--accent); color: #fff;
  border: none; border-radius: 6px; padding: 0.85rem 1.5rem;
  font-weight: 600; font-size: 1rem; font-family: inherit;
  text-decoration: none; cursor: pointer; width: 100%; text-align: center;
  transition: background 0.15s;
}
.btn:hover, button[type="submit"]:hover { background: var(--accent-soft); color: #fff; }
.btn-secondary {
  background: var(--surface-2); color: var(--text);
  border: 1px solid var(--border);
}
.btn-secondary:hover { background: var(--border); color: var(--text); }

.hint { color: var(--text-subtle); font-size: 1rem; margin: 1rem 0 0; text-align: center; }

.status-list { list-style: none; padding: 0; margin: 0.5rem 0 1.5rem; color: var(--text-muted); }
.status-list li { padding: 0.4rem 0 0.4rem 1.5rem; position: relative; }
.status-list li::before { content: '+'; position: absolute; left: 0; color: var(--success); font-weight: 600; font-family: 'JetBrains Mono', ui-monospace, Menlo, monospace; }

.site-footer {
  border-top: 1px solid var(--border); padding: 2rem 0;
  margin-top: auto; font-size: 1rem; color: var(--text-muted);
}
.site-footer .wrap {
  display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
}
.site-footer a { color: var(--text-muted); }
.site-footer a:hover { color: var(--text); }

@media (max-width: 640px) {
  .site-header .wrap { flex-direction: column; align-items: flex-start; }
  .site-nav { width: 100%; flex-wrap: wrap; gap: 1rem; }
}
CSS;
	}
}
