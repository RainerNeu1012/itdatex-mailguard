<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard;

final class Installer {

	public const OPTION_SETTINGS  = 'itdatex_mailguard_settings';
	public const OPTION_DB_VERSION = 'itdatex_mailguard_db_version';
	public const CURRENT_DB_VERSION = 14;

	// Versions-String der aktuellen Cloud-Consent-Texts. Bei jeder
	// Wortlaut-Änderung hochzählen — neue Consent-Erteilungen werden mit dem
	// dann aktuellen String festgeschrieben (cloud_consent_text_version).
	public const CURRENT_CLOUD_CONSENT_VERSION = 'v1-2026-06-29';

	// Minimal-Schwelle für Auto-Quarantäne — unter diesem Score lässt die UI/REST
	// keine Konfiguration zu. 60 entspricht dem Übergang clean→suspicious aus
	// dem Phishing-Tuning; alles darunter würde auch saubere Mails wegräumen.
	public const AUTO_QUARANTINE_MIN_SCORE = 60;

	public const TABLE_CUSTOMERS     = 'mg_customers';
	public const TABLE_IMAP_ACCOUNTS = 'mg_imap_accounts';
	public const TABLE_IMAP_FOLDERS  = 'mg_imap_folders';
	public const TABLE_MESSAGES      = 'mg_messages';
	public const TABLE_UNSUBS        = 'mg_unsubs';
	public const TABLE_RULES         = 'mg_rules';
	public const TABLE_ACTIONS       = 'mg_actions';
	public const TABLE_API_TOKENS    = 'mg_api_tokens';
	public const TABLE_PUSH_DEVICES  = 'mg_push_devices';
	public const TABLE_WEB_SESSIONS  = 'mg_web_sessions';
	public const TABLE_ATTACHMENTS   = 'mg_attachments';

	public const CRON_UNDO_EXPIRY_HOOK = 'itdatex_mailguard_undo_expiry_check';

