<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Saas;

use Itdatex\Mailguard\Admin\Settings;
use Itdatex\Mailguard\Customer\Account;
use Itdatex\Mailguard\Customer\Mailer;

/**
 * Onboard-Page für SaaS-Direktkunden (von guard.itdatex.support verlinkt).
 *
 * GET /saas-onboard/?plan=<slug>     → zeigt Plan-Summary + Email-Form mit Nonce
 * POST /saas-onboard/                → validiert, bei Free direkt Account anlegen +
 *                                      Verification-Mail; bei Paid Stripe-Session
 *                                      anlegen und 303 zu checkout.stripe.com.
 *
 * Same-Origin auf wp.itdatex.support, daher klassische wp_nonce-CSRF-Protection.
 */
final class Onboard {

	public const QUERY_VAR = 'itdatex_mg_saas_onboard';

	public static function register_rules() : void {
		add_rewrite_rule( '^saas-onboard/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '1' );
	}

	public static function register_query_var( array $vars ) : array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function maybe_handle() : void {
		if ( ! get_query_var( self::QUERY_VAR ) ) { return; }

		if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
			self::handle_post();
			return;
		}
		if ( isset( $_GET['status'] ) ) {
			self::render_status();
			exit;
		}
		self::render_form();
		exit;
	}

	private static function render_status() : void {
		$status = sanitize_key( wp_unslash( $_GET['status'] ) );
		$email  = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';
		$portal = home_url( '/portal/' );

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );

		$title = 'Erfolg';
		$kicker = '// status';
		$body_msg = '';
		switch ( $status ) {
			case 'free_created':
				$title = 'Free-Account angelegt';
				$kicker = '// status · free';
				$body_msg = sprintf(
					'<p>Wir haben deinen Free-Account angelegt und an <strong>%s</strong> eine Welcome-Mail geschickt mit:</p><ul class="status-list"><li>Email bestätigen (gültig 24 Stunden) — Pflicht für Free-Accounts</li><li>Passwort setzen (gültig 7 Tage)</li></ul><p>Bitte auch im Spam-Ordner nachschauen.</p>',
					esc_html( $email )
				);
				break;
			case 'stripe_success':
				$title = 'Zahlung erfolgreich';
				$kicker = '// status · paid';
				$body_msg = '<p>Vielen Dank! Deine Subscription ist aktiv. Wir haben dir eine Welcome-Mail geschickt mit:</p><ul class="status-list"><li><strong>Set-Password-Link</strong> (gültig 7 Tage) — damit setzt du dein Passwort und kannst dich danach einloggen</li><li>Plan-Übersicht + nächste Schritte</li></ul><p>Bitte auch im Spam-Ordner nachschauen.</p>';
				break;
			default:
				$body_msg = '<p>Status unbekannt. Bitte erneut versuchen.</p>';
		}

