<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap;

use Itdatex\Mailguard\Installer;

/**
 * Persistenz-Layer fuer mg_attachments. Speichert nur Metadaten aus
 * BODYSTRUCTURE — keine Bytes. Reasons ist ein optionaler JSON-Blob mit
 * heuristischen Warnungen; wird von der antiphish-api gefuellt.
 */
final class Attachment {

	public static function table() : string {
		global $wpdb;
		return $wpdb->prefix . Installer::TABLE_ATTACHMENTS;
	}

	/**
	 * @param array<int,array{part_num:string,filename:string,mime_type:string,size_bytes:int,encoding:string}> $rows
	 */
	public static function insert_batch( int $customer_id, int $message_id, array $rows ) : void {
		if ( ! $rows ) { return; }
		global $wpdb;
		$now = current_time( 'mysql', true );
		foreach ( $rows as $r ) {
			$suspicion = self::detect_local_suspicion( $r );
			$wpdb->insert( self::table(), [
				'customer_id'       => $customer_id,
				'message_id'        => $message_id,
				'part_num'          => mb_substr( (string) ( $r['part_num']   ?? '' ), 0, 20 ),
				'filename'          => mb_substr( (string) ( $r['filename']   ?? '' ), 0, 500 ),
				'mime_type'         => mb_substr( (string) ( $r['mime_type']  ?? '' ), 0, 190 ),
				'size_bytes'        => max( 0, (int) ( $r['size_bytes']       ?? 0 ) ),
				'encoding'          => mb_substr( (string) ( $r['encoding']   ?? '' ), 0, 30 ),
				'is_suspicious'     => $suspicion ? 1 : 0,
				'suspicion_reasons' => $suspicion ? wp_json_encode( $suspicion ) : null,
				'created_at'        => $now,
			] );
		}
	}

	/**
	 * @return array<int,int>
	 */
	public static function list_for_message( int $message_id, int $customer_id ) : array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, part_num, filename, mime_type, size_bytes, encoding, is_suspicious, suspicion_reasons
			 FROM ' . self::table() . '
			 WHERE message_id = %d AND customer_id = %d
			 ORDER BY id ASC',
			$message_id, $customer_id
		), ARRAY_A );
		return array_map( [ __CLASS__, 'public_view' ], $rows ?: [] );
	}

	public static function public_view( array $row ) : array {
		$reasons = $row['suspicion_reasons'] ? json_decode( (string) $row['suspicion_reasons'], true ) : null;
		return [
			'id'            => (int) $row['id'],
			'part_num'      => (string) $row['part_num'],
			'filename'      => (string) $row['filename'],
			'mime_type'     => (string) $row['mime_type'],
			'size_bytes'    => (int) $row['size_bytes'],
			'encoding'      => (string) $row['encoding'],
			'is_suspicious' => (int) $row['is_suspicious'],
			'reasons'       => is_array( $reasons ) ? $reasons : [],
		];
	}

	/**
	 * Lokale Heuristik, die OHNE Backend-Call sofort greift. Ergaenzt wird das
	 * durch die antiphish-api, wenn die Mail spaeter gescannt wird.
	 *
	 * @return array<int,array{rule:string,description:string,score:int}>|null
	 */
	private static function detect_local_suspicion( array $r ) : ?array {
		$fname = strtolower( (string) ( $r['filename'] ?? '' ) );
		$mime  = strtolower( (string) ( $r['mime_type'] ?? '' ) );
		$out   = [];

		// Direkt gefaehrliche Executable-Extensions (Windows-ausfuehrbar).
		$dangerous_exts = [ 'exe', 'scr', 'cmd', 'bat', 'com', 'pif', 'vbs', 'js', 'jse', 'wsf', 'msi', 'jar', 'ps1', 'lnk', 'hta' ];
		foreach ( $dangerous_exts as $ext ) {
			if ( str_ends_with( $fname, '.' . $ext ) ) {
				$out[] = [
					'rule'        => 'attachment.dangerous_ext',
					'description' => "Ausfuehrbare Datei (.{$ext}) — hoechst untypisch als E-Mail-Anhang.",
					'score'       => 45,
				];
				break;
			}
		}

		// Doppelte Endung wie 'rechnung.pdf.exe' — klassischer Phishing-Trick.
		if ( preg_match( '/\.(pdf|jpg|jpeg|png|gif|docx?|xlsx?|pptx?)\.(exe|scr|cmd|bat|com|vbs|js|jse|wsf|msi|jar|ps1|lnk|hta)$/', $fname ) ) {
			$out[] = [
				'rule'        => 'attachment.double_extension',
				'description' => 'Doppelte Endung — sieht aus wie harmloser Dateityp, ist aber ausfuehrbar.',
				'score'       => 55,
			];
		}

		// Office-Dokumente mit Makro-Support (.docm/.xlsm/.pptm) — legitim moeglich,
		// aber unter Endnutzern selten, daher als Warnhinweis.
		if ( preg_match( '/\.(docm|xlsm|pptm|xlsb)$/', $fname ) ) {
			$out[] = [
				'rule'        => 'attachment.macro_office',
				'description' => 'Office-Dokument mit Makro-Unterstuetzung — potenzielles Malware-Vehikel.',
				'score'       => 25,
			];
		}

		// Verschluesselte ZIPs: klassisch fuer Malware, weil sie keine AV umgehen.
		// Wir erkennen nur den Hinweis "encrypted" im Namen; echte Erkennung
		// braeuchte Byte-Zugriff.
		if ( preg_match( '/\.(zip|rar|7z)$/', $fname ) && str_contains( $fname, 'encrypt' ) ) {
			$out[] = [
				'rule'        => 'attachment.encrypted_archive_hint',
				'description' => 'Archiv-Dateiname deutet auf Verschluesselung hin — AV-Bypass-Muster.',
				'score'       => 30,
			];
		}

		// MIME/Extension-Mismatch: PDF-Endung aber gefaehrlicher MIME-Typ. Nur
		// als suspicious markieren, wenn der MIME explizit auf ausfuehrbaren
		// Inhalt hinweist — application/octet-stream ist bei vielen Providern
		// (GMX u.a.) der harmlose Fallback und wuerde False-Positives erzeugen.
		$dangerous_mimes = [
			'application/x-msdownload', 'application/x-executable',
			'application/x-msdos-program', 'application/x-dosexec',
			'application/x-msi', 'application/vnd.microsoft.portable-executable',
		];
		if ( str_ends_with( $fname, '.pdf' ) && in_array( $mime, $dangerous_mimes, true ) ) {
			$out[] = [
				'rule'        => 'attachment.mime_mismatch',
				'description' => "PDF-Dateiname, aber MIME-Typ {$mime} deutet auf ausfuehrbaren Inhalt.",
				'score'       => 55,
			];
		}

		return $out ?: null;
	}
}
