<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap;

use Itdatex\Mailguard\Oauth\GoogleClient;
use Itdatex\Mailguard\Oauth\MicrosoftClient;

/**
 * Liefert den passenden IMAP-Client fuer einen Account-Row.
 *
 *  - auth_type = 'basic'           → {@see ImapClient} (PHP-imap-Extension)
 *  - auth_type = 'oauth_microsoft' → {@see XOauth2ImapClient} mit Access-Token
 *
 * Bei OAuth wird das Access-Token bei Bedarf vor Rueckgabe per Refresh-Token
 * erneuert. Wenn der Refresh selbst scheitert, wirft die Methode — der Caller
 * (PullService / accounts_test) fungiert die Fehlerlage dann als
 * 'reauth_required' und markiert den Account.
 */
final class ClientFactory {

	/**
	 * @return object Hat connect/probe/uids_since/fetch_message/close — kompatibel
	 *                zwischen {@see ImapClient} und {@see XOauth2ImapClient}.
	 */
	/**
	 * Default-Folder aus dem Account-Row (Legacy-kompatibel — fuer Aufrufer
	 * die noch nicht Folder-bewusst sind). Neue Aufrufer sollten
	 * {@see for_account_folder} nutzen.
	 */
	public static function for_account( array $row ) {
		return self::build( $row, (string) ( $row['folder'] ?? 'INBOX' ) );
	}

	/**
	 * Variante mit explizitem Folder-Override — fuer den PullService, der
	 * pro Folder einen eigenen Client bekommt.
	 */
	public static function for_account_folder( array $row, string $folder_name ) {
		return self::build( $row, $folder_name ?: 'INBOX' );
	}

	private static function build( array $row, string $folder_name ) {
		$auth = (string) ( $row['auth_type'] ?? 'basic' );

		if ( $auth === 'basic' ) {
			$plain = Crypto::decrypt( (string) $row['password_enc'] );
			if ( $plain === '' ) {
				throw new \RuntimeException( 'no_password_stored' );
			}
			return new ImapClient(
				(string) $row['host'],
				(int)    $row['port'],
				(string) $row['encryption'],
				$folder_name,
				(string) $row['username'],
				$plain
			);
		}

		if ( $auth === 'oauth_microsoft' ) {
			$token = self::ensure_fresh_oauth_token( $row, 'microsoft' );
			return new XOauth2ImapClient(
				(string) ( $row['host'] ?: 'outlook.office365.com' ),
				(int)    ( $row['port'] ?: 993 ),
				(string) ( $row['encryption'] ?: 'ssl' ),
				$folder_name,
				(string) $row['username'],
				$token
			);
		}

		if ( $auth === 'oauth_google' ) {
			$token = self::ensure_fresh_oauth_token( $row, 'google' );
			return new XOauth2ImapClient(
				(string) ( $row['host'] ?: 'imap.gmail.com' ),
				(int)    ( $row['port'] ?: 993 ),
				(string) ( $row['encryption'] ?: 'ssl' ),
				$folder_name,
				(string) $row['username'],
				$token
			);
		}

		throw new \RuntimeException( 'unsupported_auth_type:' . $auth );
	}

	/**
	 * Provider-agnostischer Token-Refresh. Buffer 60 s gegen Clock-Skew,
	 * schreibt neue Tokens zurueck in die DB.
	 */
	private static function ensure_fresh_oauth_token( array $row, string $provider ) : string {
		$expires_at = isset( $row['oauth_token_expires_at'] ) ? strtotime( (string) $row['oauth_token_expires_at'] . ' UTC' ) : 0;
		$access     = Crypto::decrypt( (string) ( $row['oauth_access_token_enc'] ?? '' ) );

		if ( $access !== '' && $expires_at > 0 && $expires_at - 60 > time() ) {
			return $access;
		}

		$refresh = Crypto::decrypt( (string) ( $row['oauth_refresh_token_enc'] ?? '' ) );
		if ( $refresh === '' ) {
			throw new \RuntimeException( 'reauth_required:no_refresh_token' );
		}

		$res = match ( $provider ) {
			'microsoft' => MicrosoftClient::is_configured()
				? MicrosoftClient::refresh( $refresh )
				: [ 'ok' => false, 'error' => 'oauth_not_configured' ],
			'google' => GoogleClient::is_configured()
				? GoogleClient::refresh( $refresh )
				: [ 'ok' => false, 'error' => 'oauth_not_configured' ],
			default => [ 'ok' => false, 'error' => 'unknown_provider:' . $provider ],
		};

		if ( empty( $res['ok'] ) ) {
			throw new \RuntimeException( 'reauth_required:' . ( $res['error'] ?? 'refresh_failed' ) );
		}
		Account::store_oauth_tokens( (int) $row['id'], (int) $row['customer_id'], $res );
		return (string) $res['access_token'];
	}
}
