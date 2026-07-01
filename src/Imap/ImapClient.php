<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap;

/**
 * Wrapper um die PHP-imap-Extension fuer Per-Account-Pulls.
 * Wirft Exceptions statt PHP-Warnings, raeumt die Verbindung auf.
 *
 * Liefert:
 *  - probe() fuer Test-Connect (Phase 3)
 *  - uids_since($uid, $limit) fuer inkrementelles Polling
 *  - fetch_message($uid) mit Header-Parse + Body-Preview
 */
final class ImapClient {

	private $stream = null;

	public function __construct(
		private string $host,
		private int $port,
		private string $encryption, // ssl|tls|none
		private string $folder,
		private string $username,
		private string $password,
	) {
		if ( ! function_exists( 'imap_open' ) ) {
			throw new \RuntimeException( 'PHP imap extension fehlt.' );
		}
	}

	public function connect( int $timeout = 12 ) : void {
		if ( $this->host === '' || $this->username === '' || $this->password === '' ) {
			throw new \RuntimeException( 'IMAP-Zugangsdaten unvollstaendig.' );
		}
		$flags = '/imap';
		if ( $this->encryption === 'ssl' )      { $flags .= '/ssl'; }
		elseif ( $this->encryption === 'tls' )  { $flags .= '/tls'; }
		else                                    { $flags .= '/notls'; }
		$flags .= '/novalidate-cert';

		$mailbox = '{' . $this->host . ':' . $this->port . $flags . '}' . $this->folder;

		imap_errors();
		imap_timeout( IMAP_OPENTIMEOUT, $timeout );
		imap_timeout( IMAP_READTIMEOUT, $timeout );

		$stream = @imap_open( $mailbox, $this->username, $this->password, 0, 1 );
		if ( $stream === false ) {
			$errs = imap_errors() ?: [ 'unknown imap_open failure' ];
			throw new \RuntimeException( 'IMAP-Connect fehlgeschlagen: ' . implode( '; ', $errs ) );
		}
		$this->stream = $stream;
	}

	public function close() : void {
		if ( $this->stream ) {
			imap_close( $this->stream );
			$this->stream = null;
		}
	}

	public function __destruct() {
		$this->close();
	}

	public function folder() : string {
		return $this->folder;
	}

	/**
	 * Listet alle Folder des Postfaches.
	 * Filtert \Noselect-Container und filtert die {host}-Prefixes weg.
	 *
	 * @return list<array{name:string,display:string,attributes:string[]}>
	 */
	public function list_folders() : array {
		if ( ! $this->stream ) { $this->connect(); }
		$flags = '/imap';
		if ( $this->encryption === 'ssl' )      { $flags .= '/ssl'; }
		elseif ( $this->encryption === 'tls' )  { $flags .= '/tls'; }
		else                                    { $flags .= '/notls'; }
		$flags .= '/novalidate-cert';
		$ref     = '{' . $this->host . ':' . $this->port . $flags . '}';
		$raw     = @imap_getmailboxes( $this->stream, $ref, '*' );
		if ( ! is_array( $raw ) ) { return []; }
		$out = [];
		foreach ( $raw as $box ) {
			$name = (string) ( $box->name ?? '' );
			// Server-Prefix wegschneiden
			if ( str_starts_with( $name, $ref ) ) {
				$name = substr( $name, strlen( $ref ) );
			}
			if ( $name === '' ) { continue; }
			$attrs = [];
			$a = (int) ( $box->attributes ?? 0 );
			if ( $a & LATT_NOSELECT ) { $attrs[] = '\\Noselect'; }
			if ( $a & LATT_NOINFERIORS ) { $attrs[] = '\\Noinferiors'; }
			if ( $a & LATT_MARKED ) { $attrs[] = '\\Marked'; }
			// Noselect = reiner Container, kein abrufbarer Folder → skip
			if ( $a & LATT_NOSELECT ) { continue; }
			$out[] = [
				'name'       => $name,
				'display'    => $name,
				'attributes' => $attrs,
			];
		}
		return $out;
	}

