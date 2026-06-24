<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Admin;

use Itdatex\Mailguard\Installer;

final class Settings {

	public const OPTION_GROUP = 'itdatex_mailguard';
	public const PAGE_SLUG    = 'itdatex-mailguard';

	public static function add_menu() : void {
		add_menu_page(
			__( 'MailGuard', 'itdatex-mailguard' ),
			__( 'MailGuard', 'itdatex-mailguard' ),
			'manage_options',
			Customers::PAGE_SLUG,
			[ Customers::class, 'render' ],
			'dashicons-email-alt2',
			59
		);
		Customers::add_menu();
		add_submenu_page(
			Customers::PAGE_SLUG,
			__( 'Einstellungen', 'itdatex-mailguard' ),
			__( 'Einstellungen', 'itdatex-mailguard' ),
			'manage_options',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function register() : void {
		register_setting( self::OPTION_GROUP, Installer::OPTION_SETTINGS, [
			'type'              => 'array',
			'sanitize_callback' => [ __CLASS__, 'sanitize' ],
			'default'           => [],
			'show_in_rest'      => false,
		] );

		add_settings_section( 'mg_portal', __( 'Portal', 'itdatex-mailguard' ), '__return_false', self::PAGE_SLUG );
		self::field_text(     'portal_slug',                __( 'Portal-Slug', 'itdatex-mailguard' ),                 'mg_portal', [ 'placeholder' => 'portal' ] );
		self::field_checkbox( 'allow_registration',         __( 'Registrierung erlauben', 'itdatex-mailguard' ),      'mg_portal', __( 'Neue Endkunden duerfen sich selbst anmelden', 'itdatex-mailguard' ) );
		self::field_checkbox( 'require_email_verification', __( 'E-Mail-Bestaetigung', 'itdatex-mailguard' ),         'mg_portal', __( 'Login erst nach Klick auf den Bestaetigungs-Link', 'itdatex-mailguard' ) );

		add_settings_section( 'mg_mail', __( 'E-Mail-Versand', 'itdatex-mailguard' ), '__return_false', self::PAGE_SLUG );
		self::field_text( 'mail_from_name',    __( 'Absender-Name', 'itdatex-mailguard' ),    'mg_mail', [ 'placeholder' => 'MailGuard' ] );
		self::field_text( 'mail_from_address', __( 'Absender-Adresse', 'itdatex-mailguard' ), 'mg_mail', [ 'placeholder' => 'noreply@…' ] );

		add_settings_section( 'mg_session', __( 'Sessions & Limits', 'itdatex-mailguard' ), '__return_false', self::PAGE_SLUG );
		self::field_number( 'session_ttl_days',       __( 'Session-Laufzeit (Tage)', 'itdatex-mailguard' ),       'mg_session', 1, 90 );
		self::field_number( 'rate_login_per_min',     __( 'Login-Versuche / Minute / IP', 'itdatex-mailguard' ),  'mg_session', 1, 1000 );
		self::field_number( 'rate_register_per_hour', __( 'Registrierungen / Stunde / IP', 'itdatex-mailguard' ), 'mg_session', 1, 1000 );

		add_settings_section( 'mg_api', __( 'Anti-Phishing-API', 'itdatex-mailguard' ), '__return_false', self::PAGE_SLUG );
		self::field_text( 'antiphish_api_url', __( 'API-URL', 'itdatex-mailguard' ), 'mg_api', [ 'placeholder' => 'https://mailsec.itdatex.support' ] );
		self::field_password( 'antiphish_api_key', __( 'X-API-Key', 'itdatex-mailguard' ), 'mg_api' );
		self::field_checkbox( 'scan_deep', __( 'LLM-Deep-Mode', 'itdatex-mailguard' ), 'mg_api', __( 'LLM-Tiefenanalyse erzwingen (~15–25 s/Mail; sonst nur Heuristik)', 'itdatex-mailguard' ) );
		self::field_number( 'scan_batch_size',    __( 'Scans pro Cron-Run', 'itdatex-mailguard' ), 'mg_api', 1, 100 );
		self::field_number( 'manual_scan_quota',  __( 'Manuelle Scans / Endkunde / 24h', 'itdatex-mailguard' ), 'mg_api', 1, 1000 );

		add_settings_section( 'mg_license', __( 'Lizenz', 'itdatex-mailguard' ), [ __CLASS__, 'license_intro' ], self::PAGE_SLUG );
		self::field_text( 'license_key', __( 'Lizenzschlüssel', 'itdatex-mailguard' ), 'mg_license', [ 'placeholder' => 'XXXXX-XXXXX-…' ] );
	}

	public static function license_intro() : void {
		$status = \Itdatex\Mailguard\License\Guard::status();
		$state  = (string) ( $status['status'] ?? 'unset' );
		$color  = match ( $state ) {
			'active'   => '#00a32a',
			'past_due' => '#dba617',
			'invalid','canceled','expired' => '#d63638',
			default    => '#646970',
		};
		echo '<p style="font-size:1.05em">Status: <strong style="color:' . esc_attr( $color ) . '">' . esc_html( $state ) . '</strong>';
		if ( ! empty( $status['expires_at'] ) ) {
			echo ' · gültig bis <code>' . esc_html( (string) $status['expires_at'] ) . '</code>';
		}
		if ( ! empty( $status['cancel_at_period_end'] ) ) {
			echo ' <em>(Kündigung zum Periodenende)</em>';
		}
		if ( ! empty( $status['billing_mode'] ) ) {
			echo ' · ' . esc_html( $status['billing_mode'] === 'subscription' ? 'Abo' : 'Einmalkauf' );
		}
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Lizenz wird live gegen wp.itdatex.support geprüft (Cache 6h). Nach Eintragen + Speichern wird der Cache invalidiert.', 'itdatex-mailguard' ) . '</p>';
	}

	public static function sanitize( $input ) : array {
		$current = (array) get_option( Installer::OPTION_SETTINGS, [] );
		if ( ! is_array( $input ) ) { $input = []; }
		$out = $current;

		foreach ( [ 'portal_slug', 'mail_from_name', 'mail_from_address', 'antiphish_api_url' ] as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$out[ $k ] = $k === 'antiphish_api_url' ? esc_url_raw( (string) $input[ $k ] ) : sanitize_text_field( (string) $input[ $k ] );
			}
		}
		foreach ( [ 'allow_registration', 'require_email_verification', 'scan_deep' ] as $k ) {
			$out[ $k ] = ! empty( $input[ $k ] ) ? 1 : 0;
		}
		if ( isset( $input['scan_batch_size'] ) ) {
			$out['scan_batch_size'] = max( 1, min( 100, (int) $input['scan_batch_size'] ) );
		}
		if ( isset( $input['manual_scan_quota'] ) ) {
			$out['manual_scan_quota'] = max( 1, min( 1000, (int) $input['manual_scan_quota'] ) );
		}
		if ( array_key_exists( 'license_key', $input ) ) {
			$prev = (string) ( $current['license_key'] ?? '' );
			$next = strtoupper( trim( (string) $input['license_key'] ) );
			$out['license_key'] = $next;
			if ( $next !== $prev ) {
				\Itdatex\Mailguard\License\Guard::invalidate();
			}
		}
		if ( isset( $input['session_ttl_days'] ) ) {
			$out['session_ttl_days'] = max( 1, min( 90, (int) $input['session_ttl_days'] ) );
		}
		foreach ( [ 'rate_login_per_min', 'rate_register_per_hour' ] as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$out[ $k ] = max( 1, min( 1000, (int) $input[ $k ] ) );
			}
		}
		if ( array_key_exists( 'antiphish_api_key', $input ) ) {
			$key = trim( (string) $input['antiphish_api_key'] );
			if ( $key !== '' ) {
				$out['antiphish_api_key'] = $key;
			}
		}

		// Slug-Wechsel => Rewrite-Rules muessen neu geflusht werden.
		if ( ( $current['portal_slug'] ?? '' ) !== ( $out['portal_slug'] ?? '' ) ) {
			add_action( 'shutdown', static function () {
				\Itdatex\Mailguard\Portal\Rewrite::register_rules();
				flush_rewrite_rules();
			} );
		}
		return $out;
	}

