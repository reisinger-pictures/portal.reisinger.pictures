#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
SYNC_SCRIPT="$ROOT_DIR/sync.sh"
FILTER_FILE="$ROOT_DIR/rclone-backend-filter.txt"
TMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/rclone-sync-regression.XXXXXX")"
trap 'rm -rf "$TMP_ROOT"' EXIT

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

assert_file_content() {
    local path="$1"
    local expected="$2"

    [[ -f "$path" ]] || fail "expected file to exist: $path"
    [[ "$(cat "$path")" == "$expected" ]] || fail "unexpected content: $path"
}

assert_missing() {
    local path="$1"

    [[ ! -e "$path" ]] || fail "expected path to be absent: $path"
}

assert_list_contains() {
    local list="$1"
    local entry="$2"

    printf '%s\n' "$list" | grep -Fqx -- "$entry" || fail "filter did not include: $entry"
}

assert_list_excludes() {
    local list="$1"
    local entry="$2"
    local context="$3"

    if printf '%s\n' "$list" | grep -Fqx -- "$entry"; then
        fail "$context selected excluded file: $entry"
    fi
}

assert_list_excludes_private() {
    local list="$1"
    local context="$2"

    if printf '%s\n' "$list" | grep -Eq '(^|/)storage/app/private/'; then
        fail "$context selected private storage"
    fi
}

[[ -f "$SYNC_SCRIPT" ]] || fail "sync script not found: $SYNC_SCRIPT"
[[ -f "$FILTER_FILE" ]] || fail "filter file not found: $FILTER_FILE"
grep -Fxq -- 'set -euo pipefail' "$SYNC_SCRIPT" || fail 'sync.sh lost strict failure propagation'
! grep -Fq -- '--delete-excluded' "$SYNC_SCRIPT" || fail 'sync.sh must not use --delete-excluded'

grep -Fxq -- '- /storage/app/private/' "$FILTER_FILE" \
    || fail 'private-storage directory exclusion is missing'
grep -Fxq -- '- /storage/app/private/stripe_secret.txt' "$FILTER_FILE" \
    || fail 'Stripe tunnel secret exclusion is missing'
grep -Fxq -- '- /storage/app/private/**' "$FILTER_FILE" \
    || fail 'complete private-storage exclusion is missing'

private_rule_line="$(grep -n -F -- '- /storage/app/private/**' "$FILTER_FILE" | cut -d: -f1)"
include_rule_line="$(grep -n -F -- '+ **' "$FILTER_FILE" | cut -d: -f1)"
[[ "$private_rule_line" -lt "$include_rule_line" ]] \
    || fail 'private-storage exclusion must precede the catch-all include'

PROJECT="$TMP_ROOT/project"
DESTINATION="$TMP_ROOT/destination"
BIN_DIR="$TMP_ROOT/bin"
CALL_COUNT_FILE="$TMP_ROOT/rclone-call-count"
CALL_LOG_FILE="$TMP_ROOT/rclone-call-log"
mkdir -p \
    "$PROJECT/backend/app" \
    "$PROJECT/backend/storage/app/private/model-age-proofs/source-only" \
    "$PROJECT/backend/storage/app/private/temp" \
    "$PROJECT/frontend/dist" \
    "$DESTINATION/api-portal.reisinger.pictures/app" \
    "$DESTINATION/api-portal.reisinger.pictures/storage/app/private/model-age-proofs/destination-only" \
    "$DESTINATION/api-portal.reisinger.pictures/storage/app/private" \
    "$DESTINATION/web-portal.reisinger.pictures/dist" \
    "$BIN_DIR"

cp "$SYNC_SCRIPT" "$FILTER_FILE" "$PROJECT/"
printf 'source-app\n' > "$PROJECT/backend/app/keep.txt"
printf 'local-stripe-secret\n' > "$PROJECT/backend/storage/app/private/stripe_secret.txt"
printf 'source-proof\n' > "$PROJECT/backend/storage/app/private/model-age-proofs/source-only/proof.jpg"
printf 'local-temp\n' > "$PROJECT/backend/storage/app/private/temp/download.tmp"
printf 'local-env\n' > "$PROJECT/backend/.env.testing"
printf 'source-database\n' > "$PROJECT/backend/database.sqlite"
printf 'source-sqlite3\n' > "$PROJECT/backend/foo.sqlite3"
printf 'frontend\n' > "$PROJECT/frontend/dist/index.html"

