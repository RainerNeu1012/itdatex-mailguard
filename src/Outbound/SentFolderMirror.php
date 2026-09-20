<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Outbound;

use Itdatex\Mailguard\Imap\Account as ImapAccount;
use Itdatex\Mailguard\Imap\ClientFactory;
use Itdatex\Mailguard\Installer;

/**
 * Nach einem erfolgreichen Resend-Send: legt eine Kopie der Mail per
 * IMAP APPEND im "Gesendet"-Ordner des User-Postfachs ab, damit der
 * User seine ausgehenden Mails im echten Outlook/iCloud-Client sieht.
 *
 * Kritische Details:
 * - Sent-Folder-Name variiert pro Provider ("Sent", "Sent Messages",
 *   "Sent Items", "[Gmail]/Sent Mail"). Wir versuchen die bekannten
 *   Kandidaten in absteigender Wahrscheinlichkeit — der erste der
 *   erfolgreich existiert wird verwendet.
 * - Die APPEND-Kopie hat als From die ECHTE Adresse des Users
 *   (z.B. itdatex@outlook.com) — nicht `noreply@send.itdatex.support`.
 *   Grund: Outlook zeigt die From-Adresse prominent im Sent-Folder,
 *   `noreply@send.itdatex.support` waere verwirrend. Da's nur eine lokale
 *   Anzeige-Kopie ist (nicht auf dem Wire), keine Auth-Konsequenz.
 * - Fire-and-forget aus dem Reply/Compose-Endpoint aufgerufen: Fehler
 *   werden geswallowed, User-facing Response bleibt "ok" wenn Resend
 *   akzeptiert hat.
 */
final class SentFolderMirror {

	private const CANDIDATES = [
		'Sent',
		'Sent Messages',
		'Sent Items',
		'INBOX/Sent',
		'INBOX.Sent',
		'[Gmail]/Sent Mail',
	];

	/**
	 * @param int    $customer_id
	 * @param int    $account_id
	 * @param array  $mail        {to, subject, body_text, in_reply_to?, references?}
	 * @return array{ok:bool, folder?:string, error?:string}
	 */
	public static function mirror( int $customer_id, int $account_id, array $mail ) : array {
		$acct = ImapAccount::find_for_customer( $account_id, $customer_id );
		if ( ! $acct ) { return [ 'ok' => false, 'error' => 'no_account' ]; }

		$from_addr = (string) ( $acct['username'] ?? '' );
		if ( $from_addr === '' ) { return [ 'ok' => false, 'error' => 'no_from_addr' ]; }

		$rfc822 = self::build_rfc822( [
			'from'        => $from_addr,
			'to'          => (string) $mail['to'],
			'subject'     => (string) $mail['subject'],
			'body_text'   => (string) ( $mail['body_text'] ?? '' ),
			'in_reply_to' => (string) ( $mail['in_reply_to'] ?? '' ),
			'references'  => (string) ( $mail['references']  ?? '' ),
		] );

		try {
			$client = ClientFactory::for_account( $acct );
			$client->connect();
		} catch ( \Throwable $e ) {
			return [ 'ok' => false, 'error' => 'connect_failed', 'message' => $e->getMessage() ];
		}

		// Welche Sent-Folder existieren im Account? Wir schauen in mg_imap_folders
		// (da haben wir schon die vom PullService entdeckten Folder-Namen).
		global $wpdb;
		$t = $wpdb->prefix . Installer::TABLE_IMAP_FOLDERS;
		$existing = array_map(
			static fn( $r ) => (string) $r['folder_name'],
			$wpdb->get_results( $wpdb->prepare(
				"SELECT folder_name FROM {$t} WHERE customer_id = %d AND account_id = %d",
				$customer_id, $account_id
			), ARRAY_A ) ?: []
		);
		$existing_lc = array_map( 'strtolower', $existing );

		$target = null;
		foreach ( self::CANDIDATES as $candidate ) {
			$idx = array_search( strtolower( $candidate ), $existing_lc, true );
			if ( $idx !== false ) {
				$target = $existing[ $idx ];
				break;
			}
		}
		if ( $target === null ) {
			// Fallback: naive Erst-Suche, oder skip. Wir skippen — nicht kritisch.
			try { $client->close(); } catch ( \Throwable $e ) {}
			return [ 'ok' => false, 'error' => 'sent_folder_not_found' ];
		}

		try {
			$ok = $client->append_message( $target, $rfc822, '\\Seen' );
			$client->close();
		} catch ( \Throwable $e ) {
			return [ 'ok' => false, 'error' => 'append_failed', 'message' => $e->getMessage() ];
		}

		return $ok ? [ 'ok' => true, 'folder' => $target ] : [ 'ok' => false, 'error' => 'append_failed' ];
	}

	/**
	 * Minimalistisches RFC-822-Format. Text-body only. Content-Type
	 * text/plain; charset=utf-8. Content-Transfer-Encoding: 8bit
	 * (fuer Klarheit — die Bytes sind eh utf-8-Zeichen).
	 */
	private static function build_rfc822( array $m ) : string {
		$from    = self::encode_header_addr( $m['from'] );
		$to      = self::encode_header_addr( $m['to'] );
		$subject = self::encode_header_text( $m['subject'] );
		$date    = date( 'r' ); // RFC 2822 date
		$msg_id  = '<' . bin2hex( random_bytes( 12 ) ) . '@send.itdatex.support>';

		$headers = [
			'Message-ID: ' . $msg_id,
			'Date: ' . $date,
			'From: ' . $from,
			'To: ' . $to,
			'Subject: ' . $subject,
			'MIME-Version: 1.0',
			'Content-Type: text/plain; charset=utf-8',
			'Content-Transfer-Encoding: 8bit',
		];
		if ( $m['in_reply_to'] !== '' ) { $headers[] = 'In-Reply-To: ' . $m['in_reply_to']; }
		if ( $m['references']  !== '' ) { $headers[] = 'References: '  . $m['references']; }

		return implode( "\r\n", $headers ) . "\r\n\r\n" . (string) $m['body_text'];
	}

	private static function encode_header_addr( string $addr ) : string {
		// Simplification: nur die reine Adresse (kein Display-Name). Wenn
		// spaeter Display-Namen dazukommen, MIME-Encoding via =?UTF-8?B?...?= .
		return trim( $addr );
	}

	private static function encode_header_text( string $text ) : string {
		// Wenn nur ASCII, keine Encoding-Ceremony noetig.
		if ( preg_match( '/[^\x20-\x7E]/', $text ) === 0 ) { return $text; }
		return '=?UTF-8?B?' . base64_encode( $text ) . '?=';
	}
}
