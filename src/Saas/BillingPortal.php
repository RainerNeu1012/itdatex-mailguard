<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Saas;

use Itdatex\Mailguard\Customer\Account;
use Itdatex\Mailguard\Customer\Auth;
use Itdatex\Mailguard\Rest\Controller;

/**
 * REST-Endpoint, der einen kurzlebigen Stripe-Billing-Portal-Link für den
 * eingeloggten Customer erzeugt. Stripe hostet das eigentliche
 * Plan-Wechsel- / Zahlungsmittel- / Kündigungs-UI — wir spiegeln keinen
 * eigenen Plan-Editor nach.
 */
final class BillingPortal {

	private const STRIPE_API = 'https://api.stripe.com/v1';

	public static function register() : void {
		register_rest_route( Controller::NAMESPACE, '/saas/billing-portal', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ self::class, 'create_session' ],
		] );
	}

	public static function create_session( \WP_REST_Request $req ) : \WP_REST_Response {
		$me = Auth::current();
		if ( ! $me ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'not_authenticated' ], 401 );
		}

		global $wpdb;
		$cust = $wpdb->get_row( $wpdb->prepare(
			'SELECT stripe_customer_id FROM ' . Account::table() . ' WHERE id = %d',
			(int) $me['customer_id']
		), ARRAY_A );

		$stripe_cust = (string) ( $cust['stripe_customer_id'] ?? '' );
		if ( $stripe_cust === '' ) {
			return new \WP_REST_Response( [
				'ok'    => false,
				'error' => 'no_stripe_customer',
				'hint'  => 'Du bist im Free-Plan. Wähle auf guard.itdatex.support einen bezahlten Plan, um abzurechnen.',
			], 400 );
		}

		$secret = (string) get_option( 'itdatex_stripe_secret_key', '' );
		if ( $secret === '' ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'stripe_not_configured' ], 500 );
		}

		$return_url = home_url( '/portal/plan' );
		$resp = wp_remote_post( self::STRIPE_API . '/billing_portal/sessions', [
			'headers' => [
				'Authorization' => 'Bearer ' . $secret,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'body' => [
				'customer'   => $stripe_cust,
				'return_url' => $return_url,
			],
			'timeout' => 15,
		] );
		if ( is_wp_error( $resp ) ) {
			error_log( '[itdatex-mailguard] BillingPortal-Error: ' . $resp->get_error_message() );
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'network' ], 502 );
		}
		$status = (int) wp_remote_retrieve_response_code( $resp );
		$body   = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( $status !== 200 || empty( $body['url'] ) ) {
			$msg = $body['error']['message'] ?? 'unbekannt';
			error_log( '[itdatex-mailguard] BillingPortal-Reject HTTP ' . $status . ': ' . $msg );
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'stripe_reject', 'message' => $msg ], 502 );
		}

		return new \WP_REST_Response( [ 'ok' => true, 'url' => (string) $body['url'] ], 200 );
	}
}
