#!/usr/bin/env bash
# Reproduzierbar Banner + Icons fuer den Plugin-Shop generieren.
# Stil parallel zu rechnungseingang/mailsec: dunkles Grid, weisse bold sans,
# blauer Akzent. Unterschiedet sich nur durch die Headline (Multi-Tenant-SaaS).

set -euo pipefail

OUT="/opt/itdatex-plugins/itdatex-mailguard/branding"
mkdir -p "${OUT}"
cd "${OUT}"

FONT="/usr/share/fonts/opentype/urw-base35/NimbusSans-Bold.otf"
FONT_REG="/usr/share/fonts/opentype/urw-base35/NimbusSans-Regular.otf"
BG='#0a1020'
GRID='#1a2233'
BLUE='#3b82f6'
WHITE='#ffffff'
GRAY='#94a3b8'

# 40x40 Grid-Pattern fuer getilten Hintergrund
convert -size 40x40 xc:"$BG" \
    -fill "$GRID" \
    -draw "line 0,0 0,40" \
    -draw "line 0,0 40,0" \
    pattern.png

# Banner 772x250 (WP-Plugin-Shop-Standard)
convert -size 772x250 tile:pattern.png \
    -font "$FONT_REG" -pointsize 14 -fill "$GRAY" \
        -annotate +30+38 '// mailguard    ·    multi-tenant for wordpress' \
    -font "$FONT" -pointsize 46 -fill "$WHITE" \
        -annotate +30+105 'PHISHING-SCHUTZ.' \
        -annotate +30+155 'ALS SERVICE.' \
    -font "$FONT" -pointsize 16 -fill "$BLUE" \
        -annotate +30+200 'MAILGUARD  |  WHITE-LABEL FÜR DEINE KUNDEN.' \
    -fill "#101830" -stroke "#1f2b45" -strokewidth 2 \
        -draw "roundrectangle 600,60 730,190 14,14" \
    -font "$FONT" -pointsize 90 -fill "$BLUE" -stroke none \
        -gravity none -annotate +636+155 'M' \
    banner-772x250.png

# Icon 256x256
convert -size 256x256 xc:"$BG" \
    -fill "#101830" -stroke "#1f2b45" -strokewidth 3 \
        -draw "roundrectangle 32,32 224,224 28,28" \
    -font "$FONT" -pointsize 160 -fill "$BLUE" -stroke none \
        -gravity center -annotate +0+8 'M' \
    icon-256x256.png

# Icon 128x128 als Downscale
convert icon-256x256.png -resize 128x128 icon-128x128.png

ls -la
