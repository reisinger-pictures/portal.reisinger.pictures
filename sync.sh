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
# Was uebertragen wird: 1) Backend (API), 2) Frontend-Build (dist/),
# 3) die Caddyfile des internen FTP-Event-Listeners (Einzeldatei),
# 4) die Rechte der Laufzeit-Baeume im Backend (nur Metadaten, kein Inhalt).
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
# Die Caddyfile des ftp-events-Seitendienstes liegt NICHT im websites-Baum,
# sondern unter einem festen Host-Pfad. Grund: der Stack laeuft aus Portainer,
# und ein relatives Bind-Ziel in deployment/docker-compose.yml wuerde gegen
# Portainers eigenes Compose-Verzeichnis aufgeloest (dort existiert die Datei
# nicht -> Docker legt ein Verzeichnis an -> Caddy-Crashloop). Der Mount dort
# und dieser Default muessen identisch bleiben; der Regressionstest vergleicht
# beide.
CADDY_DEST="${PORTAL_CADDY_DEST:-${SSH_TARGET}:/home/webadmin/portal/caddy/Caddyfile.ftp-events}"

# Connection-Reuse: bei ~13.000 Backend-Dateien sonst ein Handshake pro Datei.
SSH_OPTS="-o ControlMaster=auto -o ControlPath=/tmp/ssh-sync-%r@%h:%p -o ControlPersist=60"

# Rechte-Modell der Sites: 1002:82 (uid des Website-Baums, gid webgroup).
# Seit der Owner-Entscheidung 2026-10-25 (OPS-3, Issue #31) laeuft auch der
# Backend-Container mit dieser Identitaet (`user: "1002:82"` in
# deployment/docker-compose.yml). Damit ist der schreibende Prozess der
# Eigentuemer der Dateien und das Welt-Schreibrecht entfaellt:
#   - uebertragene Dateien 0644, uebertragene Verzeichnisse 0755;
#   - nur die Baeume, die Laravel zur Laufzeit beschreibt (storage/,
#     bootstrap/cache/), bleiben gruppenschreibbar: 2775 mit setgid, dieselbe
#     Konvention wie App\Support\FtpInboxDirectory::MODE.
# Vorher stand hier F666/D2777. Das war die Notloesung fuer den uid-Konflikt
# (Baum 1002, Backend 1000): ohne Welt-Schreibrecht konnte die laufende UID
# weder in `app/` noch in `storage/` schreiben. Mit angeglichener uid ist das
# Schreibrecht des Eigentuemers ausreichend; 0644/0755 ist der Befund OPS-3.
# Begruendung und Rollout: deployment/README.md, Abschnitt "4. Deploy".
# `--chown` numerisch, weil der Gruppenname webgroup nur auf dem Host existiert;
# `--chown=1002:webgroup` scheitert lokal im Regressionstest mit "Unknown
# --groupmap name". PORTAL_TREE_OWNER ist derselbe Test-Seam wie PORTAL_*_DEST.
TREE_OWNER="${PORTAL_TREE_OWNER:-1002:82}"
CHOWN="--chown=${TREE_OWNER}"
CHMOD="--chmod=D755,F644"

# Argumente durchreichen, damit ./sync.sh --dry-run funktioniert.
# Bash 3.2 (macOS) behandelt ein leeres Array unter `set -u` als ungebundene
# Variable — "RSYNC_EXTRA[@]: unbound variable". Die ${x[@]+...}-Form ist das
# portable Idiom: sie expandiert zu nichts, wenn das Array leer ist.
if [[ $# -gt 0 ]]; then
    RSYNC_EXTRA=("$@")
else
    RSYNC_EXTRA=()
fi

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
  ${RSYNC_EXTRA[@]+"${RSYNC_EXTRA[@]}"}

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
  ${RSYNC_EXTRA[@]+"${RSYNC_EXTRA[@]}"}

# --- 3. Caddyfile des internen FTP-Event-Listeners (OPS-1) -----------------
# Einzeldatei, kein Mirror: kein --delete (das Ziel IST die Datei). --mkpath
# ist Pflicht, weil /home/webadmin/portal/caddy auf einem frischen Host noch
# nicht existiert; ohne das Flag bricht rsync mit "No such file or directory"
# ab. Danach muss der Portainer-Stack aktualisiert werden, damit der neue
# Mount greift (deployment/README.md, FTP-Upload-Events).
echo "🔐 Sync: Caddyfile fuer den internen FTP-Event-Listener..."
"$RSYNC_BIN" ./deployment/Caddyfile.ftp-events "$CADDY_DEST" \
  --archive \
  --mkpath \
  "$CHOWN" \
  "$CHMOD" \
  --info=progress2 \
  --rsh="ssh $SSH_OPTS" \
  ${RSYNC_EXTRA[@]+"${RSYNC_EXTRA[@]}"}

# --- 4. Rechte der Laufzeit-Baeume (OPS-3) ---------------------------------
# storage/ und bootstrap/cache/ sind die einzigen Baeume, die Laravel (und der
# FTP-Import) zur Laufzeit beschreibt. Beide stehen in
# rsync-backend-exclude.txt, damit lokale Logs, kompilierte Views und private
# Altersnachweise weder hochgeladen noch von --delete geloescht werden. Genau
# deshalb kann Schritt 1 ihre Rechte nicht setzen: ausgeschlossene Verzeichnisse
# fasst rsync nicht an, und auf einem bestehenden Host bleiben sie auf 2777
# stehen.
#
# Dieser Lauf uebertraegt deshalb KEINEN Inhalt: --include='*/' --exclude='*'
# laesst nur Verzeichnisse durch, ein --delete fehlt bewusst. Er setzt
# 2775+setgid (Gruppe webgroup bleibt schreibfaehig, neue Eintraege erben die
# Gruppe) und den Baumeigentuemer. Der /./-Marker mit --relative haelt das
# Ziel-Wurzelverzeichnis aus dem Lauf, --no-implied-dirs den Zwischenschritt
# `bootstrap/` (nur `bootstrap/cache/` ist beschreibbar; Schritt 1 setzt
# `bootstrap/` auf 0755 und dieser Lauf darf das nicht wieder aufweiten).
# idempotent: ein zweiter Lauf meldet nichts zu tun.
echo "🔧 Sync: Rechte der Laufzeit-Baeume..."
"$RSYNC_BIN" ./backend/./storage/ ./backend/./bootstrap/cache/ "$API_DEST" \
  --relative \
  --no-implied-dirs \
  --recursive \
  --perms \
  --times \
  --include='*/' \
  --exclude='*' \
  "$CHOWN" \
  --chmod=D2775 \
  --rsh="ssh $SSH_OPTS" \
  ${RSYNC_EXTRA[@]+"${RSYNC_EXTRA[@]}"}

echo ""
echo "✅ Sync abgeschlossen!"
