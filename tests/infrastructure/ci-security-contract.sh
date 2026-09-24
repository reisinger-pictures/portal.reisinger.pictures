#!/usr/bin/env bash
# Static security contract for the GitHub Actions and Playwright configuration.
# This deliberately checks repository policy only; it does not claim that a
# remote action, image, branch-protection rule, or deployed runtime is current.
set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW_DIR="$ROOT_DIR/.github/workflows"
AUTOMERGE_WORKFLOW="$WORKFLOW_DIR/automerge.yml"
CI_WORKFLOW="$WORKFLOW_DIR/ci.yml"
PLAYWRIGHT_CONFIG="$ROOT_DIR/frontend/playwright.config.ts"
DOCKERFILE="$ROOT_DIR/deployment/Dockerfile"
E2E_DOCKERFILE="$ROOT_DIR/deployment/Dockerfile.e2e"
COMPOSE_FILE="$ROOT_DIR/deployment/docker-compose.yml"
PACKAGE_JSON="$ROOT_DIR/frontend/package.json"
ENCRYPTED_ENV_REL="backend/.env.encrypted"
ENCRYPTED_ENV="$ROOT_DIR/$ENCRYPTED_ENV_REL"
TMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/portal-ci-security-contract.XXXXXX")"
trap 'rm -rf "$TMP_ROOT"' EXIT

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

require_file() {
    local file="$1"
    [[ -f "$file" ]] || fail "required file is missing: $file"
}

assert_contains() {
    local file="$1"
    local text="$2"
    local description="$3"

    grep -Fq -- "$text" "$file" || fail "$description ($file)"
}

assert_not_contains() {
    local file="$1"
    local text="$2"
    local description="$3"

    if grep -Fq -- "$text" "$file"; then
        fail "$description ($file)"
    fi
}

require_file "$AUTOMERGE_WORKFLOW"
require_file "$CI_WORKFLOW"
require_file "$PLAYWRIGHT_CONFIG"
require_file "$DOCKERFILE"
require_file "$E2E_DOCKERFILE"
require_file "$COMPOSE_FILE"
require_file "$PACKAGE_JSON"

