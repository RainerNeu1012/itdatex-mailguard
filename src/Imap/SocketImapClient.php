<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap;

/**
 * Pure-PHP IMAP-Client via raw TCP/SSL-Socket.
 *
 * Fallback fuer Provider deren Backend PHP's imap-Extension (c-client) nach
 * ~5 Auth-Fails per SASL-Fingerprint temporaer sperrt — konkret die 1&1-Familie
 * (GMX, web.de, mail.de, 1und1, kundenserver.de). In diesem Zustand liefert
 * imap_open() reproduzierbar "authentication failed" obwohl die Credentials
 * korrekt sind. Direktes `A001 LOGIN` ueber einen simplen PHP-Socket funktioniert.
 *
 * Diese Klasse ist API-kompatibel zu {@see ImapClient} — ClientFactory routed
 * bekannte Problem-Hosts auf SocketImapClient, alle anderen auf die native
 * c-client-Variante (die weiterhin battle-tested fuer Outlook/Gmail/iCloud ist).
 *
 * Implementiert eine Teilmenge von IMAP4rev1 (RFC 3501): LOGIN, LIST, SELECT,
 * EXAMINE, STATUS, UID SEARCH, UID FETCH, UID STORE, UID COPY, UID MOVE (wenn
 * unterstuetzt, sonst COPY+STORE+EXPUNGE), EXPUNGE, CREATE, SUBSCRIBE, APPEND,
 * NOOP, LOGOUT. BODYSTRUCTURE wird nach Bedarf fuer Attachment-Discovery und
 * Body-Fetch geparst.
 *
 * Nicht implementiert: STARTTLS (wir verbinden direkt via ssl://), Modifed
 * UTF-7 fuer Folder-Namen (rein ASCII-Namen wie bei GMX funktionieren).
 */
final class SocketImapClient {

	/** @var resource|null */
	private $socket = null;
	private int $tag_counter = 0;
	private string $current_folder = '';
	private int $select_exists = 0;
	/** @var array<string,bool> */
	private array $capabilities = [];
	private string $hierarchy_delimiter = '/';

	public function __construct(
		private string $host,
		private int $port,
		private string $encryption, // ssl|tls|none
		private string $folder,
		private string $username,
		private string $password,
	) {}

	public function __destruct() {
		$this->close();
	}

	public function folder() : string {
		return $this->folder;
	}

	// ---------------------------------------------------------------------
	//  Connection
	// ---------------------------------------------------------------------

	public function connect( int $timeout = 12 ) : void {
		if ( $this->socket !== null ) { return; }
		if ( $this->host === '' || $this->username === '' || $this->password === '' ) {
			throw new \RuntimeException( 'IMAP-Zugangsdaten unvollstaendig.' );
		}

		$scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
		$addr   = sprintf( '%s://%s:%d', $scheme, $this->host, $this->port );
		$ctx    = stream_context_create( [
			'ssl' => [
				'verify_peer'      => false,
				'verify_peer_name' => false,
				'allow_self_signed'=> true,
			],
		] );
		$errno  = 0; $errstr = '';
		$sock   = @stream_socket_client( $addr, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx );
		if ( ! $sock ) {
			throw new \RuntimeException( "IMAP-Connect fehlgeschlagen: {$errstr} (errno {$errno})" );
		}
		stream_set_timeout( $sock, $timeout );
		$this->socket = $sock;

		// Greeting lesen: * OK [CAPABILITY ...] ...
		$greeting = $this->read_line();
		if ( ! preg_match( '/^\*\s+(OK|PREAUTH)\b/i', $greeting ) ) {
			$this->hard_close();
			throw new \RuntimeException( 'IMAP-Connect fehlgeschlagen: ungueltiges Greeting: ' . trim( $greeting ) );
		}
		$this->extract_capability_from_untagged( $greeting );

		// STARTTLS: wenn encryption=tls, dann jetzt upgraden
		if ( $this->encryption === 'tls' ) {
			$res = $this->cmd( 'STARTTLS' );
			if ( $res['status'] !== 'OK' ) {
				$this->hard_close();
				throw new \RuntimeException( 'STARTTLS fehlgeschlagen: ' . $res['text'] );
			}
			if ( ! @stream_socket_enable_crypto( $this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT ) ) {
				$this->hard_close();
				throw new \RuntimeException( 'STARTTLS crypto_negotiate fehlgeschlagen.' );
			}
			$this->capabilities = []; // nach STARTTLS neu lesen
		}

		// CAPABILITY wenn nicht aus Greeting bekannt
		if ( ! $this->capabilities ) {
			$res = $this->cmd( 'CAPABILITY' );
			foreach ( $res['untagged'] as $line ) {
				$this->extract_capability_from_untagged( $line );
			}
		}

		// LOGIN
		$res = $this->cmd( sprintf(
			'LOGIN %s %s',
			$this->imap_string( $this->username ),
			$this->imap_string( $this->password )
		) );
		if ( $res['status'] !== 'OK' ) {
			$this->hard_close();
			throw new \RuntimeException( 'IMAP-Connect fehlgeschlagen: ' . trim( $res['text'] ) );
		}
		// CAPABILITY aus LOGIN-Response (OK [CAPABILITY ...])?
		$this->extract_capability_from_tagged( $res['text'] );

		// Default-Folder selektieren
		if ( $this->folder !== '' ) {
			$this->select_folder( $this->folder );
		}
	}

	public function close() : void {
		if ( $this->socket === null ) { return; }
		// Best-effort LOGOUT, dann Socket zu
		try {
			@fwrite( $this->socket, "Z999 LOGOUT\r\n" );
			stream_set_timeout( $this->socket, 1 );
			@fgets( $this->socket, 4096 );
			@fgets( $this->socket, 4096 );
		} catch ( \Throwable $e ) { /* ignore */ }
		$this->hard_close();
	}

	private function hard_close() : void {
		if ( $this->socket !== null ) {
			@fclose( $this->socket );
			$this->socket = null;
			$this->current_folder = '';
			$this->select_exists  = 0;
		}
	}

	// ---------------------------------------------------------------------
	//  Low-level I/O
	// ---------------------------------------------------------------------

	private function next_tag() : string {
		$this->tag_counter++;
		return 'A' . str_pad( (string) $this->tag_counter, 4, '0', STR_PAD_LEFT );
	}

	private function write_line( string $line ) : void {
		if ( $this->socket === null ) { throw new \RuntimeException( 'Socket geschlossen.' ); }
		$n = @fwrite( $this->socket, $line . "\r\n" );
		if ( $n === false ) { throw new \RuntimeException( 'Socket-Write fehlgeschlagen.' ); }
	}

