<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish;

use Itdatex\Mailguard\Imap\Message as ImapMessage;

/**
 * Hochlevel-Orchestrator fuer Newsletter-Unsubscribe.
 *
 * Flow:
 *  - extract_for_message(): API liefert verfuegbare Optionen (http one-click, http, mailto)
 *  - execute_for_message(opt_idx=null): waehlt Default-Option oder gegebene und fuehrt aus,
 *    persistiert das Ergebnis in mg_unsubs
 *  - status_refresh(): pollt /unsubscribe/status/{message_id} fuer mailto-Bounces
 *
 * Priorisierung der Default-Auswahl: http one-click > http > mailto (gleiche wie MailSec).
 */
final class UnsubService {

	public static function extract_for_message( int $message_id, int $customer_id ) : array {
		$row = ImapMessage::find_for_customer( $message_id, $customer_id );
		if ( ! $row ) { return [ 'ok' => false, 'error' => 'not_found' ]; }

		$payload = self::email_payload( $row );
		$res = Client::unsub_extract( $payload );
		if ( is_wp_error( $res ) ) {
			return [ 'ok' => false, 'error' => $res->get_error_code(), 'detail' => $res->get_error_message() ];
		}
		$body = is_array( $res['body'] ?? null ) ? $res['body'] : [];
		return [
			'ok'      => ( $res['status'] ?? 500 ) < 400,
			'found'   => ! empty( $body['found'] ),
			'options' => is_array( $body['options'] ?? null ) ? $body['options'] : [],
		];
	}

	public static function execute_for_message( int $message_id, int $customer_id, ?int $option_idx = null ) : array {
		$row = ImapMessage::find_for_customer( $message_id, $customer_id );
		if ( ! $row ) { return [ 'ok' => false, 'error' => 'not_found' ]; }

		// Erst Optionen ziehen — wir wollen nicht auf veraltete Cache-Optionen vertrauen.
		$ext = self::extract_for_message( $message_id, $customer_id );
		if ( empty( $ext['ok'] ) || empty( $ext['found'] ) ) {
			return [ 'ok' => false, 'error' => 'no_options', 'detail' => $ext ];
		}
		$option = self::pick_option( $ext['options'], $option_idx );
		if ( ! $option ) {
			return [ 'ok' => false, 'error' => 'no_option_picked' ];
		}

		$payload = [
			'email'  => self::email_payload( $row ),
			'option' => $option,
		];
		$res = Client::unsub_execute( $payload );
		if ( is_wp_error( $res ) ) {
			$rec = [ 'status' => 'failed', 'raw' => [ 'error' => $res->get_error_message() ] ];
			$id = Unsub::create( $customer_id, $message_id, $option, $rec );
			return [ 'ok' => false, 'error' => 'request_failed', 'detail' => $res->get_error_message(), 'unsub_id' => $id ];
		}
		$status_code = (int) ( $res['status'] ?? 500 );
		$body = is_array( $res['body'] ?? null ) ? $res['body'] : [];
		$api  = [
			'status'      => (string) ( $body['status'] ?? 'unknown' ),
			'http_status' => $body['http_status'] ?? null,
			'message_id'  => (string) ( $body['message_id'] ?? '' ),
			'manual_url'  => (string) ( $body['manual_url'] ?? '' ),
			'raw'         => $body,
		];
		$id = Unsub::create( $customer_id, $message_id, $option, $api );

		$body_status = (string) ( $body['status'] ?? '' );
		// needs_manual: Provider verlangt einen User-Klick im Browser (z.B. Benson &
		// Hedges — One-Click-Header ohne funktionierenden POST-Endpoint). Fuer den
		// Nutzer ist das ein Ergebnis, nicht ein Fehler; die manuelle URL wird nach
		// oben gereicht, damit das UI sie oeffnen kann.
		return [
			'ok'           => $status_code < 400 && $body_status === 'unsubscribed',
			'needs_manual' => $status_code < 400 && $body_status === 'needs_manual',
			'unsub_id'     => $id,
			'api'          => $api,
			'option'       => $option,
			'http_code'    => $status_code,
			'manual_url'   => $api['manual_url'],
		];
	}

	public static function status_refresh( int $unsub_id, int $customer_id ) : array {
		$row = Unsub::find_for_customer( $unsub_id, $customer_id );
		if ( ! $row ) { return [ 'ok' => false, 'error' => 'not_found' ]; }
		$msg_id = (string) $row['api_message_id'];
		if ( $msg_id === '' ) {
			return [ 'ok' => true, 'updated' => false, 'reason' => 'no_message_id' ];
		}
		$res = Client::request_get( '/unsubscribe/status/' . rawurlencode( $msg_id ) );
		if ( is_wp_error( $res ) ) {
			return [ 'ok' => false, 'error' => $res->get_error_message() ];
		}
		$body = is_array( $res['body'] ?? null ) ? $res['body'] : [];
		Unsub::update_dsn( $unsub_id, $customer_id, [
			'label' => self::derive_dsn_label( $body ),
			'raw'   => $body,
		] );
		return [ 'ok' => true, 'updated' => true, 'body' => $body ];
	}

	private static function derive_dsn_label( array $body ) : string {
		$dsn = (string) ( $body['dsn_action'] ?? '' );
		if ( $dsn === 'delivered' ) return 'delivered';
		if ( $dsn === 'failed' )    return 'bounced';
		if ( $dsn === 'delayed' )   return 'delayed';
		$smtp = (string) ( $body['smtp_status'] ?? '' );
		if ( $smtp ) return $smtp;
		return 'unknown';
	}

	private static function pick_option( array $options, ?int $idx ) : ?array {
		if ( $idx !== null && isset( $options[ $idx ] ) ) {
			return $options[ $idx ];
		}
		$http_oc = null; $http = null; $mailto = null;
		foreach ( $options as $o ) {
			$kind = $o['kind'] ?? '';
			if ( $kind === 'http' && ! empty( $o['one_click'] ) && $http_oc === null ) { $http_oc = $o; }
			elseif ( $kind === 'http' && $http === null )                              { $http    = $o; }
			elseif ( $kind === 'mailto' && $mailto === null )                          { $mailto  = $o; }
		}
		return $http_oc ?: ( $http ?: $mailto );
	}

	private static function email_payload( array $row ) : array {
		return [
			'subject'   => (string) $row['subject'],
			'body'      => (string) $row['body_preview'],
			'from_addr' => $row['from_addr'] ?: null,
			'headers'   => [
				'List-Unsubscribe'      => (string) ( $row['list_unsub_raw']  ?? '' ),
				'List-Unsubscribe-Post' => (string) ( $row['list_unsub_post'] ?? '' ),
			],
		];
	}
}
