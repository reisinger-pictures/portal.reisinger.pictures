#!/bin/sh
set -eu

usage() {
  cat >&2 <<'EOF'
Usage:
  sh scripts/gates.sh fast
  sh scripts/gates.sh full <backend|mariadb|frontend|e2e|security> [gate arguments...]

`fast` requires frontend dependencies and runs the required local
`pnpm lint:fix && pnpm build` gate, the required Pint check, and
`git diff --check`; it never runs a test suite.
`full` is CI-only and must be selected by group to preserve workflow parallelism.
EOF
}

fail() { echo "gates.sh: $*" >&2; exit 2; }
script_dir=$(CDPATH= cd "$(dirname "$0")" && pwd)
repo_root=$(CDPATH= cd "$script_dir/.." && pwd)
cd "$repo_root"

run_logged() {
  log=$1
  shift
  if [ -n "${GITHUB_WORKSPACE:-}" ]; then
    set +e
    "$@" >"$log" 2>&1
  else
    log=$(mktemp "${TMPDIR:-/tmp}/portal-gate.XXXXXX")
    set +e
    "$@" >"$log" 2>&1
  fi
  result=$?
  set -e
  cat "$log"
  [ -n "${GITHUB_WORKSPACE:-}" ] || rm -f "$log"
  return "$result"
}

mode=${1-}
[ "$#" -gt 0 ] || { usage; exit 2; }
shift

case "$mode" in
  fast)
    [ "$#" -eq 0 ] || fail "fast accepts no arguments"
    command -v pnpm >/dev/null 2>&1 || fail "pnpm is required for fast; install frontend dependencies/tooling"
    [ -d frontend/node_modules ] || fail "frontend/node_modules is missing; run pnpm install --frozen-lockfile in frontend"
    (cd frontend && pnpm lint:fix && pnpm build)
    [ -x backend/vendor/bin/pint ] || fail "Pint is required by backend/composer.json but backend/vendor/bin/pint is missing; install backend dependencies"
    bash scripts/check-php-version.sh
    (cd backend && ./vendor/bin/pint --test)
    git diff --check
    ;;
  full)
    [ "$#" -gt 0 ] || { usage; fail "full requires a CI group"; }
    [ "${GITHUB_ACTIONS:-}" = true ] || fail "full is CI-only; local test suites are intentionally not runnable through this dispatcher"
    group=$1
    shift
    case "$group" in
      backend)
        [ "$#" -eq 0 ] || fail "backend accepts no arguments"
        docker exec php ./vendor/bin/pint --test
        run_logged /tmp/phpunit.log docker exec php php artisan test --no-ansi --parallel
        ;;
      mariadb)
        [ "$#" -eq 0 ] || fail "mariadb accepts no arguments"
        docker exec php-mariadb php -r 'if (! function_exists("pcntl_fork")) { fwrite(STDERR, "ext-pcntl is missing in the CI PHP image\n"); exit(1); } echo "pcntl_fork available\n";'
        docker exec -e DB_CONNECTION -e DB_HOST -e DB_PORT -e DB_DATABASE -e DB_USERNAME -e DB_PASSWORD php-mariadb php artisan migrate --force
        run_logged /tmp/phpunit-mariadb.log docker exec -e DB_CONNECTION -e DB_HOST -e DB_PORT -e DB_DATABASE -e DB_USERNAME -e DB_PASSWORD php-mariadb php artisan test --no-ansi --filter='ContractSignerIdentityRaceTest|ContractSignerIdentityTest|ContractContentHeartbeatMySqlTest'
        ;;
      frontend)
        [ "$#" -eq 0 ] || fail "frontend accepts no arguments"
        (cd frontend && pnpm lint && pnpm lint:e2e && pnpm exec tsc -p tsconfig.tests.json --noEmit --pretty false && VITE_TURNSTILE_SITE_KEY="${VITE_TURNSTILE_SITE_KEY-}" TURNSTILE_SITE_KEY="${TURNSTILE_SITE_KEY-}" TURNSTILE_BUILD_REQUIRED="${TURNSTILE_BUILD_REQUIRED-false}" pnpm build && pnpm test:run)
        ;;
      e2e)
        [ "$#" -gt 0 ] || fail "e2e requires the workflow matrix arguments"
        (cd frontend && run_logged /tmp/e2e.log pnpm test:e2e "$@" --reporter=list)
        ;;
      security)
        [ "$#" -eq 0 ] || fail "security accepts no arguments"
        bash tests/infrastructure/ci-security-contract.sh
        bash tests/infrastructure/compose-entrypoint-contract.sh
        bash tests/infrastructure/verify-playwright-version.sh
        bash tests/infrastructure/rsync-sync-regression.sh
        bash tests/infrastructure/sync-permissions-contract.sh
        bash tests/infrastructure/verify-image-nonroot.sh
        bash tests/infrastructure/plugin-harness-fail-closed.sh
        sudo apt-get update -qq
        sudo apt-get install -y -qq lua5.4
        if ! command -v lua >/dev/null 2>&1; then sudo ln -sf "$(command -v lua5.4)" /usr/local/bin/lua; fi
        bash admin.lrplugin/tests/run.sh
        bash tests/infrastructure/verify-image-freshness.sh
        bash tests/infrastructure/image-pin-freshness-regression.sh
        ;;
      *) fail "unknown full group: $group" ;;
    esac
    ;;
  *) usage; fail "unknown mode: $mode" ;;
esac