	// Bewusst ASCII-only: IMAP-Mailbox-Namen mit Nicht-ASCII brauchen MUTF-7-Encoding
	// (RFC 3501), das nicht jeder Server gleich handhabt. Englischer Name funktioniert
	// überall; UI kann „Quarantäne" anzeigen, der Server-Name bleibt unverändert.
	public const DEFAULT_QUARANTINE_FOLDER = 'MailGuard/Quarantine';
	public const ACTION_UNDO_TTL_DAYS      = 7;

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
			'license_key'                  => '',
			'oauth_microsoft_client_id'    => '',
			'oauth_microsoft_client_secret'=> '',
			'oauth_microsoft_tenant'       => 'common',
			'oauth_google_client_id'       => '',
			'oauth_google_client_secret'   => '',
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
		if ( ! wp_next_scheduled( self::CRON_UNDO_EXPIRY_HOOK ) ) {
			// Taeglich 09:00 UTC — Push-Reminder fuer Undo-Faelle, deren 7-Tage-
			// Fenster in <24h ablaeuft.
			$next = strtotime( 'tomorrow 09:00 UTC' );
			wp_schedule_event( $next, 'daily', self::CRON_UNDO_EXPIRY_HOOK );
		}
	}

	public static function deactivate() : void {
		flush_rewrite_rules();
		foreach ( [ self::CRON_PULL_HOOK, self::CRON_SCAN_HOOK, self::CRON_UNDO_EXPIRY_HOOK ] as $hook ) {
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
			plan_slug VARCHAR(20) NOT NULL DEFAULT 'free',
			plan_status VARCHAR(20) NOT NULL DEFAULT 'active',
			imap_quota SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			llm_enabled TINYINT(1) NOT NULL DEFAULT 0,
			stripe_customer_id VARCHAR(64) NULL,
			stripe_subscription_id VARCHAR(64) NULL,
			plan_grace_until DATETIME NULL,
			cloud_consent_at DATETIME NULL,
			cloud_consent_text_version VARCHAR(20) NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_email (email),
			KEY idx_status (status),
			KEY idx_verif_token (verification_token),
			KEY idx_reset_token (password_reset_token),
			KEY idx_stripe_sub (stripe_subscription_id),
			KEY idx_plan (plan_slug, plan_status)
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
			auth_type VARCHAR(20) NOT NULL DEFAULT 'basic',
			oauth_provider VARCHAR(20) NOT NULL DEFAULT '',
			oauth_access_token_enc TEXT NULL,
			oauth_refresh_token_enc TEXT NULL,
			oauth_token_expires_at DATETIME NULL,
			oauth_scope TEXT NULL,
			quarantine_folder VARCHAR(190) NOT NULL DEFAULT '',
			auto_quarantine_min_score TINYINT UNSIGNED NULL,
			PRIMARY KEY (id),
			KEY idx_customer (customer_id),
			KEY idx_status (status),
			KEY idx_auth_type (auth_type)
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
			has_attachments TINYINT(1) NOT NULL DEFAULT 0,
			attachment_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			scan_status VARCHAR(20) NOT NULL DEFAULT 'pending',
			scan_verdict VARCHAR(20) NOT NULL DEFAULT '',
			scan_score TINYINT UNSIGNED NULL,
			scan_reasons LONGTEXT NULL,
			scanned_at DATETIME NULL,
			quarantine_action_id BIGINT UNSIGNED NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_acct_uid_folder (account_id, imap_uid, folder),
			KEY idx_customer (customer_id),
			KEY idx_account_fetched (account_id, fetched_at),
			KEY idx_scan_status (scan_status),
			KEY idx_quarantine (quarantine_action_id)
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

		$t_rul = $wpdb->prefix . self::TABLE_RULES;
		$sql_rul = "CREATE TABLE {$t_rul} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_id BIGINT UNSIGNED NOT NULL,
			kind VARCHAR(10) NOT NULL DEFAULT 'whitelist',
			match_type VARCHAR(20) NOT NULL DEFAULT 'from_addr',
			pattern VARCHAR(500) NOT NULL DEFAULT '',
			note VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_customer_kind (customer_id, kind)
		) {$charset};";

		$t_fol = $wpdb->prefix . self::TABLE_IMAP_FOLDERS;
		$sql_fol = "CREATE TABLE {$t_fol} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			account_id BIGINT UNSIGNED NOT NULL,
			customer_id BIGINT UNSIGNED NOT NULL,
			folder_name VARCHAR(190) NOT NULL,
			display_name VARCHAR(190) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			last_uid BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_test_at DATETIME NULL,
			last_test_ok TINYINT(1) NULL,
			last_test_detail VARCHAR(500) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_acct_folder (account_id, folder_name),
			KEY idx_customer_status (customer_id, status),
			KEY idx_status (status)
		) {$charset};";

		$t_act = $wpdb->prefix . self::TABLE_ACTIONS;
		$sql_act = "CREATE TABLE {$t_act} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_id BIGINT UNSIGNED NOT NULL,
			account_id BIGINT UNSIGNED NOT NULL,
			message_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(20) NOT NULL,
			source_folder VARCHAR(190) NOT NULL DEFAULT '',
			source_uid BIGINT UNSIGNED NOT NULL DEFAULT 0,
			target_folder VARCHAR(190) NOT NULL DEFAULT '',
			target_uid BIGINT UNSIGNED NOT NULL DEFAULT 0,
			verdict_snap VARCHAR(20) NOT NULL DEFAULT '',
			verdict_score_snap TINYINT UNSIGNED NULL,
			subject_snap VARCHAR(500) NOT NULL DEFAULT '',
			from_addr_snap VARCHAR(320) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'done',
			actor VARCHAR(10) NOT NULL DEFAULT 'user',
			error_detail VARCHAR(500) NULL,
			undo_until DATETIME NULL,
			undone_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_customer_created (customer_id, created_at),
			KEY idx_message (message_id),
			KEY idx_action_status (action, status)
		) {$charset};";

		$t_tok = $wpdb->prefix . self::TABLE_API_TOKENS;
		$sql_tok = "CREATE TABLE {$t_tok} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_id BIGINT UNSIGNED NOT NULL,
			token_hash CHAR(64) NOT NULL,
			refresh_hash CHAR(64) NULL,
			name VARCHAR(120) NOT NULL DEFAULT '',
			platform VARCHAR(10) NOT NULL DEFAULT 'unknown',
			last_used_at DATETIME NULL,
			access_expires_at DATETIME NOT NULL,
			refresh_expires_at DATETIME NULL,
			revoked_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_token (token_hash),
			KEY idx_refresh (refresh_hash),
			KEY idx_customer (customer_id)
		) {$charset};";

		$t_att = $wpdb->prefix . self::TABLE_ATTACHMENTS;
		// Pro Mail-Anhang ein Eintrag. Wir speichern KEINE Bytes — nur die MIME-
		// Metadaten aus IMAP BODYSTRUCTURE. Das reicht fuer Sichtbarkeits-Anzeige
		// (Dateiname/Groesse/Typ) und heuristische Warnungen (gefaehrliche
		// Extensions, doppelte Endungen, MIME/Extension-Mismatch). Fuer echte
		// Deep-Scans muesste die Datei nachgeladen werden — separater Ausbau.
		$sql_att = "CREATE TABLE {$t_att} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_id BIGINT UNSIGNED NOT NULL,
			message_id BIGINT UNSIGNED NOT NULL,
			part_num VARCHAR(20) NOT NULL DEFAULT '',
			filename VARCHAR(500) NOT NULL DEFAULT '',
			mime_type VARCHAR(190) NOT NULL DEFAULT '',
			size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
			encoding VARCHAR(30) NOT NULL DEFAULT '',
			is_suspicious TINYINT(1) NOT NULL DEFAULT 0,
			suspicion_reasons LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_customer (customer_id),
			KEY idx_message (message_id),
			KEY idx_suspicious (is_suspicious)
		) {$charset};";

		$t_dev = $wpdb->prefix . self::TABLE_PUSH_DEVICES;
		$sql_dev = "CREATE TABLE {$t_dev} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_id BIGINT UNSIGNED NOT NULL,
			token_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			platform VARCHAR(10) NOT NULL DEFAULT 'unknown',
			fcm_token VARCHAR(512) NOT NULL,
			device_label VARCHAR(120) NOT NULL DEFAULT '',
			events_mask INT UNSIGNED NOT NULL DEFAULT 15,
			last_seen_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_fcm (fcm_token),
			KEY idx_customer (customer_id),
			KEY idx_token (token_id)
		) {$charset};";

		// Web-Sessions: Tracking-Only. Der HMAC-signierte Session-Cookie ist
		// weiterhin die eigentliche Auth-Quelle (kein DB-Lookup pro Request);
		// diese Tabelle listet nur, wo der User gerade eingeloggt ist, damit
		// er einzelne Browser-Sessions gezielt beenden kann. Revoke setzt
		// revoked_at UND schreibt den JTI in die Token-Blacklist — nur die
		// Blacklist wirkt bei verify_session.
		$t_web = $wpdb->prefix . self::TABLE_WEB_SESSIONS;
		$sql_web = "CREATE TABLE {$t_web} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_id BIGINT UNSIGNED NOT NULL,
			jti CHAR(16) NOT NULL,
			ua VARCHAR(255) NOT NULL DEFAULT '',
			ip VARCHAR(45) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			last_seen_at DATETIME NULL,
			expires_at DATETIME NOT NULL,
			revoked_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_jti (jti),
			KEY idx_customer (customer_id, revoked_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_customers );
		dbDelta( $sql_imap );
		dbDelta( $sql_msg );
		dbDelta( $sql_uns );
		dbDelta( $sql_rul );
		dbDelta( $sql_fol );
		dbDelta( $sql_act );
		dbDelta( $sql_tok );
		dbDelta( $sql_dev );
		dbDelta( $sql_web );
		dbDelta( $sql_att );

		// One-shot Migration: aus jedem bestehenden Account einen Folder-Eintrag
		// erzeugen. Nur wenn die Folder-Tabelle leer ist UND mind. ein Account
		// existiert, damit frische Installs nichts kaputt machen.
		$folder_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_fol}" );
		$account_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_imap}" );
		if ( $folder_count === 0 && $account_count > 0 ) {
			$wpdb->query( "INSERT INTO {$t_fol}
				(account_id, customer_id, folder_name, display_name, status, last_uid, created_at)
				SELECT id, customer_id, IFNULL(NULLIF(folder, ''), 'INBOX'), '', 'active', last_uid, COALESCE(created_at, UTC_TIMESTAMP())
				FROM {$t_imap}" );
		}

		update_option( self::OPTION_DB_VERSION, self::CURRENT_DB_VERSION, false );
	}
}
