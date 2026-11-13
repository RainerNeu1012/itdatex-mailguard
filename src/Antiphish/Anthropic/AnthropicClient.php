<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish\Anthropic;

use Itdatex\Mailguard\Admin\Settings;

/**
 * Anthropic-Claude-Client fuer die Grauzonen-Zweitmeinung.
 *
 * Standard-Modell: claude-haiku-4-5-20251001 — schnell (~1-2 s pro Call),
 * billig (~0.0004 € pro Mail), gute Nuance bei Social-Engineering.
 *
 * Aufruf-Policy: identisch zum frueheren OllamaClient — nur Grauzone
 * (Heuristik-Score 30-49), nur wenn konfiguriert (API-Key gesetzt),
 * AuditLog-Emit pro Call.
 *
 * Score-Adjustment identisch:
 *   dangerous  +30/+20, suspicious +10/0, clean -20/-10 (je nach conf>=70)
 *
 * Response-Parsing: Claude bekommt im System-Prompt die strikte Anweisung,
 * NUR JSON zu antworten. Haiku 4.5 haelt sich zuverlaessig daran.
 */
final class AnthropicClient {

	public const GRAY_MIN       = 30;
	public const GRAY_MAX       = 49;
	public const DEFAULT_MODEL  = 'claude-haiku-4-5-20251001';
	public const API_URL        = 'https://api.anthropic.com/v1/messages';
	public const API_VERSION    = '2023-06-01';

	public static function is_configured() : bool {
		return (int) Settings::get( 'llm_enabled', 0 ) === 1
			&& (string) Settings::get( 'llm_api_key', '' ) !== '';
	}

	/**
	 * @return array|null  ['adjust'=>int, 'reason'=>['code','label','score','detail']]
	 */
	public static function second_opinion( array $mail ) : ?array {
		if ( ! self::is_configured() ) { return null; }
		$api_key = (string) Settings::get( 'llm_api_key', '' );
		$model   = (string) Settings::get( 'llm_model',   self::DEFAULT_MODEL );
		$timeout = (int)    Settings::get( 'llm_timeout', 20 );

		$system = 'Du bist ein deutscher Phishing-Detector. Du bekommst eine E-Mail und entscheidest, '
			. 'ob sie Phishing / Spam / legitime Post ist. Antworte AUSSCHLIESSLICH mit einem JSON-Objekt '
			. 'im Format {"verdict":"clean"|"suspicious"|"dangerous","confidence":0-100,"reasons":["kurz","max 5"]}. '
			. 'Kein Text ausserhalb des JSON. '
			. 'verdict=clean fuer legitime Post (Rechnungen, Newsletter, Benachrichtigungen). '
			. 'verdict=suspicious bei unklaren Faellen, moeglichem Betrug, Social-Engineering. '
			. 'verdict=dangerous bei klarem Phishing, Malware, CEO-Fraud, Geldforderung mit Urgency.';

		$user_content = self::format_mail( $mail );

		$t0 = microtime( true );
		$resp = wp_remote_post( self::API_URL, [
			'timeout' => $timeout,
			'headers' => [
				'x-api-key'         => $api_key,
				'anthropic-version' => self::API_VERSION,
				'content-type'      => 'application/json',
			],
			'body'    => wp_json_encode( [
				'model'      => $model,
				'max_tokens' => 512,
				'system'     => $system,
				'messages'   => [
					[ 'role' => 'user', 'content' => $user_content ],
				],
			] ),
		] );
		$dt = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		if ( is_wp_error( $resp ) ) {
			error_log( '[MG anthropic] http_error: ' . $resp->get_error_message() );
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body_raw = (string) wp_remote_retrieve_body( $resp );
		if ( $code !== 200 ) {
			error_log( '[MG anthropic] http ' . $code . ' body=' . substr( $body_raw, 0, 300 ) );
			return null;
		}

		$body = json_decode( $body_raw, true );
		if ( ! is_array( $body ) ) { return null; }
		// Response-Format: content ist Array mit Objekten {type:"text", text:"..."}
		$text = '';
		foreach ( (array) ( $body['content'] ?? [] ) as $block ) {
			if ( ( $block['type'] ?? '' ) === 'text' ) {
				$text .= (string) ( $block['text'] ?? '' );
			}
		}
		$text = trim( $text );
		// Claude koennte theoretisch Prefix/Suffix drum legen — tolerant extrahieren
		if ( $text !== '' && $text[0] !== '{' ) {
			if ( preg_match( '/\{.*\}/s', $text, $m ) ) { $text = $m[0]; }
		}
		$parsed = json_decode( $text, true );
		if ( ! is_array( $parsed ) ) {
			error_log( '[MG anthropic] parse_fail raw=' . substr( $text, 0, 200 ) );
			return null;
		}

		$verdict    = strtolower( trim( (string) ( $parsed['verdict'] ?? '' ) ) );
		$confidence = max( 0, min( 100, (int) ( $parsed['confidence'] ?? 50 ) ) );
		$reasons    = is_array( $parsed['reasons'] ?? null )
			? array_slice( array_map( 'strval', $parsed['reasons'] ), 0, 5 )
			: [];

		$adjust = self::score_adjustment( $verdict, $confidence );
		$usage  = is_array( $body['usage'] ?? null ) ? $body['usage'] : [];

		return [
			'adjust' => $adjust,
			'reason' => [
				'code'   => 'llm_second_opinion',
				'label'  => sprintf( 'LLM-Zweitmeinung: %s (conf %d%%)', $verdict ?: 'unbekannt', $confidence ),
				'score'  => $adjust,
				'detail' => [
					'provider'    => 'anthropic',
					'verdict'     => $verdict,
					'confidence'  => $confidence,
					'reasons'     => $reasons,
					'model'       => $model,
					'latency_ms'  => $dt,
					'input_tok'   => (int) ( $usage['input_tokens']  ?? 0 ),
					'output_tok'  => (int) ( $usage['output_tokens'] ?? 0 ),
				],
			],
		];
	}

	private static function score_adjustment( string $verdict, int $confidence ) : int {
		$high = $confidence >= 70;
		if ( $verdict === 'dangerous' )  { return $high ?  30 :  20; }
		if ( $verdict === 'suspicious' ) { return $high ?  10 :   0; }
		if ( $verdict === 'clean' )      { return $high ? -20 : -10; }
		return 0;
	}

	private static function format_mail( array $mail ) : string {
		$subject   = trim( (string) ( $mail['subject']   ?? '' ) );
		$body      = trim( (string) ( $mail['body']      ?? '' ) );
		$from_name = trim( (string) ( $mail['from_name'] ?? '' ) );
		$from_addr = trim( (string) ( $mail['from_addr'] ?? '' ) );
		$body = mb_substr( $body, 0, 2000 );
		return "Von: \"" . mb_substr( $from_name, 0, 100 ) . "\" <" . mb_substr( $from_addr, 0, 100 ) . ">\n"
			. "Betreff: " . mb_substr( $subject, 0, 200 ) . "\n"
			. "Body:\n" . $body;
	}
}
