# Changelog

All notable changes to this project. Format based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning
based on [Semantic Versioning](https://semver.org/).

Tagged releases live at
<https://github.com/RainerNeu1012/itdatex-mailguard/releases>.

## [0.31.0] – 2026-07-14

Neu: **TLD-Sperre** (Geo-/Endungs-Block). Analog zu Auto-Vernichten,
aber matched auf Absender-Domain-Endung. `.tm` blockt jede Mail deren
Absender auf `.tm` endet — `foo.tm`, `bar.gmx.tm`, etc.

### Added
- **Neue Tabelle `mg_blocked_tlds`** (customer_id, tld, hit_count) mit
  UNIQUE-Index `(customer_id, tld)`. Speichert TLD-Muster ohne Punkt
  (`tm`, `co.uk`).
- **`Antiphish\BlockedTlds`-Klasse** mit `normalize`, `matches`,
  `list_tlds`, `add/remove/list_for_customer/record_hit`. Match ueber
  `str_ends_with('.$tld')`, kein LIKE-Query pro Mail (Hot-Path).
- **PullService-Hook** nach EradicateDomains-Check: pro Cycle einmal
  die TLD-Liste laden, dann in-memory matchen. Treffer werden per
  `expunge_uids` gebatcht (kein Ingest in mg_messages).
- **REST**: `GET/POST/DELETE /me/blocked-tlds` — analog zu
  `/me/eradicate-domains`.
- **Portal**: „Auto-Vernichten"-View bekommt zwei Tabs
  („Absender-Domains" / „TLD-Sperre") mit Schnellauswahl-Chips fuer
  typische Spam-TLDs (`.tm`, `.tk`, `.ml`, `.ga`, `.cf`, `.icu`,
  `.top`, `.xyz`, `.rest`, `.zip`).

## [0.30.0] – 2026-07-14

Neu: **Content-Fingerprint fuer Kampagnen**. MailGuard erkennt jetzt
Newsletter-Vorlagen und Massenmail-Kampagnen — auch wenn Vorname,
Kundennummer oder Betrag im Subject variieren. Ein Klick loest die
ganze Kampagne auf einmal auf.

### Added
- **Spalte `body_fingerprint CHAR(16)`** in `mg_messages` mit Index
  `(customer_id, body_fingerprint)`. 64-bit SHA-256-Truncate.
- **`Antiphish\Fingerprint::compute()`** normalisiert Subject
  (Ziffern → `#`, Emails → `@`, Punctuation raus) + Sender + Set aus
  Link-Domains. Deterministisch, kein LSH-Kram.
- **`Message::ingest`** berechnet fingerprint beim Insert.
- **Migration DB v22** Backfill fuer alle bestehenden Rows in Batches
  von 500. Bei customer_id=19: 12391 Mails, sinnvolle Cluster wie
  590 Wordfence-Alerts, 274 Apple-Rechnungen, 90 PayPal-Abbuchungen.
- **REST**:
  - `GET /inbox/messages?fingerprint=xxx` — filter auf Kampagne.
  - `GET /inbox/campaigns?min_count=N` — gruppierte Sicht.
  - `POST /inbox/campaigns/{fp}/action` mit
    `action=quarantine|purge|whitelist|blacklist`.
  - `GET /inbox/messages/{id}` liefert `campaign_count` mit.
- **Portal**: neuer Tab „Kampagnen" in Newsletters mit Bulk-Actions
  („Alle in Quarantäne", „Alle endgültig weg", „Absender whitelisten/
  blocken"). Klick auf „Alle Mails zeigen" öffnet die Inbox mit
  Fingerprint-Filter — inklusive Filter-Banner + Zurücksetzen-Button.
- **App-MessageDetail**: 📇-Chip „Kampagne · N Mails" in der
  Meta-Chip-Reihe. Klick öffnet die Kampagne im Portal (Tauri-opener).

## [0.29.0] – 2026-07-14

Neu: **KI-Bewertungen sichtbar + bewertbar**. Statt Reasoning nur als
Chip-Tooltip zu verstecken zeigt MailGuard jetzt eine eigene Card in
MessageDetail (App) und im aufgeklappten Row (Portal). Neue Portal-View
"KI-Bewertungen" listet die letzten 100 Mails fuer Batch-Feedback.

### Added
- **Neue Tabelle `mg_llm_feedback`** (customer_id, message_id,
  from_addr_snap, verdict_snap, score_snap, llm_reasoning_snap,
  thumbs ENUM(up,down), note, created_at). UNIQUE (customer_id,
  message_id) — ein User bewertet jede Mail genau einmal.
- **REST `POST /inbox/messages/{id}/llm-feedback`** speichert die
  Bewertung inkl. Reasoning-Snapshot. **`GET /llm-feedback/recent`**
  fuer die Batch-View mit Filter `unrated|up|down`.
- **`GET /inbox/messages/{id}`** liefert jetzt `llm_feedback` mit,
  damit die Buttons in der App beim erneuten Oeffnen vorbelegt sind.
- **Portal-Route `/portal/llm-feedback`** und Nav-Eintrag
  "KI-Bewertungen". Neue View: Filter-Tabs + Reasoning-Preview pro Row
  + optimistic Update auf 👍/👎-Klick.
- **Inline-Reasoning-Card** im aufgeklappten Portal-Inbox-Row und
  Reasoning-Card in der App-MessageDetail. Semikolon-Split in
  Bullet-Punkte wenn Aufzaehlung, sonst Absatz. Score-abhaengige
  Farbgebung (rot ab 60, gelb ab 30).

## [0.28.0] – 2026-07-14

Neu: **Auto-Whitelist-Vorschlaege**. MailGuard erkennt jetzt, wenn du
dich wiederholt ueber denselben Absender aergerst — und schlaegt eine
passende Regel vor. Ein Klick, statt jedes Mal von Hand.

### Added
- **`SenderTrust::get_suggestion($customer_id, $from_addr)`** liest die
  Trust-Row und liefert einen Vorschlag, wenn:
  - `quarantine_undo_count >= 2` UND keine from_addr-Whitelist existiert
    → **whitelist**-Vorschlag ("Als sicher merken").
  - `quarantine_kept_count >= 3` UND keine from_addr-Blacklist
    → **blacklist**-Vorschlag ("Blockieren").
  - Sobald eine from_addr-Regel welcher Art auch immer existiert:
    kein Vorschlag mehr — der User hat sich entschieden.
- **REST `GET /inbox/messages/{id}`** liefert jetzt `sender_suggestion`
  im Item-Objekt (falls vorhanden).
- **REST `GET /senders/suggestions`** liefert alle offenen Vorschlaege
  des Customers in einem Rutsch — die Inbox-View muss nicht pro Row
  einen extra Query machen.
- **App MessageDetail**: gelber/roter Banner oben mit Reason-Text,
  „Als sicher merken"/„Blockieren"-Button und X-Dismiss (Client-State).
  Ein Klick ruft POST /rules und blendet den Banner aus.
- **Portal-Inbox** (Chrono-Liste): 💡 Vorschlag-Pill neben dem
  Absender-Namen; im aufgeklappten Body ein Banner mit demselben
  Apply/Dismiss-Verhalten wie in der App.

## [0.27.0] – 2026-07-14

Neu: **Sender-Trust-Score**. MailGuard lernt jetzt aus deiner Historie,
welche Absender du kennst und welchen du vertraust — und laesst bekannte
Absender nicht mehr versehentlich in die Auto-Quarantaene wandern. Dein
Undo-Klick von vorhin war nicht mehr verloren, sondern trainiert das
System dauerhaft.

### Added
- **Neue Tabelle `mg_sender_trust`** (customer_id, from_addr, from_domain,
  received_count, whitelist_count, blacklist_count, quarantine_undo_count,
  quarantine_kept_count, first_seen_at, last_seen_at, updated_at) mit
  UNIQUE-Index auf `(customer_id, from_addr)` und Sekundaerindex
  `(customer_id, from_domain)` fuer Domain-Aggregate.
- **`Antiphish\SenderTrust`-Klasse** mit `record_received`,
  `record_whitelist`, `record_blacklist`, `record_quarantine_undo`,
  `record_quarantine_kept` und `get_score`. Alle Signale sind
  idempotente `INSERT ... ON DUPLICATE KEY UPDATE`-Upserts.
- **Signal-Hooks** verdrahtet in:
  - `PullService::pull_folder` beim Message-Insert → `received_count++`
  - `Rules\Rule::create` (match_type=from_addr) → whitelist/blacklist
  - `QuarantineService::undo` (Original-Action war ACTOR_AUTO) → undo
  - `QuarantineService::purge` (Original-Action war ACTOR_AUTO) → kept
- **`sender_trust`-Rule in `ScanService::scan_message`** vor der
  Attachment-Heuristik. Score-Formel:
  - received ≥ 10 → -10, ≥ 50 → -20
  - whitelist ≥ 1 → -30
  - quarantine_undo → -20 pro Undo, max -40
  - quarantine_kept ≥ 2 → **+30** (Absender ist toxisch)
  - Domain-Aggregat: falls Adress-Trust schwach + Domain hat ≥20 empfangene Mails → -10
  - Untergrenze -60.
- **Hard-Signal-Schranke**: Blacklist-Hit, `unresolvable_sender_domain`
  oder `unresolvable_link_domain` deaktivieren den Trust-Bonus. AV-Hit
  ueberstimmt Trust ohnehin via `score_capped = 100`.

### Migration (DB v20)
- One-shot Backfill aus vorhandenen `mg_messages` (Received-Count),
  `mg_rules` (Whitelist/Blacklist-Count) und `mg_actions`
  (Undo/Kept-Count). Damit startet der Trust-Score direkt mit voller
  Postfach-Historie, statt bei null.

## [0.26.0] – 2026-07-14

Bugfix: Systemordner (Sent/Drafts/Trash/Deleted/Outbox/Notes/Archive)
wurden von der IMAP-Ordner-Auto-Discovery als `active` importiert und
vom Pull gescannt. Ergebnis: Auto-Quarantaene verschob eigene und
laengst geloeschte Mails in den Quarantaene-Folder.

### Fixed
- `Folder::sync_from_imap` erkennt Systemordner ueber RFC-6154
  SPECIAL-USE-Flags (fuer den raw-IMAP-Client) und ueber eine DE/EN-
  Namensheuristik (Fallback fuer die c-client-Extension, die keine
  SPECIAL-USE-Konstanten kennt). Systemordner werden mit
  `status='disabled'` angelegt — sichtbar in der UI, aber vom Pull
  ausgeschlossen. `\Junk` bleibt aktiv, das ist der Kernanwendungsfall.
- Migration (DB-Version 18): alle bereits importierten Systemordner-
  Rows werden einmalig auf `disabled` gesetzt. Manuell umbenannte
  Ordner bleiben davon unberuehrt.

### Portal
- Ordner-Liste im Postfaecher-View zeigt fuer disabled Systemordner
  einen Info-Chip „Systemordner – kein Scan" mit Tooltip und ersetzt
  den Pull-Button durch „Aktivieren", falls der User bewusst doch
  scannen moechte.

## [0.10.0] – 2026-07-10

Neues Feature: **Auto-Vernichten pro Absender-Domain**. Ergänzt den
bisherigen `eradicate_sender`-Einmalklick um eine persistente
Domain-Sperre — sobald aktiv, filtert der Pull-Service jede eingehende
Mail der Domain direkt beim IMAP-Fetch heraus, bevor sie in
`mg_messages` oder in der Inbox landet.

### Added
- **Neue Tabelle `mg_eradicate_domains`** (customer_id, domain,
  created_at, last_hit_at, hit_count) mit UNIQUE-Index
  `(customer_id, domain)`. Storage-Layer in
  `Antiphish\EradicateDomains` (add/remove/list/is_active/record_hit +
  extract_domain).
- **Ingest-Interception im `PullService`**. Vor dem Aufruf von
  `Message::ingest` wird die Absender-Domain gegen die Kunden-
  spezifische Auto-Vernichten-Liste geprüft. Bei Treffer wird die UID
  gesammelt und am Ende der Folder-Runde in einem einzigen
  `expunge_uids`-Batch entfernt (spart N Roundtrips). Neuer
  `eradicated`-Counter in der Pull-Zusammenfassung.
- **Neue REST-Endpoints unter `/me/eradicate-domains`**.
  `GET` liefert die Liste inkl. Hit-Stats, `POST` legt eine neue
  Domain an (Confirm-Guard `confirm: "VERNICHTEN"`; optional
  `purge_history: true` löscht bereits eingegangene Mails der Domain),
  `DELETE /{id}` hebt eine Sperre wieder auf.
- **`PurgeService::hard_purge_domain`**. Domain-Variante von
  `hard_purge_sender` mit `LOWER(from_addr) LIKE '%@domain'`. Bewusst
  ohne server-seitigen Orphan-Search (der wäre für eine Domain N
  SEARCH-Calls pro unique Legacy-Sender und unpredictable teuer). Wird
  von `POST /me/eradicate-domains` mit `purge_history: true`
  aufgerufen.
- **Portal-View „Auto-Vernichten"**. Neue Route
  `/portal/eradicate-domains` mit Add-Form (Domain +
  Purge-History-Checkbox), Löschen-Button und Trefferzähler pro
  Domain. Nav-Link zwischen „Regeln" und „Aktionen".
- **Domain-Häkchen im bestehenden Vernichten-Flow**. Nach der
  Type-in-`VERNICHTEN`-Bestätigung in Inbox/Newsletters fragt ein
  zweiter `window.confirm`, ob zusätzlich alle zukünftigen Mails der
  Absender-Domain automatisch vernichtet werden sollen — fängt die
  typische Sub-Adress-Rotation (news@, angebote@, service@) ab, die
  die Sender-Blacklist-Regel nicht abdeckt.

### Changed
- **DB-Schema-Bump 16 → 17.** `dbDelta` idempotent; die neue Tabelle
  wird beim nächsten `migrate_db` angelegt.
- **Plugin-Version 0.9.0 → 0.10.0.**

## [0.9.0] – 2026-07-09

Companion-Release zur ersten Version des MailGuard Windows-Clients
(itdatex-mailguard-desktop v0.1.0). Alle Änderungen in diesem Release
sind Backend/Portal-Voraussetzungen, damit derselbe Portal-Bundle-Code
in einer Tauri-Shell laufen kann + die Windows-App Toasts/Tray-Badge
mit Inhalten füttern kann.

### Added
- **In-App-Notifications-Feed**. Neuer Endpoint-Cluster unter
  `/me/notifications` (`GET` mit `since_id`/`unread_only`,
  `GET /unread-count`, `POST /mark-seen`). Der Server persistiert jetzt
  jedes User-relevante Ereignis (Phishing erkannt, Auto-Quarantäne,
  Undo-Ablauf, Newsletter-Bounce) in einer neuen `mg_notifications`-
  Tabelle. Der bestehende FCM-Push für Mobile/Web bleibt unverändert
  und läuft parallel; die neue Persistenz ist die Wahrheitsquelle für
  Poll-basierte Clients (Windows-Desktop) und die Header-Bell im
  Portal.
- **Notifications-Bell im Portal-Header**. 🔔-Button zeigt Unread-
  Zahl als roter Badge, klick öffnet Dropdown mit den letzten zehn
  Ereignissen. Klick auf einen Eintrag navigiert zur Ziel-Route und
  markiert alles bis dahin als gelesen. „Alle gelesen"-Button setzt
  Badge auf 0 ohne Navigation. Poll läuft alle 60 s bei aktivem Tab,
  alle 5 min wenn Tab im Hintergrund — spart Requests, ohne Reaktions-
  zeit im Vordergrund zu verlieren.
- **Bearer-Auth-Provider-Hook in `assets/portal/api.js`**. Wenn
  `window.itdatexMailguard.authProvider` gesetzt ist (Tauri-Shell
  hängt den Windows-Credential-Manager dahinter), liefert die Portal-
  SPA den Bearer-Token dort statt aus `localStorage`. 401-Antworten
  triggern einen transparenten `/mobile/refresh` + Request-Retry.
  Web + bestehende Mobile-Apps unverändert.
- **Dashboard-Card „🖥 Desktop-Client"** mit Autostart-Toggle. Nur
  sichtbar wenn `window.itdatexMailguard.desktop === true`, sonst
  komplett unsichtbar — Web-Nutzer sehen davon nichts.
- **CORS-Allowlist erweitert** um `tauri://localhost` und
  `https://tauri.localhost` (Standard-Origins von WebView2-basierten
  Tauri-Windows-Builds).
- **Push-Plattform-Enum erweitert** um `windows`/`macos`/`linux`. Der
  eigentliche Push-Weg bleibt FCM; die neuen Enum-Werte erlauben es
  Desktop-Clients, sich für die Notifications-Persistenz zu
  registrieren, ohne dass die bisherige Mobile-/Web-Push-Logik
  angefasst wird.

### Changed
- **DB-Schema-Bump 15 → 16.** dbDelta idempotent — `mg_notifications`
  wird beim nächsten `migrate_db` angelegt (indexiert auf
  `(customer_id, read_at, id)` und `(customer_id, created_at)` für den
  Poll- und History-Zugriff).
- **Router robust gegen `index.html` als Start-Pfad.** Tauri-Shells
  öffnen die App auf `/index.html`; der bisherige Router mappte das
  auf „not-found". Endung wird nun vor dem Route-Matching abgeschnitten
  — Web-Version bleibt unberührt (dort ist die Portal-Root ohnehin
  ohne `index.html` erreichbar).

### Windows-Client (separates Repo)
Der zugehörige Windows-Client `itdatex-mailguard-desktop` v0.1.0 ist
gleichzeitig veröffentlicht:
<https://github.com/RainerNeu1012/itdatex-mailguard-desktop/releases/tag/v0.1.0>

Nutzt die oben genannten Backend-/Portal-Änderungen; Portal-Code wird
dort per Git-Submodule referenziert.

## [0.8.9] – 2026-07-09

### Added
- **„Sender vernichten"** — kombinierter Ein-Klick-Flow, der versucht,
  beim Anbieter abzumelden (best-effort), eine Blacklist-Regel für den
  Absender anlegt und alle bestehenden Mails per IMAP-EXPUNGE endgültig
  löscht (kein Papierkorb, kein Undo). Sichtbar als roter Button auf der
  Newsletter-Seite und in der grupppierten Inbox-Ansicht. Verlangt eine
  Type-in-Bestätigung (`VERNICHTEN` eintippen) — sowohl Frontend als
  auch REST-Endpoint prüfen sie, damit DevTools-Muskelspiel nicht
  ausreicht.
- **Auto-DSN-Poll für mailto-Abmeldungen**. Neuer WP-Cron
  `itdatex_mailguard_unsub_poll` (alle 10 Min.) aktualisiert den
  Bounce-Status offener mailto-Abmeldungen der letzten 48 h. Bounces
  landen dadurch ohne User-Klick in Historie und Notify-Hook. Schedule
  wird beim `plugins_loaded` selbst-heilend nachgezogen, kein DB-Bump
  nötig.
- Neue Public-API: `UnsubService::execute_for_sender`,
  `UnsubService::eradicate_sender`, `Subscriptions::messages_for_sender`,
  `PurgeService::block_sender`, REST `POST /subscriptions/eradicate`.

### Changed
- **Robustere Newsletter-Abmeldung.**
  - `Antiphish\Client` retryt transient fehlgeschlagene Aufrufe (HTTP
    429, 5xx, Netz-/Timeout-Fehler) genau einmal mit 400 ms Backoff.
    mailto-execute bleibt bewusst ohne Retry, damit keine doppelten
    Abmelde-Mails rausgehen.
  - Idempotenz-Guard: schon erfolgreich abgemeldete Mails hitten die
    API nicht mehr erneut. Doppelklick-Race ist zusätzlich per
    Transient-Lock (60 s, per Kunde+Message) abgesichert — verhindert
    duplizierte `mg_unsubs`-Zeilen.
  - Bulk-Abmeldung fällt bei toten oder fehlenden Endpoints auf ältere
    Absender-Mails zurück (bis zu 5), statt sofort aufzugeben. Ältere
    Kampagnen haben häufiger noch gültige Tokens.
- REST-Statuscodes klarer getrennt: `already`/`ok`/`needs_manual`/
  `endpoints_dead` → 200, `not_found` → 404, `in_progress` (Lock) →
  409, `no_options` → 422, sonst 502. UI kann echte Backend-Ausfälle
  von "Provider spielt nicht mit" unterscheiden.
- Portal-UI zeigt konkrete Fehlerursachen (`attempts[]`/`detail`) statt
  „Status: unbekannt"; der `endpoints_dead`-Zweig ist auch auf der
  Newsletter-Seite verfügbar (nicht mehr nur in Inbox).

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

[0.9.0]: https://github.com/RainerNeu1012/itdatex-mailguard/releases/tag/v0.9.0
[0.8.9]: https://github.com/RainerNeu1012/itdatex-mailguard/releases/tag/v0.8.9
[0.8.8]: https://github.com/RainerNeu1012/itdatex-mailguard/releases/tag/v0.8.8
[0.8.7]: https://github.com/RainerNeu1012/itdatex-mailguard/releases/tag/v0.8.7
