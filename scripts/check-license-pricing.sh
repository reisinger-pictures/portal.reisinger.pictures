#!/usr/bin/env bash
# =============================================================================
# check-license-pricing.sh — die base_price-Gegenprobe als ein Befehl
# -----------------------------------------------------------------------------
# Frueher stand im Board: "vor jedem Deploy, der `base_price` beruehrt, erneut
# messen — authentifiziert". Der Lesebefehl aus dem Board war tot (die
# license-terms-Route liegt hinter `auth:api` **und** `super_admin`, ein
# anonymer curl liefert 401), also blieb nur ein Blick von Hand in die
# Oberflaeche oder ein `tinker`-Aufruf.
#
# Dieser Aufruf erledigt die Gegenprobe auf dem Host:
#   ./scripts/check-license-pricing.sh
#   ./scripts/check-license-pricing.sh --expect=base_price=8000
#
# Das Kommando `ops:check-license-pricing` loest die effektiven Preise so auf,
# wie der Kauf-Flow sie aufloest (Marke mit B2B-Rueckfall) und prueft sie gegen
# dieselben Regeln, die der Schreibpfeld anwendet (`LicenseTermRules::RULES`).
# Exit 1 bei einem Verstoss — etwa wenn `base_price` in Euro statt in Cent
# gespeichert ist ('35.00') oder unter dem Minimum von 500 Cent liegt.
#
# Aufrufvarianten (fuer Tests und Schattenbetrieb):
#   PORTAL_SSH_TARGET=<ziel>              das Ziel (Default: die Produktion)
#   PORTAL_BACKEND_CONTAINER=<name>        der Backend-Container
# =============================================================================
set -euo pipefail
cd "$(dirname "$0")/.."

SSH_TARGET="${PORTAL_SSH_TARGET:-root@reisinger.pictures}"
BACKEND_CONTAINER="${PORTAL_BACKEND_CONTAINER:-portal_backend}"

# Die Argumente muessen die entfernte Shell als getrennte Worte sehen; %q
# quotet sie portabel fuer bash.
remote_cmd="docker exec ${BACKEND_CONTAINER} php artisan ops:check-license-pricing"
for argument in "$@"; do
    remote_cmd+=" $(printf '%q' "$argument")"
done

ssh -o ConnectTimeout=25 "$SSH_TARGET" "$remote_cmd"
