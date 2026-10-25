#!/usr/bin/env bash
# Static contract for the deploy-time file modes and the identity that uses them
# (finding OPS-3, issue #31).
#
# The finding had one cause: the backend container ran as 1000:82 while the
# website tree belongs to 1002:82, so the only way the process could write was
# world-writable everything — `sync.sh` shipped `--chmod=D2777,F666`. That pair
# is gone: the container runs as the tree owner and the sync hands out 0755/0644,
# with 2775+setgid (the `App\Support\FtpInboxDirectory::MODE` convention) only for
# the two trees Laravel writes at runtime.
#
# Nothing in a normal test run notices a widening here — the app keeps working
# while every file is world-writable, which is exactly why it survived. This gate
# therefore pins three things that only make sense together:
#
#   1. the modes `sync.sh` hands out (no world-writable bit anywhere, exactly two
#      --chmod specifications, 2775 only on storage/ and bootstrap/cache/),
#   2. the ownership it applies (one TREE_OWNER, passed to every rsync step),
#   3. that the backend container runs as exactly that identity — the two numbers
#      drifting apart again is the precondition for the finding's return.
#
# The probes are exercised against synthetic fixtures below: a guard that cannot
# reject the old state is not a guard. See also
# tests/infrastructure/rsync-sync-regression.sh, which proves the modes the script
# actually produces on disk, and the PHP policy test that pins the identity
# contract from the backend side.
set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
SYNC_SCRIPT="$ROOT_DIR/sync.sh"
COMPOSE_FILE="$ROOT_DIR/deployment/docker-compose.yml"
TMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/sync-permissions-contract.XXXXXX")"
trap 'rm -rf "$TMP_ROOT"' EXIT

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

pass() {
    printf 'PASS: %s\n' "$*"
}

require_file() {
    local file="$1"
    [[ -f "$file" ]] || fail "required file is missing: $file"
}

require_file "$SYNC_SCRIPT"
require_file "$COMPOSE_FILE"

# A file without its full-line comments: a mode named in prose ("vorher stand
# hier F666/D2777") documents the history and must not fail the gate, while a
# mode in executable code is the thing that counts.
code_lines() {
    grep -vE '^[[:space:]]*#' "$1" || true
}

# The rsync invocation that starts with the given prefix, including its
# backslash-continued option lines.
rsync_block() {
    local file="$1"
    local prefix="$2"

    awk -v prefix="$prefix" '
        index($0, prefix) == 1 { inside = 1 }
        inside { print; if ($0 !~ /\\$/) exit }
    ' "$file"
}

# --- probe: world-writable modes --------------------------------------------
# Prints a message and returns 0 when an executable line hands out a
# world-writable mode, returns 1 with no output when clean.
world_writable_violation() {
    local file="$1"
    local hits

    hits="$(code_lines "$file" \
        | grep -nE 'F666|D2777|(^|[^0-9])0?666([^0-9]|$)|(^|[^0-9])0?777([^0-9]|$)' || true)"
    if [[ -n "$hits" ]]; then
        printf '%s\n' "sync hands out a world-writable mode (F666/D2777) in $file:" "$hits"
        return 0
    fi
    return 1
}

