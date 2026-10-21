#!/usr/bin/env bash
# Playwright version contract (the test image's browser version).
#
# Rule: the browser version baked into the E2E test image comes from the
# RESOLVED version in frontend/pnpm-lock.yaml — never from the lower bound of
# the range declared in frontend/package.json.
#
# Why this gate exists:
#   Dependabot (npm, /frontend, daily) updates the lockfile alone whenever a
#   new version falls inside the declared range. The image rebuild trigger
#   (paths: frontend/pnpm-lock.yaml) fires, but the workflow used to read the
#   range's lower bound from the manifest -> it rebuilt the SAME browser
#   version under the SAME tag. The E2E run stayed green because ci.yml
#   installs the browser per run as a fallback -> the image's promise ("no
#   browser download per run") was cancelled silently, and the freshness gate
#   still called the image fresh.
#
# The gate fails closed on:
#   * a lockfile without a resolvable @playwright/test version
#   * a resolved version outside the declared range
#   * an e2e-image workflow that derives the version from the manifest
#   * a pinned image tag in ci.yml that does not match the lockfile version
#   * a Dockerfile ARG default that does not match the lockfile version
#
# Bash 3.2 compatible: no mapfile/readarray.
set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly ROOT_DIR
readonly LOCKFILE="$ROOT_DIR/frontend/pnpm-lock.yaml"
readonly MANIFEST="$ROOT_DIR/frontend/package.json"
readonly IMAGE_WORKFLOW="$ROOT_DIR/.github/workflows/e2e-image.yml"
readonly CI_WORKFLOW="$ROOT_DIR/.github/workflows/ci.yml"
readonly E2E_DOCKERFILE="$ROOT_DIR/deployment/Dockerfile.e2e"

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

require_file() {
    [[ -f "$1" ]] || fail "required file is missing: $1"
}

require_file "$LOCKFILE"
require_file "$MANIFEST"
require_file "$IMAGE_WORKFLOW"
require_file "$CI_WORKFLOW"
require_file "$E2E_DOCKERFILE"

# The resolved version of @playwright/test in the frontend workspace: the
# importer entry of the lockfile, not the packages section.
resolved_lockfile_version() {
    grep -A2 -E "^[[:space:]]+'@playwright/test':[[:space:]]*$" "$LOCKFILE" \
        | sed -n 's/^[[:space:]]*version:[[:space:]]*\([0-9][0-9.]*\).*/\1/p' \
        | head -n1
}

# The declared range in the manifest, e.g. "^1.64.1".
declared_range() {
    sed -n 's/.*"@playwright\/test"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$MANIFEST" | head -n1
}

# The lowest version the range allows, e.g. "^1.64.1" -> "1.64.1".
range_floor() {
    printf '%s' "$1" | sed -n 's/^[^0-9]*\([0-9][0-9.]*\).*/\1/p'
}

# True when $1 >= $2 (both plain versions).
version_ge() {
    [ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n1)" = "$2" ]
}

# True when both versions share the same major version.
same_major() {
    [ "${1%%.*}" = "${2%%.*}" ]
}

LOCKFILE_VERSION="$(resolved_lockfile_version)"
[ -n "$LOCKFILE_VERSION" ] || fail "no resolvable @playwright/test version in frontend/pnpm-lock.yaml"

MANIFEST_RANGE="$(declared_range)"
[ -n "$MANIFEST_RANGE" ] || fail "@playwright/test range not found in frontend/package.json"

RANGE_FLOOR="$(range_floor "$MANIFEST_RANGE")"
[ -n "$RANGE_FLOOR" ] || fail "could not read the lower bound of the range '$MANIFEST_RANGE'"

version_ge "$LOCKFILE_VERSION" "$RANGE_FLOOR" \
    || fail "the resolved lockfile version $LOCKFILE_VERSION is below the declared range $MANIFEST_RANGE"

case "$MANIFEST_RANGE" in
    ^*)
        # A caret range keeps the major version (for major > 0).
        if [ "${RANGE_FLOOR%%.*}" -gt 0 ] && ! same_major "$LOCKFILE_VERSION" "$RANGE_FLOOR"; then
            fail "the resolved lockfile version $LOCKFILE_VERSION does not satisfy the declared range $MANIFEST_RANGE"
        fi
        ;;
esac

# 1) The image workflow must read the version from the lockfile.
EXTRACTION_LINE="$(grep -E 'PLAYWRIGHT_VERSION=' "$IMAGE_WORKFLOW" | head -n1 || true)"
[ -n "$EXTRACTION_LINE" ] || fail "no PLAYWRIGHT_VERSION extraction found in .github/workflows/e2e-image.yml"
case "$EXTRACTION_LINE" in
    *pnpm-lock.yaml*) ;;
    *) fail "the Playwright version must be read from frontend/pnpm-lock.yaml, not from the manifest" ;;
esac
case "$EXTRACTION_LINE" in
    *package.json*)
        fail "the Playwright version must not be derived from frontend/package.json (range lower bound)"
        ;;
esac

# 2) The consumer's pin must name the lockfile's version.
CI_PIN="$(grep -E '^[[:space:]]*image:.*portal-e2e:' "$CI_WORKFLOW" | head -n1 | sed -n 's/.*portal-e2e:\([0-9][0-9.]*\).*/\1/p')"
[ -n "$CI_PIN" ] || fail "no pinned portal-e2e image tag found in .github/workflows/ci.yml"
[ "$CI_PIN" = "$LOCKFILE_VERSION" ] \
    || fail "the pinned image tag portal-e2e:$CI_PIN does not match the lockfile version $LOCKFILE_VERSION"

# 3) The Dockerfile's default (used by local builds) must match as well.
ARG_DEFAULT="$(sed -n 's/^ARG PLAYWRIGHT_VERSION=//p' "$E2E_DOCKERFILE" | head -n1)"
[ -n "$ARG_DEFAULT" ] || fail "no ARG PLAYWRIGHT_VERSION default found in deployment/Dockerfile.e2e"
[ "$ARG_DEFAULT" = "$LOCKFILE_VERSION" ] \
    || fail "the Dockerfile default PLAYWRIGHT_VERSION=$ARG_DEFAULT does not match the lockfile version $LOCKFILE_VERSION"

printf 'OK: Playwright version %s (lockfile, range %s) — image tag, CI pin and Dockerfile default agree\n' \
    "$LOCKFILE_VERSION" "$MANIFEST_RANGE"