	/**
	 * Liest eine logische IMAP-Response-Zeile. Beachtet IMAP-Literals
	 * `{N}\r\n<N bytes>` — die Literal-Bytes werden transparent in die
	 * Rueckgabe gefaedelt, die Fortsetzung der Zeile danach wird angehaengt.
	 */
	private function read_line() : string {
		if ( $this->socket === null ) { throw new \RuntimeException( 'Socket geschlossen.' ); }
		$line = @fgets( $this->socket, 65536 );
		if ( $line === false ) {
			throw new \RuntimeException( 'Socket-Read fehlgeschlagen (EOF?).' );
		}
		// Literal am Zeilenende? → N bytes lesen, dann naechste Teilzeile anhaengen.
		while ( preg_match( '/\{(\d+)\}\r?\n$/', $line, $m ) ) {
			$len  = (int) $m[1];
			$data = $this->read_exact( $len );
			$next = @fgets( $this->socket, 65536 );
			if ( $next === false ) {
				throw new \RuntimeException( 'Socket-Read fehlgeschlagen nach Literal.' );
			}
			$line .= $data . $next;
		}
		return $line;
	}

	private function read_exact( int $n ) : string {
		if ( $this->socket === null ) { throw new \RuntimeException( 'Socket geschlossen.' ); }
		$out = '';
		while ( strlen( $out ) < $n ) {
			$chunk = @fread( $this->socket, $n - strlen( $out ) );
			if ( $chunk === false || $chunk === '' ) {
				$meta = stream_get_meta_data( $this->socket );
				if ( ! empty( $meta['timed_out'] ) ) { throw new \RuntimeException( 'Socket-Read timeout.' ); }
				throw new \RuntimeException( 'Socket-Read fehlgeschlagen in Literal.' );
			}
			$out .= $chunk;
		}
		return $out;
	}

	/**
	 * Sendet einen IMAP-Command und liest Response bis zur tagged-Response.
	 * Rueckgabe: ['status' => 'OK'|'NO'|'BAD', 'text' => <rest of tagged line>, 'untagged' => string[]].
	 */
	private function cmd( string $command ) : array {
		if ( $this->socket === null ) { $this->connect(); }
		$tag = $this->next_tag();
		$this->write_line( $tag . ' ' . $command );
		return $this->read_response( $tag );
	}

	private function read_response( string $tag ) : array {
		$untagged = [];
		while ( true ) {
			$line = $this->read_line();
			if ( str_starts_with( $line, $tag . ' ' ) ) {
				$rest   = substr( $line, strlen( $tag ) + 1 );
				$status = strtoupper( strtok( $rest, ' ' ) ?: '' );
				$text   = (string) substr( $rest, strlen( $status ) + 1 );
				return [ 'status' => $status, 'text' => rtrim( $text, "\r\n" ), 'untagged' => $untagged ];
			}
			if ( str_starts_with( $line, '+ ' ) ) {
				// Continuation-Request mitten im Protokoll — kommt bei Literal-Sends vor,
				// die wir mit quoted-strings vermeiden. Als Error behandeln.
				throw new \RuntimeException( 'Unerwarteter Continuation-Request: ' . trim( $line ) );
			}
			$untagged[] = $line;
			// EXISTS mitzaehlen falls wir mitten in einem SELECT sind
			if ( preg_match( '/^\*\s+(\d+)\s+EXISTS\b/i', $line, $m ) ) {
				$this->select_exists = (int) $m[1];
			}
		}
	}

	private function extract_capability_from_untagged( string $line ) : void {
		if ( preg_match( '/CAPABILITY\s+([^\]\r\n]+)/i', $line, $m ) ) {
			foreach ( preg_split( '/\s+/', trim( $m[1] ) ) as $cap ) {
				if ( $cap !== '' ) { $this->capabilities[ strtoupper( $cap ) ] = true; }
			}
		}
	}

	private function extract_capability_from_tagged( string $text ) : void {
		if ( preg_match( '/\[CAPABILITY\s+([^\]]+)\]/i', $text, $m ) ) {
			foreach ( preg_split( '/\s+/', trim( $m[1] ) ) as $cap ) {
				if ( $cap !== '' ) { $this->capabilities[ strtoupper( $cap ) ] = true; }
			}
		}
	}

	private function has_cap( string $cap ) : bool {
		return isset( $this->capabilities[ strtoupper( $cap ) ] );
	}

