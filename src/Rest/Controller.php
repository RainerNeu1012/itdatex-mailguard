<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Rest;

use Itdatex\Mailguard\Customer\ApiToken;
use Itdatex\Mailguard\Customer\Auth;
use Itdatex\Mailguard\Customer\Session;
use Itdatex\Mailguard\Customer\WebSession;
use Itdatex\Mailguard\Notify\Device as PushDevice;
use Itdatex\Mailguard\Notify\Notification;
use Itdatex\Mailguard\Imap\Account as ImapAccount;
use Itdatex\Mailguard\Imap\Action as ImapAction;
use Itdatex\Mailguard\Imap\Attachment as ImapAttachment;
use Itdatex\Mailguard\Imap\ClientFactory;
use Itdatex\Mailguard\Imap\Crypto as ImapCrypto;
use Itdatex\Mailguard\Imap\Folder as ImapFolder;
use Itdatex\Mailguard\Imap\ImapClient;
use Itdatex\Mailguard\Imap\Message as ImapMessage;
use Itdatex\Mailguard\Imap\PullService;
use Itdatex\Mailguard\Imap\SenderIndex;
use Itdatex\Mailguard\Imap\QuarantineService;
use Itdatex\Mailguard\Oauth\GoogleClient;
use Itdatex\Mailguard\Oauth\MicrosoftClient;
use Itdatex\Mailguard\Oauth\StateToken;
use Itdatex\Mailguard\Antiphish\Client as AntiphishClient;
use Itdatex\Mailguard\Antiphish\PurgeService;
use Itdatex\Mailguard\Antiphish\ScanService;
use Itdatex\Mailguard\Antiphish\Unsub;
use Itdatex\Mailguard\Antiphish\Subscriptions;
use Itdatex\Mailguard\Antiphish\UnsubService;
use Itdatex\Mailguard\Rules\Rule;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

final class Controller {

	public const NAMESPACE = 'itdatex-mailguard/v1';

