#!/usr/bin/env bash
# Provisioning des Shop-Produkts itdatex-mailguard auf wp.itdatex.support.
# Idempotent: legt das itdatex_plugin-CPT-Produkt an, falls _plugin_slug=itdatex-mailguard
# noch nicht existiert, und updated sonst die Metas.
#
# Anders als bei MailSec: billing_mode=subscription (monatlich), da MailGuard
# der Multi-Tenant-SaaS ist und der Site-Owner recurring zahlt.
# ZIP muss vorher per tools/build-zip.sh erzeugt und unter
# /opt/itdatex-shop-products/itdatex-mailguard-<VERSION>.zip abgelegt sein.

set -euo pipefail

WP_DIR="/var/www/wp.itdatex.support/html"
ASSET_DIR="/opt/itdatex-plugins/itdatex-mailguard/branding"
PLUGIN_SLUG="itdatex-mailguard"
VERSION="0.5.0"

PRICE_CENTS="4900"             # 49 EUR / Monat
BILLING_MODE="subscription"
BILLING_INTERVAL="month"
ACTIVATIONS_MAX="1"
REQUIRES_WP="6.4"
TESTED_WP="6.7"
REQUIRES_PHP="8.1"
DOWNLOAD_FILE="file:itdatex-mailguard-${VERSION}.zip"

