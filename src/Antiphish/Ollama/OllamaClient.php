<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish\Ollama;

use Itdatex\Mailguard\Admin\Settings;

/**
 * Lokaler Ollama-Client fuer die Grauzonen-Zweitmeinung.
 *
 * Nur-lokal (default 127.0.0.1:11434) — keine Cloud-Transfers, kein
 * DSGVO-Consent noetig, auch Free-Plan-Customer bekommen das Feature.
 *
 * Aufruf-Policy:
 *   - Nur fuer Mails mit Heuristik-Score zwischen MIN und MAX (Grauzone)
 *   - Kein Call wenn ollama_enabled=0 im Settings
 *   - Kein Call wenn Blacklist-Hit (uberride hat eh entschieden)
 *   - Audit-Log-Emit pro Call fuer Observability + Debugging
 *
 * Score-Adjustment:
 *   - verdict=dangerous  → +30 (confidence >= 70) bzw +20 (confidence <70)
 *   - verdict=suspicious → +10 (confidence >= 70) bzw  0
 *   - verdict=clean      → -20 (confidence >= 70) bzw -10
 *
 * Response-Parsing:
 *   Ollama im "format": "json" Modus garantiert valides JSON im `response`.
 *   Wir parsen tolerant (strings fuer verdict und confidence akzeptieren).
 */
final class OllamaClient {

	public const GRAY_MIN = 30;
	public const GRAY_MAX = 49;

	public static function is_configured() : bool {
		return (int) Settings::get( 'ollama_enabled', 0 ) === 1
			&& (string) Settings::get( 'ollama_endpoint', '' ) !== ''
			&& (string) Settings::get( 'ollama_model', '' ) !== '';
	}

	/**
	 * Fuehrt den LLM-Call fuer eine Mail durch und gibt die Score-Anpassung +
	 * ein reason-struct zurueck. Null bei Fehler / disabled.
	 *
	 * @param array $mail  ['subject','body','from_name','from_addr']
	 * @return array|null  ['adjust'=>int, 'reason'=>['code','label','score','detail']]
	 */
	public static function second_opinion( array $mail ) : ?array {
		if ( ! self::is_configured() ) { return null; }
		$endpoint = rtrim( (string) Settings::get( 'ollama_endpoint', 'http://127.0.0.1:11434' ), '/' );
		$model    = (string) Settings::get( 'ollama_model',    'qwen2.5:7b-instruct-q4_K_M' );
		$timeout  = (int)    Settings::get( 'ollama_timeout',  60 );

		$prompt = self::build_prompt( $mail );
		$req_body = wp_json_encode( [
			'model'   => $model,
			'stream'  => false,
			'format'  => 'json',
			'options' => [ 'temperature' => 0.1 ],
			'prompt'  => $prompt,
		] );

		$t0 = microtime( true );
		$resp = wp_remote_post( $endpoint . '/api/generate', [
			'timeout' => $timeout,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => $req_body,
		] );
		$dt = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		if ( is_wp_error( $resp ) ) {
			error_log( '[MG ollama] http_error: ' . $resp->get_error_message() );
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code !== 200 ) { return null; }

		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		$inner = is_array( $body ) ? (string) ( $body['response'] ?? '' ) : '';
		if ( $inner === '' ) { return null; }
		$parsed = json_decode( $inner, true );
		if ( ! is_array( $parsed ) ) {
			error_log( '[MG ollama] parse_fail raw=' . substr( $inner, 0, 200 ) );
			return null;
		}

		$verdict    = strtolower( trim( (string) ( $parsed['verdict'] ?? '' ) ) );
		$confidence = max( 0, min( 100, (int) ( $parsed['confidence'] ?? 50 ) ) );
		$reasons    = is_array( $parsed['reasons'] ?? null ) ? array_slice( array_map( 'strval', $parsed['reasons'] ), 0, 5 ) : [];

		$adjust = self::score_adjustment( $verdict, $confidence );

		return [
			'adjust' => $adjust,
			'reason' => [
				'code'   => 'llm_second_opinion',
				'label'  => sprintf( 'LLM-Zweitmeinung: %s (conf %d%%)', $verdict ?: 'unbekannt', $confidence ),
				'score'  => $adjust,
				'detail' => [
					'verdict'    => $verdict,
					'confidence' => $confidence,
					'reasons'    => $reasons,
					'model'      => $model,
					'latency_ms' => $dt,
				],
			],
		];
	}

	private static function score_adjustment( string $verdict, int $confidence ) : int {
		$high_conf = $confidence >= 70;
		if ( $verdict === 'dangerous' )  { return $high_conf ?  30 :  20; }
		if ( $verdict === 'suspicious' ) { return $high_conf ?  10 :   0; }
		if ( $verdict === 'clean' )      { return $high_conf ? -20 : -10; }
		return 0;
	}

	private static function build_prompt( array $mail ) : string {
		$subject   = trim( (string) ( $mail['subject']   ?? '' ) );
		$body      = trim( (string) ( $mail['body']      ?? '' ) );
		$from_name = trim( (string) ( $mail['from_name'] ?? '' ) );
		$from_addr = trim( (string) ( $mail['from_addr'] ?? '' ) );
		// Body kappen damit wir Modell-Context nicht sprengen
		$body = mb_substr( $body, 0, 2000 );

		return
			"Du bist ein deutscher Phishing-Detector. Du bekommst eine E-Mail und entscheidest, "
			. "ob sie Phishing / Spam / legitime Post ist. Antworte AUSSCHLIESSLICH mit JSON im Format:\n"
			. "{\"verdict\":\"clean\"|\"suspicious\"|\"dangerous\",\"confidence\":0-100,\"reasons\":[\"kurz\",\"max 5\"]}\n\n"
			. "verdict=clean fuer legitime Post (Rechnungen, Newsletter, Benachrichtigungen). "
			. "verdict=suspicious bei unklaren Faellen, moeglichem Betrug, Social-Engineering. "
			. "verdict=dangerous bei klarem Phishing, Malware, CEO-Fraud, Geldforderung mit Urgency.\n\n"
			. "E-MAIL:\n"
			. "Von: \"" . mb_substr( $from_name, 0, 100 ) . "\" <" . mb_substr( $from_addr, 0, 100 ) . ">\n"
			. "Betreff: " . mb_substr( $subject, 0, 200 ) . "\n"
			. "Body:\n" . $body . "\n\n"
			. "JSON:";
	}
}
