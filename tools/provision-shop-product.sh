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
VERSION="0.1.0"

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

<h3>📬 Per-Customer-IMAP</h3>
<p>Jeder Kunde verbindet eigene Postfächer (SSL/STARTTLS), Cron pullt alle 15 min neue Mails, IMAP-Passwort wird verschlüsselt (AES-256-GCM, Key aus WP-Salts) gespeichert.</p>

<h3>🛡 Passives Phishing-Scanning</h3>
<p>Jede eingehende Mail wird automatisch gegen die Anti-Phishing-API geprüft. Verdict-Badges (clean/suspicious/dangerous) direkt in der Inbox, gefährliche Mails fallen sofort auf.</p>

<h3>📰 Newsletter-Bulk-Unsubscribe</h3>
<p>Per RFC-8058 (HTTP One-Click + mailto via gehärtetem Postfix mit DKIM/SPF/DMARC pass). Bounce-Status wird via DSN-Monitor nachverfolgt.</p>

<h3>🔍 Manueller Scanner + Spam-Rules</h3>
<p>On-Demand URL/Mail-Scan mit 24h-Quota. Per-Customer Whitelist/Blacklist (from_addr, from_domain, subject_contains) — Blacklist gewinnt vor Whitelist.</p>

<h3>👤 Site-Owner-Admin</h3>
<p>WP-Admin → MailGuard zeigt alle Endkunden mit Stats, Such-/Sperr-/Lösch-Aktionen, Lizenz-Status mit Stripe-Subscription-Periode.</p>

<h3>Technisches</h3>
<ul>
<li>WP 6.4+, PHP 8.1+</li>
<li>Eigene React-SPA fürs /portal/* (kein wp-admin-Look)</li>
<li>WP-Cron 15-min Pull + 5-min Scan-Worker; Skalierungs-Pfad zu Action Scheduler vorgesehen</li>
<li>Abrechnung über Stripe Subscriptions; bei past_due läuft das Portal weiter (Grace), bei canceled werden neue Customer-Registrierungen blockiert</li>
</ul>
HTML
)

CHANGELOG=$(cat <<'CL'
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