	/** Test-Connect: liefert Mailbox-Statistik. */
	public function probe() : array {
		$this->connect();
		$check = @imap_check( $this->stream );
		$nmsg  = $check ? (int) $check->Nmsgs : 0;
		$this->close();
		return [
			'messages' => $nmsg,
			'folder'   => $this->folder,
		];
	}

	/**
	 * UIDs > $since_uid, hartes Limit gegen Initial-Pull-Bomben.
	 */
	public function uids_since( int $since_uid, int $limit = 100 ) : array {
		if ( ! $this->stream ) { $this->connect(); }
		$check = @imap_check( $this->stream );
		if ( ! $check || $check->Nmsgs === 0 ) {
			return [];
		}
		$query   = $since_uid > 0 ? ( ( $since_uid + 1 ) . ':*' ) : '1:*';
		$results = @imap_fetch_overview( $this->stream, $query, FT_UID );
		if ( ! is_array( $results ) ) {
			return [];
		}
		$uids = [];
		foreach ( $results as $r ) {
			$uid = (int) ( $r->uid ?? 0 );
			if ( $uid > $since_uid ) {
				$uids[] = $uid;
			}
		}
		sort( $uids, SORT_NUMERIC );
		if ( count( $uids ) > $limit ) {
			$uids = array_slice( $uids, 0, $limit );
		}
		return $uids;
	}

	/**
	 * Wechselt den aktiv selektierten Folder via imap_reopen — gleicher Stream,
	 * kein Re-Login. Wird vom QuarantineService nach MOVE genutzt, um die
	 * Ziel-UID per Message-ID-Search nachzuziehen.
	 */
	public function select_folder( string $name ) : void {
		if ( ! $this->stream ) { $this->connect(); }
		$flags = '/imap';
		if ( $this->encryption === 'ssl' )      { $flags .= '/ssl'; }
		elseif ( $this->encryption === 'tls' )  { $flags .= '/tls'; }
		else                                    { $flags .= '/notls'; }
		$flags .= '/novalidate-cert';
		$mbox = '{' . $this->host . ':' . $this->port . $flags . '}' . $name;
		imap_errors();
		if ( ! @imap_reopen( $this->stream, $mbox ) ) {
			$errs = imap_errors() ?: [ 'unknown imap_reopen failure' ];
			throw new \RuntimeException( 'SELECT ' . $name . ' fehlgeschlagen: ' . implode( '; ', $errs ) );
		}
	}

	/**
	 * Sicherstellen, dass ein Folder existiert. Idempotent.
	 * Wirft, wenn create technisch fehlschlägt — nicht, wenn der Folder
	 * schon da ist (CREATE auf existierenden Ordner liefert IMAP-NO,
	 * imap_createmailbox false; das fangen wir per imap_check als
	 * "exists genug" ab).
	 */
	public function ensure_folder( string $name ) : void {
		if ( ! $this->stream ) { $this->connect(); }
		$flags = '/imap';
		if ( $this->encryption === 'ssl' )      { $flags .= '/ssl'; }
		elseif ( $this->encryption === 'tls' )  { $flags .= '/tls'; }
		else                                    { $flags .= '/notls'; }
		$flags .= '/novalidate-cert';
		$ref = '{' . $this->host . ':' . $this->port . $flags . '}';

		imap_errors();
		$ok = @imap_createmailbox( $this->stream, $ref . $name );
		if ( $ok ) {
			// Subscribe ist freundlich für Mail-Clients, kein Hard-Fail wenn nicht supported.
			@imap_subscribe( $this->stream, $ref . $name );
			return;
		}
		// Existiert evtl. schon — probieren, ob STATUS klappt.
		$status = @imap_status( $this->stream, $ref . $name, SA_MESSAGES );
		if ( $status !== false ) {
			return;
		}
		$errs = imap_errors() ?: [ 'unknown CREATE failure' ];
		throw new \RuntimeException( 'CREATE ' . $name . ' fehlgeschlagen: ' . implode( '; ', $errs ) );
	}

