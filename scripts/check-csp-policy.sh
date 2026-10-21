#!/usr/bin/env bash
# =============================================================================
# check-csp-policy.sh — stimmt die im Repo dokumentierte CSP noch mit dem Host?
# -----------------------------------------------------------------------------
# Die Content-Security-Policy wird in Produktion von Caddy ausgeliefert (das
# Caddyfile im Volume von `proxy-stack`). Das Repo spiegelt sie seit
# 2026-10-21 in `deployment/content-security-policy.txt` — sonst wäre der
# Policy-Text nirgends lesbar (das war der Grund, warum die E2E-Suite für
# CSP-Verstöße strukturell blind bleibt, siehe
# `features/security/content-security-policy.md`).
#
# Ein Mirror kann driften. Dieser Aufruf beweist, dass Host und Repo
# dasselbe sagen:
#
#   ./scripts/check-csp-policy.sh
#
# Exit 0 = Host und Repo sind identisch. Exit 1 = der Host liefert eine
# andere Policy als das Repo dokumentiert (dann wird der Mirror hier
# nachgezogen und die Änderung wie jeder andere Vertragswechsel geprüft).
#
# Aufrufvarianten (für Tests und Schattenbetrieb):
#   PORTAL_SSH_TARGET=<ziel>              das Ziel (Default: die Produktion)
#   PORTAL_CADDY_CONTAINER=<name>          der Caddy-Container
# =============================================================================
set -euo pipefail
cd "$(dirname "$0")/.."

SSH_TARGET="${PORTAL_SSH_TARGET:-root@reisinger.pictures}"
CADDY_CONTAINER="${PORTAL_CADDY_CONTAINER:-caddy}"
MIRROR_FILE="deployment/content-security-policy.txt"
CADDYFILE_PATH="/etc/caddy/Caddyfile"

if [[ ! -f "$MIRROR_FILE" ]]; then
    echo "FEHLER: $MIRROR_FILE fehlt — das Repo traegt den Policy-Text nicht mehr." >&2
    exit 1
fi

expected="$(tr -d '\n' < "$MIRROR_FILE" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')"
if [[ -z "$expected" ]]; then
    echo "FEHLER: $MIRROR_FILE ist leer." >&2
    exit 1
fi

remote_cmd="docker exec $(printf '%q' "$CADDY_CONTAINER") cat $(printf '%q' "$CADDYFILE_PATH")"
caddyfile="$(ssh -o ConnectTimeout=25 "$SSH_TARGET" "$remote_cmd")"

# Die Policy steht in genau einem header-Block: `header Content-Security-Policy "…"`.
# Die Richtlinie selbst enthaelt keine Anfuehrungszeichen, deshalb cut -d'"' -f2.
actual="$(printf '%s\n' "$caddyfile" \
    | grep -o 'Content-Security-Policy "[^"]*"' \
    | head -1 \
    | cut -d'"' -f2)"

if [[ -z "$actual" ]]; then
    echo "FEHLER: Auf dem Host wurde kein Content-Security-Policy-Header gefunden." >&2
    echo "       (Container: $CADDY_CONTAINER, Datei: $CADDYFILE_PATH)" >&2
    exit 1
fi

if [[ "$actual" != "$expected" ]]; then
    echo "FEHLER: Der Host liefert eine andere CSP als das Repo dokumentiert." >&2
    echo "" >&2
    echo "  Host : $actual" >&2
    echo "  Repo : $expected" >&2
    echo "" >&2
    echo "  Der Mirror $MIRROR_FILE muss nachgezogen werden (die Quelle bleibt" >&2
    echo "  das Caddyfile auf dem Host)." >&2
    exit 1
fi

echo "CSP stimmt mit dem Host ueberein ($CADDY_CONTAINER:$CADDYFILE_PATH)."
echo "  $expected"
