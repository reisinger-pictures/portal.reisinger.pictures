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

assert_contains() {
    grep -Fq -- "$2" "$COMPOSE" || fail "$3"
}

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

# --- 3. a published port must have a listener behind it ----------------------
# The camera-facing port is the left half of `ports:`, the container port the
# right one. They are not the same number, and SFTPGo 2.7 does not use the
# conventional defaults: its SSH daemon listens on 2022 and its FTP daemon is
# disabled entirely (`Bindings:[{Port:0}]`). Publishing `2222:2222` therefore
# bound a host port that nothing answered on — the host sent a RST, the network
# was never at fault, and a scanner SYN in the tcpdump capture looked like a
# blocked port. Likewise `989:989` published a dead port until the FTP bindings
# were switched on.
#
# Rule: the container half of each publish must equal the port SFTPGo is told
# to bind. When the compose sets no binding env, the SFTPGo 2.7 defaults apply
# (SSH 2022, HTTP 8080, FTP disabled).
assert_contains "$COMPOSE" '      - SFTPGO_FTPD__BINDINGS__0__PORT=989' \
    'FTPS must actually be enabled; SFTPGo 2.7 ships the FTP daemon disabled'
# 0 = off, 1 = explicit (AUTH TLS), 2 = implicit (SFTPGo docs, ftpd package).
# A Canon camera sends AUTH TLS right after connecting and does not reliably
# support implicit FTPS (cam.start.canon, UG-06_Network_0060), so the binding
# must be explicit. The cleartext 220 greeting is that mode working, not a fault.
# Mode 1 is the default here, and the value is deliberately not spelled twice:
# the same variable feeds the sftpgo binding and the management API, so the UI
# cannot tell the photographer "explicit" while the daemon runs implicit
# (P1-M44). The default keeps the binding explicit even when the Portainer GUI
# env omits the variable, and the second assertion is what holds the two
# consumers together.
assert_contains "$COMPOSE" '      - SFTPGO_FTPD__BINDINGS__0__TLS_MODE=${SFTPGO_FTPD_TLS_MODE:-1}' \
    'the FTP binding must default to explicit TLS (mode 1); a Canon camera upgrades with AUTH TLS'
assert_contains "$COMPOSE" '      - SFTPGO_FTPD_TLS_MODE=${SFTPGO_FTPD_TLS_MODE}' \
    'the backend must receive the same TLS mode the binding uses, or the UI can disagree with the daemon'
assert_contains "$COMPOSE" '      - "${SFTPGO_SFTP_PORT:-2222}:2022"' \
    'the SFTP publish must target the port SFTPGo 2.7 binds (2022), not 2222'

# SFTPGo 2.7 refuses to start the FTP server with TLS and no certificate
# ("to enable TLS you need to provide a certificate"), and unlike 19-ftp 7.9 it
# does not generate one. Without the certificate paths the container restart-loops
# with the FTP service silently absent, so both paths and the generating command
# are part of the contract.
# No TLS ceiling may be pinned. Go downgrades to whatever the client supports,
# so a TLS 1.2-only camera and a TLS 1.3 client both work against the same
# binding. A ceiling would only remove capability.
if grep -qE 'SFTPGO_FTPD__BINDINGS__0__(MAX|MIN)_TLS_VERSION=' "$COMPOSE"; then
    fail 'the FTPS binding pins a TLS version; Go already downgrades per client, a ceiling only removes capability'
fi
assert_contains "$COMPOSE" '      - SFTPGO_FTPD__BINDINGS__0__CERTIFICATE_FILE=/var/lib/sftpgo/ftps/cert.pem' \
    'FTPS needs a certificate path; SFTPGo 2.7 will not generate one'
assert_contains "$COMPOSE" '      - SFTPGO_FTPD__BINDINGS__0__CERTIFICATE_KEY_FILE=/var/lib/sftpgo/ftps/key.pem' \
    'the FTPS certificate needs its key path'
assert_contains "$COMPOSE" \
    '          mkdir -p /var/lib/sftpgo/ftps && openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj /CN=sftpgo -keyout /var/lib/sftpgo/ftps/key.pem -out /var/lib/sftpgo/ftps/cert.pem;' \
    'the sftpgo command must generate the self-signed certificate on first start, on one line'
# A folded scalar (>- or >) keeps the line break of any MORE INDENTED continuation
# line, which split the openssl call into `sh: -keyout: not found` and left
# SFTPGo unstarted. The script must therefore be a literal block.
assert_contains "$COMPOSE" '      - |' \
    'the sftpgo command must use a literal block; a folded scalar breaks the multi-line script'
assert_contains "$COMPOSE" '        exec sftpgo serve' \
    'the sftpgo command must hand over via `exec sftpgo serve`; bare `sftpgo` only prints help and exits 0'

# The sftpgo command carries the same two traps as the backend command, so it is
# held to the same rule: no shell expansion, no comment.
sftpgo_command="$(awk '
    /^    command:$/ { in_cmd = 1; next }
    in_cmd && /^    [a-z_]+:/ { in_cmd = 0 }
    in_cmd { print }
' "$COMPOSE")"
for token in '$$' '$(' '${' '#'; do
    if grep -qF -- "$token" <<<"$sftpgo_command"; then
        fail "the sftpgo command block uses $token, which Compose v5 corrupts or comments out the rest of the line"
    fi
done

sftp_binding="$(grep -oE 'SFTPGO_SFTPD__BINDINGS__0__PORT=[0-9]+' "$COMPOSE" | cut -d= -f2 || true)"
if [ -n "$sftp_binding" ]; then
    sftp_publish="$(grep -E '^[[:space:]]+- "\$\{SFTPGO_SFTP_PORT' "$COMPOSE" || true)"
    grep -qF ":$sftp_binding\"" <<<"$sftp_publish" \
        || fail "the SFTP publish does not target the declared SFTPD binding port $sftp_binding"
fi

# --- 4. no pinned address on the internal network ---------------------------
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
