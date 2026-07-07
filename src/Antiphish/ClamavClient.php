<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Antiphish;

/**
 * clamd INSTREAM Client. Spricht das native clamd-Protokoll ueber TCP oder
 * Unix-Socket — keine Prozess-Fork-Latenz wie bei clamscan. Antworten von clamd
 * sind kurze Text-Zeilen, z.B. "stream: Win.Test.EICAR_HDB-1 FOUND".
 *
 * Kein Byte-Persist: der Aufrufer streamt die Daten direkt aus IMAP, wir
 * schreiben sie in Chunks ins Socket und werfen sie danach weg.
 */
final class ClamavClient {

	// clamd verlangt Chunks < StreamMaxLength; 64 KiB ist Praxis-Kompromiss.
	private const CHUNK_SIZE = 65536;

	public function __construct(
		private string $socket,          // tcp://host:port ODER unix:///pfad
		private int $timeout_seconds = 15,
		private int $max_bytes = 26214400, // 25 MiB — muss <= clamd StreamMaxLength sein
	) {}

	/**
	 * Health-Check via PING (clamd antwortet "PONG"). Gibt Version zurueck, sonst null.
	 */
	public function ping() : ?string {
		try {
			$sock = $this->open();
		} catch ( \RuntimeException $e ) {
			return null;
		}
		@fwrite( $sock, "zPING\0" );
		$reply = self::read_line( $sock );
		if ( trim( (string) $reply ) !== 'PONG' ) {
			@fclose( $sock );
			return null;
		}
		@fclose( $sock );

		try {
			$sock2 = $this->open();
		} catch ( \RuntimeException $e ) {
			return 'unknown';
		}
		@fwrite( $sock2, "zVERSION\0" );
		$version = trim( (string) self::read_line( $sock2 ) );
		@fclose( $sock2 );
		return $version !== '' ? $version : 'unknown';
	}

	/**
	 * Streamt Bytes an clamd INSTREAM. Bricht ab, wenn max_bytes ueberschritten.
	 *
	 * @return array{status:string,signature:?string,detail:?string}
	 *   status: 'clean' | 'infected' | 'error' | 'too_large'
	 */
	public function scan_bytes( string $bytes ) : array {
		if ( strlen( $bytes ) > $this->max_bytes ) {
			return [ 'status' => 'too_large', 'signature' => null, 'detail' => sprintf( 'Anhang %d bytes ueberschreitet av_max_bytes=%d', strlen( $bytes ), $this->max_bytes ) ];
		}
		try {
			$sock = $this->open();
		} catch ( \RuntimeException $e ) {
			return [ 'status' => 'error', 'signature' => null, 'detail' => $e->getMessage() ];
		}

		// Null-prefixed Kommando (zINSTREAM) — kein Newline-Trimming durch clamd.
		if ( @fwrite( $sock, "zINSTREAM\0" ) === false ) {
			@fclose( $sock );
			return [ 'status' => 'error', 'signature' => null, 'detail' => 'INSTREAM-Header konnte nicht gesendet werden' ];
		}

		// Format: [4-Byte-BE-Length][chunk-bytes]... [0000 Terminator]
		$offset = 0;
		$total  = strlen( $bytes );
		while ( $offset < $total ) {
			$chunk = substr( $bytes, $offset, self::CHUNK_SIZE );
			$len   = strlen( $chunk );
			$hdr   = pack( 'N', $len );
			if ( @fwrite( $sock, $hdr . $chunk ) === false ) {
				@fclose( $sock );
				return [ 'status' => 'error', 'signature' => null, 'detail' => 'Chunk-Write fehlgeschlagen' ];
			}
			$offset += $len;
		}
		@fwrite( $sock, pack( 'N', 0 ) );

		$reply = trim( (string) self::read_line( $sock ) );
		@fclose( $sock );

		if ( $reply === '' ) {
			return [ 'status' => 'error', 'signature' => null, 'detail' => 'Leere Antwort von clamd' ];
		}
		// "stream: OK" oder "stream: <sig> FOUND" oder "stream: <detail> ERROR"
		if ( str_ends_with( $reply, ' OK' ) ) {
			return [ 'status' => 'clean', 'signature' => null, 'detail' => null ];
		}
		if ( preg_match( '/^stream:\s*(.+?)\s+FOUND$/', $reply, $m ) ) {
			return [ 'status' => 'infected', 'signature' => trim( $m[1] ), 'detail' => null ];
		}
		if ( preg_match( '/^stream:\s*(.+?)\s+ERROR$/', $reply, $m ) ) {
			return [ 'status' => 'error', 'signature' => null, 'detail' => 'clamd ERROR: ' . trim( $m[1] ) ];
		}
		return [ 'status' => 'error', 'signature' => null, 'detail' => 'Unerwartete clamd-Antwort: ' . $reply ];
	}

	/**
	 * Oeffnet Socket zu clamd. Unterstuetzt tcp://host:port, unix:///pfad und
	 * (Kurzform) host:port sowie /absolute/pfad.sock.
	 *
	 * @return resource
	 */
	private function open() {
		$target = trim( $this->socket );
		if ( $target === '' ) {
			throw new \RuntimeException( 'clamd-Socket nicht konfiguriert' );
		}
		// Normalisieren: nackter Pfad → unix://; nackt host:port → tcp://.
		if ( ! preg_match( '#^(tcp|unix)://#', $target ) ) {
			if ( str_starts_with( $target, '/' ) ) {
				$target = 'unix://' . $target;
			} else {
				$target = 'tcp://' . $target;
			}
		}
		$errno  = 0;
		$errstr = '';
		$sock = @stream_socket_client( $target, $errno, $errstr, (float) $this->timeout_seconds );
		if ( $sock === false ) {
			throw new \RuntimeException( sprintf( 'clamd-Verbindung fehlgeschlagen (%s): %s', $target, $errstr !== '' ? $errstr : (string) $errno ) );
		}
		stream_set_timeout( $sock, $this->timeout_seconds );
		return $sock;
	}

	/**
	 * Liest bis Newline ODER Null-Byte (clamd terminiert Antworten mit \0
	 * bei zBefehlen). fgets stoppt nur an \n, deswegen manuell.
	 *
	 * @param resource $sock
	 */
	private static function read_line( $sock ) : string {
		$buf = '';
		while ( ! feof( $sock ) ) {
			$c = @fread( $sock, 1 );
			if ( $c === false || $c === '' ) { break; }
			if ( $c === "\0" || $c === "\n" ) { break; }
			$buf .= $c;
			if ( strlen( $buf ) > 4096 ) { break; }
		}
		return $buf;
	}
}
