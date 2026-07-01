<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap;

/**
 * Schlanker pure-PHP IMAP-Client mit SASL XOAUTH2 (RFC 7628 / 5092).
 *
 * Existiert, weil die PHP-imap-Extension auf wp.itdatex.support gegen
 * c-client 2007f gebaut ist und OP_XOAUTH2 nicht definiert ist — Microsoft
 * 365 (und Gmail) brauchen aber zwingend XOAUTH2, da Basic Auth seit
 * Sept 2022 fuer IMAP abgeschaltet ist.
 *
 * Public-API ist API-kompatibel mit {@see ImapClient} (connect, probe,
 * uids_since, fetch_message, close), damit der PullService nichts ueber
 * den verwendeten Backend-Client wissen muss.
 *
 * Was wir tun (Minimal-IMAP):
 *  - TLS-Connect zu host:993, * OK greeting lesen
 *  - AUTHENTICATE XOAUTH2 <base64(user=...\x01auth=Bearer ...\x01\x01)>
 *  - SELECT <folder>
 *  - UID FETCH <range> (UID)               → fuer uids_since
 *  - UID FETCH <uid> (BODY.PEEK[HEADER])   → Header-Parse via ImapClient::parse_headers
 *  - UID FETCH <uid> (BODYSTRUCTURE)       → Mime-Struktur fuer Preview-Auswahl
 *  - UID FETCH <uid> BODY.PEEK[<part>]     → Body-Part fuer Preview
 *  - LOGOUT
 *
 * Was wir NICHT tun: IDLE, NAMESPACE, COMPRESS, ACL, Quotas, Threading,
 * Multi-Mailbox-Pipelining. Reicht fuer unsere Pull-Logik.
 */
final class XOauth2ImapClient {

	private $stream = null;
	private int $tag_seq = 0;
	/** @var array<string,bool> Cache der Server-Capabilities (lowercase) */
	private array $caps = [];

	public function __construct(
		private string $host,
		private int $port,
		private string $encryption, // ssl|tls
		private string $folder,
		private string $username,
		private string $access_token,
	) {}

	public function connect( int $timeout = 15 ) : void {
		if ( $this->host === '' || $this->username === '' || $this->access_token === '' ) {
			throw new \RuntimeException( 'XOAUTH2: Zugangsdaten unvollstaendig.' );
		}
		$transport = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
		$errno = 0; $errstr = '';
		$ctx = stream_context_create( [
			'ssl' => [
				'verify_peer'       => true,
				'verify_peer_name'  => true,
				'allow_self_signed' => false,
				'SNI_enabled'       => true,
			],
		] );
		$stream = @stream_socket_client(
			$transport . '://' . $this->host . ':' . $this->port,
			$errno, $errstr, $timeout,
			STREAM_CLIENT_CONNECT, $ctx
		);
		if ( ! $stream ) {
			throw new \RuntimeException( sprintf( 'XOAUTH2-Connect fehlgeschlagen (%d): %s', $errno, $errstr ) );
		}
		stream_set_timeout( $stream, $timeout );
		$this->stream = $stream;

		$greet = $this->read_line();
		if ( ! str_starts_with( $greet, '* OK' ) ) {
			$this->close();
			throw new \RuntimeException( 'IMAP-Greeting unerwartet: ' . trim( $greet ) );
		}

		if ( $this->encryption === 'tls' ) {
			$tag = $this->send( 'STARTTLS' );
			$resp = $this->read_until_tag( $tag );
			if ( ! preg_match( '/^' . $tag . ' OK/m', $resp ) ) {
				$this->close();
				throw new \RuntimeException( 'STARTTLS abgelehnt: ' . trim( $resp ) );
			}
			if ( ! @stream_socket_enable_crypto( $stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT ) ) {
				$this->close();
				throw new \RuntimeException( 'STARTTLS-Handshake fehlgeschlagen.' );
			}
		}

		// SASL XOAUTH2 — RFC 7628 / Google + Microsoft Konvention.
		$sasl = 'user=' . $this->username . "\x01" . 'auth=Bearer ' . $this->access_token . "\x01\x01";
		$tag  = $this->send( 'AUTHENTICATE XOAUTH2 ' . base64_encode( $sasl ) );
		$resp = $this->read_until_tag( $tag );
		if ( strpos( $resp, "\n+ " ) !== false || str_starts_with( $resp, '+ ' ) ) {
			// Server schickt Challenge mit base64-JSON-Fehler — leere Antwort zum Abschluss.
			fwrite( $stream, "\r\n" );
			$resp .= $this->read_until_tag( $tag );
		}
		if ( ! preg_match( '/^' . $tag . ' OK/m', $resp ) ) {
			$this->close();
			throw new \RuntimeException( 'XOAUTH2 AUTH abgelehnt: ' . trim( $resp ) );
		}
		$this->absorb_capabilities( $resp );
		// Wenn Server keine unsolicited CAPABILITY in AUTH-OK geschickt hat, holen wir sie explizit.
		if ( ! $this->caps ) {
			$ctag  = $this->send( 'CAPABILITY' );
			$cresp = $this->read_until_tag( $ctag );
			$this->absorb_capabilities( $cresp );
		}

		$tag  = $this->send( 'SELECT ' . $this->quote_mailbox( $this->folder ) );
		$resp = $this->read_until_tag( $tag );
		if ( ! preg_match( '/^' . $tag . ' OK/m', $resp ) ) {
			$this->close();
			throw new \RuntimeException( 'SELECT ' . $this->folder . ' fehlgeschlagen: ' . trim( $resp ) );
		}
	}