	public static function register() : void {
		register_rest_route( self::NAMESPACE, '/register', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'register_customer' ],
		] );

		register_rest_route( self::NAMESPACE, '/login', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'login' ],
		] );

		register_rest_route( self::NAMESPACE, '/logout', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'logout' ],
		] );

		register_rest_route( self::NAMESPACE, '/verify-email', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'verify_email' ],
		] );

		register_rest_route( self::NAMESPACE, '/forgot-password', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'forgot_password' ],
		] );

		register_rest_route( self::NAMESPACE, '/reset-password', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'reset_password' ],
		] );

		register_rest_route( self::NAMESPACE, '/me', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'me' ],
		] );

		register_rest_route( self::NAMESPACE, '/me/cloud-consent', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'me_cloud_consent' ],
		] );

		register_rest_route( self::NAMESPACE, '/mobile/login', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'mobile_login' ],
		] );

		register_rest_route( self::NAMESPACE, '/mobile/refresh', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'mobile_refresh' ],
		] );

		register_rest_route( self::NAMESPACE, '/mobile/logout', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'mobile_logout' ],
		] );

		register_rest_route( self::NAMESPACE, '/me/tokens', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'me_tokens_list' ],
		] );

		register_rest_route( self::NAMESPACE, '/me/tokens/(?P<id>\d+)', [
			'methods'             => 'DELETE',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'me_tokens_revoke' ],
		] );

		register_rest_route( self::NAMESPACE, '/me/push-devices', [
			[
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => [ __CLASS__, 'me_push_devices_list' ],
			],
			[
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => [ __CLASS__, 'me_push_devices_upsert' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/me/push-devices/(?P<id>\d+)', [
			'methods'             => 'DELETE',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'me_push_devices_delete' ],
		] );

		register_rest_route( self::NAMESPACE, '/me/web-sessions', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'me_web_sessions_list' ],
		] );

		register_rest_route( self::NAMESPACE, '/me/web-sessions/(?P<id>\d+)', [
			'methods'             => 'DELETE',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'me_web_sessions_revoke' ],
		] );

		register_rest_route( self::NAMESPACE, '/me/notifications', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'me_notifications_list' ],
			'args'                => [
				'since_id'    => [ 'type' => 'integer', 'default' => 0 ],
				'limit'       => [ 'type' => 'integer', 'default' => 50 ],
				'unread_only' => [ 'type' => 'integer', 'default' => 0 ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/me/notifications/unread-count', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'me_notifications_unread_count' ],
		] );

		register_rest_route( self::NAMESPACE, '/me/notifications/mark-seen', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'me_notifications_mark_seen' ],
		] );

		register_rest_route( self::NAMESPACE, '/accounts', [
			[
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => [ __CLASS__, 'accounts_list' ],
			],
			[
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => [ __CLASS__, 'accounts_create' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/accounts/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => [ __CLASS__, 'accounts_get' ],
			],
			[
				'methods'             => 'PATCH',
				'permission_callback' => '__return_true',
				'callback'            => [ __CLASS__, 'accounts_update' ],
			],
			[
				'methods'             => 'DELETE',
				'permission_callback' => '__return_true',
				'callback'            => [ __CLASS__, 'accounts_delete' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/accounts/(?P<id>\d+)/test', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'accounts_test' ],
		] );

		register_rest_route( self::NAMESPACE, '/accounts/(?P<id>\d+)/pull', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'accounts_pull' ],
		] );

		register_rest_route( self::NAMESPACE, '/accounts/(?P<id>\d+)/folders', [
			[
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => [ __CLASS__, 'folders_list' ],
			],
			[
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => [ __CLASS__, 'folders_create' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/accounts/(?P<id>\d+)/folders/discover', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'folders_discover' ],
		] );

		register_rest_route( self::NAMESPACE, '/folders/(?P<id>\d+)', [
			[
				'methods'             => 'PATCH',
				'permission_callback' => '__return_true',
				'callback'            => [ __CLASS__, 'folders_patch' ],
			],
			[
				'methods'             => 'DELETE',
				'permission_callback' => '__return_true',
				'callback'            => [ __CLASS__, 'folders_delete' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/folders/(?P<id>\d+)/test', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'folders_test' ],
		] );

		register_rest_route( self::NAMESPACE, '/folders/(?P<id>\d+)/pull', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'folders_pull' ],
		] );

		register_rest_route( self::NAMESPACE, '/oauth/microsoft/start', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'oauth_microsoft_start' ],
		] );

		register_rest_route( self::NAMESPACE, '/oauth/microsoft/callback', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'oauth_microsoft_callback' ],
		] );

		register_rest_route( self::NAMESPACE, '/oauth/google/start', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'oauth_google_start' ],
		] );

		register_rest_route( self::NAMESPACE, '/oauth/google/callback', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'oauth_google_callback' ],
		] );

		register_rest_route( self::NAMESPACE, '/accounts/(?P<id>\d+)/oauth/disconnect', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'oauth_disconnect' ],
		] );

		register_rest_route( self::NAMESPACE, '/imap/discover', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'imap_discover' ],
			'args'                => [
				'email' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/imap/discover-public', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'imap_discover_public' ],
			'args'                => [
				'email' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/inbox/messages', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'inbox_list' ],
			'args'                => [
				'account_id' => [ 'type' => 'integer' ],
				'unsub_only' => [ 'type' => 'integer' ],
				'q'          => [ 'type' => 'string' ],
				'from_addr'  => [ 'type' => 'string' ],
				'page'       => [ 'type' => 'integer', 'default' => 1 ],
				'per_page'   => [ 'type' => 'integer', 'default' => 25 ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/inbox/senders/purge', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'inbox_senders_purge' ],
			'args'                => [
				'from_addr' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/inbox/senders/block', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'inbox_senders_block' ],
			'args'                => [
				'from_addr' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/inbox/senders', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'inbox_senders' ],
			'args'                => [
				'account_id' => [ 'type' => 'integer' ],
				'unsub_only' => [ 'type' => 'integer' ],
				'verdict'    => [ 'type' => 'string' ],
				'q'          => [ 'type' => 'string' ],
				'page'       => [ 'type' => 'integer', 'default' => 1 ],
				'per_page'   => [ 'type' => 'integer', 'default' => 50 ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/inbox/stats', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'inbox_stats' ],
			'args'                => [
				'account_id' => [ 'type' => 'integer' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/inbox/messages/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => [ __CLASS__, 'inbox_get' ],
			],
			[
				'methods'             => 'DELETE',
				'permission_callback' => '__return_true',
				'callback'            => [ __CLASS__, 'inbox_delete' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/inbox/messages/(?P<id>\d+)/rescan', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'inbox_rescan' ],
		] );

		register_rest_route( self::NAMESPACE, '/inbox/messages/(?P<id>\d+)/attachments', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'inbox_attachments' ],
		] );

		register_rest_route( self::NAMESPACE, '/inbox/messages/(?P<id>\d+)/unsub-options', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'inbox_unsub_options' ],
		] );

		register_rest_route( self::NAMESPACE, '/inbox/messages/(?P<id>\d+)/unsubscribe', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'inbox_unsubscribe' ],
		] );

		register_rest_route( self::NAMESPACE, '/inbox/messages/(?P<id>\d+)/quarantine', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'inbox_quarantine' ],
		] );

		register_rest_route( self::NAMESPACE, '/inbox/messages/(?P<id>\d+)/purge', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'inbox_purge' ],
		] );

		register_rest_route( self::NAMESPACE, '/actions', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'actions_list' ],
			'args'                => [
				'page'       => [ 'type' => 'integer', 'default' => 1 ],
				'per_page'   => [ 'type' => 'integer', 'default' => 50 ],
				'account_id' => [ 'type' => 'integer' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/actions/(?P<id>\d+)/undo', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'actions_undo' ],
		] );

		register_rest_route( self::NAMESPACE, '/actions/(?P<id>\d+)/purge', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'actions_purge' ],
		] );

		register_rest_route( self::NAMESPACE, '/unsubs', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'unsubs_list' ],
			'args'                => [
				'page'       => [ 'type' => 'integer', 'default' => 1 ],
				'per_page'   => [ 'type' => 'integer', 'default' => 25 ],
				'account_id' => [ 'type' => 'integer' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/unsubs/(?P<id>\d+)/status', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'unsubs_status' ],
		] );

		register_rest_route( self::NAMESPACE, '/subscriptions', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'subscriptions_list' ],
			'args'                => [
				'page'       => [ 'type' => 'integer', 'default' => 1 ],
				'per_page'   => [ 'type' => 'integer', 'default' => 50 ],
				'account_id' => [ 'type' => 'integer' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/subscriptions/unsubscribe', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'subscriptions_unsubscribe' ],
		] );

		register_rest_route( self::NAMESPACE, '/subscriptions/purge', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'subscriptions_purge' ],
		] );

		register_rest_route( self::NAMESPACE, '/subscriptions/eradicate', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'subscriptions_eradicate' ],
		] );

		register_rest_route( self::NAMESPACE, '/scan/url', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'manual_scan_url' ],
		] );

		register_rest_route( self::NAMESPACE, '/scan/email', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'manual_scan_email' ],
		] );

		register_rest_route( self::NAMESPACE, '/scan/quota', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'scan_quota' ],
		] );

		register_rest_route( self::NAMESPACE, '/rules', [
			[
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => [ __CLASS__, 'rules_list' ],
			],
			[
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => [ __CLASS__, 'rules_create' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/rules/(?P<id>\d+)', [
			'methods'             => 'DELETE',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'rules_delete' ],
		] );
	}

	/**
	 * Cookie-basierter Customer-Auth-Check. Liefert customer_id oder WP_Error 401.
	 */
	private static function require_customer() {
		$me = Auth::current();
		if ( ! $me ) {
			return new WP_Error( 'not_authenticated', __( 'Anmeldung erforderlich.', 'itdatex-mailguard' ), [ 'status' => 401 ] );
		}
		return (int) $me['customer_id'];
	}

	public static function accounts_list( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		return new WP_REST_Response( [
			'ok'    => true,
			'items' => ImapAccount::list_for_customer( $cid ),
		], 200 );
	}

	public static function accounts_get( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$row = ImapAccount::find_for_customer( (int) $req['id'], $cid );
		if ( ! $row ) { return new WP_Error( 'not_found', '', [ 'status' => 404 ] ); }
		return new WP_REST_Response( [ 'ok' => true, 'item' => ImapAccount::public_view( $row ) ], 200 );
	}

	public static function accounts_create( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }

		$quota_err = self::check_imap_quota( $cid );
		if ( $quota_err ) { return $quota_err; }

		$json = (array) $req->get_json_params();
		$err = self::validate_account_payload( $json, true );
		if ( $err ) { return $err; }
		$id = ImapAccount::create( $cid, $json );
		if ( ! $id ) { return new WP_Error( 'create_failed', __( 'Anlegen fehlgeschlagen.', 'itdatex-mailguard' ), [ 'status' => 500 ] ); }

		$row = ImapAccount::find_for_customer( $id, $cid );

		// Alle vorhandenen IMAP-Folder als aktive Pull-Folder mit uebernehmen.
		// Fallback: wenn die IMAP-LIST fehlschlaegt (z.B. bei OAuth-Refresh-Delay),
		// legen wir mindestens den vom User angegebenen Default-Folder / INBOX an,
		// damit der User nicht mit einem leeren Folder-Set daneben steht.
		$sync = ImapFolder::sync_from_imap( $row, $cid );
		if ( empty( $sync['ok'] ) || (int) ( $sync['added'] ?? 0 ) === 0 && ImapFolder::list_for_account( $id, $cid ) === [] ) {
			$default_folder = trim( (string) ( $json['folder'] ?? '' ) ) ?: 'INBOX';
			ImapFolder::create( $id, $cid, $default_folder );
		}

		return new WP_REST_Response( [ 'ok' => true, 'item' => ImapAccount::public_view( $row ), 'folder_sync' => $sync ], 201 );
	}

	/**
	 * Prüft, ob der Customer noch ein weiteres IMAP-Postfach anlegen darf.
	 * Pro-Gated nach Plan-Quota.
	 * Bei past_due wird noch erlaubt (Grace), bei canceled+abgelaufenem grace blockiert.
	 */
	private static function check_imap_quota( int $cid ) : ?WP_Error {
		global $wpdb;
		$cust = $wpdb->get_row( $wpdb->prepare(
			'SELECT plan_slug, plan_status, imap_quota, plan_grace_until FROM ' . $wpdb->prefix . 'mg_customers WHERE id = %d',
			$cid
		), ARRAY_A );
		if ( ! $cust ) { return new WP_Error( 'unknown_customer', '', [ 'status' => 401 ] ); }

		$status = (string) $cust['plan_status'];
		if ( $status === 'canceled' ) {
			$grace = (string) ( $cust['plan_grace_until'] ?? '' );
			if ( $grace === '' || strtotime( $grace . ' UTC' ) < time() ) {
				return new WP_Error( 'plan_canceled', __( 'Abo gekündigt. Postfach-Anlage ist deaktiviert. Bitte reaktivieren.', 'itdatex-mailguard' ), [ 'status' => 402 ] );
			}
		}

		$quota = (int) $cust['imap_quota'];
		if ( $quota <= 0 ) { $quota = 1; }

		$active = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . "mg_imap_accounts WHERE customer_id = %d AND status != 'deleted'",
			$cid
		) );
		if ( $active >= $quota ) {
			return new WP_Error(
				'quota_exceeded',
				sprintf(
					__( 'Plan-Limit erreicht: %d von %d Postfächern in Nutzung. Bitte Plan upgraden.', 'itdatex-mailguard' ),
					$active, $quota
				),
				[ 'status' => 402, 'plan_slug' => $cust['plan_slug'], 'quota' => $quota, 'in_use' => $active ]
			);
		}
		return null;
	}

	public static function accounts_update( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$id  = (int) $req['id'];
		$row = ImapAccount::find_for_customer( $id, $cid );
		if ( ! $row ) { return new WP_Error( 'not_found', '', [ 'status' => 404 ] ); }
		$json = (array) $req->get_json_params();
		$err = self::validate_account_payload( $json, false );
		if ( $err ) { return $err; }
		ImapAccount::update( $id, $cid, $json );
		$row = ImapAccount::find_for_customer( $id, $cid );
		return new WP_REST_Response( [ 'ok' => true, 'item' => ImapAccount::public_view( $row ) ], 200 );
	}

	public static function accounts_delete( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$id  = (int) $req['id'];
		if ( ! ImapAccount::find_for_customer( $id, $cid ) ) {
			return new WP_Error( 'not_found', '', [ 'status' => 404 ] );
		}
		ImapAccount::delete( $id, $cid );
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public static function accounts_test( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$id  = (int) $req['id'];
		$row = ImapAccount::find_for_customer( $id, $cid );
		if ( ! $row ) { return new WP_Error( 'not_found', '', [ 'status' => 404 ] ); }
		try {
			$client = \Itdatex\Mailguard\Imap\ClientFactory::for_account( $row );
			$probe = $client->probe();
			ImapAccount::record_test( $id, $cid, true, 'ok · messages=' . (int) $probe['messages'] );
			return new WP_REST_Response( [ 'ok' => true, 'probe' => $probe ], 200 );
		} catch ( \Throwable $e ) {
			ImapAccount::record_test( $id, $cid, false, $e->getMessage() );
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'connect_failed', 'detail' => $e->getMessage() ], 200 );
		}
	}

	public static function accounts_pull( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$id  = (int) $req['id'];
		$res = PullService::pull_account( $id, $cid );
		$status = ! empty( $res['ok'] ) ? 200 : ( ( $res['error'] ?? '' ) === 'not_found' ? 404 : 502 );
		return new WP_REST_Response( $res, $status );
	}

	public static function inbox_list( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$filter = [
			'account_id' => (int) $req->get_param( 'account_id' ),
			'unsub_only' => (int) $req->get_param( 'unsub_only' ),
			'verdict'    => trim( (string) $req->get_param( 'verdict' ) ),
			'q'          => trim( (string) $req->get_param( 'q' ) ),
			'from_addr'  => trim( (string) $req->get_param( 'from_addr' ) ),
		];
		$page     = (int) $req->get_param( 'page' );
		$per_page = (int) $req->get_param( 'per_page' );
		$data = ImapMessage::list_for_customer( $cid, $filter, $page, $per_page );
		$data['ok'] = true;
		return new WP_REST_Response( $data, 200 );
	}

	public static function inbox_senders_purge( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$json      = (array) $req->get_json_params();
		$from_addr = trim( (string) ( $json['from_addr'] ?? '' ) );
		if ( $from_addr === '' ) {
			return new WP_Error( 'missing_from_addr', __( 'from_addr fehlt.', 'itdatex-mailguard' ), [ 'status' => 400 ] );
		}
		$res = PurgeService::hard_purge_sender( $cid, $from_addr );
		$status = ! empty( $res['ok'] ) ? 200 : ( ( $res['error'] ?? '' ) === 'bad_from_addr' ? 400 : 502 );
		return new WP_REST_Response( $res, $status );
	}

	public static function inbox_senders_block( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$json      = (array) $req->get_json_params();
		$from_addr = trim( (string) ( $json['from_addr'] ?? '' ) );
		if ( $from_addr === '' ) {
			return new WP_Error( 'missing_from_addr', __( 'from_addr fehlt.', 'itdatex-mailguard' ), [ 'status' => 400 ] );
		}
		$note = sanitize_text_field( (string) ( $json['note'] ?? 'Blockiert aus Portal' ) );
		$res  = PurgeService::block_sender( $cid, $from_addr, $note );
		$res['from_addr'] = strtolower( $from_addr );
		$status = ! empty( $res['ok'] ) ? 200 : ( ( $res['error'] ?? '' ) === 'bad_from_addr' ? 400 : 502 );
		return new WP_REST_Response( $res, $status );
	}

	public static function inbox_senders( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$filter = [
			'account_id' => (int) $req->get_param( 'account_id' ),
			'unsub_only' => (int) $req->get_param( 'unsub_only' ),
			'verdict'    => trim( (string) $req->get_param( 'verdict' ) ),
			'q'          => trim( (string) $req->get_param( 'q' ) ),
		];
		$page     = (int) $req->get_param( 'page' );
		$per_page = (int) $req->get_param( 'per_page' );
		$data = SenderIndex::list_for_customer( $cid, $filter, $page, $per_page );
		$data['ok'] = true;
		return new WP_REST_Response( $data, 200 );
	}

	public static function inbox_stats( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$aid = (int) $req->get_param( 'account_id' );
		return new WP_REST_Response( [ 'ok' => true, 'stats' => ImapMessage::stats_for_customer( $cid, $aid ) ], 200 );
	}

	public static function inbox_get( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$row = ImapMessage::find_for_customer( (int) $req['id'], $cid );
		if ( ! $row ) { return new WP_Error( 'not_found', '', [ 'status' => 404 ] ); }
		return new WP_REST_Response( [ 'ok' => true, 'item' => ImapMessage::public_view( $row ) ], 200 );
	}

	public static function inbox_delete( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		ImapMessage::delete( (int) $req['id'], $cid );
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public static function manual_scan_url( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$json = (array) $req->get_json_params();
		$url  = trim( (string) ( $json['url'] ?? '' ) );
		if ( $url === '' || ! preg_match( '#^https?://#i', $url ) || strlen( $url ) > 4096 ) {
			return new WP_Error( 'bad_url', __( 'Ungueltige URL.', 'itdatex-mailguard' ), [ 'status' => 400 ] );
		}
		$q = ScanService::consume_manual_quota( $cid );
		if ( ! $q['allowed'] ) {
			$resp = new WP_REST_Response( [ 'ok' => false, 'error' => 'rate_limited', 'retry_after' => $q['retry_after'], 'limit' => $q['limit'] ], 429 );
			$resp->header( 'Retry-After', (string) $q['retry_after'] );
			return $resp;
		}
		$res = AntiphishClient::scan_url( $url );
		return self::scan_response( $res, $q );
	}

	public static function manual_scan_email( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$json    = (array) $req->get_json_params();
		$payload = [
			'subject'   => (string) ( $json['subject'] ?? '' ),
			'body'      => (string) ( $json['body'] ?? '' ),
			'from_addr' => isset( $json['from_addr'] ) && $json['from_addr'] !== '' ? (string) $json['from_addr'] : null,
			'deep'      => ! empty( $json['deep'] ),
		];
		if ( isset( $json['headers'] ) && is_array( $json['headers'] ) ) {
			$h = [];
			foreach ( $json['headers'] as $k => $v ) {
				if ( is_string( $k ) && is_scalar( $v ) ) {
					$h[ $k ] = (string) $v;
				}
			}
			$payload['headers'] = $h;
		}
		if ( $payload['subject'] === '' && $payload['body'] === '' ) {
			return new WP_Error( 'bad_input', __( 'Subject oder Body angeben.', 'itdatex-mailguard' ), [ 'status' => 400 ] );
		}
		$q = ScanService::consume_manual_quota( $cid );
		if ( ! $q['allowed'] ) {
			$resp = new WP_REST_Response( [ 'ok' => false, 'error' => 'rate_limited', 'retry_after' => $q['retry_after'], 'limit' => $q['limit'] ], 429 );
			$resp->header( 'Retry-After', (string) $q['retry_after'] );
			return $resp;
		}
		$res = AntiphishClient::scan_email( $payload );
		return self::scan_response( $res, $q );
	}

	public static function scan_quota( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		global $wpdb;
		$rate = max( 1, (int) \Itdatex\Mailguard\Admin\Settings::get( 'manual_scan_quota', 50 ) );
		$entries = (array) get_transient( 'itdatex_mg_quota_' . $cid );
		$now = time();
		$active = array_filter( $entries, static fn( $t ) => is_int( $t ) && ( $now - $t ) < DAY_IN_SECONDS );
		return new WP_REST_Response( [
			'ok' => true,
			'limit'     => $rate,
			'used'      => count( $active ),
			'remaining' => max( 0, $rate - count( $active ) ),
		], 200 );
	}

	private static function scan_response( $res, array $quota ) : WP_REST_Response {
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => $res->get_error_code(), 'detail' => $res->get_error_message() ], 502 );
		}
		$status = (int) ( $res['status'] ?? 500 );
		$resp = new WP_REST_Response( [
			'ok'        => $status < 400,
			'result'    => $res['body'],
			'remaining' => $quota['remaining'] ?? null,
			'limit'     => $quota['limit'] ?? null,
		], $status );
		return $resp;
	}

	public static function rules_list( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		return new WP_REST_Response( [ 'ok' => true, 'items' => Rule::list_for_customer( $cid ) ], 200 );
	}

	public static function rules_create( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$json = (array) $req->get_json_params();
		$res = Rule::create( $cid, $json );
		if ( empty( $res['ok'] ) ) {
			return new WP_Error( 'bad_input', $res['error'] ?? '', [ 'status' => 400 ] );
		}
		$row = Rule::find_for_customer( (int) $res['id'], $cid );
		return new WP_REST_Response( [ 'ok' => true, 'item' => Rule::public_view( $row ) ], 201 );
	}

	public static function rules_delete( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$id = (int) $req['id'];
		if ( ! Rule::find_for_customer( $id, $cid ) ) {
			return new WP_Error( 'not_found', '', [ 'status' => 404 ] );
		}
		Rule::delete( $id, $cid );
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public static function inbox_unsub_options( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$res = UnsubService::extract_for_message( (int) $req['id'], $cid );
		$status = ! empty( $res['ok'] ) ? 200 : ( ( $res['error'] ?? '' ) === 'not_found' ? 404 : 502 );
		return new WP_REST_Response( $res, $status );
	}

	public static function inbox_unsubscribe( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$json = (array) $req->get_json_params();
		$idx  = isset( $json['option_idx'] ) ? (int) $json['option_idx'] : null;
		$res  = UnsubService::execute_for_message( (int) $req['id'], $cid, $idx );
		return new WP_REST_Response( $res, self::unsub_http_code( $res ) );
	}

	/**
	 * Mapping Unsub-Ergebnis → HTTP-Status.
	 *
	 * 200 = klarer Ausgang, den das UI anzeigen soll:
	 *       • ok (erfolgreich abgemeldet)
	 *       • needs_manual (User muss im Browser klicken)
	 *       • already (schon zuvor abgemeldet)
	 *       • endpoints_dead (Provider hat Abmelde-URL stillgelegt — echtes Ergebnis, kein Serverfehler)
	 * 404 = Ziel-Mail existiert nicht mehr
	 * 409 = parallele Abmeldung läuft bereits (Doppelklick-Lock)
	 * 422 = kein List-Unsubscribe-Header vorhanden (Client-seitig unlösbar)
	 * 502 = Backend-Fehler (API down, Timeout, unerwarteter Provider-Fehler)
	 *
	 * Trennt "unser Server hat gerade ein Problem" von "der Newsletter-Absender
	 * spielt nicht mit" — das UI kann darauf unterschiedlich reagieren, statt
	 * überall dieselbe rote 502-Fehlermeldung zu zeigen.
	 */
	private static function unsub_http_code( array $res ) : int {
		if ( ! empty( $res['ok'] ) )           return 200;
		if ( ! empty( $res['needs_manual'] ) ) return 200;
		if ( ( $res['reason'] ?? '' ) === 'endpoints_dead' ) return 200;
		return match ( (string) ( $res['error'] ?? '' ) ) {
			'not_found'      => 404,
			'in_progress'    => 409,
			'no_options',
			'no_option_picked' => 422,
			default          => 502,
		};
	}

	public static function inbox_quarantine( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$res = QuarantineService::quarantine( (int) $req['id'], $cid );
		$status = ! empty( $res['ok'] )
			? 200
			: match ( $res['error'] ?? '' ) {
				'not_found', 'account_gone', 'source_mail_gone' => 404,
				'already_quarantined'       => 409,
				'connect_failed', 'create_folder_failed', 'move_failed' => 502,
				default                     => 500,
			};
		return new WP_REST_Response( $res, $status );
	}

	public static function inbox_purge( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$res = QuarantineService::purge_message( (int) $req['id'], $cid );
		$status = ! empty( $res['ok'] )
			? 200
			: match ( $res['error'] ?? '' ) {
				'not_found', 'account_gone'                                    => 404,
				'connect_failed', 'find_target_failed', 'expunge_failed',
				'target_uid_unknown'                                           => 502,
				default                                                        => 500,
			};
		return new WP_REST_Response( $res, $status );
	}

	public static function actions_list( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$page     = max( 1, (int) $req->get_param( 'page' ) );
		$per_page = max( 1, min( 100, (int) $req->get_param( 'per_page' ) ) );
		$aid      = (int) $req->get_param( 'account_id' );
		$data = ImapAction::list_for_customer( $cid, $page, $per_page, $aid );
		$data['ok'] = true;
		return new WP_REST_Response( $data, 200 );
	}

	public static function actions_undo( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$res = QuarantineService::undo( (int) $req['id'], $cid );
		$status = ! empty( $res['ok'] )
			? 200
			: match ( $res['error'] ?? '' ) {
				'not_found', 'account_gone'                                   => 404,
				'not_undoable', 'already_undone_or_failed', 'undo_expired'    => 409,
				'connect_failed', 'find_target_failed', 'move_failed',
				'target_uid_unknown'                                          => 502,
				default                                                       => 500,
			};
		return new WP_REST_Response( $res, $status );
	}

	public static function actions_purge( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$res = QuarantineService::purge( (int) $req['id'], $cid );
		$status = ! empty( $res['ok'] )
			? 200
			: match ( $res['error'] ?? '' ) {
				'not_found', 'account_gone'                => 404,
				'not_quarantine_action', 'not_purgeable'   => 409,
				'connect_failed', 'find_target_failed',
				'expunge_failed', 'target_uid_unknown'     => 502,
				default                                    => 500,
			};
		return new WP_REST_Response( $res, $status );
	}

	public static function unsubs_list( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$page     = (int) $req->get_param( 'page' );
		$per_page = (int) $req->get_param( 'per_page' );
		$aid      = (int) $req->get_param( 'account_id' );
		$data = Unsub::list_for_customer( $cid, $page ?: 1, $per_page ?: 25, $aid );
		$data['ok'] = true;
		return new WP_REST_Response( $data, 200 );
	}

	public static function folders_list( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$aid = (int) $req['id'];
		if ( ! ImapAccount::find_for_customer( $aid, $cid ) ) {
			return new WP_Error( 'not_found', '', [ 'status' => 404 ] );
		}
		return new WP_REST_Response( [ 'ok' => true, 'items' => ImapFolder::list_for_account( $aid, $cid ) ], 200 );
	}

	public static function folders_create( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$aid = (int) $req['id'];
		$account = ImapAccount::find_for_customer( $aid, $cid );
		if ( ! $account ) {
			return new WP_Error( 'not_found', '', [ 'status' => 404 ] );
		}
		$json = (array) $req->get_json_params();
		$names = $json['folders'] ?? null;
		if ( ! is_array( $names ) ) {
			$names = isset( $json['folder_name'] ) ? [ (string) $json['folder_name'] ] : [];
		}
		$quarantine = QuarantineService::quarantine_folder_for_account( $account );
		$created = []; $skipped = [];
		foreach ( $names as $n ) {
			$nm = trim( (string) $n );
			if ( $nm === '' ) { continue; }
			if ( $nm === $quarantine ) {
				$skipped[] = $nm;
				continue;
			}
			$id = ImapFolder::create( $aid, $cid, $nm );
			if ( $id ) { $created[] = $id; }
		}
		return new WP_REST_Response( [
			'ok'      => true,
			'created' => $created,
			'skipped' => $skipped,
			'items'   => ImapFolder::list_for_account( $aid, $cid ),
		], 201 );
	}

	public static function folders_discover( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$aid = (int) $req['id'];
		$row = ImapAccount::find_for_customer( $aid, $cid );
		if ( ! $row ) { return new WP_Error( 'not_found', '', [ 'status' => 404 ] ); }
		try {
			$client = ClientFactory::for_account( $row );
			$client->connect();
			$folders = $client->list_folders();
			$client->close();
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'connect_failed', 'detail' => $e->getMessage() ], 502 );
		}
		// Bereits konfigurierte Folder markieren + Quarantäne-Folder kennzeichnen,
		// damit das UI ihn nicht versehentlich als Pull-Ordner anbietet (sonst
		// würde der nächste Pull die quarantänisierten Mails wieder als neue Mails
		// einfangen).
		$configured = array_column( ImapFolder::list_for_account( $aid, $cid ), 'folder_name' );
		$cfg_set   = array_flip( $configured );
		$quar_name = QuarantineService::quarantine_folder_for_account( $row );
		foreach ( $folders as &$f ) {
			$f['configured']    = isset( $cfg_set[ $f['name'] ] );
			$f['is_quarantine'] = $f['name'] === $quar_name;
		}
		unset( $f );
		return new WP_REST_Response( [ 'ok' => true, 'items' => $folders ], 200 );
	}

	public static function folders_patch( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$fid = (int) $req['id'];
		if ( ! ImapFolder::find_for_customer( $fid, $cid ) ) {
			return new WP_Error( 'not_found', '', [ 'status' => 404 ] );
		}
		$json = (array) $req->get_json_params();
		if ( isset( $json['status'] ) ) {
			ImapFolder::update_status( $fid, $cid, (string) $json['status'] );
		}
		$row = ImapFolder::find_for_customer( $fid, $cid );
		return new WP_REST_Response( [ 'ok' => true, 'item' => ImapFolder::public_view( $row ) ], 200 );
	}

	public static function folders_delete( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$fid = (int) $req['id'];
		if ( ! ImapFolder::find_for_customer( $fid, $cid ) ) {
			return new WP_Error( 'not_found', '', [ 'status' => 404 ] );
		}
		ImapFolder::delete( $fid, $cid );
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public static function folders_test( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$fid = (int) $req['id'];
		$f   = ImapFolder::find_for_customer( $fid, $cid );
		if ( ! $f ) { return new WP_Error( 'not_found', '', [ 'status' => 404 ] ); }
		$acc = ImapAccount::find_for_customer( (int) $f['account_id'], $cid );
		if ( ! $acc ) { return new WP_Error( 'not_found', '', [ 'status' => 404 ] ); }
		try {
			$client = ClientFactory::for_account_folder( $acc, (string) $f['folder_name'] );
			$probe = $client->probe();
			ImapFolder::record_test( $fid, $cid, true, 'ok · messages=' . (int) $probe['messages'] );
			return new WP_REST_Response( [ 'ok' => true, 'probe' => $probe ], 200 );
		} catch ( \Throwable $e ) {
			ImapFolder::record_test( $fid, $cid, false, $e->getMessage() );
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'connect_failed', 'detail' => $e->getMessage() ], 200 );
		}
	}

	public static function folders_pull( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$fid = (int) $req['id'];
		$res = PullService::pull_folder( $fid, $cid );
		$status = ! empty( $res['ok'] ) ? 200 : ( ( $res['error'] ?? '' ) === 'not_found' ? 404 : 502 );
		return new WP_REST_Response( $res, $status );
	}

	public static function subscriptions_list( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$page     = max( 1, (int) $req->get_param( 'page' ) );
		$per_page = max( 1, min( 100, (int) $req->get_param( 'per_page' ) ) );
		$aid      = (int) $req->get_param( 'account_id' );
		$data = Subscriptions::list_for_customer( $cid, $page, $per_page, $aid );
		$data['ok']       = true;
		$data['page']     = $page;
		$data['per_page'] = $per_page;
		return new WP_REST_Response( $data, 200 );
	}

	public static function subscriptions_unsubscribe( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$json      = (array) $req->get_json_params();
		$from_addr = strtolower( trim( (string) ( $json['from_addr'] ?? '' ) ) );
		if ( $from_addr === '' || ! str_contains( $from_addr, '@' ) ) {
			return new WP_Error( 'bad_input', __( 'from_addr fehlt.', 'itdatex-mailguard' ), [ 'status' => 400 ] );
		}
		$res = UnsubService::execute_for_sender( $cid, $from_addr );
		$res['from_addr'] = $from_addr;
		return new WP_REST_Response( $res, self::unsub_http_code( $res ) );
	}

	public static function subscriptions_purge( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$json      = (array) $req->get_json_params();
		$from_addr = strtolower( trim( (string) ( $json['from_addr'] ?? '' ) ) );
		if ( $from_addr === '' || ! str_contains( $from_addr, '@' ) ) {
			return new WP_Error( 'bad_input', __( 'from_addr fehlt.', 'itdatex-mailguard' ), [ 'status' => 400 ] );
		}
		$auto_rule = ! empty( $json['auto_rule'] );
		$res = PurgeService::purge_sender( $cid, $from_addr, $auto_rule );
		$res['from_addr'] = $from_addr;
		return new WP_REST_Response( $res, ! empty( $res['ok'] ) ? 200 : 502 );
	}

	/**
	 * "Sender vernichten" — kombiniert Abmelde-Versuch + Blockieren + Hard-Purge
	 * in einem atomaren Aufruf. Server-seitig confirmieren, damit weder DevTools-
	 * Nutzer noch böse Curls das Nur-Frontend-Confirm umgehen können: das UI muss
	 * `confirm: 'VERNICHTEN'` mitschicken, sonst 422.
	 */
	public static function subscriptions_eradicate( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$json      = (array) $req->get_json_params();
		$from_addr = strtolower( trim( (string) ( $json['from_addr'] ?? '' ) ) );
		$confirm   = trim( (string) ( $json['confirm'] ?? '' ) );
		if ( $from_addr === '' || ! str_contains( $from_addr, '@' ) ) {
			return new WP_Error( 'bad_input', __( 'from_addr fehlt.', 'itdatex-mailguard' ), [ 'status' => 400 ] );
		}
		if ( strtoupper( $confirm ) !== 'VERNICHTEN' ) {
			return new WP_Error(
				'confirm_missing',
				__( 'Bestätigung fehlt — bitte "VERNICHTEN" eintippen, damit diese endgültige Aktion ausgeführt wird.', 'itdatex-mailguard' ),
				[ 'status' => 422 ]
			);
		}
		$res = UnsubService::eradicate_sender( $cid, $from_addr );
		return new WP_REST_Response( $res, ! empty( $res['ok'] ) ? 200 : 502 );
	}

	public static function unsubs_status( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$res = UnsubService::status_refresh( (int) $req['id'], $cid );
		return new WP_REST_Response( $res, ! empty( $res['ok'] ) ? 200 : 502 );
	}

	public static function inbox_rescan( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$id = (int) $req['id'];
		if ( ! ImapMessage::find_for_customer( $id, $cid ) ) {
			return new WP_Error( 'not_found', '', [ 'status' => 404 ] );
		}
		ScanService::reset_to_pending( $id, $cid );
		$res = ScanService::scan_message( $id );
		$row = ImapMessage::find_for_customer( $id, $cid );
		return new WP_REST_Response( [ 'ok' => $res['ok'] ?? false, 'item' => $row ? ImapMessage::public_view( $row ) : null ], 200 );
	}

	public static function inbox_attachments( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$id = (int) $req['id'];
		if ( ! ImapMessage::find_for_customer( $id, $cid ) ) {
			return new WP_Error( 'not_found', '', [ 'status' => 404 ] );
		}
		return new WP_REST_Response( [ 'ok' => true, 'items' => ImapAttachment::list_for_message( $id, $cid ) ], 200 );
	}

	private static function validate_account_payload( array $json, bool $is_create ) {
		if ( $is_create ) {
			$is_oauth = isset( $json['auth_type'] ) && str_starts_with( (string) $json['auth_type'], 'oauth_' );
			$required = $is_oauth ? [ 'host', 'username' ] : [ 'host', 'username', 'password' ];
			foreach ( $required as $k ) {
				if ( empty( $json[ $k ] ) ) {
					return new WP_Error( 'bad_input', sprintf( __( 'Feld %s ist erforderlich.', 'itdatex-mailguard' ), $k ), [ 'status' => 400 ] );
				}
			}
		}
		if ( isset( $json['encryption'] ) && ! in_array( $json['encryption'], [ 'ssl', 'tls', 'none' ], true ) ) {
			return new WP_Error( 'bad_input', __( 'Ungueltige Encryption.', 'itdatex-mailguard' ), [ 'status' => 400 ] );
		}
		if ( isset( $json['port'] ) ) {
			$p = (int) $json['port'];
			if ( $p < 1 || $p > 65535 ) {
				return new WP_Error( 'bad_input', __( 'Ungueltiger Port.', 'itdatex-mailguard' ), [ 'status' => 400 ] );
			}
		}
		if ( array_key_exists( 'auto_quarantine_min_score', $json ) ) {
			$raw = $json['auto_quarantine_min_score'];
			if ( $raw !== null && $raw !== '' && $raw !== 0 && $raw !== '0' ) {
				$v   = (int) $raw;
				$min = \Itdatex\Mailguard\Installer::AUTO_QUARANTINE_MIN_SCORE;
				if ( $v < $min || $v > 100 ) {
					return new WP_Error( 'bad_input', sprintf(
						__( 'Auto-Quarantäne-Schwelle muss zwischen %d und 100 liegen.', 'itdatex-mailguard' ),
						$min
					), [ 'status' => 400 ] );
				}
			}
		}
		return null;
	}

	public static function register_customer( WP_REST_Request $req ) {
		$json     = (array) $req->get_json_params();
		$email    = (string) ( $json['email'] ?? '' );
		$password = (string) ( $json['password'] ?? '' );
		$res = Auth::register( $email, $password );
		return self::respond( $res );
	}

	public static function login( WP_REST_Request $req ) {
		$json     = (array) $req->get_json_params();
		$email    = (string) ( $json['email'] ?? '' );
		$password = (string) ( $json['password'] ?? '' );
		$res = Auth::login( $email, $password );
		return self::respond( $res );
	}

	public static function logout( WP_REST_Request $req ) {
		return self::respond( Auth::logout() );
	}

	public static function verify_email( WP_REST_Request $req ) {
		$json  = (array) $req->get_json_params();
		$token = (string) ( $json['token'] ?? '' );
		return self::respond( Auth::verify_email( $token ) );
	}

	public static function forgot_password( WP_REST_Request $req ) {
		$json  = (array) $req->get_json_params();
		$email = (string) ( $json['email'] ?? '' );
		return self::respond( Auth::forgot_password( $email ) );
	}

	public static function reset_password( WP_REST_Request $req ) {
		$json     = (array) $req->get_json_params();
		$token    = (string) ( $json['token'] ?? '' );
		$password = (string) ( $json['password'] ?? '' );
		return self::respond( Auth::reset_password( $token, $password ) );
	}

	public static function me( WP_REST_Request $req ) {
		$me = Auth::current();
		if ( ! $me ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'not_authenticated' ], 401 );
		}
		return new WP_REST_Response( [ 'ok' => true, 'customer' => $me ], 200 );
	}

	/**
	 * Bearer-Token-Login fuer die mobile App. Verwendet dieselbe Passwort-
	 * Validierung wie Auth::login(); zusaetzlich zur Cookie-Session (harmlos
	 * fuer Nicht-Browser-Clients) wird ein DB-Backed Access/Refresh-Token-Paar
	 * ausgegeben. Client speichert die Tokens in Preferences/Keychain.
	 */
	public static function mobile_login( WP_REST_Request $req ) {
		$json     = (array) $req->get_json_params();
		$email    = (string) ( $json['email'] ?? '' );
		$password = (string) ( $json['password'] ?? '' );
		$platform = (string) ( $json['platform'] ?? 'unknown' );
		$name     = (string) ( $json['device_name'] ?? '' );

		$res = Auth::login( $email, $password );
		if ( empty( $res['ok'] ) ) {
			return self::respond( $res );
		}
		$cid  = (int) $res['customer_id'];
		$pair = ApiToken::issue( $cid, $platform, $name );
		$me   = Auth::current();

		return new WP_REST_Response( [
			'ok'                 => true,
			'token_id'           => $pair['token_id'],
			'access_token'       => $pair['access_token'],
			'refresh_token'      => $pair['refresh_token'],
			'access_expires_at'  => gmdate( 'c', $pair['access_expires_at'] ),
			'refresh_expires_at' => gmdate( 'c', $pair['refresh_expires_at'] ),
			'customer'           => $me,
		], 200 );
	}

	public static function mobile_refresh( WP_REST_Request $req ) {
		$json  = (array) $req->get_json_params();
		$token = (string) ( $json['refresh_token'] ?? '' );
		if ( $token === '' ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'missing_refresh_token' ], 400 );
		}
		$pair = ApiToken::refresh( $token );
		if ( ! $pair ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'invalid_refresh_token' ], 401 );
		}
		return new WP_REST_Response( [
			'ok'                 => true,
			'token_id'           => $pair['token_id'],
			'access_token'       => $pair['access_token'],
			'refresh_token'      => $pair['refresh_token'],
			'access_expires_at'  => gmdate( 'c', $pair['access_expires_at'] ),
			'refresh_expires_at' => gmdate( 'c', $pair['refresh_expires_at'] ),
		], 200 );
	}

	public static function mobile_logout( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		// Der Bearer-Token in der aktuellen Request-Header wird revoked.
		$hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' );
		if ( stripos( (string) $hdr, 'Bearer ' ) === 0 ) {
			ApiToken::revoke_by_hash( trim( substr( (string) $hdr, 7 ) ), $cid );
		}
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public static function me_tokens_list( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		return new WP_REST_Response( [ 'ok' => true, 'items' => ApiToken::list_for_customer( $cid ) ], 200 );
	}

	public static function me_tokens_revoke( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		ApiToken::revoke( (int) $req['id'], $cid );
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public static function me_push_devices_list( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		return new WP_REST_Response( [ 'ok' => true, 'items' => PushDevice::list_for_customer( $cid ) ], 200 );
	}

	public static function me_push_devices_upsert( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$json = (array) $req->get_json_params();
		$id   = PushDevice::upsert( $cid, [
			'fcm_token'    => (string) ( $json['fcm_token'] ?? '' ),
			'platform'     => (string) ( $json['platform'] ?? 'unknown' ),
			'device_label' => (string) ( $json['device_label'] ?? '' ),
			'events_mask'  => isset( $json['events_mask'] ) ? (int) $json['events_mask'] : 15,
		] );
		if ( ! $id ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'missing_fcm_token' ], 400 );
		}
		return new WP_REST_Response( [ 'ok' => true, 'id' => $id ], 200 );
	}

	public static function me_push_devices_delete( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		PushDevice::delete( (int) $req['id'], $cid );
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public static function me_web_sessions_list( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		return new WP_REST_Response( [
			'ok'    => true,
			'items' => WebSession::list_for_customer( $cid, Session::current_jti() ),
		], 200 );
	}

	public static function me_web_sessions_revoke( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$res = WebSession::revoke_by_id( (int) $req['id'], $cid, Session::current_jti() );
		if ( empty( $res['ok'] ) ) {
			$status = ( $res['error'] ?? '' ) === 'not_found' ? 404 : 400;
			return new WP_REST_Response( $res, $status );
		}
		return new WP_REST_Response( $res, 200 );
	}

	public static function me_notifications_list( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$since_id    = max( 0, (int) $req->get_param( 'since_id' ) );
		$limit       = max( 1, min( 200, (int) $req->get_param( 'limit' ) ?: 50 ) );
		$unread_only = (int) $req->get_param( 'unread_only' ) === 1;
		$items = Notification::list_for_customer( $cid, $since_id, $limit, $unread_only );
		return new WP_REST_Response( [
			'ok'           => true,
			'items'        => $items,
			// Highest returned id → Client speichert das lokal als lastSeenId und
			// schickt es beim nächsten Poll als since_id, damit er nur wirklich
			// Neues bekommt.
			'max_id'       => $items ? max( array_column( $items, 'id' ) ) : $since_id,
			'unread_count' => Notification::unread_count( $cid ),
		], 200 );
	}

	public static function me_notifications_unread_count( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		return new WP_REST_Response( [
			'ok'           => true,
			'unread_count' => Notification::unread_count( $cid ),
		], 200 );
	}

	public static function me_notifications_mark_seen( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$json     = (array) $req->get_json_params();
		$up_to_id = isset( $json['up_to_id'] ) ? (int) $json['up_to_id'] : 0;
		$marked   = Notification::mark_seen( $cid, $up_to_id );
		return new WP_REST_Response( [
			'ok'     => true,
			'marked' => $marked,
		], 200 );
	}

	/**
	 * Cloud-LLM-Einwilligung erteilen oder widerrufen.
	 * Body: {accept: true|false}
	 * - accept=true:  cloud_consent_at = now, llm_enabled = 1 falls Plan llm_enabled
	 * - accept=false: cloud_consent_at = NULL, llm_enabled = 0
	 */
	public static function me_cloud_consent( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$json   = (array) $req->get_json_params();
		$accept = ! empty( $json['accept'] );

		if ( $accept ) {
			\Itdatex\Mailguard\Customer\Account::set_cloud_consent(
				$cid,
				\Itdatex\Mailguard\Installer::CURRENT_CLOUD_CONSENT_VERSION
			);
			// llm_enabled wieder spiegeln zum Plan — set_cloud_consent allein
			// reaktiviert es nicht, weil revoke es zuvor explizit auf 0 gesetzt
			// hat. Plus/Pro-Customer erwarten aber sofortige Re-Aktivierung
			// nach Re-Consent.
			$row = \Itdatex\Mailguard\Customer\Account::find_by_id( $cid );
			if ( $row && Auth::plan_has_llm( (string) ( $row['plan_slug'] ?? 'free' ) ) ) {
				global $wpdb;
				$wpdb->update( \Itdatex\Mailguard\Customer\Account::table(),
					[ 'llm_enabled' => 1 ],
					[ 'id' => $cid ], [ '%d' ], [ '%d' ]
				);
			}
		} else {
			\Itdatex\Mailguard\Customer\Account::revoke_cloud_consent( $cid );
		}
		return new WP_REST_Response( [ 'ok' => true, 'customer' => Auth::current() ], 200 );
	}

	public static function oauth_microsoft_start( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		if ( ! MicrosoftClient::is_configured() ) {
			return new WP_Error( 'oauth_not_configured', __( 'Microsoft-OAuth ist im Site-Setup nicht konfiguriert.', 'itdatex-mailguard' ), [ 'status' => 503 ] );
		}
		$json       = (array) $req->get_json_params();
		$aid        = isset( $json['account_id'] ) ? (int) $json['account_id'] : null;
		$login_hint = trim( (string) ( $json['login_hint'] ?? '' ) );
		if ( $aid ) {
			$row = ImapAccount::find_for_customer( $aid, $cid );
			if ( ! $row ) { return new WP_Error( 'not_found', '', [ 'status' => 404 ] ); }
		} else {
			// Quota nur beim Neuanlegen pruefen
			$quota_err = self::check_imap_quota( $cid );
			if ( $quota_err ) { return $quota_err; }
		}
		$state = StateToken::create( $cid, $aid );
		$url   = MicrosoftClient::authorize_url( $state, $login_hint );
		return new WP_REST_Response( [ 'ok' => true, 'authorize_url' => $url ], 200 );
	}

	public static function oauth_microsoft_callback( WP_REST_Request $req ) {
		$state = (string) $req->get_param( 'state' );
		$code  = (string) $req->get_param( 'code' );
		$err   = (string) $req->get_param( 'error' );

		if ( $err !== '' ) {
			return self::oauth_html( false, sprintf( '%s: %s', $err, (string) $req->get_param( 'error_description' ) ) );
		}
		$claims = StateToken::verify( $state );
		if ( ! $claims ) {
			return self::oauth_html( false, 'state_invalid_or_expired' );
		}
		if ( $code === '' ) {
			return self::oauth_html( false, 'no_code' );
		}
		$tok = MicrosoftClient::exchange_code( $code );
		if ( empty( $tok['ok'] ) ) {
			return self::oauth_html( false, ( $tok['error'] ?? 'token_error' ) . ': ' . ( $tok['detail'] ?? '' ) );
		}
		$email = (string) ( $tok['email'] ?? '' );
		if ( $email === '' ) {
			return self::oauth_html( false, 'no_email_in_token' );
		}

		$cid = (int) $claims['cid'];
		$aid = $claims['aid'];

		if ( $aid ) {
			$row = ImapAccount::find_for_customer( $aid, $cid );
			if ( ! $row ) { return self::oauth_html( false, 'account_gone' ); }
			ImapAccount::store_oauth_tokens( $aid, $cid, $tok );
			ImapAccount::record_test( $aid, $cid, true, 'oauth reconnected ' . $email );
			return self::oauth_html( true, 'Verbindung erneuert: ' . $email );
		}

		// Existierendes Konto fuer gleichen User+Provider wiederverwenden, sonst neu
		$existing = ImapAccount::find_oauth_by_username( $cid, 'microsoft', $email );
		if ( $existing ) {
			ImapAccount::store_oauth_tokens( (int) $existing['id'], $cid, $tok );
			ImapAccount::record_test( (int) $existing['id'], $cid, true, 'oauth reauth ' . $email );
			return self::oauth_html( true, 'Bestehende Verbindung erneuert: ' . $email );
		}

		$new_id = ImapAccount::create( $cid, [
			'label'         => 'Microsoft · ' . $email,
			'host'          => 'outlook.office365.com',
			'port'          => 993,
			'encryption'    => 'ssl',
			'username'      => $email,
			'folder'        => 'INBOX',
			'status'        => 'active',
			'auth_type'     => 'oauth_microsoft',
		] );
		if ( ! $new_id ) {
			return self::oauth_html( false, 'account_create_failed' );
		}
		// oauth_provider + Tokens patchen
		global $wpdb;
		$wpdb->update(
			ImapAccount::table(),
			[ 'oauth_provider' => 'microsoft' ],
			[ 'id' => $new_id, 'customer_id' => $cid ]
		);
		ImapAccount::store_oauth_tokens( $new_id, $cid, $tok );
		ImapAccount::record_test( $new_id, $cid, true, 'oauth verbunden ' . $email );
		ImapFolder::create( $new_id, $cid, 'INBOX' );

		return self::oauth_html( true, 'Postfach verbunden: ' . $email );
	}

	public static function imap_discover( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }

		// Rate-Limit: 10/min/Customer — generös für Autocomplete-Debounce, sperrt aber Loop-Bombing.
		$bucket  = 'mg_disc_' . $cid;
		$counts  = (array) get_transient( $bucket );
		$now     = time();
		$counts  = array_values( array_filter( $counts, static fn( $t ) => is_int( $t ) && $now - $t < 60 ) );
		if ( count( $counts ) >= 10 ) {
			return new WP_Error( 'rate_limited', '', [ 'status' => 429 ] );
		}
		$counts[] = $now;
		set_transient( $bucket, $counts, 60 );

		$email = trim( (string) $req->get_param( 'email' ) );
		if ( $email === '' || ! is_email( $email ) ) {
			return new WP_Error( 'bad_input', __( 'Ungueltige Email.', 'itdatex-mailguard' ), [ 'status' => 400 ] );
		}
		$hit = \Itdatex\Mailguard\Imap\Autoconfig\Resolver::for_email( $email );
		if ( ! $hit ) {
			return new WP_REST_Response( [ 'ok' => true, 'found' => false ], 200 );
		}
		return new WP_REST_Response( [ 'ok' => true, 'found' => true, 'config' => $hit ], 200 );
	}

	/**
	 * Public-Variante des Discover-Endpoints — fuer Register-Page nutzbar,
	 * BEVOR ein Customer-Account existiert. Rate-Limit pro Client-IP
	 * (10/min) statt pro Customer. Nur ProviderRegistry + Mozilla werden
	 * konsultiert, KEIN MS-Autodiscover und KEIN LLM (DSGVO-Schutz —
	 * keine Domain-Uebermittlung an Dritte bevor User einwilligen kann).
	 */
	public static function imap_discover_public( WP_REST_Request $req ) {
		$ip = self::client_ip();
		$bucket = 'mg_disc_pub_' . md5( $ip );
		$counts = (array) get_transient( $bucket );
		$now    = time();
		$counts = array_values( array_filter( $counts, static fn( $t ) => is_int( $t ) && $now - $t < 60 ) );
		if ( count( $counts ) >= 10 ) {
			return new WP_Error( 'rate_limited', '', [ 'status' => 429 ] );
		}
		$counts[] = $now;
		set_transient( $bucket, $counts, 60 );

		$email = trim( (string) $req->get_param( 'email' ) );
		if ( $email === '' || ! is_email( $email ) ) {
			return new WP_Error( 'bad_input', __( 'Ungueltige Email.', 'itdatex-mailguard' ), [ 'status' => 400 ] );
		}
		$pos = strrpos( $email, '@' );
		$domain = $pos !== false ? strtolower( substr( $email, $pos + 1 ) ) : '';

		// Nur datenschutzfreundliche Quellen vor Account-Anlage: Static + Mozilla.
		$hit = \Itdatex\Mailguard\Imap\Autoconfig\ProviderRegistry::lookup_by_domain( $domain )
			?? \Itdatex\Mailguard\Imap\Autoconfig\MozillaAutoconfig::lookup( $domain );
		if ( ! $hit ) {
			return new WP_REST_Response( [ 'ok' => true, 'found' => false ], 200 );
		}
		return new WP_REST_Response( [ 'ok' => true, 'found' => true, 'config' => $hit ], 200 );
	}

	private static function client_ip() : string {
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
		// Vertraue nur eigenen Reverse-Proxy-Headern (nginx auf 127.0.0.1)
		if ( $ip === '127.0.0.1' && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$fwd = explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] );
			$ip  = trim( $fwd[0] );
		}
		return $ip;
	}

	public static function oauth_google_start( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		if ( ! GoogleClient::is_configured() ) {
			return new WP_Error( 'oauth_not_configured', __( 'Google-OAuth ist im Site-Setup nicht konfiguriert.', 'itdatex-mailguard' ), [ 'status' => 503 ] );
		}
		$json       = (array) $req->get_json_params();
		$aid        = isset( $json['account_id'] ) ? (int) $json['account_id'] : null;
		$login_hint = trim( (string) ( $json['login_hint'] ?? '' ) );
		if ( $aid ) {
			$row = ImapAccount::find_for_customer( $aid, $cid );
			if ( ! $row ) { return new WP_Error( 'not_found', '', [ 'status' => 404 ] ); }
		} else {
			$quota_err = self::check_imap_quota( $cid );
			if ( $quota_err ) { return $quota_err; }
		}
		$state = StateToken::create( $cid, $aid );
		$url   = GoogleClient::authorize_url( $state, $login_hint );
		return new WP_REST_Response( [ 'ok' => true, 'authorize_url' => $url ], 200 );
	}

	public static function oauth_google_callback( WP_REST_Request $req ) {
		$state = (string) $req->get_param( 'state' );
		$code  = (string) $req->get_param( 'code' );
		$err   = (string) $req->get_param( 'error' );

		if ( $err !== '' ) {
			return self::oauth_html( false, sprintf( '%s: %s', $err, (string) $req->get_param( 'error_description' ) ) );
		}
		$claims = StateToken::verify( $state );
		if ( ! $claims ) {
			return self::oauth_html( false, 'state_invalid_or_expired' );
		}
		if ( $code === '' ) {
			return self::oauth_html( false, 'no_code' );
		}
		$tok = GoogleClient::exchange_code( $code );
		if ( empty( $tok['ok'] ) ) {
			return self::oauth_html( false, ( $tok['error'] ?? 'token_error' ) . ': ' . ( $tok['detail'] ?? '' ) );
		}
		$email = (string) ( $tok['email'] ?? '' );
		if ( $email === '' ) {
			return self::oauth_html( false, 'no_email_in_token' );
		}

		$cid = (int) $claims['cid'];
		$aid = $claims['aid'];

		if ( $aid ) {
			$row = ImapAccount::find_for_customer( $aid, $cid );
			if ( ! $row ) { return self::oauth_html( false, 'account_gone' ); }
			ImapAccount::store_oauth_tokens( $aid, $cid, $tok );
			ImapAccount::record_test( $aid, $cid, true, 'google oauth reconnected ' . $email );
			return self::oauth_html( true, 'Verbindung erneuert: ' . $email );
		}

		$existing = ImapAccount::find_oauth_by_username( $cid, 'google', $email );
		if ( $existing ) {
			ImapAccount::store_oauth_tokens( (int) $existing['id'], $cid, $tok );
			ImapAccount::record_test( (int) $existing['id'], $cid, true, 'google oauth reauth ' . $email );
			return self::oauth_html( true, 'Bestehende Verbindung erneuert: ' . $email );
		}

		$new_id = ImapAccount::create( $cid, [
			'label'         => 'Google · ' . $email,
			'host'          => 'imap.gmail.com',
			'port'          => 993,
			'encryption'    => 'ssl',
			'username'      => $email,
			'folder'        => 'INBOX',
			'status'        => 'active',
			'auth_type'     => 'oauth_google',
		] );
		if ( ! $new_id ) {
			return self::oauth_html( false, 'account_create_failed' );
		}
		global $wpdb;
		$wpdb->update(
			ImapAccount::table(),
			[ 'oauth_provider' => 'google' ],
			[ 'id' => $new_id, 'customer_id' => $cid ]
		);
		ImapAccount::store_oauth_tokens( $new_id, $cid, $tok );
		ImapAccount::record_test( $new_id, $cid, true, 'google oauth verbunden ' . $email );
		ImapFolder::create( $new_id, $cid, 'INBOX' );

		return self::oauth_html( true, 'Gmail-Postfach verbunden: ' . $email );
	}

	public static function oauth_disconnect( WP_REST_Request $req ) {
		$cid = self::require_customer();
		if ( is_wp_error( $cid ) ) { return $cid; }
		$id  = (int) $req['id'];
		$row = ImapAccount::find_for_customer( $id, $cid );
		if ( ! $row ) { return new WP_Error( 'not_found', '', [ 'status' => 404 ] ); }
		global $wpdb;
		$wpdb->update( ImapAccount::table(), [
			'oauth_access_token_enc'  => '',
			'oauth_refresh_token_enc' => '',
			'oauth_token_expires_at'  => null,
			'status'                  => 'disabled',
		], [ 'id' => $id, 'customer_id' => $cid ] );
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/**
	 * Schlanker HTML-Response fuer den Popup-Callback. Sendet Header + Body
	 * direkt und beendet PHP, weil WP_REST_Response nur JSON/Data sauber
	 * serialisiert. Das Popup macht postMessage zum Opener + window.close.
	 */
	private static function oauth_html( bool $ok, string $msg ) : void {
		$color   = $ok ? '#1f883d' : '#cf222e';
		$title   = $ok ? 'Erfolgreich verbunden' : 'Verbindung fehlgeschlagen';
		$detail  = esc_html( $msg );
		$ok_js   = $ok ? 'true' : 'false';
		$body    = <<<HTML
<!doctype html>
<html lang="de"><head><meta charset="utf-8"><title>{$title}</title>
<style>body{margin:0;font:14px/1.5 system-ui,sans-serif;background:#0d1117;color:#e6edf3;display:flex;align-items:center;justify-content:center;min-height:100vh}
.card{background:#161b22;border:1px solid #30363d;border-radius:8px;padding:32px;max-width:480px;text-align:center}
h1{margin:0 0 8px;color:{$color};font-size:1.4em}
.msg{color:#8b949e;margin:0 0 16px;word-break:break-word}
button{background:#2f81f7;color:#fff;border:0;border-radius:6px;padding:8px 18px;font-weight:600;cursor:pointer}</style>
</head><body><div class="card"><h1>{$title}</h1><p class="msg">{$detail}</p>
<button onclick="notifyAndClose()">Fenster schliessen</button>
<script>
function notifyAndClose(){try{window.opener&&window.opener.postMessage({type:'mg-oauth',ok:{$ok_js}},'*');}catch(e){}window.close();}
setTimeout(notifyAndClose,800);
</script>
</div></body></html>
HTML;
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $body;
		exit;
	}

	private static function respond( array $res ) : WP_REST_Response {
		$status = self::status_for( $res );
		$resp = new WP_REST_Response( $res, $status );
		if ( $status === 429 && isset( $res['retry_after'] ) ) {
			$resp->header( 'Retry-After', (string) (int) $res['retry_after'] );
		}
		return $resp;
	}

	private static function status_for( array $res ) : int {
		if ( ! empty( $res['ok'] ) ) {
			return 200;
		}
		return match ( $res['error'] ?? '' ) {
			Auth::ERR_RATE                                 => 429,
			Auth::ERR_DUPLICATE                            => 409,
			Auth::ERR_INVALID_CREDS, Auth::ERR_UNVERIFIED  => 401,
			Auth::ERR_SUSPENDED                            => 403,
			Auth::ERR_TOKEN                                => 410,
			'license_required'                             => 402,
			Auth::ERR_BAD_INPUT, 'registration_disabled', 'create_failed' => 400,
			default                                        => 500,
		};
	}
}
