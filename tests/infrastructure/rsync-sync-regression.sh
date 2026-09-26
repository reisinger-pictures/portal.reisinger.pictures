#!/usr/bin/env bash
# =============================================================================
# rsync-sync-regression.sh — Regressionstest fuer den rsync/ssh-Deploy
# =============================================================================
#
# Ersetzt rclone-sync-regression.sh (rclone-Filter-Syntax) nach der Migration
# von rclone auf rsync/ssh.
#
# Der Test fährt das ECHTE sync.sh gegen lokale Temp-Verzeichnisse --dry-run.
# Gegenüber der rclone-Variante (die eine Python-Fake-rclone nachbaute) ist das
# eine staerkere Assertion: es wird nicht die Filter-Logik simuliert, sondern
# das tatsaechliche Verhalten von rsync geprueft.
#
# Geprueft wird:
#   1. sync.sh-Invarianten (strict mode, kein --delete-excluded, --delete da)
#   2. Pflichtregeln in rsync-backend-exclude.txt
#   3. Secrets/Abhaengigkeiten werden NICHT uebertragen
#   4. storage/app/private/ wird weder uebertragen NOCH geloescht
#   5. destination-only Dateien im privaten Storage bleiben erhalten
#   6. Stale-Dateien ausserhalb der Excludes werden GELOESCHT (Mirror-Semantik)
#   7. SQLite-Dateien (lokal + destination-only) bleiben unangetastet
#
# Aufruf:  bash tests/infrastructure/rsync-sync-regression.sh
# Exit:    0 = alles gruen, 1 = Regression
# =============================================================================
set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
SYNC_SCRIPT="$ROOT_DIR/sync.sh"
EXCLUDE_FILE="$ROOT_DIR/rsync-backend-exclude.txt"
TMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/rsync-sync-regression.XXXXXX")"
# KEEP_TMP=1 laesst das Fixture zur Fehlersuche stehen.
if [[ -n "${KEEP_TMP:-}" ]]; then
    trap ':' EXIT
    printf 'KEEP_TMP: fixture bleibt unter %s\n' "$TMP_ROOT" >&2
else
    trap 'rm -rf "$TMP_ROOT"' EXIT
fi

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

pass() {
    printf 'PASS: %s\n' "$*"
}

# --- GNU-rsync ---------------------------------------------------------------
RSYNC_BIN="${RSYNC_BIN:-$(command -v rsync || true)}"
_rsync_version="$("$RSYNC_BIN" --version 2>/dev/null | head -1 || true)"
[[ "$_rsync_version" == "rsync  version"* ]] \
    || fail "GNU-rsync required (macOS default is openrsync). Install: brew install rsync"

# ============================================================================
# 1. Script-Invarianten
# ============================================================================
[[ -f "$SYNC_SCRIPT" ]] || fail "sync script not found: $SYNC_SCRIPT"
[[ -f "$EXCLUDE_FILE" ]] || fail "exclude file not found: $EXCLUDE_FILE"

grep -Fxq -- 'set -euo pipefail' "$SYNC_SCRIPT" \
    || fail 'sync.sh lost strict failure propagation'
grep -Fq -- '--delete' "$SYNC_SCRIPT" \
    || fail 'sync.sh must use --delete (rclone sync was a mirror)'
# Nur ausfuehrender Code zaehlt: sync.sh erwaehnt --delete-excluded in einem
# Kommentar ("NIEMALS verwenden"). Ein naiver grep wuerde das falsch werten.
if grep -vE '^[[:space:]]*#' "$SYNC_SCRIPT" | grep -Fq -- '--delete-excluded'; then
    fail 'sync.sh must not use --delete-excluded (would wipe private storage)'
fi
grep -Fq -- '--exclude-from=rsync-backend-exclude.txt' "$SYNC_SCRIPT" \
    || fail 'sync.sh must read the exclude file'
pass 'sync.sh invariants (strict mode, --delete, no --delete-excluded, exclude file)'

# ============================================================================
# 2. Pflichtregeln in der Exclude-Datei
# ============================================================================
require_rule() {
    grep -Fxq -- "$1" "$EXCLUDE_FILE" || fail "missing exclude rule: $1"
}

require_rule '/storage/app/private/stripe_secret.txt'
require_rule '/storage/app/private/'
require_rule '.env'
require_rule '.env.*'
require_rule 'vendor/'
require_rule 'node_modules/'
# unanchored (entspricht rclone 'storage/logs/**' ohne fuehrenden Slash)
require_rule 'storage/logs/'
require_rule 'bootstrap/cache/'
require_rule '/*.sqlite*'
require_rule '*.sqlite*'
pass 'exclude file contains all mandatory rules'