DESCRIPTION_HTML=$(cat <<'HTML'
<p>itdatex MailGuard ist die <strong>Multi-Tenant-SaaS-Erweiterung</strong>: deine WordPress-Seite wird zum Phishing-/Spam-Portal, in dem sich deine eigenen Kunden registrieren, ihre IMAP-Konten einbinden und ihre Mail-Inboxen automatisch gegen die Anti-Phishing-API scannen lassen.</p>

<h3>🔐 Eigene Customer-Logins</h3>
<p>Endkunden registrieren sich unter <code>/portal/register</code>, bestätigen ihre E-Mail und loggen sich mit eigener Account-Schicht ein — getrennt von WP-Usern.</p>

<h3>🔑 OAuth-Connect für Microsoft & Google</h3>
<p>Endkunden mit Outlook.com, Office 365, Microsoft 365 Family oder Gmail verbinden ihr Postfach per OAuth-Klick — XOAUTH2 (RFC 7628) ohne Basic Auth, ohne App-Passwort. Site-Owner registriert je eine OAuth-App im Azure-Portal und in der Google Cloud Console, die Endkunden klicken sich durch den Standard-Consent-Flow.</p>

<h3>🧭 Auto-Discovery für eigene Postfächer</h3>
<p>Für IMAP-Postfächer ohne OAuth-Provider reicht die Mailadresse: 4-stufige Autodiscovery (statische DB der Top-30-Provider → Mozilla ISPDB → Microsoft Autodiscover → DNS SRV) füllt Host/Port/SSL automatisch aus. GMX, Web.de, T-Online, IONOS, Mailbox.org, Yahoo, iCloud und viele Custom-Domains sind ohne Konfiguration einbindbar.</p>

<h3>📬 Per-Customer-IMAP</h3>
<p>Jeder Kunde verbindet eigene Postfächer (SSL/STARTTLS oder OAuth-XOAUTH2). Cron pullt alle 15 Minuten neue Mails. Passwörter UND OAuth-Tokens werden verschlüsselt (AES-256-GCM, Key aus WP-Salts) gespeichert. Token-Refresh läuft automatisch.</p>

<h3>🛡 Passives Phishing-Scanning</h3>
<p>Jede eingehende Mail wird automatisch gegen die Anti-Phishing-API geprüft (LLM-Deep-Scan via Ollama Cloud / GLM-5.2 — externer US-Anbieter, AVV erforderlich). Verdict-Badges (clean/suspicious/dangerous) direkt in der Inbox, konservativ kalibriert — echte Phishes werden erkannt ohne dass legitime Service-Mails als Verdacht markiert werden.</p>

<h3>📰 Newsletter-Bulk-Unsubscribe pro Sender</h3>
<p>Newsletter-View gruppiert alle Mails pro Absender. Ein Klick reicht: RFC-8058 HTTP One-Click oder signierte mailto (DKIM/SPF/DMARC pass via gehärtetem Postfix). Bestehende und künftige Mails desselben Senders werden automatisch als „abgemeldet" markiert. Bounce-Status wird via DSN-Monitor nachverfolgt.</p>

<h3>🔍 Manueller Scanner + Spam-Rules</h3>
<p>On-Demand URL/Mail-Scan mit 24h-Quota. Per-Customer Whitelist/Blacklist (from_addr, from_domain, subject_contains) — Blacklist gewinnt vor Whitelist, beide überschreiben die KI-Bewertung.</p>

<h3>👤 Site-Owner-Admin</h3>
<p>WP-Admin → MailGuard zeigt alle Endkunden mit Stats, Such-/Sperr-/Lösch-Aktionen, Lizenz-Status mit Stripe-Subscription-Periode.</p>

<h3>Technisches</h3>
<ul>
<li>WP 6.4+, PHP 8.1+ (OAuth-XOAUTH2 funktioniert auch ohne PHP-imap-Extension — eigener pure-PHP-IMAP-Client kompensiert)</li>
<li>Eigene React-SPA fürs /portal/* (kein wp-admin-Look)</li>
<li>WP-Cron 15-min Pull + 5-min Scan-Worker; <strong>System-Cron via systemd-Timer empfohlen</strong> für stabile Intervalle bei wenig Traffic</li>
<li>Abrechnung über Stripe Subscriptions; bei past_due läuft das Portal weiter (Grace), bei canceled werden neue Customer-Registrierungen blockiert</li>
</ul>
HTML
)

CHANGELOG=$(cat <<'CL'
= 0.5.0 — Onboarding-Flow mit Auto-Pre-Fill =
* NEU: Register-Page erkennt Mail-Provider schon während der Eingabe (Public-Discover-Endpoint, IP-Rate-Limit). Bei OAuth-Providern Hinweis "Nach Verifizierung mit einem Klick verbinden", bei IMAP-Providern Host/Port/SSL + Anbieter-spezifische Notes, bei ProtonMail/Tuta klare "kein IMAP"-Warnung.
* NEU: Nach E-Mail-Verification automatischer Redirect zu Login mit pre-filled Email + Hinweis "next=accounts/new". Nach Login direkt im Account-Form mit Username pre-filled, Auto-Discovery feuert sofort.
* NEU: REST /imap/discover-public — Public-Variante des Discover-Endpoints ohne Customer-Auth (nur Static + Mozilla, kein MS-Autodiscover/LLM vor User-Einwilligung — DSGVO-schonend).
* CHANGE: Login-View nimmt query-params ?email= und ?next= auf für nahtloses Onboarding.

= 0.4.0 — LLM-Discovery + Cloud-LLM-Backend =
* NEU: 5. Auto-Discovery-Stufe: GLM-5.2 via Ollama Cloud — fragt das KI-Modell nach IMAP-Settings unbekannter Domains (nur Domain übermittelt, keine Mailadresse, kein Inhalt)
* CHANGE: LLM-Backend für Phishing-Scan auf Ollama Cloud (GLM-5.2) umgestellt — deutlich schneller (~2-5s statt 15-25s), CPU-bound-Probleme entfallen
* DSGVO: Mail-Subject/Body verdächtiger Mails (Heuristik-Score 30-70) gehen jetzt an Ollama Inc. (USA), AVV erforderlich, Datenschutzerklärung anpassen
* CHANGE: Anleitung um neue Section "Unterstützte Mail-Anbieter" erweitert (Tabelle mit Host/Port/Auth/Note für 19 Anbieter)
* NEU: Verbleibender Lokal-Modus weiterhin per OLLAMA_URL-Env-Switch verfügbar (qwen3:4b)

= 0.3.0 — Multi-Folder pro Account =
* NEU: Endkunden können pro Postfach beliebig viele IMAP-Folder abrufen (INBOX + Junk + Archiv + eigene Labels), jeder mit eigenem inkrementellen Pull-Stand
* NEU: Folder-Discover via IMAP-LIST — Portal-Picker zeigt alle verfügbaren Folder mit Checkboxes
* NEU: REST /accounts/{id}/folders[/discover], /folders/{id}/{test,pull}, PATCH /folders/{id}
* CHANGE: PullService arbeitet jetzt Folder-zentriert (25 Folders/Cron, 50 UIDs/Folder), pull_account bleibt für Single-Account-Pull
* DB v8 — mg_imap_folders Tabelle, Auto-Migration aus bestehenden Accounts (1 INBOX-Folder pro Account mit dem alten last_uid)

= 0.2.0 — OAuth, Auto-Discovery, Subscriptions =
* NEU: OAuth-Connect für Microsoft 365 / Outlook.com / Microsoft 365 Family via XOAUTH2 (Azure-App-Registrierung erforderlich)
* NEU: OAuth-Connect für Gmail / Google Workspace via XOAUTH2 (Google-Cloud-Projekt erforderlich)
* NEU: Pure-PHP-XOAUTH2-IMAP-Client (kommt ohne PHP-imap-OP_XOAUTH2 aus, kompatibel mit altem c-client)
* NEU: 4-stufige Auto-Discovery für eigene IMAP-Postfächer — Mailadresse genügt (statische Provider-DB → Mozilla ISPDB → Microsoft Autodiscover → DNS SRV)
* NEU: Newsletter-Subscriptions-View — pro Absender gruppiert, 1-Klick-Bulk-Unsubscribe, "bereits abgemeldet"-Marker in Inbox
* TUNING: Verdict-Schwellen entschärft (suspicious ab Score 50 statt 30), LLM-Prompt v2-conservative — drastische Reduktion der False-Positives auf legitime Service-Mails
* DB v7 — neue Spalten in mg_imap_accounts für auth_type, oauth_provider und verschlüsselte Tokens (Migration automatisch)
* NEU: REST /imap/discover, /oauth/{microsoft,google}/{start,callback}, /accounts/{id}/oauth/disconnect, /subscriptions, /subscriptions/unsubscribe

= 0.1.0 — initial release =
* Customer-Auth (register/verify/login/reset/me/logout) mit signiertem Session-Cookie
* React-Portal-SPA für /portal/* mit eigenem Layout
* Per-Customer IMAP-Mgmt mit CRUD, Test-Connect und AES-GCM-Passwort-Encryption
* Inbox-Pull (15-min Cron) + Passive Phishing-Scan (5-min Worker) gegen mailsec.itdatex.support
* Newsletter-Bulk-Unsubscribe (extract → execute → DSN-Tracking)
* Manueller URL/Mail-Scanner mit per-Customer 24h-Quota
* Whitelist/Blacklist-Engine (Override nach API-Scan, Blacklist wins)
* Site-Owner-Admin im WP-Admin (Customer-Liste, Stats, Suspend/Activate/Purge mit Cascade)
* Stripe-Subscription-Webhooks (customer.subscription.* + invoice.payment_failed) mit past_due-Grace und canceled-Hard-Stop
CL
)

cd "${WP_DIR}"

PID="$(sudo -u www-data wp post list \
    --post_type=itdatex_plugin --post_status=any \
    --meta_key=_plugin_slug --meta_value="${PLUGIN_SLUG}" \
    --fields=ID --format=ids)"

if [[ -z "${PID}" ]]; then
    echo "Lege neues Produkt an"
    PID="$(sudo -u www-data wp post create \
        --post_type=itdatex_plugin --post_status=publish \
        --post_title='itdatex MailGuard' \
        --post_excerpt='Multi-Tenant Anti-Phishing- und Newsletter-Portal für WordPress — Endkunden registrieren sich selbst, ihre Postfächer werden automatisch geschützt.' \
        --post_content="${DESCRIPTION_HTML}" --porcelain)"
else
    echo "Update existierendes Produkt #${PID}"
    sudo -u www-data wp post update "${PID}" \
        --post_status=publish \
        --post_title='itdatex MailGuard' \
        --post_excerpt='Multi-Tenant Anti-Phishing- und Newsletter-Portal für WordPress — Endkunden registrieren sich selbst, ihre Postfächer werden automatisch geschützt.' \
        --post_content="${DESCRIPTION_HTML}"
fi

declare -A METAS=(
    [_plugin_slug]="${PLUGIN_SLUG}"
    [_version]="${VERSION}"
    [_price_cents]="${PRICE_CENTS}"
    [_currency]="EUR"
    [_billing_mode]="${BILLING_MODE}"
    [_billing_interval]="${BILLING_INTERVAL}"
    [_activations_max]="${ACTIVATIONS_MAX}"
    [_download_url]="${DOWNLOAD_FILE}"
    [_requires_wp]="${REQUIRES_WP}"
    [_tested_wp]="${TESTED_WP}"
    [_requires_php]="${REQUIRES_PHP}"
    [_changelog]="${CHANGELOG}"
)
for key in "${!METAS[@]}"; do
    sudo -u www-data wp post meta update "${PID}" "${key}" "${METAS[$key]}" >/dev/null
done

# Featured Image: nur einmal importieren, wenn _thumbnail_id leer.
TID="$(sudo -u www-data wp post meta get "${PID}" _thumbnail_id 2>/dev/null || true)"
if [[ -z "${TID}" || "${TID}" == "0" ]]; then
    if [[ -f "${ASSET_DIR}/banner-772x250.png" ]]; then
        BANNER_COPY="/tmp/itdatex-mailguard-banner-772x250.png"
        cp "${ASSET_DIR}/banner-772x250.png" "${BANNER_COPY}"
        chown www-data:www-data "${BANNER_COPY}"
        sudo -u www-data wp media import "${BANNER_COPY}" --post_id="${PID}" --featured_image \
            --title='itdatex MailGuard — Phishing-Schutz als Service' \
            --alt='itdatex MailGuard Banner: Phishing-Schutz als Service, White-Label für deine Kunden'
        rm -f "${BANNER_COPY}"
    fi
fi

echo "OK — Produkt #${PID} mit slug=${PLUGIN_SLUG} version=${VERSION} billing=${BILLING_MODE}/${BILLING_INTERVAL}"
sudo -u www-data wp post url "${PID}"
