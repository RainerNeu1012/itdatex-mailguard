<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Oauth;

/**
 * HMAC-signierter Roundtrip-State fuer OAuth-Authorization-Code-Flow.
 *
 * Format: base64url(json{cid,aid,nonce,ts}).base64url(hmac_sha256)
 *
 * Damit Browser-Redirect von Microsoft zurueck zum callback-Endpoint die
 * Customer-Zuordnung sicher mitbringt, ohne dass wir Session-State pflegen.
 * TTL haelt das Token kurz scharf — 10min reichen fuer den Consent-Flow.
 */
final class StateToken {

	private const TTL = 600;

	public static function create( int $customer_id, ?int $account_id = null ) : string {
		$payload = [
			'cid'   => $customer_id,
			'aid'   => $account_id,
			'nonce' => bin2hex( random_bytes( 8 ) ),
			'ts'    => time(),
		];
		$json = wp_json_encode( $payload );
		$body = self::b64url_encode( $json );
		$sig  = hash_hmac( 'sha256', $body, self::key(), true );
		return $body . '.' . self::b64url_encode( $sig );
	}

	/** @return array{cid:int,aid:?int}|null */
	public static function verify( string $state ) : ?array {
		if ( ! str_contains( $state, '.' ) ) { return null; }
		[ $body, $sig_b64 ] = explode( '.', $state, 2 );
		$expected = hash_hmac( 'sha256', $body, self::key(), true );
		$got      = self::b64url_decode( $sig_b64 );
		if ( $got === '' || ! hash_equals( $expected, $got ) ) { return null; }
		$json = self::b64url_decode( $body );
		if ( $json === '' ) { return null; }
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || empty( $data['cid'] ) || empty( $data['ts'] ) ) { return null; }
		if ( ( time() - (int) $data['ts'] ) > self::TTL ) { return null; }
		return [
			'cid' => (int) $data['cid'],
			'aid' => isset( $data['aid'] ) ? (int) $data['aid'] : null,
		];
	}

	private static function key() : string {
		$salt = '';
		if ( defined( 'AUTH_KEY' ) )        { $salt .= AUTH_KEY; }
		if ( defined( 'SECURE_AUTH_KEY' ) ) { $salt .= SECURE_AUTH_KEY; }
		if ( $salt === '' ) { $salt = 'itdatex-mailguard-state-fallback'; }
		return hash( 'sha256', 'itdatex-mailguard|oauth-state|' . $salt, true );
	}

	private static function b64url_encode( string $bin ) : string {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	private static function b64url_decode( string $s ) : string {
		$pad = strlen( $s ) % 4;
		if ( $pad > 0 ) { $s .= str_repeat( '=', 4 - $pad ); }
		$raw = base64_decode( strtr( $s, '-_', '+/' ), true );
		return $raw === false ? '' : $raw;
	}
}