# --- probe: the identity the container runs as ------------------------------
# Prints a message and returns 0 when the backend `user:` differs from the
# TREE_OWNER default of sync.sh, returns 1 with no output when they agree.
tree_owner_mismatch() {
    local sync="$1"
    local compose="$2"
    local owner backend_owner

    owner="$(sed -n 's/^TREE_OWNER="\${PORTAL_TREE_OWNER:-\([0-9]*:[0-9]*\)}"$/\1/p' "$sync")"
    if [[ -z "$owner" ]]; then
        printf '%s\n' "sync.sh declares no TREE_OWNER default, so the tree owner is unknown: $sync"
        return 0
    fi

    backend_owner="$(awk '
        /^  backend:$/ { in_service = 1; next }
        in_service && /^  [a-z_]+:/ { in_service = 0 }
        in_service && /^[[:space:]]*user:[[:space:]]*"[0-9]+:[0-9]+"/ {
            line = $0
            sub(/^[^"]*"/, "", line)
            sub(/".*$/, "", line)
            print line
            exit
        }
    ' "$compose")"
    if [[ -z "$backend_owner" ]]; then
        printf '%s\n' "the backend service declares no numeric user:, so its identity is unknown: $compose"
        return 0
    fi
    if [[ "$backend_owner" != "$owner" ]]; then
        printf '%s\n' \
            "the backend runs as ${backend_owner} but sync.sh chowns the tree to ${owner}; that drift is the OPS-3 cause"
        return 0
    fi
    return 1
}

# --- 1. modes ---------------------------------------------------------------
if message="$(world_writable_violation "$SYNC_SCRIPT")"; then
    fail "$message"
fi
fixture_world_writable="$TMP_ROOT/sync-world-writable.sh"
printf '#!/usr/bin/env bash\nCHMOD="--chmod=D2777,F666"\n' >"$fixture_world_writable"
if ! world_writable_violation "$fixture_world_writable" >/dev/null; then
    fail 'the world-writable fixture was not rejected by the mode probe'
fi
# The historical value in a comment is documentation, not a mode the script
# applies — the gate must tolerate it or the next honest comment turns it red.
fixture_commented="$TMP_ROOT/sync-commented.sh"
printf '#!/usr/bin/env bash\n# vorher stand hier F666/D2777 (OPS-3)\nCHMOD="--chmod=D755,F644"\n' >"$fixture_commented"
if world_writable_violation "$fixture_commented" >/dev/null; then
    fail 'a mode named in a comment was treated as an applied mode'
fi
pass 'sync.sh hands out no world-writable mode (F666/D2777 rejected, comment tolerated)'

grep -Fq -- 'CHMOD="--chmod=D755,F644"' "$SYNC_SCRIPT" \
    || fail 'sync.sh must transfer files as 0644 and directories as 0755'

chmod_specs="$(code_lines "$SYNC_SCRIPT" | grep -oE '\-\-chmod=[A-Za-z0-9,+-]+' || true)"
[[ "$(printf '%s\n' "$chmod_specs" | grep -c .)" == '2' ]] \
    || fail "sync.sh must use exactly two --chmod specifications, found:
$chmod_specs"
while IFS= read -r spec; do
    case "$spec" in
        '--chmod=D755,F644' | '--chmod=D2775') ;;
        *) fail "unexpected --chmod specification in sync.sh: $spec" ;;
    esac
done <<<"$chmod_specs"
pass 'sync.sh has exactly two chmod specifications: 0755/0644 and 2775'

# --- 2. ownership -----------------------------------------------------------
tree_owner="$(sed -n 's/^TREE_OWNER="\${PORTAL_TREE_OWNER:-\([0-9]*:[0-9]*\)}"$/\1/p' "$SYNC_SCRIPT")"
[[ -n "$tree_owner" ]] \
    || fail 'sync.sh must declare TREE_OWNER="${PORTAL_TREE_OWNER:-<uid>:<gid>}"'
grep -Fq -- 'CHOWN="--chown=${TREE_OWNER}"' "$SYNC_SCRIPT" \
    || fail 'sync.sh must apply its declared tree owner through --chown'

rsync_invocations="$(grep -cE '^"\$RSYNC_BIN" ' "$SYNC_SCRIPT" || true)"
chown_uses="$(grep -cF '"$CHOWN"' "$SYNC_SCRIPT" || true)"
[[ "$rsync_invocations" == '4' ]] \
    || fail "sync.sh must have four rsync steps (backend, frontend, Caddyfile, runtime trees), found: $rsync_invocations"
[[ "$chown_uses" == "$rsync_invocations" ]] \
    || fail "every rsync step must pass \"\$CHOWN\" (steps: $rsync_invocations, chown uses: $chown_uses)"
