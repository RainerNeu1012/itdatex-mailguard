<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish;

use Itdatex\Mailguard\Imap\Message as ImapMessage;
use Itdatex\Mailguard\Antiphish\Subscriptions;
use Itdatex\Mailguard\Antiphish\PurgeService;
use Itdatex\Mailguard\Installer;

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

		// Idempotenz: schon erfolgreich abgemeldet → nicht nochmal die API anhauen.
		// Spart Roundtrip, verhindert Doppel-Abmeldung bei mailto, und schützt vor
		// versehentlichem Reset bereits geklärter Zustände. Explizite Optionswahl
		// zählt aber als bewusster Retry-Wunsch (User klickt "trotzdem").
		if ( $option_idx === null ) {
			$prev = Unsub::find_by_message( $message_id, $customer_id );
			if ( $prev && (string) $prev['api_status'] === 'unsubscribed' ) {
				return [
					'ok'             => true,
					'already'        => true,
					'unsub_id'       => (int) $prev['id'],
					'api'            => [
						'status'      => 'unsubscribed',
						'http_status' => $prev['api_http_status'] !== null ? (int) $prev['api_http_status'] : null,
						'message_id'  => (string) $prev['api_message_id'],
						'manual_url'  => '',
					],
					'option'         => null,
					'http_code'      => 200,
					'attempts'       => [],
				];
			}
		}

		// Doppelklick-Lock: kurzlebiger Transient verhindert, dass zwei parallele
		// Requests (Doppel-Tap, zweiter Tab) gleichzeitig die API befeuern und
		// duplizierte mg_unsubs-Zeilen erzeugen.
		$lock_key = 'mg_unsub_lock_' . $customer_id . '_' . $message_id;
		if ( get_transient( $lock_key ) ) {
			return [ 'ok' => false, 'error' => 'in_progress', 'detail' => 'Es läuft bereits eine Abmeldung für diese Mail — bitte kurz warten und die Seite neu laden.' ];
		}
		set_transient( $lock_key, 1, 60 );
		try {
			return self::execute_locked( $row, $message_id, $customer_id, $option_idx );
		} finally {
			delete_transient( $lock_key );
		}
	}

	private static function execute_locked( array $row, int $message_id, int $customer_id, ?int $option_idx ) : array {
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
			// Retry nur für http one-click (RFC 8058 idempotent) und plain http GET-Fallback.
			// mailto NICHT — jeder Retry würde eine zweite Abmelde-Mail rausschicken.
			$kind        = (string) ( $option['kind'] ?? '' );
			$allow_retry = $kind === 'http';
			$res = Client::unsub_execute( $payload, 20, $allow_retry );
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

	/**
	 * Bulk-Abmeldung für alle Newsletter-Mails eines Absenders. Versucht die
	 * neueste Mail zuerst; scheitert die mit no_options oder endpoints_dead,
	 * geht es der Reihe nach mit älteren Mails weiter. Sobald eine gelingt
	 * (oder needs_manual auslöst), Ende der Kette.
	 *
	 * Grund: bei manchen Providern ist die List-Unsubscribe-URL der jüngsten
	 * Kampagne bereits abgeräumt, ältere Kampagnen der gleichen Serie haben
	 * aber noch funktionierende Endpoints. Aus User-Sicht ist es egal, welche
	 * Mail den Abmelde-Klick trägt — Hauptsache es klappt.
	 */
	public static function execute_for_sender( int $customer_id, string $from_addr ) : array {
		$ids = Subscriptions::messages_for_sender( $customer_id, $from_addr, 5 );
		if ( ! $ids ) {
			return [ 'ok' => false, 'error' => 'not_found', 'from_addr' => $from_addr ];
		}
		$last = null;
		foreach ( $ids as $mid ) {
			$res = self::execute_for_message( $mid, $customer_id, null );
			$res['source_msg_id'] = $mid;
			$last = $res;
			if ( ! empty( $res['ok'] ) || ! empty( $res['needs_manual'] ) ) {
				return $res;
			}
			// Nur bei "keine Optionen" oder "Endpoints tot" auf ältere Mail ausweichen.
			// Bei transienten Fehlern (Timeout, 5xx nach Retry) macht der Fallback
			// wenig Sinn — der Provider hat dieselben Endpoints in älteren Mails.
			$reason = (string) ( $res['reason'] ?? '' );
			$error  = (string) ( $res['error']  ?? '' );
			if ( $error !== 'no_options' && $reason !== 'endpoints_dead' ) {
				return $res;
			}
		}
		if ( $last !== null ) {
			$last['fallback_exhausted'] = true;
			return $last;
		}
		return [ 'ok' => false, 'error' => 'not_found', 'from_addr' => $from_addr ];
	}

	/**
	 * "Sender vernichten" — der einzigartige Newsletter-Killer, der drei
	 * Aktionen in einem Rutsch bündelt:
	 *   1. Best-effort Abmeldung beim Provider (RFC 8058 One-Click / mailto).
	 *      Fehler blockieren den Rest NICHT — auch wenn der Abmelde-Endpoint
	 *      tot ist, wollen wir die Mails trotzdem loswerden.
	 *   2. Blacklist-Regel anlegen (auto-quarantiniert künftige Mails, sofern
	 *      der User eine auto_quarantine_min_score-Schwelle gesetzt hat).
	 *   3. Hard-Purge: alle bestehenden Mails dieses Senders werden per
	 *      IMAP-EXPUNGE endgültig gelöscht (nicht Papierkorb, nicht Quarantäne).
	 *      Kein Undo mehr möglich — deswegen muss die UI eine deutliche
	 *      Type-in-Confirmation davor schalten.
	 *
	 * Reihenfolge ist wichtig:
	 *   - Zuerst Abmelden, damit die Message-IDs noch existieren (nach Purge
	 *     können wir die Ursprungsmail nicht mehr für Extract-Options nutzen).
	 *   - Dann Blockieren, damit selbst wenn der Purge einzelne Mails verpasst
	 *     (z.B. weil sie parallel angekommen sind) die Blacklist-Regel greift.
	 *   - Zuletzt Purge — schaltet die Ursprungs-Referenz frei.
	 *
	 * Response ist als Zusammenfassung strukturiert, nicht als "alles-oder-
	 * nichts". Der User will nach dem Klick eine Zeile pro Aktion sehen:
	 * "Abmelde-Versuch: ok / needs_manual / endpoints_dead / skipped",
	 * "Blockiert: neu / bestand bereits", "Endgültig gelöscht: N Mails".
	 */
	public static function eradicate_sender( int $customer_id, string $from_addr ) : array {
		$from_addr = strtolower( trim( $from_addr ) );
		if ( $from_addr === '' || ! str_contains( $from_addr, '@' ) ) {
			return [ 'ok' => false, 'error' => 'bad_input' ];
		}

		$out = [
			'ok'        => true,
			'from_addr' => $from_addr,
			'unsub'     => null,
			'block'     => null,
			'purge'     => null,
		];

		// Schritt 1: Best-effort Unsub. Kein return-on-error, selbst wenn der
		// Provider gar nicht mehr erreichbar ist, geht der Purge weiter.
		try {
			$unsub = self::execute_for_sender( $customer_id, $from_addr );
			$out['unsub'] = [
				'ok'          => ! empty( $unsub['ok'] ),
				'already'     => ! empty( $unsub['already'] ),
				'needs_manual'=> ! empty( $unsub['needs_manual'] ),
				'reason'      => (string) ( $unsub['reason'] ?? '' ),
				'dead_cause'  => (string) ( $unsub['dead_cause'] ?? '' ),
				'error'       => (string) ( $unsub['error']  ?? '' ),
				'manual_url'  => (string) ( $unsub['manual_url'] ?? '' ),
			];
		} catch ( \Throwable $e ) {
			// Ausnahmesituation: Backend-Panic. Nicht abbrechen — der User will
			// die Mails weg haben, egal was der Abmelde-Call macht.
			$out['unsub'] = [ 'ok' => false, 'error' => 'exception', 'detail' => $e->getMessage() ];
		}

		// Schritt 2: Blockieren. Blacklist-Regel für künftige Mails.
		$block = PurgeService::block_sender( $customer_id, $from_addr, 'Auto-Regel: Sender vernichtet' );
		$out['block'] = [
			'ok'      => ! empty( $block['ok'] ),
			'rule_id' => isset( $block['id'] ) ? (int) $block['id'] : 0,
			'existed' => ! empty( $block['existed'] ),
			'error'   => (string) ( $block['error'] ?? '' ),
		];

		// Schritt 3: Hard-Purge. IMAP-EXPUNGE aller Mails. Der teuerste Teil,
		// deshalb zum Schluss — wenn er scheitert, sind Abmeldung + Sperre
		// wenigstens schon durch, und der User kann's nochmal versuchen.
		$purge = PurgeService::hard_purge_sender( $customer_id, $from_addr );
		$out['purge'] = [
			'ok'       => ! empty( $purge['ok'] ),
			'purged'   => (int) ( $purge['purged']  ?? 0 ),
			'skipped'  => (int) ( $purge['skipped'] ?? 0 ),
			'failed'   => (int) ( $purge['failed']  ?? 0 ),
			'failures' => $purge['failures'] ?? [],
		];

		// Gesamt-ok nur wenn Block + Purge sauber durchliefen. Unsub kann
		// bewusst fehlschlagen (endpoints_dead) und trotzdem als "erledigt"
		// gelten — der Sender ist ja jetzt geblockt und die Mails sind weg.
		$out['ok'] = $out['block']['ok'] && $out['purge']['ok'];
		return $out;
	}

	/**
	 * Cron-Handler: pollt DSN-Status für alle offenen mailto/api-Abmeldungen der
	 * letzten 48h. Erledigt genau das, was der User sonst manuell mit dem
	 * "↻ Status"-Button anstoßen müsste — damit Bounces automatisch auffallen.
	 *
	 * Cutoff 48h, weil DSN-Bounces spätestens innerhalb 24-48h eintrudeln. Danach
	 * ist "keine Antwort" praktisch als "durchgegangen" zu werten und wir sparen
	 * uns die API-Calls.
	 *
	 * Cap 100 Zeilen pro Lauf — schützt vor Runaway-Load, falls sich mal eine
	 * riesige Backlog aufgestaut hat. Rest kommt im nächsten Slot.
	 */
	public static function poll_pending_dsn() : void {
		global $wpdb;
		$t = $wpdb->prefix . Installer::TABLE_UNSUBS;
		$rows = $wpdb->get_results(
			"SELECT id, customer_id
			 FROM {$t}
			 WHERE api_message_id != ''
			   AND ( dsn_status = '' OR dsn_status = 'delayed' )
			   AND created_at > (UTC_TIMESTAMP() - INTERVAL 48 HOUR)
			 ORDER BY id ASC
			 LIMIT 100",
			ARRAY_A
		);
		foreach ( $rows ?: [] as $r ) {
			self::status_refresh( (int) $r['id'], (int) $r['customer_id'] );
		}
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