# ============================================================================
# 3.–7. Fixture + echter --dry-run
# ============================================================================
PROJECT="$TMP_ROOT/project"
DEST="$TMP_ROOT/destination"
mkdir -p \
    "$PROJECT/backend/app" \
    "$PROJECT/backend/vendor/pkg" \
    "$PROJECT/backend/node_modules/dep" \
    "$PROJECT/backend/tests/Feature" \
    "$PROJECT/backend/storage/app/private/model-age-proofs/source-only" \
    "$PROJECT/backend/storage/app/private/temp" \
    "$PROJECT/backend/storage/logs" \
    "$PROJECT/backend/bootstrap/cache" \
    "$PROJECT/backend/out" \
    "$PROJECT/frontend/dist/assets" \
    "$DEST/api-portal.reisinger.pictures/app" \
    "$DEST/api-portal.reisinger.pictures/storage/app/private/model-age-proofs/destination-only" \
    "$DEST/api-portal.reisinger.pictures/vendor/pkg" \
    "$DEST/api-portal.reisinger.pictures/storage/logs" \
    "$DEST/web-portal.reisinger.pictures/dist/assets"

cp "$SYNC_SCRIPT" "$EXCLUDE_FILE" "$PROJECT/"

# Quelle (was hochgeladen werden darf / nicht)
printf 'source-app\n'          > "$PROJECT/backend/app/keep.txt"
printf 'source-index\n'        > "$PROJECT/backend/index.php"
printf 'source-env\n'          > "$PROJECT/backend/.env.testing"
printf 'local-stripe-secret\n' > "$PROJECT/backend/storage/app/private/stripe_secret.txt"
printf 'source-proof\n'        > "$PROJECT/backend/storage/app/private/model-age-proofs/source-only/proof.jpg"
printf 'local-temp\n'          > "$PROJECT/backend/storage/app/private/temp/download.tmp"
printf 'source-vendor\n'       > "$PROJECT/backend/vendor/pkg/autoload.php"
printf 'source-node-modules\n' > "$PROJECT/backend/node_modules/dep/index.js"
printf 'source-test\n'         > "$PROJECT/backend/tests/Feature/ExampleTest.php"
printf 'source-log\n'          > "$PROJECT/backend/storage/logs/laravel.log"
printf 'source-cache\n'        > "$PROJECT/backend/bootstrap/cache/config.php"
printf 'source-out\n'          > "$PROJECT/backend/out/build.txt"
printf 'source-database\n'     > "$PROJECT/backend/database.sqlite"
printf 'source-sqlite3\n'      > "$PROJECT/backend/foo.sqlite3"
printf 'frontend-index\n'      > "$PROJECT/frontend/dist/index.html"
printf 'frontend-asset\n'      > "$PROJECT/frontend/dist/assets/app-NEW.js"

# Ziel (was schon auf dem Server liegt)
printf 'destination-secret\n'  > "$DEST/api-portal.reisinger.pictures/storage/app/private/stripe_secret.txt"
printf 'destination-proof\n'   > "$DEST/api-portal.reisinger.pictures/storage/app/private/model-age-proofs/destination-only/proof.jpg"
printf 'destination-vendor\n'  > "$DEST/api-portal.reisinger.pictures/vendor/pkg/autoload.php"
printf 'destination-log\n'     > "$DEST/api-portal.reisinger.pictures/storage/logs/laravel.log"
printf 'destination-db\n'      > "$DEST/api-portal.reisinger.pictures/database.sqlite"
printf 'destination-sqlite3\n' > "$DEST/api-portal.reisinger.pictures/foo.sqlite3"
printf 'stale-app\n'           > "$DEST/api-portal.reisinger.pictures/app/stale.txt"
printf 'stale-asset\n'         > "$DEST/web-portal.reisinger.pictures/dist/assets/app-OLD.js"

cd "$PROJECT"

API_OUT="$TMP_ROOT/api.out"
WEB_OUT="$TMP_ROOT/web.out"

# WICHTIG: die KOPIE im Fixture ausfuehren, nicht $SYNC_SCRIPT. sync.sh macht
# `cd "$(dirname "$0")"` — mit dem Originalpfad wuerde es in den echten
# Portal-Repo-Root springen und das echte backend/ statt des Fixtures syncen.
# Mit der Kopie ist cwd == $PROJECT und ./backend/ ist die Fixture.
[[ -f "$PROJECT/sync.sh" ]] || fail 'sync.sh was not copied into the fixture'

