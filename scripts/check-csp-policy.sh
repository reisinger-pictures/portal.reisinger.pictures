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
# Exit 0 = Host und Repo sind identisch. Exit 1 = der Host liefert keine
# passende Policy oder eine andere als das Repo dokumentiert (dann wird der
# Mirror hier nachgezogen und die Änderung wie jeder andere Vertragswechsel
# geprüft).
#
# Das Caddyfile laedt seit 2026-11 via `import conf/*.caddy` Fragmente; die
# Seiten-Policy steht in einem davon (nicht mehr im Haupt-Caddyfile). Der
# Check liest deshalb das Haupt-Caddyfile UND alle Fragmente und vergleicht
# den Mirror mit der Menge aller darin deklarierten CSP-Header. Das ist
# reihenfolgeunabhaengig (ein anderes Fragment deklariert eine
# `frame-ancestors 'none'`-Snippet-Policy) und an keinen Dateinamen gebunden.
#
# Aufrufvarianten (für Tests und Schattenbetrieb):
#   PORTAL_SSH_TARGET=<ziel>              das Ziel (Default: die Produktion)
#   PORTAL_CADDY_CONTAINER=<name>          der Caddy-Container
#   PORTAL_CADDY_CONF_DIR=<pfad>           Verzeichnis der importierten
#                                          *.caddy-Fragmente (Default: /etc/caddy/conf)
# =============================================================================
set -euo pipefail
cd "$(dirname "$0")/.."

SSH_TARGET="${PORTAL_SSH_TARGET:-root@reisinger.pictures}"
CADDY_CONTAINER="${PORTAL_CADDY_CONTAINER:-caddy}"
MIRROR_FILE="deployment/content-security-policy.txt"
CADDYFILE_PATH="/etc/caddy/Caddyfile"
CADDY_CONF_DIR="${PORTAL_CADDY_CONF_DIR:-/etc/caddy/conf}"

if [[ ! -f "$MIRROR_FILE" ]]; then
    echo "FEHLER: $MIRROR_FILE fehlt — das Repo traegt den Policy-Text nicht mehr." >&2
    exit 1
fi

expected="$(tr -d '\n' < "$MIRROR_FILE" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')"
if [[ -z "$expected" ]]; then
    echo "FEHLER: $MIRROR_FILE ist leer." >&2
    exit 1
fi

# Das Haupt-Caddyfile und alle importierten Fragmente lesen. Die Glob-Expansion
# findet im Container statt (sh -c), deshalb wird sie nicht vom lokalen
# ssh-Aufruf aufgeloest. Ein fehlendes Fragment-Verzeichnis ist kein Fehler:
# `[ -f ]` ueberspringt es, solange das Haupt-Caddyfile die Policy enthaelt.
remote_cmd="docker exec $(printf '%q' "$CADDY_CONTAINER") sh -c 'cat $(printf '%q' "$CADDYFILE_PATH"); for f in $(printf '%q' "$CADDY_CONF_DIR")/*.caddy; do [ -f \"\$f\" ] && cat \"\$f\"; done'"
caddyfile="$(ssh -o ConnectTimeout=25 "$SSH_TARGET" "$remote_cmd")"

# Jeder CSP-Header steht in einem `header Content-Security-Policy "…"`-Block.
# Die Richtlinie selbst enthaelt keine Anfuehrungszeichen, deshalb cut -d'"' -f2.
# head -1 faellt weg: die Policy kann in einem Fragment stehen, und ein anderes
# Fragment deklariert eine `frame-ancestors 'none'`-Snippet-Policy.
found="$(printf '%s\n' "$caddyfile" \
    | grep -o 'Content-Security-Policy "[^"]*"' \
    | cut -d'"' -f2 \
    | sort -u)"

if [[ -z "$found" ]]; then
    echo "FEHLER: Auf dem Host wurde kein Content-Security-Policy-Header gefunden." >&2
    echo "       (Container: $CADDY_CONTAINER, Dateien: $CADDYFILE_PATH, $CADDY_CONF_DIR/*.caddy)" >&2
    exit 1
fi

# Der Mirror muss unter den gefundenen Policies sein (exakte Uebereinstimmung,
# eine Zeile pro Policy). Andere Policies - zum Beispiel die
# frame-ancestors-Snippets - duerfen daneben stehen, ohne den Check zu faellen.
if ! printf '%s\n' "$found" | grep -Fxq -- "$expected"; then
    echo "FEHLER: Der Host liefert keine CSP, die mit dem Repo uebereinstimmt." >&2
    echo "" >&2
    echo "  Host:" >&2
    printf '%s\n' "$found" | sed 's/^/    /' >&2
    echo "  Repo : $expected" >&2
    echo "" >&2
    echo "  Der Mirror $MIRROR_FILE muss nachgezogen werden (die Quelle bleibt" >&2
    echo "  die Caddy-Konfiguration auf dem Host)." >&2
    exit 1
fi

echo "CSP stimmt mit dem Host ueberein ($CADDY_CONTAINER:$CADDYFILE_PATH + $CADDY_CONF_DIR/*.caddy)."
echo "  $expected"