	public static function get( string $key, $default = null ) {
		$o = (array) get_option( Installer::OPTION_SETTINGS, [] );
		return $o[ $key ] ?? $default;
	}

	public static function render_page() : void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		echo '<div class="wrap"><h1>' . esc_html__( 'MailGuard — Einstellungen', 'itdatex-mailguard' ) . '</h1>';
		echo '<form action="options.php" method="post">';
		settings_fields( self::OPTION_GROUP );
		do_settings_sections( self::PAGE_SLUG );
		submit_button();
		echo '</form></div>';
	}

	private static function field_text( string $key, string $label, string $section, array $args = [] ) : void {
		add_settings_field( $key, $label, function () use ( $key, $args ) {
			$val = (string) self::get( $key, '' );
			printf( '<input type="text" class="regular-text" name="%1$s[%2$s]" value="%3$s" placeholder="%4$s" />',
				esc_attr( Installer::OPTION_SETTINGS ),
				esc_attr( $key ),
				esc_attr( $val ),
				esc_attr( $args['placeholder'] ?? '' )
			);
		}, self::PAGE_SLUG, $section );
	}

	private static function field_password( string $key, string $label, string $section ) : void {
		add_settings_field( $key, $label, function () use ( $key ) {
			$has = (string) self::get( $key, '' ) !== '';
			$placeholder = $has ? __( '•••••••• (gespeichert) — leer lassen, um nicht zu aendern', 'itdatex-mailguard' ) : '';
			printf( '<input type="password" class="regular-text" name="%1$s[%2$s]" value="" autocomplete="new-password" placeholder="%3$s" />',
				esc_attr( Installer::OPTION_SETTINGS ),
				esc_attr( $key ),
				esc_attr( $placeholder )
			);
		}, self::PAGE_SLUG, $section );
	}

	private static function field_checkbox( string $key, string $label, string $section, string $cb_label ) : void {
		add_settings_field( $key, $label, function () use ( $key, $cb_label ) {
			$val = (int) self::get( $key, 0 );
			printf( '<label><input type="checkbox" name="%1$s[%2$s]" value="1"%3$s /> %4$s</label>',
				esc_attr( Installer::OPTION_SETTINGS ),
				esc_attr( $key ),
				checked( 1, $val, false ),
				esc_html( $cb_label )
			);
		}, self::PAGE_SLUG, $section );
	}

	private static function field_number( string $key, string $label, string $section, int $min, int $max ) : void {
		add_settings_field( $key, $label, function () use ( $key, $min, $max ) {
			$val = (int) self::get( $key, $min );
			printf( '<input type="number" class="small-text" name="%1$s[%2$s]" value="%3$d" min="%4$d" max="%5$d" />',
				esc_attr( Installer::OPTION_SETTINGS ),
				esc_attr( $key ),
				$val, $min, $max
			);
		}, self::PAGE_SLUG, $section );
	}
}
