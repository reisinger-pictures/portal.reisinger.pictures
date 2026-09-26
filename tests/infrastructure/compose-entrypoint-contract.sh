#!/usr/bin/env bash
# Regression for the two Compose-v5 traps that took production down on
# 2026-09-26, plus the pin rules that belong to them.
#
# 1. `#` inside a folded `command: >` block. The folded scalar joins every line
#    into ONE line, and `sh` starts a comment at a `#` that appears mid-line. The
#    comment then swallows the rest of the line — the path loop, `migrate`,
#    `db:seed` and the final `exec`. Observed effect: the backend never reached
#    php-fpm and Docker restarted it in a loop while the log stayed quiet.
#
# 2. `$` inside a folded `command: >` block. Docker Compose v5.0.2 emits every
#    command substitution as `$$(...)`, even when the file already contains
#    `$$`, and it never reduces `$$` to `$` in any YAML form. The container
#    shell then expands `$$` to its PID, so
#        [ "$$(id -u)" -ne 1000 ]   becomes   [ "1(id -u)" -ne 1000 ]
#    which fails with `Illegal number`, and
#        "$${APP_ENV}"              becomes   "1234{APP_ENV}"
#    which fails every comparison. All 16 escapes of the identity and
#    environment guard were dead; the guard either refused a valid start or, in
#    the `test -z` case, silently passed without checking anything.
#    The guard therefore reads values via `printenv | grep` and `xargs -I{}`
#    instead of shell expansion, and must not use `${VAR}` at all: Compose would
#    resolve that at interpolation time and bake secrets into `docker inspect`.
#
# 3. A pinned `ipv4_address` on `portal_internal`. That network already exists
#    from the stack and its subnet is 172.21.0.0/16, so pinning 172.18.0.32
#    aborts the whole deploy with "no configured subnet contains IP address".
#    Only `webnet` may be pinned, because Caddy routes to that address.
#
# 4. The SFTPGo admin bootstrap must be mapped from the `SFTPGO_ADMIN_*` names
#    that .env.production and config/services.php already use. SFTPGo reads
#    `SFTPGO_DEFAULT_ADMIN_*`; a second, separately pasted value pair is how the
#    container ended up with an empty admin and a restart loop.
set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
COMPOSE="$ROOT_DIR/deployment/docker-compose.yml"

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

[[ -f "$COMPOSE" ]] || fail "required file is missing: $COMPOSE"

# The backend `command:` block: from the folded scalar to the next service-level
# key. Everything in between is one shell string once Compose is done with it.
backend_command() {
    awk '
        /^    command: >$/ { inside = 1; next }
        inside && /^    [a-z_]+:/ { inside = 0 }
        inside { print }
    ' "$COMPOSE"
}

CMD_BLOCK="$(backend_command)"
[[ -n "$CMD_BLOCK" ]] || fail 'the backend command block could not be located in the compose file'

# --- 1. no comment may survive into the folded command ----------------------
if grep -q '#' <<<"$CMD_BLOCK"; then
    fail 'a # inside the folded command: block comments out the rest of the joined line'
fi

# --- 2. no shell expansion may be used in the command -----------------------
for token in '$$' '$(' '${'; do
    if grep -qF -- "$token" <<<"$CMD_BLOCK"; then
        fail "the folded command: block uses $token, which Compose v5 corrupts (see the header of this gate)"
    fi
done

# Identity and path guards must read the environment without expansion.
for needed in 'id -u | grep -qx 1000' 'id -g | grep -qx 1000' 'printenv APP_ENV' \
              'printenv APP_KEY' 'printenv JWT_SECRET' 'printenv FILE_ENCRYPTION_KEY' \
              'printenv ADMIN_EMAIL' 'printenv ADMIN_PASSWORD' 'printenv PHOTO_STORAGE_PATH' \
              'printenv AI_SESSION_HEADER' 'printenv AI_SESSION_PREFIX'; do
    grep -qF -- "$needed" <<<"$CMD_BLOCK" \
        || fail "the fail-closed guard lost this check: $needed"
done