	/**
	 * Quoted-String fuer IMAP: Backslash + Doublequote escapen. Gilt nur fuer
	 * ASCII ohne CR/LF (RFC 3501 §4.3). Fuer alles andere waere ein Literal
	 * noetig — Username/Password passen typisch in Quoted.
	 */
	private function imap_string( string $s ) : string {
		if ( preg_match( '/[\r\n\x00]/', $s ) ) {
			// Fallback auf Literal (synchron via Continuation-Request)
			return $this->send_as_literal_hack( $s );
		}
		$escaped = str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], $s );
		return '"' . $escaped . '"';
	}

	/**
	 * Nur bei exotischen Zeichen genutzt — fast nie. Wir inlinen den Literal
	 * im Command-String; wenn der Server LITERAL+ beherrscht geht das in einem
	 * Zug durch, sonst brauchts Continuation-Handling (hier bewusst nicht
	 * implementiert, daher Fallback: wir kodieren Base64 im UTF8=ACCEPT-Stil
	 * ist nicht standard-compliant — besser: Exception).
	 */
	private function send_as_literal_hack( string $s ) : string {
		throw new \RuntimeException( 'IMAP-String mit CR/LF/NULL nicht unterstuetzt.' );
	}

	// ---------------------------------------------------------------------
	//  Folder Operations
	// ---------------------------------------------------------------------

	public function select_folder( string $name ) : void {
		if ( $this->socket === null ) { $this->connect(); }
		$res = $this->cmd( 'SELECT ' . $this->imap_string( $name ) );
		if ( $res['status'] !== 'OK' ) {
			throw new \RuntimeException( 'SELECT ' . $name . ' fehlgeschlagen: ' . $res['text'] );
		}
		$this->current_folder = $name;
		// EXISTS wurde in read_response() aus untagged mitgetrackt
	}

	public function list_folders() : array {
		if ( $this->socket === null ) { $this->connect(); }
		$res = $this->cmd( 'LIST "" *' );
		if ( $res['status'] !== 'OK' ) { return []; }
		$out = [];
		foreach ( $res['untagged'] as $line ) {
			$p = self::parse_list_line( $line );
			if ( ! $p ) { continue; }
			// \Noselect ueberspringen (reiner Container)
			$attrs_upper = array_map( 'strtoupper', $p['attributes'] );
			if ( in_array( '\\NOSELECT', $attrs_upper, true ) ) { continue; }
			if ( $p['delimiter'] !== '' ) {
				$this->hierarchy_delimiter = $p['delimiter'];
			}
			$out[] = [
				'name'       => $p['name'],
				'display'    => $p['name'],
				'attributes' => $p['attributes'],
			];
		}
		return $out;
	}

	public function probe() : array {
		$this->connect();
		// SELECT liefert EXISTS → nutzen
		$this->select_folder( $this->folder );
		$n = $this->select_exists;
		$this->close();
		return [ 'messages' => $n, 'folder' => $this->folder ];
	}

	public function ensure_folder( string $name ) : void {
		if ( $this->socket === null ) { $this->connect(); }
		$res = $this->cmd( 'CREATE ' . $this->imap_string( $name ) );
		if ( $res['status'] === 'OK' ) {
			// Subscribe ist freundlich, aber kein Hard-Fail
			$this->cmd( 'SUBSCRIBE ' . $this->imap_string( $name ) );
			return;
		}
		// Existiert evtl. schon — STATUS pruefen
		$status = $this->cmd( 'STATUS ' . $this->imap_string( $name ) . ' (MESSAGES)' );
		if ( $status['status'] === 'OK' ) { return; }
		throw new \RuntimeException( 'CREATE ' . $name . ' fehlgeschlagen: ' . $res['text'] );
	}

	public function detect_namespace() : array {
		if ( $this->socket === null ) { $this->connect(); }
		// RFC 2342 NAMESPACE wenn verfuegbar
		if ( $this->has_cap( 'NAMESPACE' ) ) {
			$res = $this->cmd( 'NAMESPACE' );
			if ( $res['status'] === 'OK' ) {
				foreach ( $res['untagged'] as $line ) {
					if ( preg_match( '/^\*\s+NAMESPACE\s+(.*)$/i', $line, $m ) ) {
						[ $prefix, $delim ] = self::parse_namespace_response( $m[1] );
						if ( $delim !== '' ) {
							return [ 'prefix' => $prefix, 'delimiter' => $delim ];
						}
					}
				}
			}
		}
		// Fallback: LIST "" INBOX → Delimiter aus Attributes, dann Heuristik
		$res = $this->cmd( 'LIST "" INBOX' );
		$delim = '/';
		foreach ( $res['untagged'] as $line ) {
			$p = self::parse_list_line( $line );
			if ( $p && $p['delimiter'] !== '' ) { $delim = $p['delimiter']; break; }
		}
		if ( $delim !== '.' ) {
			return [ 'prefix' => '', 'delimiter' => $delim ];
		}
		// Dovecot-typisch: '.' → pruefen ob alle Non-INBOX-Folder unter INBOX. haengen
		$all = $this->cmd( 'LIST "" *' );
		$has_prefixed = false; $has_outside = false;
		foreach ( $all['untagged'] as $line ) {
			$p = self::parse_list_line( $line );
			if ( ! $p ) { continue; }
			$n = $p['name'];
			if ( strcasecmp( $n, 'INBOX' ) === 0 ) { continue; }
			if ( stripos( $n, 'INBOX.' ) === 0 ) { $has_prefixed = true; }
			else                                 { $has_outside = true; }
		}
		if ( $has_prefixed && ! $has_outside ) {
			return [ 'prefix' => 'INBOX.', 'delimiter' => '.' ];
		}
		return [ 'prefix' => '', 'delimiter' => '.' ];
	}

	// ---------------------------------------------------------------------
	//  Search
	// ---------------------------------------------------------------------

	public function uids_since( int $since_uid, int $limit = 100 ) : array {
		if ( $this->socket === null ) { $this->connect(); }
		if ( $this->current_folder === '' ) { $this->select_folder( $this->folder ); }
		if ( $this->select_exists === 0 ) { return []; }
		$query = $since_uid > 0 ? ( ( $since_uid + 1 ) . ':*' ) : '1:*';
		$res   = $this->cmd( 'UID SEARCH UID ' . $query );
		if ( $res['status'] !== 'OK' ) { return []; }
		$uids = self::parse_search_untagged( $res['untagged'] );
		$uids = array_values( array_filter( $uids, static fn ( $u ) => $u > $since_uid ) );
		sort( $uids, SORT_NUMERIC );
		if ( count( $uids ) > $limit ) { $uids = array_slice( $uids, 0, $limit ); }
		return $uids;
	}

	public function uids_by_from( string $from_addr ) : array {
		if ( $this->socket === null ) { $this->connect(); }
		if ( $from_addr === '' ) { return []; }
		$res = $this->cmd( 'UID SEARCH FROM ' . $this->imap_string( $from_addr ) );
		if ( $res['status'] !== 'OK' ) { return []; }
		return self::parse_search_untagged( $res['untagged'] );
	}

	public function find_uid_by_message_id( string $message_id ) : int {
		if ( $this->socket === null ) { $this->connect(); }
		if ( $message_id === '' ) { return 0; }
		$res = $this->cmd( 'UID SEARCH HEADER Message-ID ' . $this->imap_string( '<' . $message_id . '>' ) );
		if ( $res['status'] === 'OK' ) {
			$uids = self::parse_search_untagged( $res['untagged'] );
			if ( $uids ) { return (int) max( $uids ); }
		}
		// Fallback: scan ALL headers (langsam)
		$overview = $this->cmd( 'UID FETCH 1:* (UID BODY.PEEK[HEADER.FIELDS (Message-ID)])' );
		if ( $overview['status'] !== 'OK' ) { return 0; }
		foreach ( $overview['untagged'] as $line ) {
			if ( ! preg_match( '/UID\s+(\d+)/', $line, $mu ) ) { continue; }
			$uid = (int) $mu[1];
			if ( preg_match( '/Message-ID:\s*<([^>]+)>/i', $line, $mm ) && $mm[1] === $message_id ) {
				return $uid;
			}
		}
		return 0;
	}

	public function uid_exists( int $uid ) : bool {
		if ( $this->socket === null ) { $this->connect(); }
		$res = $this->cmd( 'UID FETCH ' . $uid . ' (UID)' );
		if ( $res['status'] !== 'OK' ) { return false; }
		foreach ( $res['untagged'] as $line ) {
			if ( preg_match( '/UID\s+' . $uid . '\b/', $line ) ) { return true; }
		}
		return false;
	}

	// ---------------------------------------------------------------------
	//  Fetch (headers + preview + full body)
	// ---------------------------------------------------------------------

	public function fetch_message( int $uid ) : array {
		if ( $this->socket === null ) { $this->connect(); }
		// Header + BODYSTRUCTURE in einem Call
		$res = $this->cmd( sprintf(
			'UID FETCH %d (UID BODYSTRUCTURE BODY.PEEK[HEADER])',
			$uid
		) );
		if ( $res['status'] !== 'OK' ) {
			throw new \RuntimeException( 'UID FETCH ' . $uid . ' fehlgeschlagen: ' . $res['text'] );
		}
		$raw        = implode( '', $res['untagged'] );
		$parsed     = self::parse_fetch_atoms( $raw );
		$raw_header = (string) ( $parsed['BODY[HEADER]'] ?? '' );
		if ( $raw_header === '' ) {
			$raw_header = (string) ( $parsed['BODY.PEEK[HEADER]'] ?? '' );
		}
		$headers = ImapClient::parse_headers( $raw_header );

		// BODYSTRUCTURE parsen fuer Attachment-Metadaten + Body-Part-Nummer
		$bs = $parsed['BODYSTRUCTURE'] ?? null;
		$structure = is_string( $bs ) ? self::parse_bodystructure( $bs ) : null;

		$preview = '';
		$attachments = [];
		if ( $structure ) {
			$pick = self::pick_text_part( $structure );
			if ( $pick ) {
				$preview = $this->fetch_body_part_decoded( $uid, $pick['part_num'], $pick['encoding'], 500 );
				if ( strtolower( $pick['subtype'] ) === 'html' ) {
					$preview = wp_strip_all_tags( $preview );
				}
				$preview = ImapClient::clean_preview( $preview, 500 );
			}
			$attachments = self::structure_to_attachments( $structure );
		}

		return [
			'uid'             => $uid,
			'headers'         => $headers['headers'],
			'subject'         => $headers['subject'],
			'from_name'       => $headers['from_name'],
			'from_addr'       => $headers['from_addr'],
			'message_id'      => $headers['message_id'],
			'date_hdr'        => $headers['date_hdr'],
			'list_unsub'      => $headers['list_unsub'],
			'list_unsub_post' => $headers['list_unsub_post'],
			'body_preview'    => $preview,
			'attachments'     => $attachments,
		];
	}

	public function fetch_body_text( int $uid, int $max = 100000 ) : string {
		if ( $this->socket === null ) { $this->connect(); }
		$bs_res = $this->cmd( sprintf( 'UID FETCH %d (BODYSTRUCTURE)', $uid ) );
		$structure = null;
		if ( $bs_res['status'] === 'OK' ) {
			$raw    = implode( '', $bs_res['untagged'] );
			$parsed = self::parse_fetch_atoms( $raw );
			$bs     = $parsed['BODYSTRUCTURE'] ?? null;
			if ( is_string( $bs ) ) { $structure = self::parse_bodystructure( $bs ); }
		}
		if ( $structure ) {
			$pick = self::pick_text_part( $structure, true );
			if ( $pick ) {
				$text = $this->fetch_body_part_decoded( $uid, $pick['part_num'], $pick['encoding'], $max );
				if ( strtolower( $pick['subtype'] ) === 'html' ) {
					$plain = html_entity_decode( strip_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					$plain = (string) preg_replace( '/\s+/', ' ', $plain );
					return trim( mb_substr( $plain, 0, $max ) );
				}
				return trim( mb_substr( $text, 0, $max ) );
			}
		}
		// Fallback: BODY.PEEK[] gesamte Mail, dann simpler Plaintext-Strip
		$raw = $this->fetch_body_part_raw( $uid, '', $max );
		if ( $raw === '' ) { return ''; }
		if ( stripos( $raw, '<html' ) !== false || stripos( $raw, '<body' ) !== false ) {
			$plain = html_entity_decode( strip_tags( $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$plain = (string) preg_replace( '/\s+/', ' ', $plain );
			return trim( mb_substr( $plain, 0, $max ) );
		}
		return trim( mb_substr( $raw, 0, $max ) );
	}

	public function fetch_body_html( int $uid, int $max = 500000 ) : array {
		if ( $this->socket === null ) { $this->connect(); }
		$bs_res = $this->cmd( sprintf( 'UID FETCH %d (BODYSTRUCTURE)', $uid ) );
		$structure = null;
		if ( $bs_res['status'] === 'OK' ) {
			$raw    = implode( '', $bs_res['untagged'] );
			$parsed = self::parse_fetch_atoms( $raw );
			$bs     = $parsed['BODYSTRUCTURE'] ?? null;
			if ( is_string( $bs ) ) { $structure = self::parse_bodystructure( $bs ); }
		}
		if ( $structure ) {
			$html = self::pick_text_part( $structure, false, 'html' );
			if ( $html ) {
				$body = $this->fetch_body_part_decoded( $uid, $html['part_num'], $html['encoding'], $max );
				if ( $body !== '' ) {
					return [ 'format' => 'html', 'body' => mb_substr( ImapClient::sanitize_html_body( $body ), 0, $max ) ];
				}
			}
			$plain = self::pick_text_part( $structure, true, 'plain' );
			if ( $plain ) {
				$body = $this->fetch_body_part_decoded( $uid, $plain['part_num'], $plain['encoding'], $max );
				if ( $body !== '' ) {
					return [ 'format' => 'text', 'body' => mb_substr( $body, 0, $max ) ];
				}
			}
		}
		// Fallback: ganze Mail
		$raw = $this->fetch_body_part_raw( $uid, '', $max );
		if ( $raw === '' ) { return [ 'format' => 'text', 'body' => '' ]; }
		$fmt = ( stripos( $raw, '<html' ) !== false || stripos( $raw, '<body' ) !== false || stripos( $raw, '<div' ) !== false )
			? 'html' : 'text';
		$body = $fmt === 'html' ? ImapClient::sanitize_html_body( $raw ) : $raw;
		return [ 'format' => $fmt, 'body' => mb_substr( $body, 0, $max ) ];
	}

	public function fetch_attachment_body( int $uid, string $part_num, string $encoding, int $max_bytes, int $size_hint = 0 ) : ?string {
		if ( $this->socket === null ) { $this->connect(); }
		if ( $part_num === '' ) { return null; }
		if ( $size_hint > 0 && $size_hint > $max_bytes ) { return null; }
		$raw = $this->fetch_body_part_raw( $uid, $part_num, $max_bytes + 10000 );
		if ( $raw === '' ) { return null; }
		$decoded = ImapClient::decode_transfer( $raw, $encoding );
		if ( strlen( $decoded ) > $max_bytes ) { return null; }
		return $decoded;
	}

	/**
	 * Holt rohe Bytes eines BODY-Parts (ohne Decoding). PEEK → kein \Seen.
	 */
	private function fetch_body_part_raw( int $uid, string $part_num, int $max_bytes ) : string {
		$section = $part_num === '' ? 'BODY.PEEK[]' : ( 'BODY.PEEK[' . $part_num . ']' );
		// <0.N> Byte-Range zum Capping
		$section .= '<0.' . $max_bytes . '>';
		$res = $this->cmd( sprintf( 'UID FETCH %d (%s)', $uid, $section ) );
		if ( $res['status'] !== 'OK' ) { return ''; }
		$raw    = implode( '', $res['untagged'] );
		$parsed = self::parse_fetch_atoms( $raw );
		// Keys koennen 'BODY[...]' oder 'BODY[...]<0>' heissen
		foreach ( $parsed as $k => $v ) {
			if ( ! is_string( $v ) ) { continue; }
			if ( stripos( $k, 'BODY[' ) === 0 || stripos( $k, 'BODY.PEEK[' ) === 0 ) {
				return $v;
			}
		}
		return '';
	}

	private function fetch_body_part_decoded( int $uid, string $part_num, int $encoding, int $max_bytes ) : string {
		$raw = $this->fetch_body_part_raw( $uid, $part_num, $max_bytes );
		if ( $raw === '' ) { return ''; }
		switch ( $encoding ) {
			case 3: return (string) base64_decode( $raw, true ) ?: $raw;
			case 4: return (string) quoted_printable_decode( $raw );
			default: return $raw;
		}
	}

	// ---------------------------------------------------------------------
	//  Flags / Store / Expunge / Move
	// ---------------------------------------------------------------------

	public function set_flag( int $uid, string $flag ) : bool {
		if ( $this->socket === null ) { $this->connect(); }
		if ( $flag === '' ) { return false; }
		$res = $this->cmd( sprintf( 'UID STORE %d +FLAGS (%s)', $uid, $flag ) );
		return $res['status'] === 'OK';
	}

	public function clear_flag( int $uid, string $flag ) : bool {
		if ( $this->socket === null ) { $this->connect(); }
		if ( $flag === '' ) { return false; }
		$res = $this->cmd( sprintf( 'UID STORE %d -FLAGS (%s)', $uid, $flag ) );
		return $res['status'] === 'OK';
	}

	public function expunge_selected() : void {
		if ( $this->socket === null ) { $this->connect(); }
		$res = $this->cmd( 'EXPUNGE' );
		if ( $res['status'] !== 'OK' ) {
			throw new \RuntimeException( 'EXPUNGE fehlgeschlagen: ' . $res['text'] );
		}
	}

	public function expunge_uid( int $uid ) : void {
		$this->expunge_uids( [ $uid ] );
	}

	public function expunge_uids( array $uids ) : void {
		if ( $this->socket === null ) { $this->connect(); }
		$uids = array_values( array_unique( array_filter( array_map( 'intval', $uids ), static fn ( $u ) => $u > 0 ) ) );
		if ( ! $uids ) { return; }
		$set = implode( ',', $uids );
		$res = $this->cmd( 'UID STORE ' . $set . ' +FLAGS (\\Deleted)' );
		if ( $res['status'] !== 'OK' ) {
			throw new \RuntimeException( 'UID STORE \Deleted fehlgeschlagen fuer UID-Set ' . $set . ': ' . $res['text'] );
		}
		// UID EXPUNGE wenn UIDPLUS (RFC 4315) unterstuetzt, sonst folder-weit
		if ( $this->has_cap( 'UIDPLUS' ) ) {
			$res = $this->cmd( 'UID EXPUNGE ' . $set );
			if ( $res['status'] === 'OK' ) { return; }
		}
		$res = $this->cmd( 'EXPUNGE' );
		if ( $res['status'] !== 'OK' ) {
			throw new \RuntimeException( 'EXPUNGE fehlgeschlagen: ' . $res['text'] );
		}
	}

	public function move_uid( int $uid, string $target_folder ) : int {
		$this->move_uids( [ $uid ], $target_folder );
		return 0;
	}

	public function move_uids( array $uids, string $target_folder ) : void {
		if ( $this->socket === null ) { $this->connect(); }
		$uids = array_values( array_unique( array_filter( array_map( 'intval', $uids ), static fn ( $u ) => $u > 0 ) ) );
		if ( ! $uids ) { return; }
		$set    = implode( ',', $uids );
		$target = $this->imap_string( $target_folder );
		// UID MOVE (RFC 6851) bevorzugt
		if ( $this->has_cap( 'MOVE' ) ) {
			$res = $this->cmd( 'UID MOVE ' . $set . ' ' . $target );
			if ( $res['status'] === 'OK' ) { return; }
			// NO → Fallback versuchen
		}
		// Fallback: COPY + STORE \Deleted (KEIN EXPUNGE — identisch zu ImapClient-Verhalten)
		$res = $this->cmd( 'UID COPY ' . $set . ' ' . $target );
		if ( $res['status'] !== 'OK' ) {
			throw new \RuntimeException( 'UID COPY ' . $set . ' → ' . $target_folder . ' fehlgeschlagen: ' . $res['text'] );
		}
		$res = $this->cmd( 'UID STORE ' . $set . ' +FLAGS (\\Deleted)' );
		if ( $res['status'] !== 'OK' ) {
			throw new \RuntimeException( 'UID STORE \Deleted nach COPY fehlgeschlagen: ' . $res['text'] );
		}
	}

	public function append_message( string $folder, string $rfc822, string $flags = '\\Seen' ) : bool {
		if ( $this->socket === null ) { $this->connect(); }
		if ( $folder === '' || $rfc822 === '' ) { return false; }
		// RFC-822-Content ist i.d.R. mit CR/LF + nicht-ASCII → via Literal senden.
		// Wir machen synchron-Literal: APPEND folder (flags) {N}\r\n <wait + CR/LF> <bytes>
		$len = strlen( $rfc822 );
		$cmd = 'APPEND ' . $this->imap_string( $folder ) . ' (' . $flags . ') {' . $len . '}';
		$tag = $this->next_tag();
		$this->write_line( $tag . ' ' . $cmd );
		// Server antwortet "+ Ready for literal data" → dann bytes schicken
		$cont = $this->read_line();
		if ( ! str_starts_with( $cont, '+ ' ) ) {
			return false; // Server wollte keinen Literal, z.B. NO
		}
		$n = @fwrite( $this->socket, $rfc822 . "\r\n" );
		if ( $n === false ) { return false; }
		$resp = $this->read_response( $tag );
		return $resp['status'] === 'OK';
	}

	public function ping() : bool {
		if ( $this->socket === null ) { return false; }
		$res = $this->cmd( 'NOOP' );
		return $res['status'] === 'OK';
	}

	// ---------------------------------------------------------------------
	//  Response Parsers (static)
	// ---------------------------------------------------------------------

	/**
	 * Parst eine `* LIST (\attr \attr) "/" "FolderName"`-Zeile.
	 * @return array{attributes:string[],delimiter:string,name:string}|null
	 */
	public static function parse_list_line( string $line ) : ?array {
		if ( ! preg_match( '/^\*\s+(LIST|LSUB)\s+\(([^)]*)\)\s+(?:NIL|"([^"]*)"|(\S+))\s+(.+)$/i', trim( $line ), $m ) ) {
			return null;
		}
		$attrs_raw = trim( $m[2] );
		$attrs = $attrs_raw === '' ? [] : array_values( array_filter( preg_split( '/\s+/', $attrs_raw ) ) );
		$delim = $m[3] !== '' ? $m[3] : ( $m[4] === 'NIL' ? '' : $m[4] );
		$name  = rtrim( $m[5] );
		// Name kann quoted oder atom sein
		if ( str_starts_with( $name, '"' ) && str_ends_with( $name, '"' ) ) {
			$name = stripcslashes( substr( $name, 1, -1 ) );
		}
		return [ 'attributes' => $attrs, 'delimiter' => $delim, 'name' => $name ];
	}

	/**
	 * Parst eine NAMESPACE-Response: `((("" "/")) NIL NIL)` o.ae.
	 * Rueckgabe: [prefix, delimiter] vom personal namespace.
	 * @return array{0:string,1:string}
	 */
	public static function parse_namespace_response( string $s ) : array {
		if ( preg_match( '/^\s*\(\s*\(\s*"([^"]*)"\s+"([^"]*)"/', $s, $m ) ) {
			return [ $m[1], $m[2] ];
		}
		return [ '', '' ];
	}

	/**
	 * Parst eine SEARCH-Response, Format: `* SEARCH 1 2 3 4` (oder leer).
	 * @return int[]
	 */
	public static function parse_search_untagged( array $lines ) : array {
		$out = [];
		foreach ( $lines as $line ) {
			if ( ! preg_match( '/^\*\s+SEARCH\b(.*)$/i', $line, $m ) ) { continue; }
			foreach ( preg_split( '/\s+/', trim( $m[1] ) ) as $n ) {
				if ( ctype_digit( $n ) ) { $out[] = (int) $n; }
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Parst eine FETCH-Response in Atoms. Behandelt:
	 *  - Flags-Listen `FLAGS (\Seen \Deleted)`
	 *  - Quoted-Strings `INTERNALDATE "01-Jan-2026 12:34:56 +0000"`
	 *  - Literals `BODY[HEADER] {234}\r\n<234 bytes>`
	 *  - Nested parens fuer BODYSTRUCTURE
	 *  - Atome `UID 123`
	 *
	 * Rueckgabe: ['UID'=>123, 'FLAGS'=>['\Seen'], 'BODY[HEADER]'=>'...', 'BODYSTRUCTURE'=>'(...)' (raw)]
	 */
	public static function parse_fetch_atoms( string $raw ) : array {
		// Wir suchen den oeffnenden `(` nach `FETCH ` und tokenisieren bis zum
		// matching `)`. Innerhalb: atom name → value.
		$pos = strpos( $raw, 'FETCH (' );
		if ( $pos === false ) { return []; }
		$pos += strlen( 'FETCH (' );
		$depth = 1; $len = strlen( $raw );
		$out = [];
		while ( $pos < $len && $depth > 0 ) {
			// Skip whitespace
			while ( $pos < $len && ( $raw[ $pos ] === ' ' || $raw[ $pos ] === "\r" || $raw[ $pos ] === "\n" ) ) { $pos++; }
			if ( $pos >= $len ) { break; }
			if ( $raw[ $pos ] === ')' ) { $depth--; $pos++; continue; }
			// Atom (key)
			$key_start = $pos;
			while ( $pos < $len && $raw[ $pos ] !== ' ' && $raw[ $pos ] !== '[' ) {
				if ( $raw[ $pos ] === '(' || $raw[ $pos ] === ')' ) { break; }
				$pos++;
			}
			// Key kann []-Suffix haben: BODY[HEADER], BODY[1.TEXT], BODY[]
			if ( $pos < $len && $raw[ $pos ] === '[' ) {
				$pos++;
				while ( $pos < $len && $raw[ $pos ] !== ']' ) { $pos++; }
				if ( $pos < $len ) { $pos++; } // closing ]
				// Optional <0.N> Range-Suffix
				if ( $pos < $len && $raw[ $pos ] === '<' ) {
					while ( $pos < $len && $raw[ $pos ] !== '>' ) { $pos++; }
					if ( $pos < $len ) { $pos++; }
				}
			}
			$key = strtoupper( substr( $raw, $key_start, $pos - $key_start ) );
			// Skip space(s)
			while ( $pos < $len && $raw[ $pos ] === ' ' ) { $pos++; }
			if ( $pos >= $len ) { break; }
			// Value
			$ch = $raw[ $pos ];
			if ( $ch === '(' ) {
				// Nested paren group (z.B. FLAGS, BODYSTRUCTURE). Lesen bis matching close.
				$grp_start = $pos;
				$d = 1; $pos++;
				while ( $pos < $len && $d > 0 ) {
					$c = $raw[ $pos ];
					if ( $c === '"' ) {
						// Quoted string: skip bis closing
						$pos++;
						while ( $pos < $len ) {
							if ( $raw[ $pos ] === '\\' && $pos + 1 < $len ) { $pos += 2; continue; }
							if ( $raw[ $pos ] === '"' ) { $pos++; break; }
							$pos++;
						}
						continue;
					}
					if ( $c === '{' ) {
						// Literal innerhalb der Paren-Gruppe (passiert in BODYSTRUCTURE praktisch nie, aber safe)
						$end = strpos( $raw, '}', $pos );
						if ( $end === false ) { break; }
						$n   = (int) substr( $raw, $pos + 1, $end - $pos - 1 );
						$pos = $end + 1;
						while ( $pos < $len && ( $raw[ $pos ] === "\r" || $raw[ $pos ] === "\n" ) ) { $pos++; }
						$pos += $n;
						continue;
					}
					if ( $c === '(' ) { $d++; $pos++; continue; }
					if ( $c === ')' ) { $d--; $pos++; continue; }
					$pos++;
				}
				$out[ $key ] = substr( $raw, $grp_start, $pos - $grp_start );
			} elseif ( $ch === '"' ) {
				$pos++;
				$v_start = $pos;
				while ( $pos < $len ) {
					if ( $raw[ $pos ] === '\\' && $pos + 1 < $len ) { $pos += 2; continue; }
					if ( $raw[ $pos ] === '"' ) { break; }
					$pos++;
				}
				$out[ $key ] = stripcslashes( substr( $raw, $v_start, $pos - $v_start ) );
				if ( $pos < $len ) { $pos++; } // closing "
			} elseif ( $ch === '{' ) {
				$end = strpos( $raw, '}', $pos );
				if ( $end === false ) { break; }
				$n   = (int) substr( $raw, $pos + 1, $end - $pos - 1 );
				$pos = $end + 1;
				// Skip CRLF nach {N}
				while ( $pos < $len && ( $raw[ $pos ] === "\r" || $raw[ $pos ] === "\n" ) ) { $pos++; }
				$out[ $key ] = substr( $raw, $pos, $n );
				$pos += $n;
			} else {
				// Bare atom (z.B. UID 123 oder NIL)
				$v_start = $pos;
				while ( $pos < $len && $raw[ $pos ] !== ' ' && $raw[ $pos ] !== ')' && $raw[ $pos ] !== "\r" && $raw[ $pos ] !== "\n" ) {
					$pos++;
				}
				$val = substr( $raw, $v_start, $pos - $v_start );
				$out[ $key ] = ctype_digit( $val ) ? (int) $val : $val;
			}
		}
		return $out;
	}

	/**
	 * Parst eine BODYSTRUCTURE in eine Tree-Struktur, aehnlich wie
	 * imap_fetchstructure() sie als Object-Tree liefert.
	 *
	 * Rueckgabe: ['type'=>int, 'subtype'=>string, 'encoding'=>int, 'bytes'=>int,
	 *            'parameters'=>[], 'dparameters'=>[], 'disposition'=>string,
	 *            'parts'=>[children]|null, 'part_num'=>string]
	 */
	public static function parse_bodystructure( string $raw ) : ?array {
		$raw = trim( $raw );
		if ( $raw === '' || $raw[0] !== '(' ) { return null; }
		$pos = 0; $tokens = self::tokenize_imap_value( $raw, $pos );
		if ( ! is_array( $tokens ) ) { return null; }
		return self::bodystructure_from_tokens( $tokens, '1' );
	}

	/**
	 * Tokenisiert einen IMAP-Value rekursiv — gibt zurueck entweder einen
	 * String (quoted / atom / NIL → ''), Integer, oder ein Array von
	 * Tokens (bei Paren-Gruppe).
	 */
	private static function tokenize_imap_value( string $raw, int &$pos ) {
		$len = strlen( $raw );
		// Skip whitespace
		while ( $pos < $len && ( $raw[ $pos ] === ' ' || $raw[ $pos ] === "\r" || $raw[ $pos ] === "\n" ) ) { $pos++; }
		if ( $pos >= $len ) { return null; }
		$ch = $raw[ $pos ];
		if ( $ch === '(' ) {
			$pos++;
			$list = [];
			while ( $pos < $len ) {
				// Skip whitespace
				while ( $pos < $len && ( $raw[ $pos ] === ' ' || $raw[ $pos ] === "\r" || $raw[ $pos ] === "\n" ) ) { $pos++; }
				if ( $pos >= $len ) { break; }
				if ( $raw[ $pos ] === ')' ) { $pos++; return $list; }
				$list[] = self::tokenize_imap_value( $raw, $pos );
			}
			return $list;
		}
		if ( $ch === '"' ) {
			$pos++;
			$start = $pos;
			while ( $pos < $len ) {
				if ( $raw[ $pos ] === '\\' && $pos + 1 < $len ) { $pos += 2; continue; }
				if ( $raw[ $pos ] === '"' ) { break; }
				$pos++;
			}
			$v = stripcslashes( substr( $raw, $start, $pos - $start ) );
			if ( $pos < $len ) { $pos++; }
			return $v;
		}
		if ( $ch === '{' ) {
			$end = strpos( $raw, '}', $pos );
			if ( $end === false ) { return ''; }
			$n   = (int) substr( $raw, $pos + 1, $end - $pos - 1 );
			$pos = $end + 1;
			while ( $pos < $len && ( $raw[ $pos ] === "\r" || $raw[ $pos ] === "\n" ) ) { $pos++; }
			$v = substr( $raw, $pos, $n );
			$pos += $n;
			return $v;
		}
		// Atom (inkl. NIL, Zahlen)
		$start = $pos;
		while ( $pos < $len && $raw[ $pos ] !== ' ' && $raw[ $pos ] !== ')' && $raw[ $pos ] !== '(' && $raw[ $pos ] !== "\r" && $raw[ $pos ] !== "\n" ) {
			$pos++;
		}
		$atom = substr( $raw, $start, $pos - $start );
		if ( strcasecmp( $atom, 'NIL' ) === 0 ) { return ''; }
		if ( ctype_digit( $atom ) ) { return (int) $atom; }
		return $atom;
	}

	/**
	 * Konvertiert eine tokenisierte BODYSTRUCTURE in eine flache Baum-Struktur.
	 * Multipart: ersten Elemente sind rekursiv Parts, dann "subtype" + optional params.
	 * Singlepart: type subtype (params) id description encoding size ...
	 */
	private static function bodystructure_from_tokens( array $tokens, string $part_num ) : array {
		if ( ! $tokens ) {
			return [ 'type' => 7, 'subtype' => '', 'encoding' => 0, 'bytes' => 0, 'parameters' => [], 'dparameters' => [], 'disposition' => '', 'parts' => null, 'part_num' => $part_num ];
		}
		// Multipart erkennen: erstes Element ist ein Array (Child-Part)
		if ( is_array( $tokens[0] ) ) {
			$children = [];
			$i = 0;
			while ( $i < count( $tokens ) && is_array( $tokens[ $i ] ) ) {
				$child_num = $part_num === '1' ? (string) ( $i + 1 ) : ( $part_num . '.' . ( $i + 1 ) );
				$children[] = self::bodystructure_from_tokens( $tokens[ $i ], $child_num );
				$i++;
			}
			$subtype = (string) ( $tokens[ $i ] ?? 'mixed' );
			return [
				'type'        => 1, // multipart
				'subtype'     => strtolower( $subtype ),
				'encoding'    => 0,
				'bytes'       => 0,
				'parameters'  => [],
				'dparameters' => [],
				'disposition' => '',
				'parts'       => $children,
				'part_num'    => $part_num,
			];
		}
		// Singlepart: type subtype (params) body-id body-description body-encoding body-size [text-lines|envelope|...] body-md5 (disposition) body-language body-location
		$type_str = strtolower( (string) ( $tokens[0] ?? 'application' ) );
		$subtype  = strtolower( (string) ( $tokens[1] ?? 'octet-stream' ) );
		$params_raw = $tokens[2] ?? null;
		$params = is_array( $params_raw ) ? self::params_pairs( $params_raw ) : [];
		$encoding_str = strtolower( (string) ( $tokens[5] ?? '7bit' ) );
		$bytes = is_int( $tokens[6] ?? null ) ? (int) $tokens[6] : 0;
		// Disposition liegt nach body-size; bei text + encoding. Fuer Attachment-Extraction
		// scannen wir hinten im Token-Array nach einem Array das wie ("attachment" ("filename" "...")) aussieht.
		$disposition = '';
		$dparameters = [];
		for ( $j = 7; $j < count( $tokens ); $j++ ) {
			if ( is_array( $tokens[ $j ] ) && isset( $tokens[ $j ][0] ) && is_string( $tokens[ $j ][0] ) ) {
				$maybe = strtoupper( (string) $tokens[ $j ][0] );
				if ( $maybe === 'ATTACHMENT' || $maybe === 'INLINE' ) {
					$disposition = $maybe;
					if ( isset( $tokens[ $j ][1] ) && is_array( $tokens[ $j ][1] ) ) {
						$dparameters = self::params_pairs( $tokens[ $j ][1] );
					}
					break;
				}
			}
		}
		return [
			'type'        => self::type_str_to_int( $type_str ),
			'subtype'     => $subtype,
			'encoding'    => self::encoding_str_to_int( $encoding_str ),
			'bytes'       => $bytes,
			'parameters'  => $params,
			'dparameters' => $dparameters,
			'disposition' => $disposition,
			'parts'       => null,
			'part_num'    => $part_num,
		];
	}

	private static function params_pairs( array $tokens ) : array {
		$out = [];
		for ( $i = 0; $i + 1 < count( $tokens ); $i += 2 ) {
			$k = (string) $tokens[ $i ];
			$v = (string) $tokens[ $i + 1 ];
			if ( $k !== '' ) { $out[ strtolower( $k ) ] = $v; }
		}
		return $out;
	}

	private static function type_str_to_int( string $t ) : int {
		$map = [ 'text' => 0, 'multipart' => 1, 'message' => 2, 'application' => 3, 'audio' => 4, 'image' => 5, 'video' => 6, 'other' => 7 ];
		return $map[ $t ] ?? 7;
	}

	private static function encoding_str_to_int( string $e ) : int {
		$map = [ '7bit' => 0, '8bit' => 1, 'binary' => 2, 'base64' => 3, 'quoted-printable' => 4 ];
		return $map[ $e ] ?? 5;
	}

	/**
	 * Waehlt den besten Text-Part aus einer parsed BODYSTRUCTURE.
	 * $prefer_plain: wenn true, bevorzuge text/plain (fuer Preview + fetch_body_text);
	 *                false = HTML bevorzugen (fuer fetch_body_html).
	 * $force_subtype: wenn gesetzt, nur Parts mit diesem subtype zulassen.
	 *
	 * @return array{part_num:string,encoding:int,subtype:string}|null
	 */
	private static function pick_text_part( array $structure, bool $prefer_plain = true, string $force_subtype = '' ) : ?array {
		$plain = null; $html = null;
		$walk = function ( array $node ) use ( &$walk, &$plain, &$html ) {
			if ( $node['parts'] ) {
				foreach ( $node['parts'] as $child ) { $walk( $child ); }
				return;
			}
			if ( $node['type'] !== 0 ) { return; }
			if ( $node['subtype'] === 'plain' && $plain === null ) {
				$plain = [ 'part_num' => $node['part_num'], 'encoding' => $node['encoding'], 'subtype' => 'plain' ];
			}
			if ( $node['subtype'] === 'html' && $html === null ) {
				$html = [ 'part_num' => $node['part_num'], 'encoding' => $node['encoding'], 'subtype' => 'html' ];
			}
		};
		$walk( $structure );
		if ( $force_subtype === 'plain' ) { return $plain; }
		if ( $force_subtype === 'html' )  { return $html; }
		if ( $prefer_plain ) { return $plain ?: $html; }
		return $html ?: $plain;
	}

	/**
	 * Extrahiert Attachment-Metadaten aus einer parsed BODYSTRUCTURE.
	 * @return array<int,array{part_num:string,filename:string,mime_type:string,size_bytes:int,encoding:string}>
	 */
	private static function structure_to_attachments( array $structure ) : array {
		$out = [];
		$walk = function ( array $node ) use ( &$walk, &$out ) {
			if ( $node['parts'] ) {
				foreach ( $node['parts'] as $child ) { $walk( $child ); }
				return;
			}
			$filename = '';
			if ( isset( $node['dparameters']['filename'] ) ) {
				$filename = ImapClient::decode_mime( (string) $node['dparameters']['filename'] );
			} elseif ( isset( $node['parameters']['name'] ) ) {
				$filename = ImapClient::decode_mime( (string) $node['parameters']['name'] );
			}
			$disp = strtoupper( (string) $node['disposition'] );
			$type = (int) $node['type'];
			$subtype = strtolower( (string) $node['subtype'] );
			// Reiner Text-Body ohne Disposition? → kein Anhang.
			$is_body_text = $type === 0 && in_array( $subtype, [ 'plain', 'html' ], true ) && $disp === '';
			if ( $is_body_text && $filename === '' ) { return; }
			// Ohne Dateiname UND ohne Attachment-Disposition → kein Anhang.
			if ( $filename === '' && $disp !== 'ATTACHMENT' && $disp !== 'INLINE' ) { return; }
			$primary = [ 0 => 'text', 1 => 'multipart', 2 => 'message', 3 => 'application', 4 => 'audio', 5 => 'image', 6 => 'video', 7 => 'other' ];
			$enc_name = [ 0 => '7bit', 1 => '8bit', 2 => 'binary', 3 => 'base64', 4 => 'quoted-printable', 5 => 'other' ];
			$out[] = [
				'part_num'   => (string) $node['part_num'],
				'filename'   => $filename,
				'mime_type'  => ( $primary[ $type ] ?? 'other' ) . '/' . ( $subtype !== '' ? $subtype : 'octet-stream' ),
				'size_bytes' => (int) $node['bytes'],
				'encoding'   => $enc_name[ (int) $node['encoding'] ] ?? 'other',
			];
		};
		$walk( $structure );
		return $out;
	}
}