	/**
	 * UID-basiertes MOVE in den Zielordner. Nutzt imap_mail_move + CP_UID,
	 * was intern UID COPY + UID STORE +FLAGS (\Deleted) ausführt — KEIN
	 * EXPUNGE. Die Quelle bleibt also wiederherstellbar, solange der
	 * Server (oder ein anderer Client) nicht EXPUNGE auslöst.
	 *
	 * c-client kennt keine UIDPLUS-Response — wir können die Ziel-UID nicht
	 * sicher zurückgeben (0 = unknown). Undo holt sie via Message-ID-Lookup
	 * im Ziel-Folder neu.
	 *
	 * @return int Ziel-UID falls bekannt, sonst 0
	 */
	public function move_uid( int $uid, string $target_folder ) : int {
		if ( ! $this->stream ) { $this->connect(); }
		// imap_mail_move() erwartet den nackten Folder-Namen — KEIN Server-Prefix-
		// String wie '{host:port/imap/ssl}Folder'. GMX (und einige andere Server)
		// lehnen einen prefix-haltigen Namen mit "[TRYCREATE] destination folder
		// does not exist" ab, Outlook365 toleriert es zufällig.
		imap_errors();
		$ok = @imap_mail_move( $this->stream, (string) $uid, $target_folder, CP_UID );
		if ( ! $ok ) {
			$errs = imap_errors() ?: [ 'unknown MOVE failure' ];
			throw new \RuntimeException( 'MOVE UID ' . $uid . ' → ' . $target_folder . ' fehlgeschlagen: ' . implode( '; ', $errs ) );
		}
		return 0;
	}

	/**
	 * Sucht eine Mail im aktuell selektierten Folder anhand des
	 * RFC-822 Message-ID-Headers und gibt deren UID zurück (0 = nicht gefunden).
	 *
	 * Bevorzugt RFC-3501 IMAP-HEADER-Search; bei Servern, die das ablehnen
	 * (z.B. GMX wirft "Unknown search criterion: HEADER"), Fallback auf
	 * imap_fetch_overview-Scan über alle Folder-Mails.
	 */
	public function find_uid_by_message_id( string $message_id ) : int {
		if ( ! $this->stream ) { $this->connect(); }
		if ( $message_id === '' ) { return 0; }
		imap_errors();
		$search  = 'HEADER "Message-ID" "<' . str_replace( '"', '\\"', $message_id ) . '>"';
		$results = @imap_search( $this->stream, $search, SE_UID );
		if ( is_array( $results ) && $results ) {
			return (int) max( $results );
		}
		imap_errors();
		$overview = @imap_fetch_overview( $this->stream, '1:*', FT_UID );
		if ( ! is_array( $overview ) ) { return 0; }
		foreach ( $overview as $m ) {
			$raw = trim( (string) ( $m->message_id ?? '' ) );
			$norm = trim( $raw, '<>' );
			if ( $norm === $message_id ) {
				return (int) ( $m->uid ?? 0 );
			}
		}
		return 0;
	}

	/**
	 * Endgültiges Löschen einer Mail im aktuell selektierten Folder:
	 * UID STORE +FLAGS \Deleted + EXPUNGE. Wird nur für Mails im Quarantäne-
	 * Folder aufgerufen (Endgültig-löschen-Button nach Undo-Fenster).
	 *
	 * c-client kennt kein UID EXPUNGE — imap_expunge() räumt alle
	 * \Deleted-Marker im Folder ab. Solange nur MailGuard in den Quarantäne-
	 * Folder schreibt (siehe Installer::DEFAULT_QUARANTINE_FOLDER), ist das
	 * ohne Kollateralschaden. Auf keinen Fall in normalen User-Ordnern
	 * verwenden.
	 */
	public function expunge_uid( int $uid ) : void {
		if ( ! $this->stream ) { $this->connect(); }
		imap_errors();
		$ok = @imap_delete( $this->stream, (string) $uid, FT_UID );
		if ( ! $ok ) {
			$errs = imap_errors() ?: [ 'unknown DELETE failure' ];
			throw new \RuntimeException( 'UID STORE \Deleted fehlgeschlagen fuer UID ' . $uid . ': ' . implode( '; ', $errs ) );
		}
		imap_errors();
		$ok = @imap_expunge( $this->stream );
		if ( ! $ok ) {
			$errs = imap_errors() ?: [ 'unknown EXPUNGE failure' ];
			throw new \RuntimeException( 'EXPUNGE fehlgeschlagen: ' . implode( '; ', $errs ) );
		}
	}

