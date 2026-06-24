#!/usr/bin/env bash
# Reproducible distributable ZIP fuer den Plugin-Shop.
# Erzeugt /opt/itdatex-shop-products/itdatex-mailguard-<version>.zip
# Voraussetzungen: composer install + npm run build vorher laufen lassen.

set -euo pipefail

PLUGIN_DIR="/opt/itdatex-plugins/itdatex-mailguard"
TARGET_DIR="/opt/itdatex-shop-products"
OWNER="itdatex:itdatex"

VERSION="$(awk -F"'" '/define\( .ITDATEX_MAILGUARD_VERSION./ { print $4 }' "${PLUGIN_DIR}/itdatex-mailguard.php")"
if [[ -z "${VERSION}" ]]; then
    echo "Konnte Version aus itdatex-mailguard.php nicht lesen." >&2
    exit 1
fi

ZIP="${TARGET_DIR}/itdatex-mailguard-${VERSION}.zip"
echo "Building ${ZIP} from ${PLUGIN_DIR}"

[[ -d "${PLUGIN_DIR}/vendor" ]] || { echo "vendor/ fehlt — composer install"; exit 1; }
[[ -d "${PLUGIN_DIR}/build"  ]] || { echo "build/  fehlt — npm run build";  exit 1; }

rm -f "${ZIP}"
cd "$(dirname "${PLUGIN_DIR}")"
zip -rq "${ZIP}" "$(basename "${PLUGIN_DIR}")" \
    -x "$(basename "${PLUGIN_DIR}")/node_modules/*" \
    -x "$(basename "${PLUGIN_DIR}")/.git/*" \
    -x "$(basename "${PLUGIN_DIR}")/.gitignore" \
    -x "$(basename "${PLUGIN_DIR}")/package.json" \
    -x "$(basename "${PLUGIN_DIR}")/package-lock.json" \
    -x "$(basename "${PLUGIN_DIR}")/vite.config.js" \
    -x "$(basename "${PLUGIN_DIR}")/assets/portal/*" \
    -x "$(basename "${PLUGIN_DIR}")/composer.lock" \
    -x "$(basename "${PLUGIN_DIR}")/branding/*" \
    -x "$(basename "${PLUGIN_DIR}")/tools/*"

chown "${OWNER}" "${ZIP}"
ls -la "${ZIP}"
echo "OK"