printf 'destination-stripe-secret\n' > "$DESTINATION/api-portal.reisinger.pictures/storage/app/private/stripe_secret.txt"
printf 'destination-proof\n' > "$DESTINATION/api-portal.reisinger.pictures/storage/app/private/model-age-proofs/destination-only/proof.jpg"
printf 'destination-database\n' > "$DESTINATION/api-portal.reisinger.pictures/database.sqlite"
printf 'destination-sqlite3\n' > "$DESTINATION/api-portal.reisinger.pictures/foo.sqlite3"
printf 'stale-app\n' > "$DESTINATION/api-portal.reisinger.pictures/app/stale.txt"
printf 'stale-frontend\n' > "$DESTINATION/web-portal.reisinger.pictures/dist/stale.html"

if [[ -n "${RCLONE_REAL_BIN:-}" ]]; then
    if [[ ! -x "$RCLONE_REAL_BIN" ]]; then
        fail "RCLONE_REAL_BIN is not executable: $RCLONE_REAL_BIN"
    fi
    MODE='real'
else
    MODE='fake'
fi

cat > "$BIN_DIR/rclone-wrapper" <<'WRAPPER'
#!/usr/bin/env bash
set -euo pipefail

count_file="${RCLONE_CALL_COUNT_FILE:?}"
log_file="${RCLONE_CALL_LOG:?}"
call_count=0
if [[ -s "$count_file" ]]; then
    call_count="$(<"$count_file")"
fi
call_count=$((call_count + 1))
printf '%s\n' "$call_count" > "$count_file"
printf '%s\t%s\n' "$call_count" "$*" >> "$log_file"

if [[ "${FAIL_RCLONE_CALL:-0}" == "$call_count" ]]; then
    exit 42
fi

if [[ -n "${FAKE_RCLONE_PYTHON:-}" ]]; then
    exec python3 "$FAKE_RCLONE_PYTHON" "$@"
fi

exec "${RCLONE_REAL_BIN:?}" "$@"
WRAPPER
chmod +x "$BIN_DIR/rclone-wrapper"

if [[ "$MODE" == 'fake' ]]; then
    cat > "$BIN_DIR/fake-rclone.py" <<'PYTHON'
#!/usr/bin/env python3
import fnmatch
import os
import shutil
import sys
from pathlib import Path


def load_rules(filter_path):
    rules = []
    with open(filter_path, encoding="utf-8") as handle:
        for line_number, raw_line in enumerate(handle, 1):
            line = raw_line.strip()
            if not line or line.startswith("#") or line.startswith(";"):
                continue
            if line[0] not in "+-":
                raise ValueError("invalid filter rule at line {}".format(line_number))
            rules.append((line[0] == "+", line[1:].strip()))
    return rules


def glob_matches(pattern, relative_path):
    candidates = [relative_path]
    if pattern.startswith("/"):
        pattern = pattern[1:]
    else:
        parts = relative_path.split("/")
        candidates.extend("/".join(parts[index:]) for index in range(1, len(parts)))
        candidates.extend(parts)
    if pattern.endswith("/"):
        pattern = pattern.rstrip("/") + "/**"
    if pattern.startswith("**/"):
        # rclone's **/ prefix requires at least one directory separator;
        # do not let the fake matcher accidentally match a root-level file.
        candidates = [candidate for candidate in candidates if "/" in candidate]
    return any(fnmatch.fnmatchcase(candidate, pattern) for candidate in candidates)


def is_included(relative_path, rules):
    for include, pattern in rules:
        if glob_matches(pattern, relative_path):
            return include
    return True


def relative_files(root):
    if not root.exists():
        return []
    return sorted(
        (path for path in root.rglob("*") if path.is_file()),
        key=lambda path: path.relative_to(root).as_posix(),
    )


def destination_path(remote):
    prefix = os.environ["FAKE_RCLONE_DEST"]
    if remote.startswith("reisinger.pictures:"):
        return Path(prefix) / remote.split(":", 1)[1].lstrip("/")
    if ":" in remote:
        return Path(prefix) / remote.split(":", 1)[1].lstrip("/")
    return Path(remote)


def get_filter_path(arguments):
    for index, argument in enumerate(arguments):
        if argument == "--filter-from":
            if index + 1 >= len(arguments):
                raise ValueError("--filter-from requires a path")
            return arguments[index + 1]
    return None


def run_sync(arguments):
    if len(arguments) < 2:
        raise ValueError("sync requires source and destination")
    source = Path(arguments[0])
    destination = destination_path(arguments[1])
    filter_path = get_filter_path(arguments[2:])
    rules = load_rules(filter_path) if filter_path else []

    source_files = relative_files(source)
    included_source = {}
    for source_file in source_files:
        relative_path = source_file.relative_to(source).as_posix()
        if is_included(relative_path, rules):
            included_source[relative_path] = source_file

    destination.mkdir(parents=True, exist_ok=True)
    for relative_path, source_file in included_source.items():
        destination_file = destination / relative_path
        destination_file.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(str(source_file), str(destination_file))
        print("FAKE-RCLONE COPY {}".format(relative_path))

    for destination_file in relative_files(destination):
        relative_path = destination_file.relative_to(destination).as_posix()
        if relative_path in included_source:
            continue
        if is_included(relative_path, rules):
            destination_file.unlink()
            print("FAKE-RCLONE DELETE {}".format(relative_path))


