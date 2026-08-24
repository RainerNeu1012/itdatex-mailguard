=== itdatex MailGuard ===
Contributors: itdatex
Tags: anti-phishing, spam, mail, oauth, saas
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.34.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multi-Tenant-Portal fuer Anti-Phishing, Spam-Schutz und Newsletter-Abmeldung. Endkunden verbinden Outlook/Gmail per OAuth oder eigenes IMAP.

== Description ==

**itdatex MailGuard** ist die WordPress-Seite eines Multi-Tenant-Systems: Der Site-Owner (Agentur, IT-Dienstleister, MSP) betreibt das Plugin als Kunden-Portal, seine Endkunden verbinden ihre Postfaecher darueber und sehen ausschliesslich ihre eigenen Mails, Regeln und Statistiken.

= Was das Plugin macht =

* **Postfach-Anbindung** — Endkunden verbinden ihr Postfach per Microsoft-OAuth (Outlook/Microsoft 365), Google-OAuth (Gmail/Workspace) oder klassisch per IMAP mit Auto-Discovery.
* **Antiphish-Scan** — eingehende Mails werden gegen die zugehoerige `antiphish`-API (lokales LLM auf einem Server in Deutschland) geprueft; Treffer werden mit Score, Kategorie und Begruendung im Kunden-Portal angezeigt.
* **Regel-System** — Absender-Whitelist, Absender-Block, Content-Filter mit Action `quarantine|delete` pro Regel. Sender-Aggregation zeigt pro Absender, ob eine Block- oder Whitelist-Regel bereits aktiv ist und erlaubt einen Rueckgaengig-Klick.
* **Newsletter-Helper** — pro Absender abmeldbar (RFC-8058 List-Unsubscribe / mailto-Fallback).
* **Multi-Tenant-Isolation** — jede Zeile in `mg_*`-Tabellen ist mit `customer_id` verknuepft; REST- und Portal-Endpoints filtern durchgehend serverseitig.

= Voraussetzungen =

* WordPress 6.4+, PHP 8.1+
* Gueltige Lizenz und API-Zugang zur `antiphish`-API (VPS-seitig, wird ueber die Site-Owner-Einstellungen konfiguriert)

= Datenschutz =

Mail-Inhalte werden nur temporaer fuer den Scan-Job an die API uebergeben und nicht persistiert. Die API-Kette (Portal → REST → antiphish) laeuft komplett auf Servern in Deutschland. Kein Third-Party-Analytics, keine externen Fonts, keine Telemetry.

== Installation ==

1. Plugin nach `/wp-content/plugins/itdatex-mailguard/` entpacken.
2. Im WP-Admin unter **Plugins** aktivieren — Aktivierung legt die `mg_*`-Tabellen an und registriert den Cron-Job fuer regelmaessige Scans.
3. Unter **MailGuard → Einstellungen** Lizenzschluessel, API-Endpoint und OAuth-Credentials (Microsoft-/Google-App-Registrierung) eintragen.
4. Portal-Seite via Shortcode `[itdatex_mailguard_portal]` in eine Seite einbetten; Endkunden loggen sich dort ein und verbinden ihr Postfach.

== Changelog ==

= 0.34.0 =
* Vernichten-Dialog bekommt Toggle "Absender kuenftig automatisch vernichten". Anhaken legt eine Blacklist-Regel `from_addr` mit Aktion `purge` an bzw. hebt eine bestehende `quarantine`-Regel an — naechste Mail dieses Absenders wird direkt beim Scan expunget. Wirkt in Inbox-Row, Sender-Vernichten (Newsletters/Inbox) und Quarantaene-Purge. Kein Schema-Change.

= 0.33.0 =
* Absender-Erlauben und Rueckgaengig in Portal und App-Sender-Row. `SenderIndex::list_for_customer` liefert `sender_whitelisted`, `block_rule_id`, `whitelist_rule_id`. Kein Schema-Change.

= 0.32.2 =
* Fix: `Installer::CURRENT_DB_VERSION` von 23 auf 24 gebumpt, damit dbDelta bei Bestand-Installs die `action`-Spalte in `mg_rules` nachzieht.

= 0.32.0 =
* Content-Filter-Regeln mit `action`-Spalte (`quarantine|delete`), Portal-UI und REST-Endpoint.

Aeltere Eintraege siehe `CHANGELOG.md`.
