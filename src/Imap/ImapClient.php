<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap;

/**
 * Minimaler Wrapper um die PHP-imap-Extension fuer Test-Connect.
 * Wirft Exceptions statt PHP-Warnings.
 *
 * Phase 4 wird einen umfangreicheren Reader bauen (Header-Parsing, Body-Preview);
 * fuer Phase 3 reicht: connect + close.
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

	public function connect( int $timeout = 8 ) : void {
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
		// imap_timeout setzt fuer alle Verbindungstypen separate Limits.
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

	/** Test-Connect: liefert kurze Info zur Mailbox (Anzahl Mails). */
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
}