	/**
	 * Prüft, ob eine UID im aktuell selektierten Folder existiert. Wird vom
	 * QuarantineService vor jedem MOVE genutzt — IMAP MOVE auf einer nicht-
	 * existenten UID liefert auf vielen Servern kommentarlos OK ohne Effekt,
	 * was ohne Vor-Check zu false-positiven Audit-Einträgen führt.
	 */
	public function uid_exists( int $uid ) : bool {
		if ( ! $this->stream ) { $this->connect(); }
		imap_errors();
		$r = @imap_fetch_overview( $this->stream, (string) $uid, FT_UID );
		return is_array( $r ) && count( $r ) > 0;
	}

	public function fetch_message( int $uid ) : array {
		if ( ! $this->stream ) { $this->connect(); }
		$raw_headers = @imap_fetchheader( $this->stream, $uid, FT_UID );
		if ( ! is_string( $raw_headers ) ) {
			throw new \RuntimeException( 'IMAP fetchheader fehlgeschlagen fuer UID ' . $uid );
		}
		$parsed = self::parse_headers( $raw_headers );

		$preview   = '';
		$structure = @imap_fetchstructure( $this->stream, $uid, FT_UID );
		if ( $structure ) {
			$preview = $this->extract_text_preview( $uid, $structure );
		}

		return [
			'uid'             => $uid,
			'headers'         => $parsed['headers'],
			'subject'         => $parsed['subject'],
			'from_name'       => $parsed['from_name'],
			'from_addr'       => $parsed['from_addr'],
			'message_id'      => $parsed['message_id'],
			'date_hdr'        => $parsed['date_hdr'],
			'list_unsub'      => $parsed['list_unsub'],
			'list_unsub_post' => $parsed['list_unsub_post'],
			'body_preview'    => $preview,
		];
	}

	public static function parse_headers( string $raw ) : array {
		$normalized = preg_replace( "/\r\n/", "\n", $raw );
		$lines = explode( "\n", (string) $normalized );
		$unfolded = [];
		foreach ( $lines as $line ) {
			if ( $line === '' ) continue;
			if ( ( $line[0] === ' ' || $line[0] === "\t" ) && ! empty( $unfolded ) ) {
				$unfolded[ count( $unfolded ) - 1 ] .= ' ' . trim( $line );
			} else {
				$unfolded[] = $line;
			}
		}
		$headers = [];
		foreach ( $unfolded as $line ) {
			if ( preg_match( '/^([A-Za-z0-9-]+)\s*:\s*(.*)$/', $line, $m ) ) {
				$k = $m[1]; $v = $m[2];
				$headers[ $k ] = isset( $headers[ $k ] ) ? ( $headers[ $k ] . ', ' . $v ) : $v;
			}
		}
		$get = function ( string $name ) use ( $headers ) : string {
			$target = strtolower( $name );
			foreach ( $headers as $k => $v ) {
				if ( strtolower( $k ) === $target ) {
					return self::decode_mime( (string) $v );
				}
			}
			return '';
		};
		$from_raw = $get( 'From' );
		[ $from_name, $from_addr ] = self::parse_address( $from_raw );

		$msg_id = '';
		$mid_raw = $get( 'Message-ID' );
		if ( $mid_raw && preg_match( '/<([^>]+)>/', $mid_raw, $m ) ) {
			$msg_id = $m[1];
		}
		$date_hdr = null;
		$date_raw = $get( 'Date' );
		if ( $date_raw ) {
			$ts = strtotime( $date_raw );
			if ( $ts ) { $date_hdr = gmdate( 'Y-m-d H:i:s', $ts ); }
		}
		return [
			'headers'         => $headers,
			'subject'         => $get( 'Subject' ),
			'from_name'       => $from_name,
			'from_addr'       => $from_addr,
			'message_id'      => $msg_id,
			'date_hdr'        => $date_hdr,
			'list_unsub'      => $get( 'List-Unsubscribe' ),
			'list_unsub_post' => $get( 'List-Unsubscribe-Post' ),
		];
	}

