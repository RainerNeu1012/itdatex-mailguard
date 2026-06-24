<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard;

final class Installer {

	public const OPTION_SETTINGS  = 'itdatex_mailguard_settings';
	public const OPTION_DB_VERSION = 'itdatex_mailguard_db_version';
	public const CURRENT_DB_VERSION = 4;

	public const TABLE_CUSTOMERS     = 'mg_customers';
	public const TABLE_IMAP_ACCOUNTS = 'mg_imap_accounts';
	public const TABLE_MESSAGES      = 'mg_messages';
	public const TABLE_UNSUBS        = 'mg_unsubs';

	public const CRON_PULL_HOOK     = 'itdatex_mailguard_pull_all';
	public const CRON_PULL_SCHEDULE = 'itdatex_mailguard_15min';
	public const CRON_SCAN_HOOK     = 'itdatex_mailguard_scan_pending';
	public const CRON_SCAN_SCHEDULE = 'itdatex_mailguard_5min';

	public static function activate() : void {
		$defaults = [
			'portal_slug'                  => 'portal',
			'allow_registration'           => 1,
			'require_email_verification'   => 1,
			'mail_from_name'               => 'MailGuard',
			'mail_from_address'            => '',  // leer => WP-Default
			'session_ttl_days'             => 14,
			'rate_login_per_min'           => 10,
			'rate_register_per_hour'       => 5,
			'antiphish_api_url'            => 'https://mailsec.itdatex.support',
			'antiphish_api_key'            => '',
			'scan_deep'                    => 0,
			'scan_batch_size'              => 10,
			'manual_scan_quota'            => 50,
		];
		$existing = (array) get_option( self::OPTION_SETTINGS, [] );
		update_option( self::OPTION_SETTINGS, array_merge( $defaults, $existing ), false );

		self::migrate_db();

		// Rewrite-Rules muessen nach Plugin-Aktivierung neu generiert werden,
		// damit /portal/* routet.
		\Itdatex\Mailguard\Portal\Rewrite::register_rules();
		flush_rewrite_rules();

		if ( ! wp_next_scheduled( self::CRON_PULL_HOOK ) ) {
			wp_schedule_event( time() + 300, self::CRON_PULL_SCHEDULE, self::CRON_PULL_HOOK );
		}
		if ( ! wp_next_scheduled( self::CRON_SCAN_HOOK ) ) {
			wp_schedule_event( time() + 120, self::CRON_SCAN_SCHEDULE, self::CRON_SCAN_HOOK );
		}
	}

	public static function deactivate() : void {
		flush_rewrite_rules();
		foreach ( [ self::CRON_PULL_HOOK, self::CRON_SCAN_HOOK ] as $hook ) {
			$ts = wp_next_scheduled( $hook );
			if ( $ts ) {
				wp_unschedule_event( $ts, $hook );
			}
		}
	}

	public static function migrate_db() : void {
		global $wpdb;
		$installed = (int) get_option( self::OPTION_DB_VERSION, 0 );
		if ( $installed >= self::CURRENT_DB_VERSION ) {
			return;
		}

		$charset    = $wpdb->get_charset_collate();
		$t_cust     = $wpdb->prefix . self::TABLE_CUSTOMERS;
		$t_imap     = $wpdb->prefix . self::TABLE_IMAP_ACCOUNTS;

		$sql_customers = "CREATE TABLE {$t_cust} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(190) NOT NULL,
			password_hash VARCHAR(255) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			email_verified TINYINT(1) NOT NULL DEFAULT 0,
			verification_token CHAR(64) NULL,
			verification_expires DATETIME NULL,
			password_reset_token CHAR(64) NULL,
			password_reset_expires DATETIME NULL,
			created_at DATETIME NOT NULL,
			last_login_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_email (email),
			KEY idx_status (status),
			KEY idx_verif_token (verification_token),
			KEY idx_reset_token (password_reset_token)
		) {$charset};";

		$sql_imap = "CREATE TABLE {$t_imap} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_id BIGINT UNSIGNED NOT NULL,
			label VARCHAR(120) NOT NULL DEFAULT '',
			host VARCHAR(255) NOT NULL DEFAULT '',
			port SMALLINT UNSIGNED NOT NULL DEFAULT 993,
			encryption VARCHAR(8) NOT NULL DEFAULT 'ssl',
			username VARCHAR(255) NOT NULL DEFAULT '',
			password_enc TEXT NOT NULL,
			folder VARCHAR(190) NOT NULL DEFAULT 'INBOX',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			last_uid BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_test_at DATETIME NULL,
			last_test_ok TINYINT(1) NULL,
			last_test_detail VARCHAR(500) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_customer (customer_id),
			KEY idx_status (status)
		) {$charset};";

		$t_msg = $wpdb->prefix . self::TABLE_MESSAGES;
		$sql_msg = "CREATE TABLE {$t_msg} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_id BIGINT UNSIGNED NOT NULL,
			account_id BIGINT UNSIGNED NOT NULL,
			imap_uid BIGINT UNSIGNED NOT NULL,
			folder VARCHAR(190) NOT NULL DEFAULT 'INBOX',
			msg_id_hdr VARCHAR(255) NOT NULL DEFAULT '',
			from_addr VARCHAR(320) NOT NULL DEFAULT '',
			from_name VARCHAR(255) NOT NULL DEFAULT '',
			subject VARCHAR(500) NOT NULL DEFAULT '',
			date_hdr DATETIME NULL,
			fetched_at DATETIME NOT NULL,
			has_unsub TINYINT(1) NOT NULL DEFAULT 0,
			list_unsub_raw TEXT NULL,
			list_unsub_post VARCHAR(255) NULL,
			body_preview TEXT NULL,
			scan_status VARCHAR(20) NOT NULL DEFAULT 'pending',
			scan_verdict VARCHAR(20) NOT NULL DEFAULT '',
			scan_score TINYINT UNSIGNED NULL,
			scan_reasons LONGTEXT NULL,
			scanned_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_acct_uid_folder (account_id, imap_uid, folder),
			KEY idx_customer (customer_id),
			KEY idx_account_fetched (account_id, fetched_at),
			KEY idx_scan_status (scan_status)
		) {$charset};";

		$t_uns = $wpdb->prefix . self::TABLE_UNSUBS;
		$sql_uns = "CREATE TABLE {$t_uns} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_id BIGINT UNSIGNED NOT NULL,
			message_id BIGINT UNSIGNED NOT NULL,
			kind VARCHAR(10) NOT NULL DEFAULT '',
			target VARCHAR(2048) NOT NULL DEFAULT '',
			one_click TINYINT(1) NOT NULL DEFAULT 0,
			api_status VARCHAR(40) NOT NULL DEFAULT '',
			api_http_status SMALLINT UNSIGNED NULL,
			api_message_id VARCHAR(255) NOT NULL DEFAULT '',
			api_detail LONGTEXT NULL,
			dsn_status VARCHAR(20) NOT NULL DEFAULT '',
			dsn_detail TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY idx_customer (customer_id),
			KEY idx_message  (message_id),
			KEY idx_status   (api_status)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_customers );
		dbDelta( $sql_imap );
		dbDelta( $sql_msg );
		dbDelta( $sql_uns );

		update_option( self::OPTION_DB_VERSION, self::CURRENT_DB_VERSION, false );
	}
}