mapfile -t workflow_files < <(
    find "$WORKFLOW_DIR" -maxdepth 1 -type f \( -name '*.yml' -o -name '*.yaml' \) -print | sort
)
((${#workflow_files[@]} > 0)) || fail 'no GitHub Actions workflows found'

# 1. Every external action is immutable (40-character commit SHA). Local
# actions are repository-controlled and are the only permitted non-SHA form.
for workflow in "${workflow_files[@]}"; do
    [[ -f "$workflow" ]] || fail "workflow disappeared while being checked: $workflow"

    while IFS= read -r action; do
        [[ -n "$action" ]] || continue
        action="${action#\"}"
        action="${action%\"}"
        [[ "$action" == ./* ]] && continue
        [[ "$action" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+@[0-9a-f]{40}$ ]] \
            || fail "action is not pinned to a full commit SHA in $workflow: $action"
    done < <(sed -nE 's/^[[:space:]]*(-[[:space:]]*)?uses:[[:space:]]*([^[:space:]#]+).*/\2/p' "$workflow")
done

# 2. Least-privilege permissions are explicit. The CI workflow is read-only;
# image publishing has the narrowly scoped packages write it needs; automerge
# has only merge, PR, and check-read access.
for workflow in "${workflow_files[@]}"; do
    grep -Eq '^[[:space:]]*permissions:' "$workflow" \
        || fail "workflow has no explicit permissions block: $workflow"
done

if grep -Eq '^[[:space:]]+(contents|packages|pull-requests|actions|id-token|deployments|repository-projects|security-events):[[:space:]]*write' "$CI_WORKFLOW"; then
    fail 'CI must not request a write scope'
fi
assert_contains "$CI_WORKFLOW" '  contents: read' 'CI must be contents: read only'

for image_workflow in "$WORKFLOW_DIR/base-image.yml" "$WORKFLOW_DIR/e2e-image.yml"; do
    require_file "$image_workflow"
    assert_contains "$image_workflow" '      contents: read' 'image workflow must read repository contents'
    assert_contains "$image_workflow" '      packages: write' 'image workflow must be able to publish its image'
    if grep -Eq '^[[:space:]]+(actions|id-token|deployments|security-events):[[:space:]]*write' "$image_workflow"; then
        fail "image workflow requests an unnecessary write scope: $image_workflow"
    fi
done

assert_contains "$AUTOMERGE_WORKFLOW" '  contents: write' 'automerge needs contents write for the merge'
assert_contains "$AUTOMERGE_WORKFLOW" '  pull-requests: write' 'automerge needs pull-request write for the merge'
assert_contains "$AUTOMERGE_WORKFLOW" '  checks: read' 'automerge needs checks read for the CI gate'
if grep -Eq '^[[:space:]]+(actions|id-token|packages|deployments|security-events):[[:space:]]+(read|write)' "$AUTOMERGE_WORKFLOW"; then
    fail 'automerge requests an unnecessary actions/id-token/packages scope'
fi

# 3. Dependabot metadata is passed through env and never interpolated into the
# github-script source. The workflow also refuses skipped checks and stale
# duplicate runs.
assert_contains "$AUTOMERGE_WORKFLOW" '          UPDATE_TYPE: ${{ steps.metadata.outputs.update-type }}' \
    'Dependabot update type must be passed through env'
assert_contains "$AUTOMERGE_WORKFLOW" '          DEPENDENCY_GROUP: ${{ steps.metadata.outputs.dependency-group }}' \
    'Dependabot dependency group must be passed through env'
assert_contains "$AUTOMERGE_WORKFLOW" "            const updateType = process.env.UPDATE_TYPE || '';" \
    'github-script must read update type from process.env'
assert_contains "$AUTOMERGE_WORKFLOW" "            const dependencyGroup = process.env.DEPENDENCY_GROUP || '';" \
    'github-script must read dependency group from process.env'
assert_contains "$AUTOMERGE_WORKFLOW" "  if: github.actor == 'dependabot[bot]'" \
    'automerge must retain the Dependabot actor gate'

automerge_script="$(
    awk '
        /^[[:space:]]*script:[[:space:]]*\|/ { in_script = 1; next }
        in_script && /^[[:space:]]*#/ { exit }
        in_script { print }
    ' "$AUTOMERGE_WORKFLOW"
)"
if grep -Fq -- '${{' <<<"$automerge_script"; then
    fail 'automerge github-script contains a GitHub expression interpolation'
fi
if grep -Fq -- 'steps.metadata.outputs' <<<"$automerge_script"; then
    fail 'automerge github-script directly reads untrusted metadata outputs'
fi
assert_contains "$AUTOMERGE_WORKFLOW" '          allowed-conclusions: success' \
    'automerge must allow successful checks only'
assert_not_contains "$AUTOMERGE_WORKFLOW" 'allowed-conclusions: success,skipped' \
    'automerge must not allow skipped checks'
assert_contains "$AUTOMERGE_WORKFLOW" '          fail-on-no-checks: true' \
    'automerge must fail closed when checks are missing'
assert_contains "$AUTOMERGE_WORKFLOW" '          wait-for-duplicates: true' \
    'automerge must not hide failed duplicate checks'
automerge_checkout="$(
    awk '
        /- name: Checkout main/ { in_checkout = 1 }
        in_checkout { print }
        in_checkout && /- name: Merge Dependabot PR/ { exit }
    ' "$AUTOMERGE_WORKFLOW"
)"
if grep -Eq 'ref:[[:space:]]+\$\{\{[[:space:]]*github\.event\.pull_request\.head' <<<"$automerge_checkout"; then
    fail 'automerge must not check out the untrusted pull-request ref'
fi
assert_contains "$AUTOMERGE_WORKFLOW" '          ref: main' 'automerge checkout must stay on main'

# 4. No Playwright artifact upload path or secret diagnostic is permitted in
# CI. The config is checked separately below for the fail-closed artifact mode.
if grep -R -Eq -- '^[[:space:]]*(-[[:space:]]*)?uses:[[:space:]]+actions/upload-artifact@' "$WORKFLOW_DIR"; then
    fail 'a GitHub Actions workflow uploads Playwright artifacts'
fi
if grep -R -Eq -- '^[[:space:]]*path:[[:space:]].*(playwright-report|test-results|trace|screenshot|video)' "$WORKFLOW_DIR"; then
    fail 'a GitHub Actions workflow declares a Playwright artifact path'
fi
if grep -R -Eq -- '--reporter[=[:space:]]+[^[:space:]]*html|--reporter[=[:space:]]+list,html' "$WORKFLOW_DIR"; then
    fail 'CI enables the Playwright HTML reporter'
fi
if grep -R -Eq -- 'PW_TRACE[[:space:]]*=[[:space:]]*1' "$WORKFLOW_DIR"; then
    fail 'CI enables Playwright tracing'
fi

workflow_code="$TMP_ROOT/workflow-code"
: >"$workflow_code"
for workflow in "${workflow_files[@]}"; do
    while IFS= read -r line; do
        stripped="${line#"${line%%[![:space:]]*}"}"
        [[ "$stripped" == \#* ]] && continue
        [[ "$stripped" == //* ]] && continue
        printf '%s\n' "$line" >>"$workflow_code"
    done <"$workflow"
done
if grep -Eiq '(^|[[:space:]])(md5sum|sha1sum|printenv|fingerprint|set[[:space:]]+-x)([[:space:]]|$)' "$workflow_code"; then
    fail 'CI contains a key-fingerprint/debug-logging command'
fi
if grep -Eiq '(^|[[:space:]])(echo|printf).*(APP_KEY|JWT_SECRET|STRIPE_SECRET|STRIPE_KEY|STRIPE_WEBHOOK_SECRET|ADMIN_PASSWORD|AI_API_KEY|MAKE_API_KEY).*(\$\{|\$)' "$workflow_code"; then
    fail 'CI may echo an interpolated secret value'
fi

assert_contains "$PLAYWRIGHT_CONFIG" "const isCi = process.env.CI === 'true' || process.env.CI === '1';" \
    'Playwright config must detect GitHub CI explicitly'
assert_contains "$PLAYWRIGHT_CONFIG" "reporter: isCi ? [['list']] : [['html', {open: 'never'}]]," \
    'Playwright must select only the list reporter in CI'
assert_contains "$PLAYWRIGHT_CONFIG" "outputDir: isCi ? '/tmp/portal-playwright-results' : 'test-results'," \
    'Playwright CI output must stay outside the checkout'
assert_contains "$PLAYWRIGHT_CONFIG" "preserveOutput: isCi ? 'never' : 'always'," \
    'Playwright must discard CI output'
assert_contains "$PLAYWRIGHT_CONFIG" "trace: isCi ? 'off' : process.env.PW_TRACE === '1' ? 'on-first-retry' : 'off'," \
    'Playwright tracing must be disabled in CI'
assert_contains "$PLAYWRIGHT_CONFIG" "        screenshot: 'off'," \
    'Playwright screenshots must be disabled'
assert_contains "$PLAYWRIGHT_CONFIG" "        video: 'off'," \
    'Playwright video must be disabled'
assert_contains "$CI_WORKFLOW" '          CI: "1"' \
    'the E2E step must explicitly identify CI mode'

# 5. Runtime and image references are immutable. This checks syntax and the
# repository's declared policy; it does not contact registries or prove that a
# published digest is fresh or currently pullable.
config_files=(
    "$DOCKERFILE"
    "$E2E_DOCKERFILE"
    "$COMPOSE_FILE"
    "$ROOT_DIR/docker-compose.local.yml"
    "$ROOT_DIR/docker-compose.test.yml"
)
for file in "${config_files[@]}"; do
    require_file "$file"
    while IFS= read -r line; do
        stripped="${line#"${line%%[![:space:]]*}"}"
        [[ "$stripped" == \#* ]] && continue

        if [[ "$line" =~ ^[[:space:]]*(image:[[:space:]]|FROM[[:space:]]|COPY[[:space:]]+--from=) ]]; then
            [[ "$line" =~ @sha256:[0-9a-f]{64}([[:space:]]|$) ]] \
                || fail "image reference is not digest-pinned in $file: $line"
        elif [[ "$line" =~ (ghcr\.io/|getmeili/|axllent/|mariadb:|composer:|php:) ]] \
            && [[ "$line" != *"@sha256:"* ]]; then
            fail "workflow/container image reference is not digest-pinned in $file: $line"
        fi
    done <"$file"
done

# 6. Node and accounting runtime declarations stay aligned. The E2E image
# verifies its downloaded tarball with the declared SHA-256; the CI jobs use
# the same exact Node version rather than a moving major tag.
assert_contains "$E2E_DOCKERFILE" 'ARG NODE_VERSION=26.7.0' \
    'E2E image must declare the exact Node version'
if ! grep -Eq '^ARG NODE_SHA256=[0-9a-f]{64}$' "$E2E_DOCKERFILE"; then
    fail 'E2E image must declare a 64-character Node SHA-256 checksum'
fi
assert_contains "$E2E_DOCKERFILE" 'sha256sum -c --strict' \
    'E2E image must verify the Node download checksum'
if [[ "$(grep -cE 'node-version:[[:space:]]+26\.7\.0' "$CI_WORKFLOW")" -ne 2 ]]; then
    fail 'both frontend CI jobs must use Node 26.7.0'
fi
if grep -Eq 'node-version:[[:space:]]+26([[:space:]]|$)' "$CI_WORKFLOW"; then
    fail 'CI must not use the mutable Node 26 version selector'
fi
assert_contains "$PACKAGE_JSON" '        "node": ">=26.7.0 <27"' \
    'frontend package.json must declare the supported Node major/minor range'
assert_contains "$PACKAGE_JSON" '    "packageManager": "pnpm@11.23.0"' \
    'frontend package.json must keep the pnpm runtime declaration'
assert_contains "$E2E_DOCKERFILE" 'ARG PNPM_VERSION=11.23.0' \
    'E2E image default pnpm version must match packageManager'
assert_contains "$COMPOSE_FILE" '      - ACCOUNTING_EMAIL=${ACCOUNTING_EMAIL}' \
    'production compose must pass ACCOUNTING_EMAIL into the backend'
assert_contains "$ROOT_DIR/backend/.env.example" 'ACCOUNTING_EMAIL=' \
    'the local env template must document ACCOUNTING_EMAIL'
assert_contains "$ROOT_DIR/backend/.env.ci" 'ACCOUNTING_EMAIL=' \
    'the CI env fixture must define ACCOUNTING_EMAIL'

# 7. Encrypted environment backups are local-only. A deletion is accepted while
# this shared, unstaged remediation is in progress; once committed, the path
# must no longer appear in the index.
if [[ -e "$ENCRYPTED_ENV" ]]; then
    fail 'encrypted .env backup exists in the working tree'
fi
if grep -Fq -- '!.env.encrypted' "$ROOT_DIR/.gitignore"; then
    fail '.gitignore still explicitly allowlists .env.encrypted'
fi
if grep -R -Fq -- '.env.encrypted' "$WORKFLOW_DIR" "$ROOT_DIR/scripts" "$ROOT_DIR/deployment"; then
    fail 'operational infrastructure references the encrypted env backup'
fi
if git -C "$ROOT_DIR" ls-files --error-unmatch -- "$ENCRYPTED_ENV_REL" >/dev/null 2>&1; then
    if ! git -C "$ROOT_DIR" diff --name-status -- "$ENCRYPTED_ENV_REL" | grep -Eq '^D[[:space:]]' \
        && ! git -C "$ROOT_DIR" diff --cached --name-status -- "$ENCRYPTED_ENV_REL" | grep -Eq '^D[[:space:]]'; then
        fail 'encrypted .env backup is still tracked without a pending deletion'
    fi
fi

printf 'PASS: CI/Playwright security contract (workflows=%d)\n' "${#workflow_files[@]}"
