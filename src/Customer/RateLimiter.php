<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Customer;

use Itdatex\Mailguard\Admin\Settings;

/**
 * Per-IP sliding-window Limiter via Transients.
 * Schuetzt Auth-Endpoints vor Brute-Force.
 */
final class RateLimiter {

	public const KEY_LOGIN    = 'login';
	public const KEY_REGISTER = 'register';
	public const KEY_RESET    = 'reset';

	public static function check( string $key ) : array {
		[ $rate, $window ] = self::config( $key );

		$bucket = self::ip_hash() . ':' . $key;
		$tname  = 'itdatex_mg_rl_' . md5( $bucket );

		$now     = time();
		$entries = (array) get_transient( $tname );
		$entries = array_values( array_filter( $entries, static fn( $t ) => is_int( $t ) && ( $now - $t ) < $window ) );

		if ( count( $entries ) >= $rate ) {
			$oldest = (int) min( $entries );
			return [
				'allowed'     => false,
				'retry_after' => max( 1, ( $oldest + $window ) - $now ),
			];
		}

		$entries[] = $now;
		set_transient( $tname, $entries, $window );
		return [ 'allowed' => true, 'retry_after' => 0 ];
	}

	private static function config( string $key ) : array {
		// [rate, window_seconds]
		return match ( $key ) {
			self::KEY_LOGIN    => [ max( 1, (int) Settings::get( 'rate_login_per_min', 10 ) ), 60 ],
			self::KEY_REGISTER => [ max( 1, (int) Settings::get( 'rate_register_per_hour', 5 ) ), 3600 ],
			self::KEY_RESET    => [ 5, 3600 ],
			default            => [ 30, 60 ],
		};
	}

	private static function ip_hash() : string {
		$ip = '';
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $k ) {
			if ( ! empty( $_SERVER[ $k ] ) ) {
				$ip = (string) $_SERVER[ $k ];
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				break;
			}
		}
		return hash( 'sha256', 'itdatex-mailguard|' . $ip );
	}
}