def run_lsf(arguments):
    if not arguments:
        raise ValueError("lsf requires a path")
    source = Path(arguments[0])
    filter_path = get_filter_path(arguments[1:])
    rules = load_rules(filter_path) if filter_path else []
    for source_file in relative_files(source):
        relative_path = source_file.relative_to(source).as_posix()
        if is_included(relative_path, rules):
            print(relative_path)


if __name__ == "__main__":
    try:
        if len(sys.argv) < 2:
            raise ValueError("missing command")
        command = sys.argv[1]
        command_arguments = sys.argv[2:]
        if command == "sync":
            run_sync(command_arguments)
        elif command == "lsf":
            run_lsf(command_arguments)
        else:
            raise ValueError("unsupported fake rclone command: {}".format(command))
    except (OSError, ValueError) as error:
        print("fake rclone: {}".format(error), file=sys.stderr)
        sys.exit(64)
PYTHON
    chmod +x "$BIN_DIR/fake-rclone.py"
    RCLONE_REAL_BIN_FOR_WRAPPER=''
    FAKE_RCLONE_PYTHON="$BIN_DIR/fake-rclone.py"
else
    RCLONE_REAL_BIN_FOR_WRAPPER="$RCLONE_REAL_BIN"
    FAKE_RCLONE_PYTHON=''
    cat > "$TMP_ROOT/rclone.conf" <<EOF
[reisinger.pictures]
type = alias
remote = $DESTINATION
EOF
fi

cp "$BIN_DIR/rclone-wrapper" "$BIN_DIR/rclone"
chmod +x "$BIN_DIR/rclone"

run_sync() {
    if command -v zsh >/dev/null 2>&1; then
        zsh ./sync.sh
    else
        bash ./sync.sh
    fi
}

run_lsf() {
    local source_path="$1"
    local count_file="$TMP_ROOT/lsf-count"
    local log_file="$TMP_ROOT/lsf-log"
    : > "$count_file"
    : > "$log_file"

    if [[ "$MODE" == 'real' ]]; then
        RCLONE_CONFIG="$TMP_ROOT/rclone.conf" "$RCLONE_REAL_BIN" lsf "$source_path" \
            --recursive --files-only --filter-from "$FILTER_FILE"
    else
        FAKE_RCLONE_DEST="$DESTINATION" \
        FAKE_RCLONE_PYTHON="$FAKE_RCLONE_PYTHON" \
        RCLONE_CALL_COUNT_FILE="$count_file" \
        RCLONE_CALL_LOG="$log_file" \
        "$BIN_DIR/rclone" lsf "$source_path" --recursive --files-only --filter-from "$FILTER_FILE"
    fi
}

source_selected="$(run_lsf "$PROJECT/backend")"
destination_selected="$(run_lsf "$DESTINATION/api-portal.reisinger.pictures")"
assert_list_contains "$source_selected" 'app/keep.txt'
assert_list_excludes_private "$source_selected" 'source filter'
assert_list_excludes "$source_selected" 'database.sqlite' 'source filter'
assert_list_excludes "$source_selected" 'foo.sqlite3' 'source filter'
if printf '%s\n' "$source_selected" | grep -Fqx -- '.env.testing'; then
    fail 'source filter included .env.testing'
fi
assert_list_contains "$destination_selected" 'app/stale.txt'
assert_list_excludes_private "$destination_selected" 'destination filter'
assert_list_excludes "$destination_selected" 'database.sqlite' 'destination filter'
assert_list_excludes "$destination_selected" 'foo.sqlite3' 'destination filter'

: > "$CALL_COUNT_FILE"
: > "$CALL_LOG_FILE"
if [[ "$MODE" == 'real' ]]; then
    (
        cd "$PROJECT"
        PATH="$BIN_DIR:$PATH" \
        RCLONE_CONFIG="$TMP_ROOT/rclone.conf" \
        RCLONE_CALL_COUNT_FILE="$CALL_COUNT_FILE" \
        RCLONE_CALL_LOG="$CALL_LOG_FILE" \
        RCLONE_REAL_BIN="$RCLONE_REAL_BIN_FOR_WRAPPER" \
        FAKE_RCLONE_PYTHON="$FAKE_RCLONE_PYTHON" \
        FAIL_RCLONE_CALL=0 \
        run_sync
    ) > "$TMP_ROOT/sync-output" 2>&1
