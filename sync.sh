#!/usr/bin/env bash
# =============================================================================
# sync.sh — portal.reisinger.pictures via rsync/ssh auf root@reisinger.pictures
# =============================================================================
#
# Ersetzt die frühere rclone-SFTP-Variante (Remote "reisinger.pictures").
# Hintergrund und Migrations-Reihenfolge: strato-vps/README.md
#
# Aufruf:
#   ./sync.sh              echter Deploy
#   ./sync.sh --dry-run    nur anzeigen, nichts aendern (empfohlen zuerst)
#
# Warum rsync und nicht scp: rclone sync war ein MIRROR (inkl. Loeschen). Der
# Frontend-Build erzeugt content-hashed Assets, jeder Deploy muss den alten
# Hash entfernen — scp -r hat kein --delete und wuerde die Dateien liegen lassen.
# =============================================================================
set -euo pipefail
cd "$(dirname "$0")"

# --- GNU-rsync-Pflicht -------------------------------------------------------
# macOS liefert per Default /usr/bin/rsync = openrsync (Protokoll 29), das weder
# --chown noch --chmod im benoetigten Umfang unterstuetzt.
# WICHTIG: nicht per `rsync --version | grep -q ...` pruefen. Unter `set -o
# pipefail` beendet `grep -q` den Upstream vorzeitig per SIGPIPE, die Pipeline
# gilt dann als fehlgeschlagen und der Guard schlaegt IMMER an. Deshalb die
# Ausgabe zuerst in eine Variable ziehen.
RSYNC_BIN="${RSYNC_BIN:-$(command -v rsync || true)}"
_rsync_version="$("$RSYNC_BIN" --version 2>/dev/null | head -1 || true)"
if [[ "$_rsync_version" != "rsync  version"* ]]; then
  echo "FEHLER: GNU-rsync benoetigt (macOS-Default ist openrsync)." >&2
  echo "       Install:  brew install rsync" >&2
  echo "       Oder:     RSYNC_BIN=/opt/homebrew/bin/rsync ./sync.sh" >&2
  exit 1
fi
unset _rsync_version

# --- Konfiguration ----------------------------------------------------------
# Achtung: der SFTP-Chroot ist weg, daher die vollen Pfade.
# Die PORTAL_*-Overrides existieren, damit der Regressionstest
# (tests/infrastructure/rsync-sync-regression.sh) das echte Script gegen
# lokale Temp-Verzeichnisse fahren kann. Ohne Override = Produktion.
SITES="${PORTAL_SITES:-/home/webadmin/websites}"
SSH_TARGET="${PORTAL_SSH_TARGET:-root@reisinger.pictures}"
API_DEST="${PORTAL_API_DEST:-${SSH_TARGET}:${SITES}/api-portal.reisinger.pictures/}"
WEB_DEST="${PORTAL_WEB_DEST:-${SSH_TARGET}:${SITES}/web-portal.reisinger.pictures/dist/}"

# Connection-Reuse: bei ~13.000 Backend-Dateien sonst ein Handshake pro Datei.
SSH_OPTS="-o ControlMaster=auto -o ControlPath=/tmp/ssh-sync-%r@%h:%p -o ControlPersist=60"

# Rechte-Modell der Sites: 1002:webgroup, Dateien 666, Verzeichnisse 2777
# (setgid). Ein reines -a wuerde die LOKALEN Rechte (644/755) durchdruecken.
CHOWN="--chown=1002:webgroup"
CHMOD="--chmod=D2777,F666"

# Argumente durchreichen, damit ./sync.sh --dry-run funktioniert.
RSYNC_EXTRA=("$@")

echo "==================================================="
echo "🔄 Starte rsync/ssh Sync zum Server..."
echo "==================================================="

# --- 1. API Bereich (Backend) ----------------------------------------------
# ACHTUNG: --delete ist hier durch die Excludes aus rsync-backend-exclude.txt
# entschaerft. Die Datei schuetzt u. a. storage/app/private/ vor der Loeschung
# (destination-only Dateien wie Modell-Altersnachweise). NIEMALS
# --delete-excluded verwenden — das wuerde genau diese Dateien loeschen.
echo "📦 Sync: API-Ordner..."
"$RSYNC_BIN" ./backend/ "$API_DEST" \
  --archive \
  --delete \
  "$CHOWN" \
  "$CHMOD" \
  --exclude-from=rsync-backend-exclude.txt \
  --info=progress2 \
  --rsh="ssh $SSH_OPTS" \
  "${RSYNC_EXTRA[@]}"

# --- 2. Web Bereich (Frontend Dist) ----------------------------------------
# Reines Build-Artefakt: keine Excludes noetig, der Ordner enthaelt nur
# Vite-Output. --delete ist hier unbedenklich UND noetig (Hash-Assets).
echo "🎨 Sync: Frontend (dist)..."
"$RSYNC_BIN" ./frontend/dist/ "$WEB_DEST" \
  --archive \
  --delete \
  "$CHOWN" \
  "$CHMOD" \
  --info=progress2 \
  --rsh="ssh $SSH_OPTS" \
  "${RSYNC_EXTRA[@]}"

echo ""
echo "✅ Sync abgeschlossen!"