# "Unknown --groupmap name on receiver: webgroup" ist hier ERWARTET: die Gruppe
# webgroup existiert nur auf dem Produktionsserver, das Fixture-Ziel ist lokal.
# Im --dry-run wird ohnehin kein chown ausgefuehrt.
RSYNC_BIN="$RSYNC_BIN" \
PORTAL_API_DEST="$DEST/api-portal.reisinger.pictures/" \
PORTAL_WEB_DEST="$DEST/web-portal.reisinger.pictures/dist/" \
    bash "$PROJECT/sync.sh" --dry-run --itemize-changes > "$TMP_ROOT/full.out" 2>&1 \
    || fail "sync.sh --dry-run exited non-zero:
$(tail -20 "$TMP_ROOT/full.out")"

# Trenne die beiden rsync-Aufrufe anhand der Itemize-Zeilen.
# Der Backend-Lauf referenziert backend/, der Frontend-Lauf frontend/dist/.
sed -n '/Synchronisiere/,/^$/!p' /dev/null 2>/dev/null || true
awk '/📦 Sync: API-Ordner/,/🎨 Sync: Frontend/' "$TMP_ROOT/full.out" | grep -E '^([<>*.]|c|h)' > "$API_OUT" || true
awk '/🎨 Sync: Frontend/,0'                       "$TMP_ROOT/full.out" | grep -E '^([<>*.]|c|h)' > "$WEB_OUT" || true

# --- 3. Secrets / Abhaengigkeiten werden NICHT uebertragen -------------------
assert_not_transferred() {
    local list="$1" context="$2" entry="$3"
    if printf '%s\n' "$list" | grep -Fqx -- "$entry"; then
        fail "$context selected excluded file: $entry"
    fi
    # auch als Verzeichnis-Reihe darf es nicht auftauchen
    if printf '%s\n' "$list" | grep -qE "^[<>][fdL].*\b${entry}"; then
        fail "$context selected excluded file: $entry"
    fi
}

for entry in \
    '.env.testing' \
    'storage/app/private/stripe_secret.txt' \
    'storage/app/private/model-age-proofs/source-only/proof.jpg' \
    'storage/app/private/temp/download.tmp' \
    'vendor/pkg/autoload.php' \
    'node_modules/dep/index.js' \
    'tests/Feature/ExampleTest.php' \
    'storage/logs/laravel.log' \
    'bootstrap/cache/config.php' \
    'out/build.txt' \
    'database.sqlite' \
    'foo.sqlite3'
do
    assert_not_transferred "$API_OUT" 'backend sync' "$entry"
done
pass 'backend sync transfers no secrets, no dependencies, no local SQLite'

# --- 4./5. Private Storage: weder Upload noch Loeschung --------------------
# Erfasst JEDE Itemize-Zeile (Transfer oder Loeschung), die private Storage
# oder das Stripe-Secret beruehrt. Destination-only Dateien (Modell-
# Altersnachweise, stripe_secret.txt) duerfen weder hochgeladen noch entfernt
# werden -- das ist der wichtigste Guard dieses Tests.
private_hits="$(grep -E 'storage/app/private|stripe_secret\.txt' "$API_OUT" || true)"
if [[ -n "$private_hits" ]]; then
    fail "backend sync touched private storage (upload or delete):
$private_hits"
fi
pass 'storage/app/private is neither uploaded nor deleted (destination-only data safe)'

# --- 6. Stale-Dateien ausserhalb der Excludes werden GELOESCHT --------------
grep -qF 'deleting   app/stale.txt' "$API_OUT" \
    || fail 'stale file outside excludes was not deleted (mirror semantics lost):
'"$(cat "$API_OUT")"
grep -qF 'deleting   assets/app-OLD.js' "$WEB_OUT" \
    || fail 'stale hashed asset was not deleted (mirror semantics lost):
'"$(cat "$WEB_OUT")"
pass 'stale files and stale hashed assets are deleted (mirror semantics preserved)'

# --- 7. destination-only Vendor/Logs bleiben erhalten -----------------------
if grep -qF 'vendor/pkg/autoload.php' "$API_OUT"; then
    fail 'server-side vendor/ must not be deleted by the backend sync'
fi
if grep -qF 'storage/logs/laravel.log' "$API_OUT"; then
    fail 'server-side runtime logs must not be deleted by the backend sync'
fi
pass 'server-side vendor/ and storage/logs survive the sync'

# --- Erwartete Inhalte werden uebertragen -----------------------------------
grep -qF 'index.php' "$API_OUT" \
    || fail 'backend source file index.php was not selected'
grep -qF 'app/keep.txt' "$API_OUT" \
    || fail 'backend source file app/keep.txt was not selected'
grep -qF 'index.html' "$WEB_OUT" \
    || fail 'frontend index.html was not selected'
pass 'ordinary source files are selected for transfer'

printf '\nOK: all rsync sync regressions passed\n'