pass "sync.sh applies one tree owner (${tree_owner}) to all four rsync steps"

# --- 3. the 2775 step touches only the runtime trees ------------------------
runtime_block="$(rsync_block "$SYNC_SCRIPT" '"$RSYNC_BIN" ./backend/./storage/')"
[[ -n "$runtime_block" ]] \
    || fail 'sync.sh has no runtime-tree step (rsync over ./backend/./storage/ and ./backend/./bootstrap/cache/)'
grep -qF './backend/./storage/ ./backend/./bootstrap/cache/ "$API_DEST"' <<<"$runtime_block" \
    || fail 'the 2775 step must cover storage/ and bootstrap/cache/ and nothing else'
grep -qF -- "--chmod=D2775" <<<"$runtime_block" \
    || fail 'the runtime-tree step must set 2775+setgid (FtpInboxDirectory::MODE convention)'
grep -qF -- "--include='*/'" <<<"$runtime_block" \
    || fail 'the runtime-tree step must be directory-only (--include=*/)'
if grep -qF -- '--implied-dirs' <<<"$runtime_block" && ! grep -qF -- '--no-implied-dirs' <<<"$runtime_block"; then
    fail 'the runtime-tree step must not widen the implied bootstrap/ directory; use --no-implied-dirs'
fi
grep -qF -- '--no-implied-dirs' <<<"$runtime_block" \
    || fail 'the runtime-tree step must use --no-implied-dirs so only bootstrap/cache/ becomes 2775, not bootstrap/'
grep -qF -- '--relative' <<<"$runtime_block" \
    || fail 'the runtime-tree step must use --relative with the /./ marker so the API tree root stays 0755'
grep -qF -- "--exclude='*'" <<<"$runtime_block" \
    || fail 'the runtime-tree step must exclude every file (--exclude=*)'
if grep -qF -- '--delete' <<<"$runtime_block"; then
    fail 'the runtime-tree step must never delete: private storage (model age proofs, stripe secret) lives there'
fi
if grep -qF -- '"$CHMOD"' <<<"$runtime_block"; then
    fail 'the runtime-tree step must not re-apply the 0755/0644 default; it would undo the 2775 mode'
fi
pass 'the 2775 step is directory-only, covers storage/ and bootstrap/cache/, and never deletes'

# --- 4. the container runs as that owner ------------------------------------
if message="$(tree_owner_mismatch "$SYNC_SCRIPT" "$COMPOSE_FILE")"; then
    fail "$message"
fi
if grep -qF 'user: "1000:82"' "$COMPOSE_FILE"; then
    fail 'a service still runs as 1000:82, the non-owner identity OPS-3 was measured with'
fi
grep -qE "^[[:space:]]+id -u \| grep -qx ${tree_owner%%:*}" "$COMPOSE_FILE" \
    || fail "the fail-closed startup guard must pin UID ${tree_owner%%:*}, the identity the tree owner check just verified"

fixture_mismatch_compose="$TMP_ROOT/compose-mismatch.yml"
printf 'services:\n  backend:\n    user: "1000:82"\n' >"$fixture_mismatch_compose"
if ! tree_owner_mismatch "$SYNC_SCRIPT" "$fixture_mismatch_compose" >/dev/null; then
    fail 'the identity-drift fixture was not rejected by the owner probe'
fi
fixture_no_owner="$TMP_ROOT/sync-no-owner.sh"
printf '#!/usr/bin/env bash\nCHOWN="--chown=1002:82"\n' >"$fixture_no_owner"
if ! tree_owner_mismatch "$fixture_no_owner" "$COMPOSE_FILE" >/dev/null; then
    fail 'a sync script without a declared TREE_OWNER was not rejected'
fi
pass "the backend container runs as the tree owner (${tree_owner}) and the guard pins the same uid"

printf '\nOK: sync permissions contract (tree owner=%s)\n' "$tree_owner"
