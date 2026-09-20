<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Outbound;

use Itdatex\Mailguard\Admin\Settings;

/**
 * Resend-Outbound-Client fuer Reply/Compose aus MailGuard-App/Portal.
 *
 * Design-Entscheidungen (mit User abgestimmt 2026-09-20):
 * - Alle User senden via einen zentralen itdatex-Resend-Account
 *   (site-wide `resend_api_key` in Settings)
 * - From = `noreply@itdatex.support` (Domain muss auf Resend verifiziert sein)
 * - Reply-To = die reale IMAP-Adresse des Users (fuer legitime Rueckantworten)
 * - Positioniert als Anti-Spam-Feature, nicht als voller Mail-Client
 *
 * Kein Composer-Package — direkter HTTPS-Call via wp_remote_post.
 */
final class ResendClient {

	private const API_URL = 'https://api.resend.com/emails';

	/**
	 * @param array{
	 *   to: string,
	 *   subject: string,
	 *   reply_to?: string,
	 *   body_text?: string,
	 *   body_html?: string,
	 *   in_reply_to?: string,
	 *   references?: string,
	 * } $mail
	 * @return array{ok:bool, id?:string, error?:string, http_status?:int}
	 */
	public static function send( array $mail ) : array {
		$api_key = trim( (string) Settings::get( 'resend_api_key', '' ) );
		if ( $api_key === '' ) {
			return [ 'ok' => false, 'error' => 'no_api_key', 'message' => 'Resend-API-Key nicht konfiguriert.' ];
		}

		$from_addr = trim( (string) Settings::get( 'resend_from_address', 'noreply@itdatex.support' ) );
		$from_name = trim( (string) Settings::get( 'resend_from_name', 'MailGuard User' ) );
		$from      = $from_name !== '' ? sprintf( '%s <%s>', $from_name, $from_addr ) : $from_addr;

		$payload = [
			'from'    => $from,
			'to'      => [ (string) $mail['to'] ],
			'subject' => (string) $mail['subject'],
		];
		if ( ! empty( $mail['reply_to'] ) ) {
			$payload['reply_to'] = (string) $mail['reply_to'];
		}
		if ( ! empty( $mail['body_html'] ) ) {
			$payload['html'] = (string) $mail['body_html'];
		}
		if ( ! empty( $mail['body_text'] ) ) {
			$payload['text'] = (string) $mail['body_text'];
		}
		// Threading-Header damit Reply im Original-Thread im Empfaenger-Mail-Client
		// eingehaengt wird (Gmail/Outlook nutzen In-Reply-To + References).
		$headers = [];
		if ( ! empty( $mail['in_reply_to'] ) ) { $headers['In-Reply-To'] = (string) $mail['in_reply_to']; }
		if ( ! empty( $mail['references'] ) )  { $headers['References']  = (string) $mail['references']; }
		if ( $headers ) { $payload['headers'] = $headers; }

		$response = wp_remote_post( self::API_URL, [
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $payload ),
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'error' => 'network', 'message' => $response->get_error_message() ];
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 200 && $code < 300 ) {
			return [
				'ok'          => true,
				'id'          => (string) ( $body['id'] ?? '' ),
				'http_status' => $code,
			];
		}
		return [
			'ok'          => false,
			'error'       => (string) ( $body['name'] ?? 'api_error' ),
			'message'     => (string) ( $body['message'] ?? ('HTTP ' . $code) ),
			'http_status' => $code,
		];
	}
}
