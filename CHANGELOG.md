# Changelog

All notable changes to this project. Format based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning
based on [Semantic Versioning](https://semver.org/).

Tagged releases live at
<https://github.com/RainerNeu1012/itdatex-mailguard/releases>.

## [0.43.0] – 2026-09-20

### Added
- **Operator-Settings-Endpoints** (`GET/POST /admin/settings`): read
  Resend-Konfig (Key masked) und update fuer Site-Operator. Gated auf
  neuen `operator_customer_ids`-Setting (Default `[19]` fuer Rainer's
  Account). Andere Customers bekommen 403.

## [0.42.0] – 2026-09-20

### Added
- **Resend-Outbound-Integration** — MailGuard-User koennen jetzt auf Mails
  antworten oder neue Compose-Mails senden. Alle User senden via einen
  zentralen itdatex-Resend-Account (site-wide `resend_api_key` in Settings),
  From = `noreply@itdatex.support`, Reply-To = die reale IMAP-Adresse des
  Users. Positioniert als Anti-Spam-Feature (Antworten mit "STOP",
  Abuse-Reports) plus Ergaenzung zu Feature-2-Read-View.
- **Neuer Client** `Outbound\ResendClient::send()` — direkter HTTPS-Call
  an `api.resend.com/v1/emails` mit Bearer-Auth, kein Composer-Dep.
  Handhabt Threading-Header (In-Reply-To/References) fuer Mail-Client-
  seitige Konversations-Zuordnung.
- **Neue Endpoints:**
  - `POST /inbox/messages/{id}/reply` — Reply auf existierende Mail;
    zieht To/Subject/Threading aus dem Original.
  - `POST /outbound/send` — Compose neu mit To/Subject/Body.
  - `GET /outbound/config` — read-only: `available` (API-Key gesetzt?)
    und `from_address` (fuer UI-Hinweis).
- **Neue Settings-Defaults:** `resend_api_key`, `resend_from_address`,
  `resend_from_name`. Muss admin-seitig ausgefuellt werden (Resend-Account
  + Domain-Verification).
- **Outbound-Audit:** Sendungen werden in `mg_actions` als
  `outbound_reply` / `outbound_compose` protokolliert.

### Bekannte Limitationen (bewusst)
- Kein IMAP APPEND ins Sent-Folder — gesendete Mail ist nur in Resend-Log
  + `mg_actions`, nicht im User-Mailbox-Sent-Ordner. Future TODO.
- From-Address ist fix `noreply@itdatex.support` fuer alle User. Fuer
  echten "aus deiner Adresse senden" braucht OAuth-SMTP-Route (nicht Teil
  dieser Version, aber Migrations-Pfad ist offen).

## [0.41.0] – 2026-09-20

### Added
- **HTML-Body-Fetch** (`GET /inbox/messages/{id}/body?format=html`): liefert
  HTML-Body statt Plain-Text fuer sandboxed iframe-Rendering in App/Portal.
  Server-seitiger Minimal-Strip gegen XSS-Vektoren: `<script>`/`<style>`/
  `<link>`/`<meta>`/`<object>`/`<embed>`/`<iframe>`/`<form>` raus, `on*`-
  Handler-Attribute raus, `javascript:`-URLs neutralisiert. Client-seitiges
  Rendern erfolgt zusaetzlich in `<iframe sandbox="">` (ohne allow-scripts)
  mit CSP — Defense-in-Depth.
- **Attachment-Content-Fetch** (`GET /inbox/messages/{id}/attachments/{aid}/content`):
  liefert Anhaenge on-demand, base64-kodiert im JSON. Cap 20 MB (schuetzt
  vor Multi-GB-Videos). Nutzt bestehende `fetch_attachment_body`-API der
  ImapClient/XOauth2ImapClient.
- Neue Methoden `ImapClient::fetch_body_html()` und
  `XOauth2ImapClient::fetch_body_html()` — beide mit Fallback auf Plain-
  Text wenn kein HTML-Part vorhanden. Statische Helper
  `ImapClient::sanitize_html_body()`.

## [0.40.0] – 2026-09-19

### Added
- **On-demand Full-Body-Fetch** (`GET /inbox/messages/{id}/body`): laedt bei
  Bedarf den vollen Mail-Text live per IMAP nach — die DB haelt weiter nur
  den ~500-Zeichen-Preview (kein Storage-Blowup). HTML-Bodies werden zu
  Klartext gestripped (`wp_strip_all_tags`) — kein Sanitize/Sandbox noetig
  fuer Feature-2-Lite. Neue Methoden `ImapClient::fetch_body_text()` und
  `XOauth2ImapClient::fetch_body_text()` mit 100.000-Zeichen-Cap.

## [0.39.0] – 2026-09-19

### Added
- **Portal-Parity fuer Postfach-Regeln**: neue View `PostboxRules.jsx`
  im Portal (`assets/portal/views/`), nav-slot "Postfach". Selbe
  Multi-Condition/Multi-Action-UX wie die Desktop-App.
- **Postfach-Regeln** (`GET/POST/PUT/DELETE /postbox-rules`) — multi-condition
  User-Regeln, die nach Scan aber vor Auto-Quarantaene ausgewertet werden.
  Neue Klassen `Rules\PostboxRule` (CRUD) + `Rules\PostboxRuleEngine`
  (Application). Datenmodell in `mg_postbox_rules` mit
  `conditions_json` (Array `{field, op, value}`) und `actions_json`
  (Array `{type: 'move'|'delete'|'flag', ...}`). Match-Ops `AND` / `OR`.
  Priorisierung via `priority` ASC + `stop_processing`. Felder: `from_addr`,
  `from_domain`, `from_name`, `subject`, `body`, `verdict`, `score`,
  `has_unsub`, `has_attachments`. Operators: equals, not_equals, contains,
  not_contains, starts_with, ends_with (Strings) + gt/ge/lt/le/eq/ne (Zahlen).
  Actions: `move` (IMAP-Move in Ziel-Ordner, mit `ensure_folder`),
  `delete` (Hard-Purge via bestehenden PurgeService),
  `flag` (Platzhalter fuer spaetere IMAP-Flag-Unterstuetzung).
- **DB v26**: neue Tabelle `mg_postbox_rules` (id, customer_id, name,
  priority, enabled, match_op, conditions_json, actions_json,
  stop_processing, hit_count, last_hit_at, created_at, updated_at).
- **ScanService-Hook**: nach `maybe_auto_quarantine` — wenn die Mail nicht
  quarantaenisiert wurde, laeuft `PostboxRuleEngine::apply` und kann Move
  oder Delete ausloesen. Dangerous Mails in Auto-Quarantaene umgehen die
  User-Regeln bewusst — Sicherheit vor Sortier-Komfort.

## [0.38.0] – 2026-09-19

### Added
- **Auto-Vernichten-Vorschlaege** (`GET /wp-json/itdatex-mailguard/v1/inbox/auto-destroy-suggestions`):
  Neue Klasse `Antiphish\AutoDestroySuggestions` liest `mg_actions` der letzten
  N Tage (Default 90) und schlaegt Domains fuer die Eradicate-Liste vor, wenn
  der User dort >= 3 Mails per `action='purge'` `status='done'` hart entsorgt
  hat und keine einzige `quarantine`+`undone` oder `undo_quarantine`-Aktion
  gegen Absender derselben Domain vorliegt. Zusaetzlich muss die Domain
  >= 2 verschiedene Absender aufweisen — damit blockiert ein einzelner
  Massen-Spammer (`security-noreply@microsoft.com` 44x gepurgt) nicht die
  gesamte Provider-Domain. Filter: Domains die bereits in
  `mg_eradicate_domains` stehen oder eine `blacklist`+`from_domain`+`action=purge`-
  Regel haben werden uebersprungen. Read-only — die App legt via bestehendem
  `POST /me/eradicate-domains` an (mit dem etablierten `confirm: "VERNICHTEN"`-Guard).

## [0.37.0] – 2026-09-19

### Changed
- **LLM-Feedback fliesst jetzt ins Absender-Scoring ein.** `SenderTrust::get_score()`
  aggregiert die letzten 90 Tage `mg_llm_feedback`-Votes fuer denselben Absender
  und interpretiert Thumbs-Richtung + damaliges Verdict gemeinsam:
  - 👍 auf "dangerous/suspicious" ODER 👎 auf "clean" = User bestaetigt: bad → +25 pro Vote (Cap +50)
  - 👍 auf "clean" ODER 👎 auf "dangerous/suspicious" = User bestaetigt: OK → -15 pro Vote (Cap -30)
  Vorher wurden Votes nur als Trainings-Snapshots gespeichert und flossen
  nirgendwo zurueck. Konservative Gewichtung: schwaecher als eine explizite
  Whitelist-Regel, damit zwei fehlgeklickte Thumbs das Modell nicht umdrehen.
  Neue Signale erscheinen in `scan_reasons` als "User-Feedback: Nx als sicher/gefaehrlich bestaetigt".

## [0.36.0] – 2026-09-19

### Added
- **Pattern-Vorschlaege** (`GET /wp-json/itdatex-mailguard/v1/inbox/pattern-suggestions`):
  Neue Klasse `Antiphish\PatternSuggestions` clustert `mg_messages` der letzten
  N Stunden (Default 72) nach drei Achsen und schlaegt eine einzelne
  Blacklist-Regel vor, die den ganzen Cluster wegraeumt:
  - `from_domain` — mehrere unterschiedliche Absender aus derselben Domain,
    alle suspicious/dangerous, keiner whitelisted.
  - `from_name_contains` — identischer Anzeigename ueber mehrere Domains
    (typischer Absender-Spoof: "Sparkasse" von 5 Fantasy-Domains).
  - `subject_contains` — identischer `body_fingerprint` = Kampagne von
    mehreren Sendern; Vorschlag matched auf ein Subject-Snippet (60 Zeichen).
  Duplikat-Schutz: bestehende Regeln (Whitelist + Blacklist) werden vor der
  Vorschlags-Emission gegen `match_type` + normalisiertes `pattern` gecheckt,
  sodass keine redundanten Vorschlaege gerendert werden. Ranking nach
  `sample_count` DESC, max. 15 Vorschlaege pro Response. Read-only: die App
  legt die Regel danach ueber den bestehenden `POST /rules`-Endpoint an.

## [0.35.1] – 2026-08-25

### Fixed
- **`Installer::migrate_db`**: verifiziert nach dem `dbDelta`-Block, dass
  alle 17 erwarteten Tabellen tatsaechlich existieren. Nur dann wird
  `db_version` gebumpt. Vorher hat `dbDelta` einzelne CREATE-TABLE-Statements
  im Fehlerfall stumm uebersprungen, `update_option` lief trotzdem — so
  landete v0.35.0 auf `wp.itdatex.support` mit gesetztem `db_version=25`
  **ohne** die neue `mg_content_blocks`-Tabelle. Folge: jeder Content-Muster-
  Add in der App-View "Auto-Vernichten" antwortete `db_insert_failed`. Fix
  greift auch fuer alle zukuenftigen Schema-Bumps: eine unvollstaendige
  Migration friert nicht mehr ein, sondern laeuft beim naechsten Plugin-Load
  automatisch nochmal — plus `error_log`-Notice mit den fehlenden Tabellen.

### Ops
- Auf `wp.itdatex.support` einmalig per WP-CLI nachgezogen:
  `delete_option('itdatex_mailguard_db_version')` +
  `Installer::migrate_db()`. `wp_mg_content_blocks` inkl. UNIQUE-Index
  `uniq_customer_pattern_scope` und `idx_customer` ist wieder da.

## [0.35.0] – 2026-08-24

Zwei neue Features fuer den taeglichen Umgang mit wiederkehrendem Spam:

**1. Ein-Klick "Auto-Vernichten" fuer Absender.** Neuer Button in der
SenderCard (Absender-Ansicht) und in der Inbox-Row (chronologisch)
neben "Absender blockieren". Klick legt eine `blacklist from_addr`-Regel
mit Aktion `purge` an — oder hebt eine bestehende Quarantaene-Regel
per UPDATE hoch. Naechste Mail dieses Absenders wird direkt beim Scan
per IMAP EXPUNGE geloescht. Rueckgaengig ueber den bekannten
"↺ Auto-Vernichten aus"-Button.

**2. Content-Blocklist (Inhalts-Muster-Filter).** Neue Tabelle
`mg_content_blocks` mit Substring-Matches auf Subject/Body. Ein Match
loescht die Mail vor dem Ingest per EXPUNGE — analog zur TLD-Sperre
(v0.31.0). Optional case-sensitive und ganzes-Wort-Match (default an,
verhindert Fehl-Treffer wie "date" -> "update"). Portal-Tab
"Inhalts-Muster" in der Auto-Vernichten-View mit Schnellauswahl
fuer typische Spam-Woerter.

### Added
- **`ContentBlocks`** — neue Persistenz-Klasse mit
  `list_patterns($cid)` (Hot-Path) und `matches($subject, $body, $patterns)`.
  In-Memory-Match pro Pull-Cycle; kein Query pro Mail.
- **PullService**: Content-Block-Check nach TLD-Sperre, vor Ingest.
  Batch-EXPUNGE gemeinsam mit Eradicate/TLD-Blocks.
- **REST**: `GET/POST /me/content-blocks`, `DELETE /me/content-blocks/{id}`.
- **SenderIndex**: neues Feld `block_rule_action` (`quarantine|purge|null`),
  damit der Client den Auto-Vernichten-Button korrekt darstellen kann.
- **`PurgeService::block_sender($cid, $addr, $note, $action)`** mit
  neuem Action-Param — nutzt intern `ensure_blacklist_rule` mit dem
  Auto-Upgrade-Verhalten aus v0.34.0.
- **REST `POST /inbox/senders/block`** akzeptiert neu `action` im Body
  (`quarantine`|`purge`).
- **Portal `SenderCard` + Inbox `Row`**: "🚫 Auto-Vernichten"-Button.
  Wird zu "⚡ Hochstufen" wenn eine Quarantaene-Regel schon aktiv ist;
  zu "↺ Auto-Vernichten aus" (via Undo-Block-Handler) wenn Purge-Regel
  aktiv.
- **Portal `EradicateDomains`**: dritter Tab "Inhalts-Muster" mit
  Textfield + Scope-Radio + case-sensitive/whole-word Checkboxen +
  Schnellauswahl-Chips + Trefferliste mit Hit-Counter.

### Changed
- **DB-Version 24 -> 25**: dbDelta legt `mg_content_blocks` an.
  Kein Data-Migrations-Skript noetig.

## [0.34.0] – 2026-08-24

Neu: **Absender kuenftig auto-vernichten** — der Vernichten-Dialog bietet
jetzt einen zweiten Toggle. Ist er angehakt, wird die Blacklist-Regel
fuer den Absender direkt mit Aktion `purge` angelegt (oder eine
bestehende `quarantine`-Regel entsprechend hochgestuft). Die naechste
Mail dieses Absenders wird dann beim Scan direkt per IMAP EXPUNGE
entfernt — keine Quarantaene, kein Papierkorb, kein Undo. Der Toggle ist
default aus.

### Added
- **PurgeService::ensure_blacklist_rule** um Parameter `$action`
  (`quarantine|purge`) erweitert. Findet sie eine bestehende
  `quarantine`-Regel und wird `purge` angefordert, wird die Regel
  per `UPDATE` hochgestuft — Duplikate werden vermieden, und der Toggle
  hat auch beim zweiten Vernichten Wirkung.
- **REST**: `POST /inbox/messages/{id}/purge`, `POST /actions/{id}/purge`
  und `POST /inbox/senders/purge` akzeptieren neu den Body-Parameter
  `create_purge_rule: bool`. Response enthaelt in dem Fall `rule` mit
  `id`, `existed`, `upgraded`, `action`.
- **REST**: `POST /subscriptions/eradicate` akzeptiert ebenfalls
  `create_purge_rule` — die von `block_sender` intern angelegte
  Quarantaene-Regel wird nach erfolgreichem Eradicate auf `purge`
  hochgestuft.
- **Portal**: neue geteilte Sender-Toggle-UI in `PurgeConfirmDialog`
  (`senderToggleLabel` / `senderToggleChecked` / `onSenderToggle`).
  Genutzt vom Inbox-Row-Purge (neu ueber Dialog statt window.confirm),
  vom Sender-Vernichten (Newsletters/Inbox) und vom Quarantaene-Purge.
- **Portal**: neuer Hook `useMsgPurgeDialog` in `Inbox.jsx` — bringt
  die Message-Level-Loeschen-UX auf die gleiche modal-basierte
  Bestaetigungs-UX wie das Sender-Vernichten.

### Changed
- Kein Schema-Change, `CURRENT_DB_VERSION` bleibt bei 24 (aus v0.32.2).
- `useRowHandlers` nimmt jetzt einen optionalen `requestPurge`-Callback.
  Ohne Callback fallen die Handler defensiv auf das alte
  `window.confirm`-Verhalten zurueck (kein Regel-Toggle).

### Not covered by this release
- Companion-Angleichung der Desktop-App: `vendor/mailguard`-Submodule
  im Desktop-Repo steht auf v0.8.8+6 und ist damit 25 Versionen hinter
  dem Plugin. Ein Submodule-Bump ist eigene Session.

## [0.33.0] – 2026-07-24

Neu: **Absender-Erlauben + Rueckgaengig**. Die aggregierte Sender-Ansicht
(App + Portal) kann Absender jetzt whitelisten (Whitelist-Regel via
`createRule`) und gesetzte Block-/Whitelist-Regeln mit einem Klick
wieder zurueckziehen.

### Added
- **`SenderIndex::list_for_customer`** liefert pro Sender jetzt
  `sender_whitelisted` (bool) sowie `block_rule_id` und
  `whitelist_rule_id`. Der Client kann so ohne zweiten Roundtrip auf
  `/rules` per `DELETE /rules/{id}` die Regel aufheben.
- **Portal-Sender-Card**: neue Pill `✓ erlaubt`; die Buttons
  "Absender als sicher" / "Absender blockieren" wechseln zu
  "↺ Rueckgaengig", wenn die entsprechende Regel bereits aktiv ist.
- **App-Sender-Row**: Buttons `Erlauben` und `Rueckgaengig` analog zur
  Portal-UX. Badge `erlaubt` neben `blockiert`. Purge (Vernichten)
  bleibt unangetastet — kein Undo, per Design.

### Changed
- Kein Schema-Change, `CURRENT_DB_VERSION` bleibt bei 24 (aus v0.32.2).

### Companion
- Desktop-App **v0.32.0** rollt die gleiche UX aus:
  <https://github.com/RainerNeu1012/itdatex-mailguard-app/releases/tag/v0.32.0>.
  App-User bekommen das Update automatisch beim naechsten Update-Check
  (stuendlicher Sync auf `wp.itdatex.support/mailguard/latest.json`).

## [0.32.2] – 2026-07-22

Bugfix. In v0.32.0 wurde die `action`-Spalte zwar ins CREATE-TABLE-SQL
von `mg_rules` aufgenommen, aber `Installer::CURRENT_DB_VERSION` blieb
auf 23 stehen. Der Migrations-Guard `if ($installed >= CURRENT_DB_VERSION)`
uebersprang dbDelta bei allen Bestand-Installs komplett — der neue
Column landet nur bei Neuinstallationen. Auf Bestand-Installs schlug
`Rule::create` mit `insert_failed` fehl, sobald eine Content-Filter-
Regel (oder eine ganz normale neue Regel) angelegt werden sollte.

### Fixed
- **`Installer::CURRENT_DB_VERSION` von 23 auf 24 gebumpt**, damit dbDelta
  bei Bestand-Installs die `action`-Spalte in `mg_rules` nachzieht.
  Kein separates Data-Migration-Skript noetig — dbDelta ist idempotent
  und erkennt fehlende Spalten via CREATE-TABLE-Diff.

### Notes
- Live-Server `wp.itdatex.support` wurde vor dem Repo-Bump manuell per
  `ALTER TABLE wp_mg_rules ADD COLUMN action VARCHAR(20) NOT NULL DEFAULT 'quarantine' AFTER note`
  gefixt; der spaetere dbDelta-Run ist dann ein No-Op.
- Regressionsvermeidung fuer die Zukunft: bei jedem Schema-Change im
  CREATE-TABLE-Block MUSS `CURRENT_DB_VERSION` mitgebumpt werden, sonst
  greift die Migration nur bei Neuinstallationen.

## [0.32.1] – 2026-07-22

Portal-UX-Parity zur Desktop-App v0.30.3+: die Type-in-Bestaetigung
("VERNICHTEN eintippen") beim Sender- und Domain-Vernichten wird
durch ein Modal mit Bestaetigungs-Checkbox ersetzt. Damit ist die
Portal-UX deckungsgleich mit der Modal-Version aus
`src/views/Senders.jsx` in der App.

### Added
- Neue geteilte Komponente `assets/portal/components/PurgeConfirmDialog.jsx`.
  Fixed-Overlay-Modal mit Bestaetigungs-Checkbox, optionalem `extras`-Slot
  (z.B. zweite Checkbox), Abbrechen/Vernichten-Buttons. Vernichten bleibt
  disabled bis die Ack-Checkbox aktiv ist. Deckungsgleich zur
  `PurgeDialog`-Komponente in der Desktop-App.

### Changed
- **`views/Inbox.jsx` (SenderList)**: `eradicateSender` splittet sich in
  `openEradicateDialog` + `confirmEradicate`. Die alte
  `window.prompt("VERNICHTEN eintippen")`-Sequenz plus zweitem
  `window.confirm("auch Domain?")` fliegt raus — beide Entscheidungen
  liegen jetzt im selben Modal (Ack-Checkbox + optionale Domain-Checkbox).
- **`views/Newsletters.jsx` (Subscriptions)**: Analog — `eradicate` wird
  zu `openEradicateDialog` + `confirmEradicate`, gleiche Modal-UX.
- **`views/EradicateDomains.jsx` (DomainsList)**: `add` oeffnet Modal
  statt `window.prompt`; `confirmAdd` fuehrt den POST aus. Modal zeigt
  je nach `purge_history`-Flag entweder "nur zukuenftige Mails" oder
  den zusaetzlichen "Verlauf endgueltig loeschen"-Hinweis in Rot.

### Notes
- Kein Backend-Change, kein DB-Migrationsschritt. Nur JSX + Rebuild.
- Type-in-Confirm bleibt am Backend als Guard erhalten (Server 422't
  weiterhin ohne `confirm: "VERNICHTEN"` im Body). Der String wird jetzt
  automatisch vom Modal-Confirm gesendet.

## [0.32.0] – 2026-07-22

Neu: **Content-Filter mit Sofort-Vernichten**. Regeln matchen jetzt auch
auf Absender-Namen und Mail-Text; Blacklist-Regeln koennen optional
Treffer direkt per IMAP EXPUNGE loeschen statt in Quarantaene zu
verschieben.

### Added
- **Zwei neue Match-Typen** in `Rules\Rule::TYPES`:
  `from_name_contains` (Substring im Anzeigenamen) und
  `body_contains` (Substring im `body_preview`). Beide case-insensitive
  via `stripos`.
- **`action`-Spalte** auf `mg_rules` (`quarantine` | `purge`, Default
  `quarantine`). Nur fuer `kind = 'blacklist'` wirksam. dbDelta migriert
  bestehende Rules-Tables beim Plugin-Update automatisch.
- **ScanService-Interception**: Bei Blacklist-Hit mit `action = 'purge'`
  ruft `ScanService::scan_message` direkt `QuarantineService::purge_message`
  auf statt `maybe_auto_quarantine`. Mail ist per IMAP EXPUNGE weg, Audit
  in `mg_actions` bleibt intakt (Purge-Action wie bei Nutzer-Vernichten).
- **Portal-Regeln-View**: Match-Type-Select um die neuen Optionen
  erweitert. Bei `kind = blacklist` neuer Action-Dropdown mit Warn-
  Hinweis wenn `purge` gewaehlt ist. Regeln-Tabelle zeigt Aktion in
  der Blacklist-Sektion (rot fuer `purge`).

### Changed
- **`Engine::apply` Signatur unveraendert** — Row-Zugriffe erweitert um
  `from_name` und `body_preview`. Whitelist-Rules ignorieren `action`
  (server-side gezwungen auf `quarantine`).
- **`Rule::public_view`** exposed neues `action`-Feld. Legacy-Rows ohne
  Spalte werden defensive auf `quarantine` gemappt.

## [0.31.1] – 2026-07-15

### Fixed
- **Regel-Anlage per REST warf HTTP 500.** Seit v0.27.0 rief
  `Rules\Rule::create()` erst `SenderTrust::record_whitelist/blacklist()`
  auf und las danach `$wpdb->insert_id`. Der Trust-Upsert nutzt intern
  `INSERT ... ON DUPLICATE KEY UPDATE` und setzt `insert_id` auf 0 wenn
  der UPDATE-Zweig greift. `Rule::find_for_customer(0, cid)` liefert dann
  null, und `Rule::public_view(null)` kippt mit TypeError. Fix: die ID
  direkt nach dem Rule-Insert einfrieren, bevor der Trust-Upsert laeuft.

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