else
    (
        cd "$PROJECT"
        PATH="$BIN_DIR:$PATH" \
        FAKE_RCLONE_DEST="$DESTINATION" \
        RCLONE_CALL_COUNT_FILE="$CALL_COUNT_FILE" \
        RCLONE_CALL_LOG="$CALL_LOG_FILE" \
        RCLONE_REAL_BIN="$RCLONE_REAL_BIN_FOR_WRAPPER" \
        FAKE_RCLONE_PYTHON="$FAKE_RCLONE_PYTHON" \
        FAIL_RCLONE_CALL=0 \
        run_sync
    ) > "$TMP_ROOT/sync-output" 2>&1
fi

grep -Fq -- '✅ Sync abgeschlossen!' "$TMP_ROOT/sync-output" \
    || fail 'sync did not report success after both syncs'
[[ "$(<"$CALL_COUNT_FILE")" == '2' ]] || fail 'sync did not invoke rclone exactly twice'
grep -Fq -- '--filter-from' "$CALL_LOG_FILE" || fail 'backend sync omitted the backend filter'
assert_file_content "$DESTINATION/api-portal.reisinger.pictures/app/keep.txt" 'source-app'
assert_missing "$DESTINATION/api-portal.reisinger.pictures/app/stale.txt"
assert_file_content "$DESTINATION/api-portal.reisinger.pictures/storage/app/private/stripe_secret.txt" 'destination-stripe-secret'
assert_file_content "$DESTINATION/api-portal.reisinger.pictures/storage/app/private/model-age-proofs/destination-only/proof.jpg" 'destination-proof'
assert_missing "$DESTINATION/api-portal.reisinger.pictures/storage/app/private/model-age-proofs/source-only/proof.jpg"
assert_missing "$DESTINATION/api-portal.reisinger.pictures/storage/app/private/temp/download.tmp"
assert_missing "$DESTINATION/api-portal.reisinger.pictures/.env.testing"
assert_file_content "$DESTINATION/api-portal.reisinger.pictures/database.sqlite" 'destination-database'
assert_file_content "$DESTINATION/api-portal.reisinger.pictures/foo.sqlite3" 'destination-sqlite3'
assert_file_content "$DESTINATION/web-portal.reisinger.pictures/dist/index.html" 'frontend'
assert_missing "$DESTINATION/web-portal.reisinger.pictures/dist/stale.html"

: > "$CALL_COUNT_FILE"
: > "$CALL_LOG_FILE"
if (
    cd "$PROJECT"
    PATH="$BIN_DIR:$PATH" \
    FAKE_RCLONE_DEST="$DESTINATION" \
    RCLONE_CALL_COUNT_FILE="$CALL_COUNT_FILE" \
    RCLONE_CALL_LOG="$CALL_LOG_FILE" \
    RCLONE_REAL_BIN="$RCLONE_REAL_BIN_FOR_WRAPPER" \
    FAKE_RCLONE_PYTHON="$FAKE_RCLONE_PYTHON" \
    FAIL_RCLONE_CALL=1 \
    run_sync
) > "$TMP_ROOT/failure-output" 2>&1; then
    fail 'sync returned success when the first rclone call failed'
fi
[[ "$(<"$CALL_COUNT_FILE")" == '1' ]] || fail 'sync continued after rclone failure'
! grep -Fq -- '✅ Sync abgeschlossen!' "$TMP_ROOT/failure-output" \
    || fail 'sync printed success after rclone failure'

: > "$CALL_COUNT_FILE"
: > "$CALL_LOG_FILE"
if (
    cd "$PROJECT"
    PATH="$BIN_DIR:$PATH" \
    FAKE_RCLONE_DEST="$DESTINATION" \
    RCLONE_CONFIG="$TMP_ROOT/rclone.conf" \
    RCLONE_CALL_COUNT_FILE="$CALL_COUNT_FILE" \
    RCLONE_CALL_LOG="$CALL_LOG_FILE" \
    RCLONE_REAL_BIN="$RCLONE_REAL_BIN_FOR_WRAPPER" \
    FAKE_RCLONE_PYTHON="$FAKE_RCLONE_PYTHON" \
    FAIL_RCLONE_CALL=2 \
    run_sync
) > "$TMP_ROOT/second-failure-output" 2>&1; then
    fail 'sync returned success when the second rclone call failed'
fi
[[ "$(<"$CALL_COUNT_FILE")" == '2' ]] || fail 'sync did not stop after the second rclone failure'
! grep -Fq -- '✅ Sync abgeschlossen!' "$TMP_ROOT/second-failure-output" \
    || fail 'sync printed success after the second rclone failure'

printf 'PASS: CR-INF-001/CR-INF-014 rclone sync regression (%s backend filter, source upload + destination deletion + root SQLite exclusion)\n' "$MODE"