	public static function decode_mime( string $s ) : string {
		if ( $s === '' ) return '';
		$parts = imap_mime_header_decode( $s );
		if ( ! is_array( $parts ) ) return $s;
		$out = '';
		foreach ( $parts as $p ) {
			$txt = $p->text ?? '';
			$cs  = $p->charset ?? 'default';
			if ( $cs && strtolower( $cs ) !== 'default' && strtolower( $cs ) !== 'utf-8' ) {
				$conv = @mb_convert_encoding( $txt, 'UTF-8', $cs );
				if ( $conv !== false ) $txt = $conv;
			}
			$out .= $txt;
		}
		return $out;
	}

	private static function parse_address( string $raw ) : array {
		$name = '';
		$addr = '';
		if ( preg_match( '/^\s*(.*?)\s*<([^>]+)>\s*$/', $raw, $m ) ) {
			$name = trim( $m[1], " \t\"'" );
			$addr = trim( $m[2] );
		} elseif ( preg_match( '/[^\s<>"]+@[^\s<>"]+/', $raw, $m ) ) {
			$addr = $m[0];
		}
		return [ $name, $addr ];
	}

	private function extract_text_preview( int $uid, $structure, int $max = 500 ) : string {
		if ( empty( $structure->parts ) ) {
			$body = @imap_fetchbody( $this->stream, $uid, '1', FT_UID );
			if ( ! is_string( $body ) ) return '';
			$decoded = $this->decode_part( $body, (int) ( $structure->encoding ?? 0 ) );
			return self::clean_preview( $decoded, $max );
		}
		$best_plain = null; $best_html = null;
		$walk = function ( $parts, $prefix ) use ( &$walk, &$best_plain, &$best_html ) {
			foreach ( $parts as $i => $p ) {
				$num = $prefix === '' ? (string) ( $i + 1 ) : ( $prefix . '.' . ( $i + 1 ) );
				$type    = (int) ( $p->type ?? 0 );
				$subtype = strtolower( (string) ( $p->subtype ?? '' ) );
				if ( $type === 0 && $subtype === 'plain' && $best_plain === null ) {
					$best_plain = [ $num, (int) ( $p->encoding ?? 0 ) ];
				}
				if ( $type === 0 && $subtype === 'html' && $best_html === null ) {
					$best_html = [ $num, (int) ( $p->encoding ?? 0 ) ];
				}
				if ( ! empty( $p->parts ) ) { $walk( $p->parts, $num ); }
			}
		};
		$walk( $structure->parts, '' );
		$pick = $best_plain ?: $best_html;
		if ( ! $pick ) return '';
		$body = @imap_fetchbody( $this->stream, $uid, $pick[0], FT_UID );
		if ( ! is_string( $body ) ) return '';
		$decoded = $this->decode_part( $body, $pick[1] );
		if ( $pick === $best_html ) {
			$decoded = wp_strip_all_tags( $decoded );
		}
		return self::clean_preview( $decoded, $max );
	}

	private function decode_part( string $body, int $encoding ) : string {
		switch ( $encoding ) {
			case 3:  return (string) base64_decode( $body, true ) ?: '';
			case 4:  return (string) quoted_printable_decode( $body );
			default: return $body;
		}
	}

	public static function clean_preview( string $s, int $max ) : string {
		$s = preg_replace( '/\s+/u', ' ', $s ) ?: '';
		$s = trim( $s );
		if ( mb_strlen( $s ) > $max ) {
			$s = mb_substr( $s, 0, $max - 1 ) . '…';
		}
		return $s;
	}
}
