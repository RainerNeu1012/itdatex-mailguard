<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Saas;

/**
 * SaaS-Plan-Definitionen für direkt-Endkunden auf guard.itdatex.support.
 * Stripe Price-IDs liegen in wp_options (eintragbar via Admin), Defaults werden hier
 * als Fallback gehalten.
 */
final class Plans {

	public const SLUGS = [ 'free', 'solo', 'plus', 'pro', 'test' ];

	public static function all() : array {
		return [
			'test' => [
				'slug'                 => 'test',
				'name'                 => 'Test (1 €)',
				'price_cents'          => 100,
				'price_id'             => (string) get_option( 'itdatex_mailguard_saas_price_test', '' ),
				'imap_quota'           => 1,
				'llm_enabled'          => true,
				'is_paid'              => true,
				'rules_limit'          => 0, // 0 = unbegrenzt
				'unsubs_monthly_limit' => 0,
				'actions_retention_days' => 0,
				'description'          => '1 Postfach, voller Scanner — nur für interne E2E-Live-Tests.',
			],
			'free' => [
				'slug'                 => 'free',
				'name'                 => 'Free',
				'price_cents'          => 0,
				'price_id'             => '',
				'imap_quota'           => 1,
				'llm_enabled'          => false,
				'is_paid'              => false,
				'rules_limit'          => 10,
				'unsubs_monthly_limit' => 5,
				'actions_retention_days' => 30,
				'description'          => '1 Postfach, Heuristik-Scanner, max. 10 Regeln, 5 Newsletter-Abmeldungen/Monat, 30 Tage Aktionen-Historie.',
			],
			'solo' => [
				'slug'                 => 'solo',
				'name'                 => 'Solo',
				'price_cents'          => 500,
				'price_id'             => (string) get_option( 'itdatex_mailguard_saas_price_solo', '' ),
				'imap_quota'           => 1,
				'llm_enabled'          => true,
				'is_paid'              => true,
				'rules_limit'          => 0,
				'unsubs_monthly_limit' => 0,
				'actions_retention_days' => 0,
				'description'          => '1 Postfach, voller Scanner + LLM-Deep-Mode. Unbegrenzt Regeln, Abmeldungen und Historie.',
			],
			'plus' => [
				'slug'                 => 'plus',
				'name'                 => 'Plus',
				'price_cents'          => 1500,
				'price_id'             => (string) get_option( 'itdatex_mailguard_saas_price_plus', '' ),
				'imap_quota'           => 5,
				'llm_enabled'          => true,
				'is_paid'              => true,
				'rules_limit'          => 0,
				'unsubs_monthly_limit' => 0,
				'actions_retention_days' => 0,
				'description'          => '5 Postfächer, voller Scanner + LLM-Deep-Mode. Unbegrenzt Regeln, Abmeldungen und Historie.',
			],
			'pro' => [
				'slug'                 => 'pro',
				'name'                 => 'Pro',
				'price_cents'          => 3900,
				'price_id'             => (string) get_option( 'itdatex_mailguard_saas_price_pro', '' ),
				'imap_quota'           => 25,
				'llm_enabled'          => true,
				'is_paid'              => true,
				'rules_limit'          => 0,
				'unsubs_monthly_limit' => 0,
				'actions_retention_days' => 0,
				'description'          => '25 Postfächer, voller Scanner + LLM-Deep-Mode. Unbegrenzt Regeln, Abmeldungen und Historie.',
			],
		];
	}

	/**
	 * Liest ein Quota-Feld fuer den aktuellen Kunden aus Plans.php.
	 * Fallback: Free-Plan-Wert, falls Slug unbekannt.
	 * Wert 0 bedeutet "unbegrenzt".
	 */
	public static function customer_limit( int $customer_id, string $field ) : int {
		global $wpdb;
		$slug = (string) $wpdb->get_var( $wpdb->prepare(
			'SELECT plan_slug FROM ' . $wpdb->prefix . 'mg_customers WHERE id = %d',
			$customer_id
		) );
		if ( $slug === '' ) { $slug = 'free'; }
		$plan = self::get( $slug ) ?? self::get( 'free' );
		return (int) ( $plan[ $field ] ?? 0 );
	}

	public static function get( string $slug ) : ?array {
		$all = self::all();
		return $all[ $slug ] ?? null;
	}

	public static function format_price( int $cents ) : string {
		if ( $cents === 0 ) { return '0 €'; }
		return number_format( $cents / 100, 2, ',', '.' ) . ' €';
	}

	public static function by_price_id( string $price_id ) : ?array {
		if ( $price_id === '' ) { return null; }
		foreach ( self::all() as $plan ) {
			if ( $plan['price_id'] === $price_id ) { return $plan; }
		}
		return null;
	}
}
