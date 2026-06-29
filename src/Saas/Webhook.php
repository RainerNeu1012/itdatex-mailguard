<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Saas;

use Itdatex\Mailguard\Customer\Account;
use Itdatex\Mailguard\Customer\Mailer;

/**
 * Stripe-Webhook für MailGuard-SaaS-Subscriptions.
 *
 * Eigener Endpoint /wp-json/itdatex-mailguard/v1/stripe-webhook und eigenes
 * `whsec_*` (separater Stripe-Webhook im Dashboard) — damit Shop-Webhook
 * unbeeinflusst bleibt. Signature-Verification ohne stripe-php-SDK.
 *
 * Eingehende Events werden nur verarbeitet wenn `metadata.source == mailguard-saas`,
 * um versehentliche Cross-Events vom Shop-Webhook zu ignorieren.
 */
final class Webhook {

	public const OPTION_SECRET = 'itdatex_mailguard_saas_webhook_secret';

	public static function register() : void {
		register_rest_route( 'itdatex-mailguard/v1', '/stripe-webhook', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'handle' ],
			'permission_callback' => '__return_true',
		] );
	}

	public static function handle( \WP_REST_Request $req ) : \WP_REST_Response {
		$secret  = (string) get_option( self::OPTION_SECRET, '' );
		$payload = (string) $req->get_body();
		$sig     = (string) $req->get_header( 'stripe_signature' );

		if ( $secret === '' || $sig === '' ) {
			return new \WP_REST_Response( [ 'error' => 'webhook not configured' ], 400 );
		}
		if ( ! self::verify_signature( $payload, $sig, $secret ) ) {
			error_log( '[itdatex-mailguard] Webhook-Signatur ungültig' );
			return new \WP_REST_Response( [ 'error' => 'invalid signature' ], 400 );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'malformed' ], 400 );
		}

		$obj = $event['data']['object'] ?? [];
		$source = $obj['metadata']['source'] ?? '';

		// Subscription-Events tragen die Metadata in subscription_data.metadata
		// die wir beim Create gesetzt haben. Stripe vererbt das auf die Subscription.
		if ( $source !== 'mailguard-saas' ) {
			// Nicht unsere Sache — Shop-Webhook handhabt das separat.
			return new \WP_REST_Response( [ 'ok' => true, 'skipped' => 'not-mailguard-saas' ], 200 );
		}

		try {
			switch ( $event['type'] ) {
				case 'checkout.session.completed':
					self::on_checkout_completed( $obj );
					break;
				case 'customer.subscription.updated':
					self::on_subscription_updated( $obj );
					break;
				case 'customer.subscription.deleted':
					self::on_subscription_deleted( $obj );
					break;
				case 'invoice.payment_failed':
					self::on_invoice_failed( $obj );
					break;
			}
		} catch ( \Throwable $e ) {
			error_log( '[itdatex-mailguard] Webhook-Handler-Fehler (' . $event['type'] . '): ' . $e->getMessage() );
			return new \WP_REST_Response( [ 'error' => 'handler error' ], 500 );
		}

		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/**
	 * Stripe-Signature-Verifikation nach Stripe-Doku:
	 * Header: `t=<timestamp>,v1=<hmac_sha256_hex>[,v1=...]`
	 * signed_payload = `<timestamp>.<body>`
	 * HMAC-SHA256(signed_payload, secret) → hex → vergleich (constant-time) gegen alle v1-Werte
	 * Tolerance: 5 Minuten (Standard Stripe).
	 */
	public static function verify_signature( string $payload, string $header, string $secret, int $tolerance = 300 ) : bool {
		$parts = [];
		foreach ( explode( ',', $header ) as $piece ) {
			$kv = explode( '=', $piece, 2 );
			if ( count( $kv ) === 2 ) { $parts[ trim( $kv[0] ) ][] = trim( $kv[1] ); }
		}
		$timestamp = (int) ( $parts['t'][0] ?? 0 );
		$sigs      = $parts['v1'] ?? [];
		if ( $timestamp <= 0 || empty( $sigs ) ) { return false; }
		if ( abs( time() - $timestamp ) > $tolerance ) { return false; }

		$signed = $timestamp . '.' . $payload;
		$expect = hash_hmac( 'sha256', $signed, $secret );
		foreach ( $sigs as $s ) {
			if ( hash_equals( $expect, $s ) ) { return true; }
		}
		return false;
	}

	private static function on_checkout_completed( array $session ) : void {
		$email = (string) ( $session['customer_details']['email'] ?? $session['customer_email'] ?? '' );
		$plan_slug = (string) ( $session['metadata']['plan_slug'] ?? '' );
		$customer_id_stripe = (string) ( $session['customer'] ?? '' );
		$sub_id = (string) ( $session['subscription'] ?? '' );

		if ( $email === '' || $plan_slug === '' ) {
			error_log( '[itdatex-mailguard] checkout.completed ohne email oder plan_slug' );
			return;
		}

		$plan = Plans::get( $plan_slug );
		if ( ! $plan ) {
			error_log( '[itdatex-mailguard] Unbekannter plan_slug: ' . $plan_slug );
			return;
		}

		// Cloud-Consent aus den Stripe-Session-Metadata lesen (kommt aus dem
		// Onboard-Formular, siehe Checkout::start_subscription). Bei Plus/Pro
		// ohne Consent würde Onboard schon im Vorfeld ablehnen — hier ist es
		// also redundante Sicherheit.
		$cloud_consent_in_session = (string) ( $session['metadata']['cloud_consent'] ?? '' ) === '1';

		$existing = Account::find_by_email( $email );
		if ( $existing ) {
			if ( $cloud_consent_in_session && ! empty( $plan['llm_enabled'] ) ) {
				Account::set_cloud_consent( (int) $existing['id'], \Itdatex\Mailguard\Installer::CURRENT_CLOUD_CONSENT_VERSION );
			}
			self::update_customer_plan( (int) $existing['id'], $plan, [
				'stripe_customer_id'     => $customer_id_stripe,
				'stripe_subscription_id' => $sub_id,
				'plan_status'            => 'active',
			] );
			return;
		}

		// Neuer Customer aus Stripe-Flow. Wir bauen das Konto OHNE Email-Verification-Token,
		// weil Stripe-Checkout die Email-Ownership bereits per Receipt-Mail bestätigt
		// hat — der Customer ist sofort als verified+active markiert.
		$password_placeholder = wp_hash_password( wp_generate_password( 24, true, true ) );
		$id = Account::create( $email, $password_placeholder, null );
		if ( ! $id ) {
			error_log( '[itdatex-mailguard] Account::create fehlgeschlagen für ' . $email );
			return;
		}

		// 7-Tage Set-Password-Token, geht direkt in die Welcome-Mail.
		$set_password_token = bin2hex( random_bytes( 32 ) );
		Account::set_password_reset_token( $id, $set_password_token, 7 * 24 * 60 );

		if ( $cloud_consent_in_session && ! empty( $plan['llm_enabled'] ) ) {
			Account::set_cloud_consent( $id, \Itdatex\Mailguard\Installer::CURRENT_CLOUD_CONSENT_VERSION );
		}

		self::update_customer_plan( $id, $plan, [
			'stripe_customer_id'     => $customer_id_stripe,
			'stripe_subscription_id' => $sub_id,
			'plan_status'            => 'active',
		] );

		Mailer::send_saas_welcome( $email, $plan, $set_password_token );
	}

	private static function on_subscription_updated( array $sub ) : void {
		$sub_id = (string) ( $sub['id'] ?? '' );
		if ( $sub_id === '' ) { return; }

		$status = (string) ( $sub['status'] ?? '' );
		$plan_slug = (string) ( $sub['metadata']['plan_slug'] ?? '' );

		// Plan auch aus Price-Item ableiten (Fallback wenn metadata leer)
		if ( $plan_slug === '' ) {
			$price_id = (string) ( $sub['items']['data'][0]['price']['id'] ?? '' );
			$plan = Plans::by_price_id( $price_id );
			if ( $plan ) { $plan_slug = $plan['slug']; }
		}
		$plan = $plan_slug !== '' ? Plans::get( $plan_slug ) : null;

		$row = self::find_by_subscription( $sub_id );
		if ( ! $row ) { return; }

		$map_status = [
			'active'             => 'active',
			'trialing'           => 'active',
			'past_due'           => 'past_due',
			'unpaid'             => 'past_due',
			'canceled'           => 'canceled',
			'incomplete'         => 'past_due',
			'incomplete_expired' => 'canceled',
		];
		$plan_status = $map_status[ $status ] ?? 'past_due';

		if ( $plan ) {
			self::update_customer_plan( (int) $row['id'], $plan, [ 'plan_status' => $plan_status ] );
		} else {
			global $wpdb;
			$wpdb->update( Account::table(), [ 'plan_status' => $plan_status ], [ 'id' => $row['id'] ], [ '%s' ], [ '%d' ] );
		}
	}

	private static function on_subscription_deleted( array $sub ) : void {
		$sub_id = (string) ( $sub['id'] ?? '' );
		if ( $sub_id === '' ) { return; }
		$row = self::find_by_subscription( $sub_id );
		if ( ! $row ) { return; }

		global $wpdb;
		$grace_until = gmdate( 'Y-m-d H:i:s', time() + 30 * 86400 );
		$wpdb->update( Account::table(), [
			'plan_status'      => 'canceled',
			'plan_grace_until' => $grace_until,
		], [ 'id' => $row['id'] ], [ '%s', '%s' ], [ '%d' ] );
	}

	private static function on_invoice_failed( array $invoice ) : void {
		$sub_id = (string) ( $invoice['subscription'] ?? '' );
		if ( $sub_id === '' ) { return; }
		$row = self::find_by_subscription( $sub_id );
		if ( ! $row ) { return; }
		global $wpdb;
		$wpdb->update( Account::table(), [ 'plan_status' => 'past_due' ], [ 'id' => $row['id'] ], [ '%s' ], [ '%d' ] );
	}

	private static function find_by_subscription( string $sub_id ) : ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . Account::table() . ' WHERE stripe_subscription_id = %s LIMIT 1',
			$sub_id
		), ARRAY_A );
		return $row ?: null;
	}

	private static function update_customer_plan( int $id, array $plan, array $extra = [] ) : void {
		global $wpdb;
		// llm_enabled darf nur dann auf 1 stehen, wenn ein gültiger
		// Cloud-Consent in der DB liegt — sonst läuft der nächste Scan
		// trotz Plan-Berechtigung in die Cloud, ohne DSGVO-Einwilligung.
		$consent_at = (string) $wpdb->get_var( $wpdb->prepare(
			'SELECT cloud_consent_at FROM ' . Account::table() . ' WHERE id = %d',
			$id
		) );
		$plan_llm = ! empty( $plan['llm_enabled'] );
		$llm_effective = $plan_llm && $consent_at !== '' && $consent_at !== '0' ? 1 : 0;

		$data = array_merge( [
			'plan_slug'        => $plan['slug'],
			'imap_quota'       => (int) $plan['imap_quota'],
			'llm_enabled'      => $llm_effective,
			'plan_grace_until' => null,
		], $extra );

		$fmt = [];
		foreach ( $data as $k => $v ) {
			if ( in_array( $k, [ 'imap_quota', 'llm_enabled' ], true ) ) { $fmt[] = '%d'; }
			else { $fmt[] = '%s'; }
		}
		$wpdb->update( Account::table(), $data, [ 'id' => $id ], $fmt, [ '%d' ] );
	}
}
