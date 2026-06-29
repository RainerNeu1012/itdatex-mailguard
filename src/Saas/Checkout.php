<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Saas;

/**
 * Stripe-Subscription-Checkout für SaaS-Pläne.
 *
 * Kein stripe-php SDK (Plugin soll keine fremde Composer-Abhängigkeit ziehen) —
 * wir sprechen die Stripe-REST direkt via wp_remote_post() an.
 *
 * Der `sk_live` wird vom Schwesterplugin `itdatex-shop` über das wp_option
 * `itdatex_stripe_secret_key` gepflegt. Wir lesen den selben Wert, sodass
 * Schlüssel-Rotation an einer Stelle reicht.
 */
final class Checkout {

	private const STRIPE_API = 'https://api.stripe.com/v1';

	public static function start_subscription( string $email, array $plan, array $extra_metadata = [] ) : void {
		if ( empty( $plan['price_id'] ) ) {
			wp_die( 'Stripe-Price-ID für diesen Plan fehlt. Bitte itdatex-Support kontaktieren.', 'Checkout', [ 'response' => 500 ] );
		}

		$secret = (string) get_option( 'itdatex_stripe_secret_key', '' );
		if ( $secret === '' ) {
			wp_die( 'Stripe-Schlüssel fehlt.', 'Checkout', [ 'response' => 500 ] );
		}

		$success_url = home_url( '/saas-onboard/?status=stripe_success&session={CHECKOUT_SESSION_ID}' );
		$cancel_url  = 'https://guard.itdatex.support/';

		$body = [
			'mode'                          => 'subscription',
			'customer_email'                => $email,
			'line_items[0][price]'          => $plan['price_id'],
			'line_items[0][quantity]'       => '1',
			'success_url'                   => $success_url,
			'cancel_url'                    => $cancel_url,
			'locale'                        => 'de',
			'billing_address_collection'    => 'required',
			'tax_id_collection[enabled]'    => 'true',
			// Explizit nur Karte — sonst zieht Stripe die Default-Payment-Method-Configuration
			// des Accounts, die SEPA-Debit aktiv hat, aber die Account-Capability dafür
			// fehlt. Sobald SEPA freigeschaltet ist, kann diese Zeile entfernt werden.
			'payment_method_types[0]'       => 'card',
			'metadata[source]'              => 'mailguard-saas',
			'metadata[plan_slug]'           => $plan['slug'],
			'metadata[accept_terms_at]'     => gmdate( 'c' ),
			'metadata[waive_withdrawal_at]' => gmdate( 'c' ),
			'subscription_data[metadata][source]'      => 'mailguard-saas',
			'subscription_data[metadata][plan_slug]'   => $plan['slug'],
			'subscription_data[metadata][customer_email]' => $email,
		];

		// Extra-Metadaten (z.B. cloud_consent) durchschleifen — werden im Webhook
		// gelesen, sobald Stripe die Session als 'completed' meldet, und dann
		// auf der frisch angelegten Customer-Row persistiert.
		foreach ( $extra_metadata as $k => $v ) {
			$key = preg_replace( '/[^a-z0-9_]/i', '', (string) $k );
			if ( $key === '' ) { continue; }
			$body[ 'metadata[' . $key . ']' ]                         = (string) $v;
			$body[ 'subscription_data[metadata][' . $key . ']' ]      = (string) $v;
		}

		$resp = wp_remote_post( self::STRIPE_API . '/checkout/sessions', [
			'headers' => [
				'Authorization' => 'Bearer ' . $secret,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'body'    => $body,
			'timeout' => 15,
		] );

		if ( is_wp_error( $resp ) ) {
			error_log( '[itdatex-mailguard] Stripe-Session-Error: ' . $resp->get_error_message() );
			wp_die( 'Checkout konnte nicht gestartet werden (Netzwerk).', 'Checkout', [ 'response' => 502 ] );
		}

		$status = (int) wp_remote_retrieve_response_code( $resp );
		$data   = json_decode( (string) wp_remote_retrieve_body( $resp ), true );

		if ( $status !== 200 || empty( $data['url'] ) ) {
			$msg = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'unbekannter Stripe-Fehler';
			error_log( '[itdatex-mailguard] Stripe-Session-Reject (HTTP ' . $status . '): ' . $msg );
			wp_die( 'Checkout konnte nicht gestartet werden: ' . esc_html( $msg ), 'Checkout', [ 'response' => 502 ] );
		}

		wp_redirect( (string) $data['url'], 303 );
		exit;
	}
}
