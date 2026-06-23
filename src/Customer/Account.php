<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Customer;

use Itdatex\Mailguard\Installer;

/**
 * Persistenz-Layer fuer mg_customers.
 * Keine HTTP-Logik hier, nur DB-Operationen.
 */
final class Account {

	public static function table() : string {
		global $wpdb;
		return $wpdb->prefix . Installer::TABLE_CUSTOMERS;
	}

	public static function find_by_email( string $email ) : ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE email = %s LIMIT 1',
			strtolower( trim( $email ) )
		), ARRAY_A );
		return $row ?: null;
	}

	public static function find_by_id( int $id ) : ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1',
			$id
		), ARRAY_A );
		return $row ?: null;
	}

	public static function find_by_verification_token( string $token ) : ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE verification_token = %s LIMIT 1',
			$token
		), ARRAY_A );
		return $row ?: null;
	}

	public static function find_by_reset_token( string $token ) : ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE password_reset_token = %s LIMIT 1',
			$token
		), ARRAY_A );
		return $row ?: null;
	}

	public static function create( string $email, string $password_hash, ?string $verification_token, int $verification_ttl_minutes = 1440 ) : int {
		global $wpdb;
		$expires = $verification_token ? gmdate( 'Y-m-d H:i:s', time() + $verification_ttl_minutes * 60 ) : null;
		$ok = $wpdb->insert( self::table(), [
			'email'                => strtolower( trim( $email ) ),
			'password_hash'        => $password_hash,
			'status'               => $verification_token ? 'pending' : 'active',
			'email_verified'       => $verification_token ? 0 : 1,
			'verification_token'   => $verification_token,
			'verification_expires' => $expires,
			'created_at'           => current_time( 'mysql', true ),
		], [ '%s', '%s', '%s', '%d', '%s', '%s', '%s' ] );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function mark_email_verified( int $id ) : void {
		global $wpdb;
		$wpdb->update( self::table(), [
			'email_verified'       => 1,
			'status'               => 'active',
			'verification_token'   => null,
			'verification_expires' => null,
		], [ 'id' => $id ], [ '%d', '%s', '%s', '%s' ], [ '%d' ] );
	}

	public static function set_password_reset_token( int $id, string $token, int $ttl_minutes = 60 ) : void {
		global $wpdb;
		$wpdb->update( self::table(), [
			'password_reset_token'   => $token,
			'password_reset_expires' => gmdate( 'Y-m-d H:i:s', time() + $ttl_minutes * 60 ),
		], [ 'id' => $id ], [ '%s', '%s' ], [ '%d' ] );
	}

	public static function update_password( int $id, string $password_hash ) : void {
		global $wpdb;
		$wpdb->update( self::table(), [
			'password_hash'          => $password_hash,
			'password_reset_token'   => null,
			'password_reset_expires' => null,
		], [ 'id' => $id ], [ '%s', '%s', '%s' ], [ '%d' ] );
	}

	public static function touch_login( int $id ) : void {
		global $wpdb;
		$wpdb->update( self::table(), [ 'last_login_at' => current_time( 'mysql', true ) ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
	}

	public static function set_status( int $id, string $status ) : void {
		global $wpdb;
		$wpdb->update( self::table(), [ 'status' => $status ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
	}
}
