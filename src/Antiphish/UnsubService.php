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

		// Explizite Option → nur diese versuchen. Ansonsten Fallback-Kette:
		// http one-click → http → mailto. RFC 8058 verlangt zwar Idempotenz für
		// One-Click, aber in der Praxis liefern Provider wie PayU einen 404 auf
		// den One-Click-Endpoint — dann muss mailto einspringen, sonst bleibt
		// der User hängen.
		$candidates = $option_idx !== null && isset( $ext['options'][ $option_idx ] )
			? [ $ext['options'][ $option_idx ] ]
			: self::pick_options_ordered( $ext['options'] );

		if ( ! $candidates ) {
			return [ 'ok' => false, 'error' => 'no_option_picked' ];
		}

		$attempts    = [];
		$last_option = null;
		$last_api    = null;
		$last_status = 0;
		$last_id     = 0;

		foreach ( $candidates as $option ) {
			$payload = [
				'email'  => self::email_payload( $row ),
				'option' => $option,
			];
			$res = Client::unsub_execute( $payload );
			if ( is_wp_error( $res ) ) {
				$rec = [ 'status' => 'failed', 'raw' => [ 'error' => $res->get_error_message() ] ];
				$last_id     = Unsub::create( $customer_id, $message_id, $option, $rec );
				$last_option = $option;
				$last_api    = $rec;
				$last_status = 0;
				$attempts[]  = [ 'kind' => (string) ( $option['kind'] ?? '' ), 'status' => 'failed', 'error' => $res->get_error_message(), 'detail' => $res->get_error_message() ];
				continue;
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
			$last_id     = Unsub::create( $customer_id, $message_id, $option, $api );
			$last_option = $option;
			$last_api    = $api;
			$last_status = $status_code;
			$attempts[]  = [ 'kind' => (string) ( $option['kind'] ?? '' ), 'status' => $api['status'], 'http_status' => $api['http_status'], 'detail' => (string) ( $body['detail'] ?? '' ) ];

			$body_status = (string) ( $body['status'] ?? '' );
			$ok = $status_code < 400 && $body_status === 'unsubscribed';
			if ( $ok ) {
				return [
					'ok'         => true,
					'unsub_id'   => $last_id,
					'api'        => $api,
					'option'     => $option,
					'http_code'  => $status_code,
					'attempts'   => $attempts,
					'manual_url' => $api['manual_url'],
				];
			}
			// needs_manual: Provider verlangt einen User-Klick im Browser. Fuer den
			// Nutzer ist das ein Ergebnis, nicht ein Fehler — Kette abbrechen und
			// die manuelle URL nach oben reichen, damit das UI sie oeffnen kann.
			if ( $status_code < 400 && $body_status === 'needs_manual' ) {
				return [
					'ok'         => false,
					'needs_manual' => true,
					'unsub_id'   => $last_id,
					'api'        => $api,
					'option'     => $option,
					'http_code'  => $status_code,
					'attempts'   => $attempts,
					'manual_url' => $api['manual_url'],
				];
			}
			// bei mailto reicht "queued" — Bounce-Status wird über status_refresh nachgezogen.
			if ( ( $option['kind'] ?? '' ) === 'mailto' && in_array( (string) ( $body['status'] ?? '' ), [ 'queued', 'sent' ], true ) ) {
				return [
					'ok'        => true,
					'unsub_id'  => $last_id,
					'api'       => $api,
					'option'    => $option,
					'http_code' => $status_code,
					'attempts'  => $attempts,
				];
			}
		}

		$out = [
			'ok'        => false,
			'unsub_id'  => $last_id,
			'api'       => $last_api ?? [ 'status' => 'failed' ],
			'option'    => $last_option,
			'http_code' => $last_status,
			'attempts'  => $attempts,
		];
		$deadCause = self::attempts_all_permanently_dead( $attempts );
		if ( $deadCause !== null ) {
			$out['reason']  = 'endpoints_dead';
			$out['detail']  = $deadCause === 'dns'
				? 'Absender hat die Newsletter-Abmelde-Endpoints stillgelegt (kein DNS-Eintrag mehr). Direkt-Blockieren empfohlen.'
				: 'Newsletter-Abmelde-Endpoint antwortet dauerhaft mit Fehler (Kampagnen-URL abgelaufen). Direkt-Blockieren empfohlen.';
			$out['dead_cause'] = $deadCause;
		}
		return $out;
	}

	/**
	 * Liefert 'dns' | 'http' | null. Kein null heisst: alle Unsub-Versuche sind
	 * gescheitert und keiner koennte durch spaeteres Nachladen noch klappen —
	 * entweder war der Ziel-Host im DNS gar nicht mehr auffindbar, oder der
	 * Endpoint antwortete mit einem permanenten HTTP-Fehler (404 Kampagne weg,
	 * 405 Methode nicht erlaubt, 410 gone, 400/401/403 Token abgelaufen).
	 *
	 * 5xx und 429 gelten NICHT als permanent — dort koennte ein Retry helfen.
	 */
	private static function attempts_all_permanently_dead( array $attempts ) : ?string {
		if ( ! $attempts ) { return null; }
		$dns_patterns = [
			'name or service not known',
			'name service error',
			'host or domain name not found',
			'host not found',
			'dns-aufloesung fehlgeschlagen',
			'dns-aufl',              // Umlaut-Varianten
			'nxdomain',
			'nodename nor servname',
		];
		$http_dead_codes = [ 400, 401, 403, 404, 405, 410 ];
		// Text-Signale fuer HTTP-tote Endpunkte, falls die numerische http_status
		// nicht durchgereicht wurde (z.B. wenn der Client GET-Fallback macht und
		// nur den kombinierten Fehler ins detail schreibt).
		$http_dead_text = [
			'http 400', 'http 401', 'http 403', 'http 404', 'http 405', 'http 410',
			'not found', 'method not allowed', 'gone',
		];

		$saw_dns  = false;
		$saw_http = false;
		foreach ( $attempts as $a ) {
			$detail  = strtolower( (string) ( $a['detail'] ?? $a['error'] ?? '' ) );
			$status  = isset( $a['http_status'] ) ? (int) $a['http_status'] : 0;
			$is_dns  = $detail !== '' && self::any_substr( $detail, $dns_patterns );
			$is_http = ( $status > 0 && in_array( $status, $http_dead_codes, true ) )
				|| ( $detail !== '' && self::any_substr( $detail, $http_dead_text ) );
			if ( ! $is_dns && ! $is_http ) { return null; }
			if ( $is_dns )  { $saw_dns  = true; }
			if ( $is_http ) { $saw_http = true; }
		}
		// Wenn beide Signale gemischt vorkommen: als "http" reporten — das ist
		// die haeufigere Ursache und der Text passt fuer den User.
		return $saw_http ? 'http' : ( $saw_dns ? 'dns' : null );
	}

	private static function any_substr( string $haystack, array $needles ) : bool {
		foreach ( $needles as $n ) {
			if ( str_contains( $haystack, $n ) ) { return true; }
		}
		return false;
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
		$body  = is_array( $res['body'] ?? null ) ? $res['body'] : [];
		$label = self::derive_dsn_label( $body );
		Unsub::update_dsn( $unsub_id, $customer_id, [
			'label' => $label,
			'raw'   => $body,
		] );
		if ( $label === 'bounced' ) {
			// Notify-Hook: Newsletter-Abmeldung fehlgeschlagen — Push-Listener kann
			// darauf reagieren, damit der Kunde einen Bounce nicht uebersieht.
			do_action( 'mailguard_unsub_bounced', $unsub_id, $customer_id );
		}
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
		$ordered = self::pick_options_ordered( $options );
		return $ordered[0] ?? null;
	}

	/**
	 * Optionen in Fallback-Reihenfolge zurueckgeben:
	 * http one-click → http → mailto. Duplikate ueberspringen.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function pick_options_ordered( array $options ) : array {
		$http_oc = null; $http = null; $mailto = null;
		foreach ( $options as $o ) {
			$kind = $o['kind'] ?? '';
			if ( $kind === 'http' && ! empty( $o['one_click'] ) && $http_oc === null ) { $http_oc = $o; }
			elseif ( $kind === 'http' && $http === null )                              { $http    = $o; }
			elseif ( $kind === 'mailto' && $mailto === null )                          { $mailto  = $o; }
		}
		return array_values( array_filter( [ $http_oc, $http, $mailto ] ) );
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
