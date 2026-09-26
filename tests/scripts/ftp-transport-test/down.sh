#!/usr/bin/env bash
# ===========================================================================
# down.sh — Test-Harness vollstaendig abbauen.
#
# Entfernt Container, Netzwerk und das lokale Laufzeitverzeichnis
# (.runtime mit SQLite-DB, Zertifikaten, Payloads und Credentials-Datei).
# Es bleibt nichts zurueck.
#
# Bewusst NICHT getan wird:
#   * kein `docker volume prune`, kein Eingriff in fremde Projekte
#   * kein `chown -R` und kein `adduser -h` (19-ftp 7.9)
#   * kein Zugriff auf `deployment/docker-compose.yml` — der Produktionsstack
#     wird von diesem Harness nicht angefasst
#
# Aufruf: bash down.sh            (Abbau + .runtime loeschen)
#        bash down.sh --keep-data (nur Container weg, Daten + Credentials bleiben)
# ===========================================================================
set -euo pipefail

# shellcheck source=lib/common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/common.sh"

KEEP_DATA=0
for arg in "$@"; do
    case "$arg" in
        --keep-data) KEEP_DATA=1 ;;
        -h|--help) sed -n '2,17p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) fail "Unbekanntes Argument: $arg" ;;
    esac
done

head1 "Container und Netzwerk entfernen"
if compose ps -a >/dev/null 2>&1 && [ -n "$(compose ps -aq 2>/dev/null || true)" ]; then
    compose down --remove-orphans
    ok "Compose-Projekt ${COMPOSE_PROJECT} abgebaut"
else
    log "Kein laufendes Compose-Projekt ${COMPOSE_PROJECT} gefunden."
    # Auch bei fehlendem Projekt koennen Reste existieren (z. B. abgebrochener Up).
    if [ -n "$(docker ps -aq --filter "name=portal_ftp_harness" 2>/dev/null || true)" ]; then
        docker rm -f $(docker ps -aq --filter "name=portal_ftp_harness") >/dev/null
        ok " verwaiste Container mit 'portal_ftp_harness' im Namen entfernt"
    fi
fi

if [ "$KEEP_DATA" -eq 1 ]; then
    log "--keep-data: ${RUNTIME_DIR} bleibt erhalten (Credentials und SQLite-DB)."
    exit 0
fi

head1 "Laufzeitverzeichnis entfernen"
if [ -d "$RUNTIME_DIR" ]; then
    rm -rf "$RUNTIME_DIR"
    ok "entfernt: ${RUNTIME_DIR}"
else
    log "Nichts zu entfernen (${RUNTIME_DIR} existiert nicht)."
fi

# Gegenprobe: kein Container und kein Verzeichnis darf uebrig sein.
leftover="$(docker ps -aq --filter "name=portal_ftp_harness" 2>/dev/null || true)"
[ -z "$leftover" ] || fail "Es sind noch Container uebrig: ${leftover}"
[ -d "$RUNTIME_DIR" ] && fail "Laufzeitverzeichnis existiert noch: ${RUNTIME_DIR}"
ok "Keine Spuren: kein Container, kein Laufzeitverzeichnis."