		Shell::open( $title, [ 'noindex' => true ] );
		?>
<main>
  <div class="content">
    <p class="kicker"><?php echo esc_html( $kicker ); ?></p>
    <h1><?php echo esc_html( $title ); ?></h1>
    <div class="card">
      <?php echo $body_msg; ?>
      <a class="btn" href="<?php echo esc_url( $portal . 'login' ); ?>">Zum Login →</a>
    </div>
  </div>
</main>
		<?php
		Shell::close();
	}

	private static function render_form() : void {
		$plan_slug = isset( $_GET['plan'] ) ? sanitize_key( wp_unslash( $_GET['plan'] ) ) : 'free';
		$plan = Plans::get( $plan_slug );
		if ( ! $plan ) {
			wp_die( 'Unbekannter Plan.', 'Onboard', [ 'response' => 404 ] );
		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );

		$nonce = wp_nonce_field( 'itdatex_mg_saas_onboard', '_wpnonce', true, false );
		$price_str = Plans::format_price( $plan['price_cents'] );

		Shell::open( 'Plan ' . $plan['name'] . ' · Onboarding', [ 'noindex' => true ] );
		?>
<main>
  <div class="content">
    <p class="kicker">// onboarding · plan <?php echo esc_html( $plan['slug'] ); ?></p>
    <h1>Plan <?php echo esc_html( $plan['name'] ); ?> aktivieren</h1>
    <p><a class="back" href="https://guard.itdatex.support/">← zurück zur Übersicht</a></p>

    <div class="summary">
      <p class="price">
        <?php echo esc_html( $price_str ); ?><?php if ( $plan['is_paid'] ): ?><span class="unit"> / Monat</span><?php endif; ?>
      </p>
      <p><?php echo esc_html( $plan['description'] ); ?></p>
    </div>

    <form class="card" method="post" action="<?php echo esc_url( home_url( '/saas-onboard/' ) ); ?>">
      <?php echo $nonce; ?>
      <input type="hidden" name="plan" value="<?php echo esc_attr( $plan['slug'] ); ?>">

      <label>
        <span class="label-text">E-Mail-Adresse (für Login + Rechnung)</span>
        <input type="email" name="email" required autocomplete="email" placeholder="du@example.de">
      </label>

      <label class="consent">
        <input type="checkbox" name="accept_terms" required value="1">
        <span>Ich habe die <a href="https://wp.itdatex.support/agb/" target="_blank" rel="noopener">AGB</a> und die <a href="https://wp.itdatex.support/widerruf/" target="_blank" rel="noopener">Widerrufsbelehrung</a> gelesen und akzeptiere sie.</span>
      </label>

      <?php if ( $plan['is_paid'] ): ?>
      <label class="consent">
        <input type="checkbox" name="waive_withdrawal" required value="1">
        <span>Ich stimme ausdrücklich zu, dass mit der Bereitstellung der MailGuard-Dienste <strong>vor Ablauf der Widerrufsfrist</strong> begonnen wird, und bestätige, dass mein Widerrufsrecht damit gemäß § 356 Abs. 5 BGB erlischt.</span>
      </label>
      <?php endif; ?>

      <?php if ( ! empty( $plan['llm_enabled'] ) ): ?>
      <label class="consent">
        <input type="checkbox" name="cloud_consent" required value="1">
        <span>Ich willige ein, dass für die KI-gestützte Phishing-Erkennung Subject und Body verdächtiger E-Mails (Heuristik-Score 30–69, typisch &lt; 10 % der Mails) an <strong>Ollama Inc., 410 Townsend St., San Francisco, CA 94107, USA</strong> übermittelt und dort durch ein KI-Modell bewertet werden. Die Übermittlung in das Drittland USA erfolgt auf Grundlage der EU-Standardvertragsklauseln (SCC, Modul 2) gemäß Art.&nbsp;46 Abs.&nbsp;2 lit.&nbsp;c DSGVO. Details siehe <a href="https://wp.itdatex.support/datenschutz/#sec-10-2" target="_blank" rel="noopener">Datenschutzerklärung Abschnitt 10.2</a>. Widerruflich jederzeit im Portal unter „Plan".</span>
      </label>
      <?php endif; ?>

      <button type="submit"><?php echo $plan['is_paid'] ? 'Weiter zu Stripe →' : 'Free-Account anlegen →'; ?></button>

      <p class="hint">
        <?php if ( $plan['is_paid'] ): ?>
          Sichere Zahlung über Stripe · Kreditkarte
        <?php else: ?>
          Kein Zahlungsmittel erforderlich · jederzeit upgrade-bar
        <?php endif; ?>
      </p>
    </form>
  </div>
</main>
		<?php
		Shell::close();
	}

	private static function handle_post() : void {
		check_admin_referer( 'itdatex_mg_saas_onboard' );

		$plan_slug = isset( $_POST['plan'] ) ? sanitize_key( wp_unslash( $_POST['plan'] ) ) : '';
		$plan = Plans::get( $plan_slug );
		if ( ! $plan ) {
			wp_die( 'Unbekannter Plan.', 'Onboard', [ 'response' => 400 ] );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_die( 'Bitte eine gültige E-Mail-Adresse angeben.', 'Onboard', [ 'response' => 400 ] );
		}
		$email = strtolower( $email );

		if ( empty( $_POST['accept_terms'] ) ) {
			wp_die( 'Bitte AGB und Widerrufsbelehrung bestätigen.', 'Onboard', [ 'response' => 400 ] );
		}
		if ( $plan['is_paid'] && empty( $_POST['waive_withdrawal'] ) ) {
			wp_die( 'Für bezahlte Pläne ist die Zustimmung zur sofortigen Ausführung Pflicht (§ 356 Abs. 5 BGB).', 'Onboard', [ 'response' => 400 ] );
		}
		if ( ! empty( $plan['llm_enabled'] ) && empty( $_POST['cloud_consent'] ) ) {
			wp_die( 'Für Pläne mit KI-Tiefenanalyse ist die Einwilligung in die Übermittlung an Ollama Cloud Pflicht (Art. 6 Abs. 1 lit. a DSGVO). Alternativ einen Plan ohne KI wählen.', 'Onboard', [ 'response' => 400 ] );
		}

		$existing = Account::find_by_email( $email );
		if ( $existing ) {
			wp_die(
				'Für diese E-Mail-Adresse existiert bereits ein Account. Bitte einloggen oder Passwort zurücksetzen: '
				. '<a href="https://wp.itdatex.support/portal/login/">Zum Login</a>',
				'Account existiert',
				[ 'response' => 409 ]
			);
		}

		if ( ! $plan['is_paid'] ) {
			self::create_free_account( $email, $plan );
			// Hard redirect zur Bestätigungs-Seite
			wp_safe_redirect( home_url( '/saas-onboard/?status=free_created&email=' . rawurlencode( $email ) ) );
			exit;
		}

		// Paid: Stripe-Session anlegen. Cloud-Consent wandert via metadata in die
		// Stripe-Session und kommt im Webhook zurück — dann erst in mg_customers
		// persistiert (Customer-Row existiert vor Webhook noch nicht).
		Checkout::start_subscription( $email, $plan, [
			'cloud_consent' => ! empty( $_POST['cloud_consent'] ) ? '1' : '0',
		] );
	}

	private static function create_free_account( string $email, array $plan ) : void {
		$verification_token = bin2hex( random_bytes( 32 ) );
		$password_placeholder = wp_hash_password( wp_generate_password( 24, true, true ) );
		$id = Account::create( $email, $password_placeholder, $verification_token, 24 * 60 );
		if ( ! $id ) {
			wp_die( 'Konto konnte nicht angelegt werden.', 'Onboard', [ 'response' => 500 ] );
		}

		// 7-Tage-Token, damit der frische Customer direkt aus der Welcome-Mail das
		// Passwort setzen kann (statt erst "Passwort vergessen" zu klicken).
		$set_password_token = bin2hex( random_bytes( 32 ) );
		Account::set_password_reset_token( $id, $set_password_token, 7 * 24 * 60 );

		// Free-Pfad: llm_enabled steht im aktuellen Plans-Katalog auf false; falls
		// das eines Tages auf einem Free-Plan true wäre, würde nur dann auf 1
		// stehen, wenn der User Cloud-Consent erteilt hätte (POSTed). Aktuell ist
		// dieser Pfad immer 0 (Plans::free.llm_enabled === false).
		$cloud_consent_now = ! empty( $plan['llm_enabled'] ) && ! empty( $_POST['cloud_consent'] );
		global $wpdb;
		$wpdb->update( Account::table(), [
			'plan_slug'   => $plan['slug'],
			'plan_status' => 'active',
			'imap_quota'  => (int) $plan['imap_quota'],
			'llm_enabled' => $cloud_consent_now ? 1 : 0,
		], [ 'id' => $id ], [ '%s', '%s', '%d', '%d' ], [ '%d' ] );

		if ( $cloud_consent_now ) {
			Account::set_cloud_consent( $id, \Itdatex\Mailguard\Installer::CURRENT_CLOUD_CONSENT_VERSION );
		}

		// Eine kombinierte Welcome-Mail mit BEIDEN Links — Free-User müssen Email
		// noch verifizieren und ein Passwort setzen.
		Mailer::send_saas_welcome( $email, $plan, $set_password_token, $verification_token );
	}
}
