<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\License;

use Itdatex\Mailguard\Admin\Settings;

/**
 * Cached License-State des Site-Owners.
 * 6h-Cache, refresh per Settings-Speichern.
 *
 * is_active() → wahr fuer status=active und past_due (Grace);
 * is_full_active() → nur fuer active.
 *
 * Site-Owner-Admin-UI nutzt das fuer Status-Anzeige + Warn-Banner;
 * spaeter koennen einzelne Endpoints (z.B. Customer-Create) Pro-gated werden.
 */
final class Guard {

	public const TRANSIENT = 'itdatex_mailguard_license_status';
	public const TTL       = 6 * HOUR_IN_SECONDS;

	public static function is_active() : bool {
		$s = self::status()['status'] ?? '';
		return in_array( $s, [ 'active', 'past_due' ], true );
	}

	public static function is_full_active() : bool {
		return ( self::status()['status'] ?? '' ) === 'active';
	}

	public static function status() : array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) { return $cached; }
		$fresh = self::probe();
		set_transient( self::TRANSIENT, $fresh, self::TTL );
		return $fresh;
	}

	public static function invalidate() : void {
		delete_transient( self::TRANSIENT );
	}

	private static function probe() : array {
		$key = trim( (string) Settings::get( 'license_key', '' ) );
		if ( $key === '' ) {
			return [ 'status' => 'unset', 'reason' => 'no_license_key' ];
		}
		$res = Client::validate( $key );
		// Shop-API liefert ok=false bei nicht-erlaubter Domain (Activate noch nicht gelaufen) ODER inactive Lizenz.
		// Wir unterscheiden anhand des Error-Codes.
		if ( ! empty( $res['ok'] ) ) {
			return [
				'status'              => (string) ( $res['license_status'] ?? 'active' ),
				'reason'              => 'live',
				'product_id'          => (int) ( $res['product_id'] ?? 0 ),
				'billing_mode'        => (string) ( $res['billing_mode'] ?? 'one_time' ),
				'expires_at'          => $res['expires_at'] ?? null,
				'cancel_at_period_end'=> ! empty( $res['cancel_at_period_end'] ),
				'subscription_id'     => (string) ( $res['subscription_id'] ?? '' ),
				'checked_at'          => time(),
			];
		}
		$err  = (string) ( $res['error'] ?? 'unknown' );
		$http = (int) ( $res['http'] ?? 0 );

		if ( in_array( $err, [ 'network_error', 'bad_response' ], true ) ) {
			return [ 'status' => 'unknown', 'reason' => $err, 'detail' => $res['detail'] ?? '', 'checked_at' => time() ];
		}
		if ( $http === 403 ) {
			$ls = (string) ( $res['license_status'] ?? 'inactive' );
			return [ 'status' => $ls, 'reason' => $err, 'checked_at' => time() ];
		}
		// 404 unknown license, oder ok=false weil domain nicht aktiviert
		return [ 'status' => 'invalid', 'reason' => $err, 'checked_at' => time() ];
	}
}
