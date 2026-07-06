# Changelog

All notable changes to this project. Format based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning
based on [Semantic Versioning](https://semver.org/).

Tagged releases live at
<https://github.com/RainerNeu1012/itdatex-mailguard/releases>.

## [0.8.8] – 2026-07-06

### Added
- **Manuelle Plan-Freischaltung** in der WP-Admin-Endkundenliste. Neue
  Row-Action „Plan" öffnet einen Editor, in dem der Site-Owner einem
  Kunden einen Plan (`free/solo/plus/pro/test`) und ein optionales
  Ablaufdatum (`plan_grace_until`) ohne Stripe zuweisen kann — für
  Comp-Konten, Rechnungskunden oder Fixes nach verpassten Webhooks.
- **1-Klick „E-Mail verifizieren"** in der Endkundenliste, sichtbar
  nur bei noch nicht verifizierten Konten. Setzt `email_verified=1`
  und `status=active`.
- Neue Plan-Spalte in der Endkundenliste zeigt Slug + Grace-Datum.
- Public API: `Customer\Account::set_plan_manual(int $id, array $plan, ?int $grace_ts)`.
  Respektiert den bestehenden `cloud_consent_at` — ohne Consent bleibt
  `llm_enabled` off, selbst wenn der Plan es erlauben würde
  (identische Guard-Logik wie im Stripe-Webhook).

### Warnhinweis in der UI
- Wenn ein Kunde bereits ein Stripe-Abo hat (`stripe_subscription_id`
  gesetzt), warnt der Plan-Editor, dass die manuelle Zuweisung beim
  nächsten Webhook wieder überschrieben wird.

## [0.8.7] – 2026-07-06

Bugfix follow-up to 0.8.6 (which was released untagged). The v0.8.7
GitHub release doubles as the announcement for the 0.8.6 feature drop.

### Fixed
- **Portal router**: `Geräte` header button now actually routes to
  the Devices page. `router.js:currentRoute()` had no `case 'devices'`
  and was returning `not-found`.
- **Portal self-revoke**: redirect after revoking your own session
  now uses `portalUrl('login')`. Was resolving relative to the
  current URL and landing on `/portal/devices/login/`.

## [0.8.6] – 2026-07-06 (untagged; folded into v0.8.7)

### Added
- **Web-session revocation.** New `mg_web_sessions` tracking table
  records cookie sessions per JTI, User-Agent, and IP. `Session::start`
  inserts a row, `Session::destroy` revokes it. `Token::verify_session`
  now checks a `wp_options`-backed JTI blacklist (auto-prunes expired
  entries on every revoke). REST endpoints `GET/DELETE /me/web-sessions`.
- **Devices page (`/portal/devices`)** shows Browser-Sessions with a
  "this browser" badge, cross-revoke and self-revoke both wired end-
  to-end. Self-revoke redirects to the login page; cross-revoke kills
  the target cookie at the next request.
- **Mobile-app REST API.** Long-lived Bearer tokens with refresh
  rotation (`Customer\ApiToken` + `mg_api_tokens`), verified alongside
  cookie sessions in `Session::current_customer_id`.
- **CORS allowlist** scoped to the plugin REST namespace
  (`Rest\Cors`). Default origins cover Capacitor / Ionic / local dev.
- **FCM v1 push provider** (`Notify\PushService` / `Device` / `Hooks`
  + `mg_push_devices`). Silent no-op until the FCM service-account
  JSON is entered in Admin → Settings → Mobile-App & Push.
- **Notify hooks** fire on dangerous verdict, auto-quarantine, undo
  expiry (daily cron), and unsub bounce.
- **IMAP folder auto-sync** per pull, so new server-side folders
  show up in MailGuard without manual re-config.

### Changed
- Schema bumped to v14 (`dbDelta` idempotent — `mg_api_tokens`,
  `mg_push_devices`, `mg_web_sessions` created on next `migrate_db`).

## [0.8.4] – 2026-07-06 (untagged; folded into v0.8.7)

### Fixed
- **Bulk delete on the grouped-by-sender inbox** ("Alle N löschen")
  no longer times out. Was opening one IMAP connection per mail —
  slow providers like IONOS summed past the FPM timeout and produced
  502s. Now batched: one connection per `(account, folder)` group,
  move to the MailGuard quarantine folder, then a single folder-safe
  expunge per account.
- **Source-folder expunge safety**. Previously a folder-wide
  `imap_expunge()` could sweep along `\Deleted` markers set by other
  IMAP clients (Thunderbird, Alpine). Purge now moves to the
  MailGuard-owned quarantine folder and expunges there, where
  folder-wide expunge is inherently safe.
- **Legacy quarantine actions** with `target_uid=0` and no
  Message-ID header now soft-purge — MailGuard row + audit are
  cleaned, the (unlocatable) server copy stays as a silent orphan
  in quarantine. Previously hard-failed with `target_uid_unknown`
  mapped to HTTP 502.

## Earlier releases

Prior 0.8.x point-bumps (0.8.0–0.8.3, 0.8.5) shipped without
release notes; substance is captured in this file at the release
that first tagged them (0.8.7). For older 0.7.x releases see the
`chore(release):` commits in `git log`, e.g.:

- `04529bc chore(release): 0.7.2 — block-sender, iCloud MX autoconfig, endpoints_dead unsub`

[0.8.8]: https://github.com/RainerNeu1012/itdatex-mailguard/releases/tag/v0.8.8
[0.8.7]: https://github.com/RainerNeu1012/itdatex-mailguard/releases/tag/v0.8.7
