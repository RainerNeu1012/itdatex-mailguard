<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard;

final class Installer {

	public const OPTION_SETTINGS  = 'itdatex_mailguard_settings';
	public const OPTION_DB_VERSION = 'itdatex_mailguard_db_version';
	public const CURRENT_DB_VERSION = 24;

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
	public const TABLE_NOTIFICATIONS = 'mg_notifications';
	public const TABLE_ERADICATE_DOMAINS = 'mg_eradicate_domains';
	public const TABLE_SENDER_TRUST      = 'mg_sender_trust';
	public const TABLE_LLM_FEEDBACK      = 'mg_llm_feedback';
	public const TABLE_BLOCKED_TLDS      = 'mg_blocked_tlds';

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
	// Poll DSN/Bounce-Status für offene mailto-Abmeldungen. Sonst bleibt ein
	// Bounce unbemerkt und der User denkt, die Abmeldung sei durchgegangen.
	public const CRON_UNSUB_POLL_HOOK     = 'itdatex_mailguard_unsub_poll';
	public const CRON_UNSUB_POLL_SCHEDULE = 'itdatex_mailguard_10min';

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
			'av_clamav_enabled'            => 0,
			'av_clamav_socket'             => '/var/run/clamav/clamd.ctl',
			'av_clamav_timeout'            => 15,
			'av_max_bytes'                 => 26214400, // 25 MiB
			'av_notify_admin'              => 1,
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
		if ( ! wp_next_scheduled( self::CRON_UNSUB_POLL_HOOK ) ) {
			wp_schedule_event( time() + 600, self::CRON_UNSUB_POLL_SCHEDULE, self::CRON_UNSUB_POLL_HOOK );
		}
	}

	public static function deactivate() : void {
		flush_rewrite_rules();
		foreach ( [ self::CRON_PULL_HOOK, self::CRON_SCAN_HOOK, self::CRON_UNDO_EXPIRY_HOOK, self::CRON_UNSUB_POLL_HOOK ] as $hook ) {
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
			body_fingerprint CHAR(16) NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_acct_uid_folder (account_id, imap_uid, folder),
			KEY idx_customer (customer_id),
			KEY idx_account_fetched (account_id, fetched_at),
			KEY idx_scan_status (scan_status),
			KEY idx_quarantine (quarantine_action_id),
			KEY idx_customer_fingerprint (customer_id, body_fingerprint)
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
			action VARCHAR(20) NOT NULL DEFAULT 'quarantine',
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
			av_status VARCHAR(20) NOT NULL DEFAULT '',
			av_signature VARCHAR(255) NOT NULL DEFAULT '',
			av_scanned_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_customer (customer_id),
			KEY idx_message (message_id),
			KEY idx_suspicious (is_suspicious),
			KEY idx_av_status (av_status)
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

		// In-App-Notifications. Der Server persistiert jedes Ereignis, das der
		// User sehen soll (Phishing erkannt, Auto-Quarantäne, Undo-Fenster
		// läuft aus, Newsletter-Abmeldung gebounced). Portal + Desktop-Client
		// pollen /me/notifications und zeigen Toasts + Header-Badge — der
		// Desktop-Client ist auf diese Tabelle angewiesen, weil auf Windows
		// kein zuverlässiger Push-Kanal existiert (FCM in WebView2 ist fragil).
		// Der bestehende FCM-Push (Mobile/Web) bleibt unverändert und läuft
		// parallel: dieselben Notify\Hooks feuern beides.
		$t_not = $wpdb->prefix . self::TABLE_NOTIFICATIONS;
		$sql_not = "CREATE TABLE {$t_not} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_id BIGINT UNSIGNED NOT NULL,
			event VARCHAR(40) NOT NULL DEFAULT '',
			title VARCHAR(200) NOT NULL DEFAULT '',
			body VARCHAR(1000) NOT NULL DEFAULT '',
			route VARCHAR(200) NOT NULL DEFAULT '',
			message_id BIGINT UNSIGNED NULL,
			action_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			read_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY idx_customer_unread (customer_id, read_at, id),
			KEY idx_customer_created (customer_id, created_at)
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

		// Persistente Absender-Domain-Auto-Vernichtung: sobald hier eine Domain
		// eingetragen ist, filtert der Pull-Service jede neue Mail vom passenden
		// Absender direkt beim IMAP-Fetch heraus (kein Ingest, keine DB-Zeile).
		// last_hit_at + hit_count sind rein informativ fuer den User.
		$t_erd = $wpdb->prefix . self::TABLE_ERADICATE_DOMAINS;
		$sql_erd = "CREATE TABLE {$t_erd} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_id BIGINT UNSIGNED NOT NULL,
			domain VARCHAR(190) NOT NULL,
			created_at DATETIME NOT NULL,
			last_hit_at DATETIME NULL,
			hit_count INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_customer_domain (customer_id, domain),
			KEY idx_customer (customer_id)
		) {$charset};";

		// TLD-Block pro Customer: filtert alle Mails deren Absender-Domain
		// auf ".<tld>" endet direkt vor dem Ingest weg. `tld` speichert das
		// Muster ohne fuehrenden Punkt (z.B. `tm`, `co.uk`).
		$t_btlds = $wpdb->prefix . self::TABLE_BLOCKED_TLDS;
		$sql_btlds = "CREATE TABLE {$t_btlds} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_id BIGINT UNSIGNED NOT NULL,
			tld VARCHAR(190) NOT NULL,
			created_at DATETIME NOT NULL,
			last_hit_at DATETIME NULL,
			hit_count INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_customer_tld (customer_id, tld),
			KEY idx_customer (customer_id)
		) {$charset};";

		// Sender-Trust-Score: pro (customer_id, from_addr) akkumulieren wir
		// Received/Whitelist/Blacklist/Undo/Purge-Signale. ScanService liest
		// daraus einen negativen Score, damit bekannte Absender nicht mehr
		// versehentlich in Quarantaene wandern. Domain-Aggregat via
		// from_domain-Index laesst neue Sub-Absender einer bekannten Domain
		// Basis-Trust erben.
		// Trainingsdaten fuer spaeteres LLM-Feintuning. Ein User kann pro Mail
		// genau einmal 👍 oder 👎 abgeben — die snap-Felder frieren Reasoning
		// und Verdict-Score zum Feedback-Zeitpunkt ein, damit spaetere Rescans
		// nicht die Ground-Truth-Referenz verschieben.
		$t_llmfb = $wpdb->prefix . self::TABLE_LLM_FEEDBACK;
		$sql_llmfb = "CREATE TABLE {$t_llmfb} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_id BIGINT UNSIGNED NOT NULL,
			message_id BIGINT UNSIGNED NOT NULL,
			from_addr_snap VARCHAR(320) NOT NULL DEFAULT '',
			verdict_snap VARCHAR(20) NOT NULL DEFAULT '',
			score_snap TINYINT UNSIGNED NULL,
			llm_reasoning_snap TEXT NULL,
			thumbs ENUM('up','down') NOT NULL,
			note VARCHAR(500) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_customer_message (customer_id, message_id),
			KEY idx_customer_created (customer_id, created_at),
			KEY idx_thumbs (thumbs)
		) {$charset};";

		$t_trust = $wpdb->prefix . self::TABLE_SENDER_TRUST;
		$sql_trust = "CREATE TABLE {$t_trust} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_id BIGINT UNSIGNED NOT NULL,
			from_addr VARCHAR(320) NOT NULL,
			from_domain VARCHAR(190) NOT NULL DEFAULT '',
			received_count INT UNSIGNED NOT NULL DEFAULT 0,
			whitelist_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			blacklist_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			quarantine_undo_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			quarantine_kept_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			first_seen_at DATETIME NOT NULL,
			last_seen_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_customer_addr (customer_id, from_addr),
			KEY idx_customer_domain (customer_id, from_domain)
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
		dbDelta( $sql_erd );
		dbDelta( $sql_btlds );
		dbDelta( $sql_not );
		dbDelta( $sql_trust );
		dbDelta( $sql_llmfb );

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

		// DB v18 (& v19-Nachlauf): Systemordner (Sent/Drafts/Trash/Deleted/Outbox/
		// Notes/Archive/Synchronisierungsprobleme) wurden bis v0.25.0 als 'active'
		// importiert und vom Pull gescannt. Das fuehrte zur Auto-Quarantaenisierung
		// eigener und geloeschter Mails. Bestehende Rows werden hier auf 'disabled'
		// zurueckgesetzt; der neue Filter in Folder::sync_from_imap verhindert das
		// Wiederkommen. DB v19 nimmt zusaetzlich Sub-Folder von Systemordnern
		// mit ("Deleted/Quarantine", "Synchronisierungsprobleme/Konflikte") —
		// dafuer die Segment-basierte Erkennung in Folder::is_system_folder.
		if ( $installed < 19 ) {
			$rows = $wpdb->get_results( "SELECT id, folder_name FROM {$t_fol} WHERE status = 'active'", ARRAY_A ) ?: [];
			foreach ( $rows as $row ) {
				if ( \Itdatex\Mailguard\Imap\Folder::is_system_folder( (string) $row['folder_name'] ) ) {
					$wpdb->update( $t_fol, [ 'status' => 'disabled' ], [ 'id' => (int) $row['id'] ] );
				}
			}
		}

		// DB v20: Backfill Sender-Trust. Aggregiert Received-Count aus mg_messages,
		// Whitelist/Blacklist-Count aus mg_rules, Undo/Kept-Count aus mg_actions.
		// Damit startet der Trust-Score bei bestehenden Postfaechern nicht bei 0,
		// sondern mit voller Historie — Stammabsender sind sofort trusted, nicht
		// erst nach der naechsten Runde neuer Mails. INSERT ... SELECT ... ON
		// DUPLICATE KEY UPDATE ist idempotent: bei erneutem Lauf ueberschreibt
		// nur die aggregierten Zahlen, keine Duplikate.
		if ( $installed < 20 ) {
			$t_msg = $wpdb->prefix . self::TABLE_MESSAGES;
			$t_rul = $wpdb->prefix . self::TABLE_RULES;
			$t_act = $wpdb->prefix . self::TABLE_ACTIONS;
			// Received: eine Row pro (customer_id, from_addr).
			$wpdb->query( "
				INSERT INTO {$t_trust}
					(customer_id, from_addr, from_domain, received_count,
					 first_seen_at, last_seen_at, updated_at)
				SELECT
					customer_id,
					LOWER(TRIM(from_addr))                                             AS from_addr,
					LOWER(TRIM(SUBSTRING_INDEX(from_addr, '@', -1)))                   AS from_domain,
					COUNT(*)                                                           AS received_count,
					COALESCE(MIN(date_hdr), MIN(fetched_at))                           AS first_seen_at,
					COALESCE(MAX(date_hdr), MAX(fetched_at))                           AS last_seen_at,
					UTC_TIMESTAMP()                                                    AS updated_at
				FROM {$t_msg}
				WHERE from_addr <> ''
				GROUP BY customer_id, LOWER(TRIM(from_addr))
				ON DUPLICATE KEY UPDATE
					received_count = VALUES(received_count),
					last_seen_at   = GREATEST(last_seen_at, VALUES(last_seen_at)),
					updated_at     = UTC_TIMESTAMP()
			" );
			// Whitelist/Blacklist aus mg_rules mit match_type='from_addr'.
			// Nur exakte Absender-Regeln zaehlen — Domain-/Regex-Regeln sind
			// zu unscharf fuer Trust-Boost.
			$wpdb->query( "
				INSERT INTO {$t_trust}
					(customer_id, from_addr, from_domain,
					 whitelist_count, blacklist_count,
					 first_seen_at, last_seen_at, updated_at)
				SELECT
					customer_id,
					LOWER(TRIM(pattern))                                         AS from_addr,
					LOWER(TRIM(SUBSTRING_INDEX(pattern, '@', -1)))               AS from_domain,
					SUM(CASE WHEN kind = 'whitelist' THEN 1 ELSE 0 END)          AS whitelist_count,
					SUM(CASE WHEN kind = 'blacklist' THEN 1 ELSE 0 END)          AS blacklist_count,
					UTC_TIMESTAMP()                                              AS first_seen_at,
					UTC_TIMESTAMP()                                              AS last_seen_at,
					UTC_TIMESTAMP()                                              AS updated_at
				FROM {$t_rul}
				WHERE match_type = 'from_addr' AND pattern <> ''
				GROUP BY customer_id, LOWER(TRIM(pattern))
				ON DUPLICATE KEY UPDATE
					whitelist_count = VALUES(whitelist_count),
					blacklist_count = VALUES(blacklist_count),
					updated_at      = UTC_TIMESTAMP()
			" );
			// Undo-Count aus mg_actions: quarantine-Aktion + actor=auto + status=undone
			// (der User hat MailGuards Auto-Quarantaene rueckgaengig gemacht).
			$wpdb->query( "
				INSERT INTO {$t_trust}
					(customer_id, from_addr, from_domain,
					 quarantine_undo_count, first_seen_at, last_seen_at, updated_at)
				SELECT
					customer_id,
					LOWER(TRIM(from_addr_snap))                                  AS from_addr,
					LOWER(TRIM(SUBSTRING_INDEX(from_addr_snap, '@', -1)))        AS from_domain,
					COUNT(*)                                                     AS quarantine_undo_count,
					MIN(created_at)                                              AS first_seen_at,
					MAX(created_at)                                              AS last_seen_at,
					UTC_TIMESTAMP()                                              AS updated_at
				FROM {$t_act}
				WHERE action = 'quarantine' AND actor = 'auto' AND status = 'undone'
				  AND from_addr_snap <> ''
				GROUP BY customer_id, LOWER(TRIM(from_addr_snap))
				ON DUPLICATE KEY UPDATE
					quarantine_undo_count = VALUES(quarantine_undo_count),
					updated_at            = UTC_TIMESTAMP()
			" );
			// Kept-Count: purge-Action, deren zugrundeliegende Quarantaene actor=auto war.
			// (der User hat MailGuards Auto-Verdict bestaetigt, indem er die Mail endgueltig geloescht hat).
			$wpdb->query( "
				INSERT INTO {$t_trust}
					(customer_id, from_addr, from_domain,
					 quarantine_kept_count, first_seen_at, last_seen_at, updated_at)
				SELECT
					p.customer_id,
					LOWER(TRIM(p.from_addr_snap))                                AS from_addr,
					LOWER(TRIM(SUBSTRING_INDEX(p.from_addr_snap, '@', -1)))      AS from_domain,
					COUNT(*)                                                     AS quarantine_kept_count,
					MIN(p.created_at)                                            AS first_seen_at,
					MAX(p.created_at)                                            AS last_seen_at,
					UTC_TIMESTAMP()                                              AS updated_at
				FROM {$t_act} p
				JOIN {$t_act} q ON q.message_id = p.message_id
					AND q.action = 'quarantine' AND q.actor = 'auto'
				WHERE p.action = 'purge' AND p.from_addr_snap <> ''
				GROUP BY p.customer_id, LOWER(TRIM(p.from_addr_snap))
				ON DUPLICATE KEY UPDATE
					quarantine_kept_count = VALUES(quarantine_kept_count),
					updated_at            = UTC_TIMESTAMP()
			" );
		}

		// DB v22: body_fingerprint backfill fuer alle bestehenden Rows, damit
		// die Kampagnen-Gruppierung sofort tragfaehig ist. In Batches, damit
		// grosse Postfaecher (12k+) nicht in einem einzigen PHP-Request laufen.
		if ( $installed < 22 ) {
			$t_msg = $wpdb->prefix . self::TABLE_MESSAGES;
			$done = 0;
			while ( true ) {
				$rows = $wpdb->get_results(
					"SELECT id, from_addr, subject, body_preview
					 FROM {$t_msg}
					 WHERE body_fingerprint IS NULL
					 ORDER BY id ASC LIMIT 500",
					ARRAY_A
				) ?: [];
				if ( ! $rows ) { break; }
				foreach ( $rows as $r ) {
					$fp = \Itdatex\Mailguard\Antiphish\Fingerprint::compute(
						(string) $r['from_addr'],
						(string) $r['subject'],
						(string) ( $r['body_preview'] ?? '' )
					);
					$wpdb->update( $t_msg, [ 'body_fingerprint' => $fp ], [ 'id' => (int) $r['id'] ] );
					$done++;
				}
				if ( count( $rows ) < 500 ) { break; }
			}
		}

		update_option( self::OPTION_DB_VERSION, self::CURRENT_DB_VERSION, false );
	}
}
