<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard;

final class Installer {

	public const OPTION_SETTINGS  = 'itdatex_mailguard_settings';
	public const OPTION_DB_VERSION = 'itdatex_mailguard_db_version';
	public const CURRENT_DB_VERSION = 3;

	public const TABLE_CUSTOMERS     = 'mg_customers';
	public const TABLE_IMAP_ACCOUNTS = 'mg_imap_accounts';
	public const TABLE_MESSAGES      = 'mg_messages';

	public const CRON_PULL_HOOK     = 'itdatex_mailguard_pull_all';
	public const CRON_PULL_SCHEDULE = 'itdatex_mailguard_15min';

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
	}

	public static function deactivate() : void {
		flush_rewrite_rules();
		$ts = wp_next_scheduled( self::CRON_PULL_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_PULL_HOOK );
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

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_customers );
		dbDelta( $sql_imap );
		dbDelta( $sql_msg );

		update_option( self::OPTION_DB_VERSION, self::CURRENT_DB_VERSION, false );
	}
}