# 19-ftp 7.9 forbids rewriting /home/webadmin/websites to 1000:1000, so the
# ownership comparison must stay out; writability is the property that matters.
if grep -qF 'stat -c' <<<"$CMD_BLOCK"; then
    fail 'the command block still compares ownership, which 19-ftp 7.9 makes unsatisfiable'
fi

# The migration gate and the supervisor hand-off must all be reachable.
for step in 'validate-production-env || exit 1' 'ops:validate-production || exit 1' \
            'migrate --force' 'db:seed --force' 'admin:update' \
            'ftp:provision-folders --fix-permissions' 'scout:sync-index-settings' \
            'queue:restart' 'exec /usr/local/bin/portal-backend-supervisor'; do
    grep -qF -- "$step" <<<"$CMD_BLOCK" \
        || fail "the start sequence lost a step: $step"
done

# --- 3. no pinned address on the internal network ---------------------------
# The backend may pin on webret (Caddy routes there). Anything else is a
# subnet assumption that aborts the deploy as soon as Docker has already
# created the network with a different pool.
if awk '
    /^    networks:$/ { in_networks = 1; next }
    in_networks && /^      portal_internal:$/ { pin = 1 }
    in_networks && pin && /ipv4_address/ { print; found = 1 }
    in_networks && /^      [a-z_]+:/ && !/portal_internal/ { pin = 0 }
    END { exit found ? 0 : 1 }
' "$COMPOSE" | grep -q .; then
    fail 'portal_internal must not pin an ipv4_address; Docker assigns it from the existing subnet'
fi

# --- 4. SFTPGo bootstrap comes from the .env.production names ---------------
assert_contains() {
    grep -Fq -- "$2" "$COMPOSE" || fail "$3"
}
assert_contains "$COMPOSE" '      - SFTPGO_DEFAULT_ADMIN_USERNAME=${SFTPGO_ADMIN_USERNAME}' \
    'the sftpgo admin username must be mapped from the .env.production name'
assert_contains "$COMPOSE" '      - SFTPGO_DEFAULT_ADMIN_PASSWORD=${SFTPGO_ADMIN_PASSWORD}' \
    'the sftpgo admin password must be mapped from the .env.production name'
assert_contains "$COMPOSE" '      - SFTPGO_ADMIN_USERNAME=${SFTPGO_ADMIN_USERNAME:-}' \
    'the backend JWT fallback must read the same .env.production name'
assert_contains "$COMPOSE" '      - SFTPGO_BASE_URL=${SFTPGO_BASE_URL:-http://sftpgo:8080}' \
    'the backend must reach the sftpgo admin API by service name'

# A ${VAR:?} guard aborts every Portainer deploy, because Portainer resolves the
# interpolation before it applies the stack environment from the GUI.
if grep -qE '\$\{[A-Za-z_][A-Za-z_0-9]*:\?' "$COMPOSE"; then
    fail 'compose uses a ${VAR:?} guard; that blocks the deploy in the Portainer GUI'
fi

# Secrets stay in the stack environment. An env_file pointing outside the repo
# reintroduces a second location per host, and Portainer cannot resolve host
# paths in the web editor anyway.
if grep -qE '^[[:space:]]*env_file:' "$COMPOSE"; then
    fail 'compose declares env_file; production secrets come from the Portainer stack environment'
fi

# Every image reference carries an explicit, non-floating tag (P1-M37).
while IFS= read -r line; do
    stripped="${line#"${line%%[![:space:]]*}"}"
    [[ "$stripped" == \#* ]] && continue
    [[ "$line" =~ ^[[:space:]]*image:[[:space:]] ]] || continue
    reference="${line#*image: }"
    reference="${reference%%[[:space:]]*}"
    [[ "$reference" == *:* || "$reference" == *@* ]] \
        || fail "image reference carries no explicit tag: $line"
    if [[ "$reference" == *:latest ]]; then
        fail "image reference uses the mutable 'latest' tag: $line"
    fi
done <"$COMPOSE"

printf 'PASS: compose entrypoint contract (command-block lines=%d)\n' "$(wc -l <<<"$CMD_BLOCK" | tr -d ' ')"
