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

	private static function parse_headers( string $raw ) : array {
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

	private static function decode_mime( string $s ) : string {
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

	private static function clean_preview( string $s, int $max ) : string {
		$s = preg_replace( '/\s+/u', ' ', $s ) ?: '';
		$s = trim( $s );
		if ( mb_strlen( $s ) > $max ) {
			$s = mb_substr( $s, 0, $max - 1 ) . '…';
		}
		return $s;
	}
}