	private function absorb_capabilities( string $resp ) : void {
		if ( ! preg_match( '/\*\s+CAPABILITY\s+([^\r\n]+)/i', $resp, $m ) ) {
			return;
		}
		foreach ( preg_split( '/\s+/', strtolower( trim( $m[1] ) ) ) ?: [] as $cap ) {
			if ( $cap !== '' ) { $this->caps[ $cap ] = true; }
		}
	}

	private function has_capability( string $name ) : bool {
		return isset( $this->caps[ strtolower( $name ) ] );
	}

	public function close() : void {
		if ( $this->stream ) {
			@fwrite( $this->stream, 'A999 LOGOUT' . "\r\n" );
			@fclose( $this->stream );
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
	 * Schickt LIST "" "*" und parst Folder + Attribute.
	 * Filtert \Noselect (reine Container).
	 *
	 * Beispiel-Response:
	 *   * LIST (\HasNoChildren) "/" "INBOX"
	 *   * LIST (\HasChildren \Noselect) "/" "[Gmail]"
	 *   * LIST (\HasNoChildren \Junk) "/" "[Gmail]/Spam"
	 *
	 * @return list<array{name:string,display:string,attributes:string[]}>
	 */
	public function list_folders() : array {
		if ( ! $this->stream ) { $this->connect(); }
		$tag  = $this->send( 'LIST "" "*"' );
		$resp = $this->read_until_tag( $tag );
		if ( ! preg_match( '/^' . $tag . ' OK/m', $resp ) ) { return []; }
		$out = [];
		foreach ( explode( "\n", $resp ) as $line ) {
			$line = trim( $line );
			if ( ! preg_match( '/^\*\s+LIST\s+\(([^)]*)\)\s+("[^"]*"|\S+)\s+("([^"\\\\]|\\\\.)*"|\S+)\s*$/i', $line, $m ) ) {
				continue;
			}
			$attrs_raw = trim( $m[1] );
			$name_raw  = $m[3];
			// String-Wert dequoten und Escapes auflösen
			if ( strlen( $name_raw ) >= 2 && $name_raw[0] === '"' && substr( $name_raw, -1 ) === '"' ) {
				$name = stripcslashes( substr( $name_raw, 1, -1 ) );
			} else {
				$name = $name_raw;
			}
			if ( $name === '' ) { continue; }
			$attrs = $attrs_raw === '' ? [] : ( preg_split( '/\s+/', $attrs_raw ) ?: [] );
			$attrs_lower = array_map( 'strtolower', $attrs );
			if ( in_array( '\\noselect', $attrs_lower, true ) ) { continue; }
			$out[] = [
				'name'       => $name,
				'display'    => $name,
				'attributes' => $attrs,
			];
		}
		return $out;
	}

	public function probe() : array {
		$this->connect();
		// SELECT-Response enthielt EXISTS — wir machen lieber einen sauberen STATUS.
		$tag  = $this->send( 'STATUS ' . $this->quote_mailbox( $this->folder ) . ' (MESSAGES)' );
		$resp = $this->read_until_tag( $tag );
		$nmsg = 0;
		if ( preg_match( '/MESSAGES\s+(\d+)/', $resp, $m ) ) {
			$nmsg = (int) $m[1];
		}
		$this->close();
		return [ 'messages' => $nmsg, 'folder' => $this->folder ];
	}

	public function uids_since( int $since_uid, int $limit = 100 ) : array {
		if ( ! $this->stream ) { $this->connect(); }
		$range = $since_uid > 0 ? ( ( $since_uid + 1 ) . ':*' ) : '1:*';
		$tag   = $this->send( 'UID FETCH ' . $range . ' (UID)' );
		$resp  = $this->read_until_tag( $tag );
		if ( ! preg_match( '/^' . $tag . ' OK/m', $resp ) ) {
			return [];
		}
		$uids = [];
		// Format pro Zeile: * <seq> FETCH (UID <uid>)
		foreach ( explode( "\n", $resp ) as $line ) {
			if ( preg_match( '/UID\s+(\d+)/', $line, $m ) ) {
				$uid = (int) $m[1];
				if ( $uid > $since_uid ) {
					$uids[ $uid ] = true;
				}
			}
		}
		$uids = array_keys( $uids );
		sort( $uids, SORT_NUMERIC );
		if ( count( $uids ) > $limit ) {
			$uids = array_slice( $uids, 0, $limit );
		}
		return $uids;
	}

	/**
	 * Idempotenter CREATE des Zielordners. Wenn der Server NO antwortet
	 * (z.B. ALREADYEXISTS), prüfen wir per STATUS, dass der Folder
	 * tatsächlich existiert — sonst werfen wir.
	 */
	public function ensure_folder( string $name ) : void {
		if ( ! $this->stream ) { $this->connect(); }
		$qname = $this->quote_mailbox( $name );
		$tag   = $this->send( 'CREATE ' . $qname );
		$resp  = $this->read_until_tag( $tag );
		if ( preg_match( '/^' . $tag . ' OK/m', $resp ) ) {
			// Subscribe-Versuch — freundlich für Mail-Clients, kein Hard-Fail.
			$stag = $this->send( 'SUBSCRIBE ' . $qname );
			$this->read_until_tag( $stag );
			return;
		}
		// NO → könnte ALREADYEXISTS sein. STATUS bestätigt Existenz.
		$stag  = $this->send( 'STATUS ' . $qname . ' (MESSAGES)' );
		$sresp = $this->read_until_tag( $stag );
		if ( preg_match( '/^' . $stag . ' OK/m', $sresp ) ) {
			return;
		}
		throw new \RuntimeException( 'CREATE ' . $name . ' fehlgeschlagen: ' . trim( $resp ) );
	}

	/**
	 * UID MOVE wenn der Server RFC 6851 MOVE supported, sonst Fallback auf
	 * UID COPY + UID STORE +FLAGS (\Deleted). KEIN EXPUNGE — Quelle bleibt
	 * für Undo wiederherstellbar (zumindest bis ein anderer Client expunged).
	 *
	 * Parst UIDPLUS COPYUID-Response (RFC 4315), um die Ziel-UID
	 * zurückzugeben. Wenn UIDPLUS fehlt, liefert die Methode 0 und der
	 * Caller muss die Ziel-UID per Message-ID-Search im Ziel-Folder
	 * nachziehen.
	 *
	 * @return int Ziel-UID falls bekannt, sonst 0
	 */
	public function move_uid( int $uid, string $target_folder ) : int {
		if ( ! $this->stream ) { $this->connect(); }
		$qtarget = $this->quote_mailbox( $target_folder );

		if ( $this->has_capability( 'move' ) ) {
			$tag  = $this->send( 'UID MOVE ' . $uid . ' ' . $qtarget );
			$resp = $this->read_until_tag( $tag );
			if ( ! preg_match( '/^' . $tag . ' OK/m', $resp ) ) {
				throw new \RuntimeException( 'UID MOVE fehlgeschlagen: ' . trim( $resp ) );
			}
			return self::extract_copyuid_target( $resp );
		}

		// Fallback: COPY + STORE \Deleted, ohne EXPUNGE.
		$tag  = $this->send( 'UID COPY ' . $uid . ' ' . $qtarget );
		$resp = $this->read_until_tag( $tag );
		if ( ! preg_match( '/^' . $tag . ' OK/m', $resp ) ) {
			throw new \RuntimeException( 'UID COPY fehlgeschlagen: ' . trim( $resp ) );
		}
		$target_uid = self::extract_copyuid_target( $resp );

		$stag  = $this->send( 'UID STORE ' . $uid . ' +FLAGS (\\Deleted)' );
		$sresp = $this->read_until_tag( $stag );
		if ( ! preg_match( '/^' . $stag . ' OK/m', $sresp ) ) {
			// Mail liegt jetzt im Ziel UND in der Quelle (ohne \Deleted-Marker) —
			// nicht ideal, aber kein Datenverlust. Wirft hoch, Caller entscheidet.
			throw new \RuntimeException( 'UID STORE \Deleted fehlgeschlagen: ' . trim( $sresp ) );
		}
		return $target_uid;
	}

	/**
	 * Parst die UIDPLUS-COPYUID-Antwort aus einem OK-Response:
	 *   "A0007 OK [COPYUID <uidvalidity> <src-uids> <dst-uids>] ..."
	 * Liefert die erste Ziel-UID oder 0.
	 */
	private static function extract_copyuid_target( string $resp ) : int {
		if ( ! preg_match( '/\[COPYUID\s+\d+\s+\S+\s+(\d+(?::\d+)?(?:,\d+(?::\d+)?)*)\s*\]/i', $resp, $m ) ) {
			return 0;
		}
		// dst-uids kann ein Range "12:14" oder "12,13,14" sein — wir nehmen das erste Token.
		$first = preg_split( '/[,:]/', $m[1] );
		return $first ? (int) $first[0] : 0;
	}

	/**
	 * Sucht nach RFC-822 Message-ID im aktuell selektierten Folder.
	 * Liefert die größte (zuletzt eingefügte) UID oder 0.
	 *
	 * Bei Servern, die HEADER-Search nicht supporten, Fallback auf
	 * UID FETCH 1:* (BODY.PEEK[HEADER.FIELDS (MESSAGE-ID)]) und Match.
	 */
	public function find_uid_by_message_id( string $message_id ) : int {
		if ( ! $this->stream ) { $this->connect(); }
		if ( $message_id === '' ) { return 0; }
		$esc  = str_replace( '"', '\\"', $message_id );
		$tag  = $this->send( 'UID SEARCH HEADER "Message-ID" "<' . $esc . '>"' );
		$resp = $this->read_until_tag( $tag );
		if ( preg_match( '/^' . $tag . ' OK/m', $resp ) ) {
			$uids = [];
			foreach ( explode( "\n", $resp ) as $line ) {
				if ( preg_match( '/^\*\s+SEARCH\s+(.+)$/i', trim( $line ), $m ) ) {
					foreach ( preg_split( '/\s+/', trim( $m[1] ) ) ?: [] as $tok ) {
						if ( ctype_digit( $tok ) ) { $uids[] = (int) $tok; }
					}
				}
			}
			if ( $uids ) {
				return max( $uids );
			}
		}
		// Fallback: header-only fetch + match. Brute-force, aber funktioniert
		// gegen Server ohne HEADER-Search-Support.
		$tag  = $this->send( 'UID FETCH 1:* (UID BODY.PEEK[HEADER.FIELDS (MESSAGE-ID)])' );
		$resp = $this->read_until_tag( $tag );
		if ( ! preg_match( '/^' . $tag . ' OK/m', $resp ) ) { return 0; }
		$cur_uid = 0; $best = 0;
		foreach ( explode( "\n", $resp ) as $line ) {
			if ( preg_match( '/\* \d+ FETCH .*UID (\d+)/i', $line, $m ) ) {
				$cur_uid = (int) $m[1];
			}
			if ( $cur_uid > 0 && preg_match( '/Message-ID:\s*<([^>]+)>/i', $line, $m ) ) {
				if ( $m[1] === $message_id && $cur_uid > $best ) {
					$best = $cur_uid;
				}
			}
		}
		return $best;
	}

	/**
	 * Existenz-Check für eine UID im aktuell selektierten Folder.
	 * Liefert true wenn die Mail noch unter der UID erreichbar ist.
	 */
	public function uid_exists( int $uid ) : bool {
		if ( ! $this->stream ) { $this->connect(); }
		$tag  = $this->send( 'UID FETCH ' . $uid . ' (UID)' );
		$resp = $this->read_until_tag( $tag );
		if ( ! preg_match( '/^' . $tag . ' OK/m', $resp ) ) { return false; }
		return (bool) preg_match( '/\* \d+ FETCH .*UID ' . $uid . '\b/i', $resp );
	}

	/**
	 * Endgültiges Löschen einer Mail im aktuell selektierten Folder:
	 * UID STORE +FLAGS \Deleted + (UID) EXPUNGE. Für den Endgültig-löschen-
	 * Button nach dem Undo-Fenster.
	 *
	 * Wenn der Server UIDPLUS supported, nutzen wir UID EXPUNGE (RFC 4315) —
	 * das räumt nur die frisch \Deleted-markierte Mail ab. Sonst Fallback
	 * auf folder-weites EXPUNGE, was in einem MailGuard-only-Quarantäne-
	 * Folder unkritisch ist.
	 */
	public function expunge_uid( int $uid ) : void {
		if ( ! $this->stream ) { $this->connect(); }
		$stag  = $this->send( 'UID STORE ' . $uid . ' +FLAGS (\\Deleted)' );
		$sresp = $this->read_until_tag( $stag );
		if ( ! preg_match( '/^' . $stag . ' OK/m', $sresp ) ) {
			throw new \RuntimeException( 'UID STORE \Deleted fehlgeschlagen fuer UID ' . $uid . ': ' . trim( $sresp ) );
		}
		if ( $this->has_capability( 'uidplus' ) ) {
			$etag  = $this->send( 'UID EXPUNGE ' . $uid );
			$eresp = $this->read_until_tag( $etag );
			if ( preg_match( '/^' . $etag . ' OK/m', $eresp ) ) { return; }
			// UID EXPUNGE abgelehnt — auf klassischen EXPUNGE zurueckfallen.
		}
		$etag  = $this->send( 'EXPUNGE' );
		$eresp = $this->read_until_tag( $etag );
		if ( ! preg_match( '/^' . $etag . ' OK/m', $eresp ) ) {
			throw new \RuntimeException( 'EXPUNGE fehlgeschlagen: ' . trim( $eresp ) );
		}
	}

	/**
	 * Hilfsmethode für den QuarantineService: wechselt den selektierten
	 * Folder ohne neuen Connect. Wird beim Undo gebraucht, wenn wir vom
	 * Quarantäne-Folder zurück in die Source-Inbox müssen.
	 */
	public function select_folder( string $name ) : void {
		if ( ! $this->stream ) { $this->connect(); return; }
		$tag  = $this->send( 'SELECT ' . $this->quote_mailbox( $name ) );
		$resp = $this->read_until_tag( $tag );
		if ( ! preg_match( '/^' . $tag . ' OK/m', $resp ) ) {
			throw new \RuntimeException( 'SELECT ' . $name . ' fehlgeschlagen: ' . trim( $resp ) );
		}
	}

	public function fetch_message( int $uid ) : array {
		if ( ! $this->stream ) { $this->connect(); }
		$raw_headers = $this->fetch_literal( $uid, 'BODY.PEEK[HEADER]' );
		if ( $raw_headers === null ) {
			throw new \RuntimeException( 'XOAUTH2 fetch header failed UID ' . $uid );
		}
		$parsed = ImapClient::parse_headers( $raw_headers );

		$preview   = '';
		$structure = $this->fetch_structure( $uid );
		if ( $structure ) {
			$preview = $this->extract_preview( $uid, $structure, 500 );
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

	/** Holt einen Literal-Body (BODY.PEEK[xxx]) — IMAP {n}\r\n<n bytes>. */
	private function fetch_literal( int $uid, string $section ) : ?string {
		$tag = $this->send( 'UID FETCH ' . $uid . ' (' . $section . ')' );
		// Erste Antwortzeile bringt entweder " {N}" am Ende (Literal folgt) oder " "..."" inline.
		$first = $this->read_line();
		while ( $first !== '' && ! str_starts_with( $first, '* ' ) && ! str_starts_with( $first, $tag . ' ' ) ) {
			$first = $this->read_line();
		}
		if ( str_starts_with( $first, $tag . ' ' ) ) {
			return null;
		}
		// Literal-Erkennung: ... {NNNN}\r\n
		if ( preg_match( '/\{(\d+)\}\s*$/', $first, $m ) ) {
			$len = (int) $m[1];
			$buf = '';
			while ( strlen( $buf ) < $len ) {
				$chunk = fread( $this->stream, $len - strlen( $buf ) );
				if ( $chunk === false || $chunk === '' ) {
					return null;
				}
				$buf .= $chunk;
			}
			// Schliessende )\r\n + Tag-OK-Zeile noch wegkonsumieren.
			$this->read_until_tag( $tag );
			return $buf;
		}
		// Inline-Quoted: " ... "  — meistens nicht der Fall fuer Header/Body.
		if ( preg_match( '/"((?:[^"\\\\]|\\\\.)*)"/', $first, $m ) ) {
			$this->read_until_tag( $tag );
			return stripslashes( $m[1] );
		}
		$this->read_until_tag( $tag );
		return null;
	}

	/** @return array{parts?:array,subtype?:string,encoding?:int}|null */
	private function fetch_structure( int $uid ) : ?array {
		$tag  = $this->send( 'UID FETCH ' . $uid . ' (BODYSTRUCTURE)' );
		$resp = $this->read_until_tag( $tag );
		if ( ! preg_match( '/BODYSTRUCTURE\s+(.+?)\)\r?\n' . $tag . ' OK/s', $resp, $m ) ) {
			// Fallback: nur die FETCH-Zeile
			if ( ! preg_match( '/BODYSTRUCTURE\s+(.+)\)$/m', $resp, $m ) ) {
				return null;
			}
		}
		$tree = BodyStructure::parse( '(' . trim( $m[1] ) . ')' );
		return $tree;
	}

	/**
	 * Wandert die Body-Struktur ab, pickt bevorzugt text/plain, fallback text/html
	 * (HTML wird per wp_strip_all_tags entkleidet) und holt den Body-Part.
	 */
	private function extract_preview( int $uid, array $structure, int $max ) : string {
		$best_plain = null; $best_html = null;
		$walk = function ( array $node, string $prefix ) use ( &$walk, &$best_plain, &$best_html ) {
			if ( ! empty( $node['parts'] ) ) {
				foreach ( $node['parts'] as $i => $sub ) {
					$num = $prefix === '' ? (string) ( $i + 1 ) : ( $prefix . '.' . ( $i + 1 ) );
					if ( ! empty( $sub['parts'] ) ) {
						$walk( $sub, $num );
					} else {
						$type    = strtolower( $sub['type'] ?? '' );
						$subtype = strtolower( $sub['subtype'] ?? '' );
						if ( $type === 'text' && $subtype === 'plain' && $best_plain === null ) {
							$best_plain = [ $num, $sub['encoding'] ?? '' ];
						}
						if ( $type === 'text' && $subtype === 'html' && $best_html === null ) {
							$best_html = [ $num, $sub['encoding'] ?? '' ];
						}
					}
				}
			} else {
				$type    = strtolower( $node['type'] ?? '' );
				$subtype = strtolower( $node['subtype'] ?? '' );
				if ( $type === 'text' && $subtype === 'plain' && $best_plain === null ) {
					$best_plain = [ '1', $node['encoding'] ?? '' ];
				}
				if ( $type === 'text' && $subtype === 'html' && $best_html === null ) {
					$best_html = [ '1', $node['encoding'] ?? '' ];
				}
			}
		};
		$walk( $structure, '' );
		$pick = $best_plain ?: $best_html;
		if ( ! $pick ) { return ''; }
		$body = $this->fetch_literal( $uid, 'BODY.PEEK[' . $pick[0] . ']' );
		if ( $body === null ) { return ''; }
		$body = self::decode_transfer( $body, (string) $pick[1] );
		if ( $pick === $best_html ) {
			$body = wp_strip_all_tags( $body );
		}
		return ImapClient::clean_preview( $body, $max );
	}

	private static function decode_transfer( string $body, string $encoding ) : string {
		$e = strtolower( $encoding );
		if ( $e === 'base64' )           { return (string) base64_decode( $body, true ); }
		if ( $e === 'quoted-printable' ) { return (string) quoted_printable_decode( $body ); }
		return $body;
	}

	private function send( string $cmd ) : string {
		$tag = sprintf( 'A%04d', ++$this->tag_seq );
		fwrite( $this->stream, $tag . ' ' . $cmd . "\r\n" );
		return $tag;
	}

	private function read_line() : string {
		$line = fgets( $this->stream, 8192 );
		return $line === false ? '' : $line;
	}

	private function read_until_tag( string $tag, int $max_lines = 50000 ) : string {
		$buf = ''; $n = 0;
		while ( $n++ < $max_lines ) {
			$line = $this->read_line();
			if ( $line === '' ) { break; }
			$buf .= $line;
			if ( preg_match( '/^' . preg_quote( $tag, '/' ) . ' (OK|NO|BAD)/m', $line ) ) {
				break;
			}
			// Literal handling: wenn Zeile mit {N}\r\n endet, N bytes konsumieren.
			if ( preg_match( '/\{(\d+)\}\s*$/', rtrim( $line ), $m ) ) {
				$need = (int) $m[1];
				$got  = '';
				while ( strlen( $got ) < $need ) {
					$chunk = fread( $this->stream, $need - strlen( $got ) );
					if ( $chunk === false || $chunk === '' ) { break; }
					$got .= $chunk;
				}
				$buf .= $got;
			}
		}
		return $buf;
	}

	private function quote_mailbox( string $name ) : string {
		// Sehr konservativ: ASCII-only, quoten + Escapes.
		return '"' . str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], $name ) . '"';
	}
}
