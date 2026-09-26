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
WAIT_FOR_MEILISEARCH="$ROOT_DIR/scripts/wait-for-meilisearch.sh"
E2E_UP_SCRIPT="$ROOT_DIR/scripts/e2e-up.sh"
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

assert_contains_text() {
    local text="$1"
    local expected="$2"
    local description="$3"

    grep -Fq -- "$expected" <<<"$text" || fail "$description"
}

# --- policy probes -----------------------------------------------------------
# Reusable so the real workflows and the synthetic fixtures below exercise
# exactly the same logic. Each *_violation function prints a message and returns
# 0 when the input violates the rule, and returns 1 with no output when clean.

# Prints every `permissions:` block (header plus its more-indented body). Scopes
# are detected structurally via indentation, so a later job-level escalation
# cannot hide behind an earlier top-level `read`.
permissions_blocks() {
    awk '
        function indent_count(line) { match(line, /^[[:space:]]*/); return RLENGTH }
        /^[[:space:]]*permissions:[[:space:]]*[^[:space:]]/ { print; next }
        /^[[:space:]]*permissions:[[:space:]]*$/ { in_block = 1; base = indent_count($0); print; next }
        in_block {
            if ($0 ~ /^[[:space:]]*$/) { next }
            if (indent_count($0) > base) { print; next }
            in_block = 0
        }
    ' "$1"
}

# Prints the write scopes granted by every permissions block in $1.
write_scopes() {
    permissions_blocks "$1" \
        | sed -nE 's/^[[:space:]]*([[:alnum:]_-]+):[[:space:]]*write[[:space:]]*$/\1/p' \
        | sort -u
}

write_all_violation() {
    if permissions_blocks "$1" | grep -Eq '^[[:space:]]*permissions:[[:space:]]*write-all[[:space:]]*$'; then
        printf '%s\n' "workflow grants write-all permissions: $1"
        return 0
    fi
    return 1
}

# Prints every `run:` block body (inline values and block scalars) in $1.
run_blocks() {
    awk '
        function indent_count(line) { match(line, /^[[:space:]]*/); return RLENGTH }
        /^[[:space:]]*(-[[:space:]]*)?run:[[:space:]]*[^[:space:]]/ { in_block = 1; base = indent_count($0); print; next }
        in_block {
            if ($0 ~ /^[[:space:]]*$/) { next }
            if (indent_count($0) > base) { print; next }
            in_block = 0
        }
    ' "$1"
}

run_block_violation() {
    if run_blocks "$1" | grep -Eq '\$\{\{[^}]*github\.event[^_[:alnum:]]'; then
        printf '%s\n' "workflow interpolates github.event directly into a run: block: $1"
        return 0
    fi
    return 1
}

artifact_upload_violation() {
    if grep -Eq '^[[:space:]]*(-[[:space:]]*)?uses:[[:space:]]*[^[:space:]]*upload(-pages)?-artifact@' "$1"; then
        printf '%s\n' "workflow declares an artifact upload: $1"
        return 0
    fi
    return 1
}

artifact_path_violation() {
    if grep -Eq "^[[:space:]]*(-[[:space:]]*)?path:[[:space:]]*['\"]?[^'\"]*(playwright-report|test-results|trace|screenshot|video)" "$1"; then
        printf '%s\n' "workflow declares a Playwright artifact path: $1"
        return 0
    fi
    return 1
}

require_file "$AUTOMERGE_WORKFLOW"
require_file "$CI_WORKFLOW"
require_file "$PLAYWRIGHT_CONFIG"
require_file "$DOCKERFILE"
require_file "$E2E_DOCKERFILE"
require_file "$COMPOSE_FILE"
require_file "$PACKAGE_JSON"
require_file "$WAIT_FOR_MEILISEARCH"
require_file "$E2E_UP_SCRIPT"

workflow_files=()
while IFS= read -r workflow_file; do
    [[ -n "$workflow_file" ]] || continue
    workflow_files+=("$workflow_file")
done < <(
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

# 2. Least-privilege permissions are explicit and scoped. Every permissions
# block is walked structurally, so a later job-level escalation cannot hide
# behind an earlier top-level `read`; `write-all` is rejected outright.
for workflow in "${workflow_files[@]}"; do
    grep -Eq '^[[:space:]]*permissions:' "$workflow" \
        || fail "workflow has no explicit permissions block: $workflow"

    if message="$(write_all_violation "$workflow")"; then
        fail "$message"
    fi
done

# CI is read-only: no block may grant any write scope.
ci_write_scopes="$(write_scopes "$CI_WORKFLOW")"
ci_write_scopes_oneline="$(printf '%s' "$ci_write_scopes" | tr '\n' ',')"
[[ -z "$ci_write_scopes" ]] \
    || fail "CI must not request a write scope (found: ${ci_write_scopes_oneline%,})"
# The top-level block must itself be the read-only one; a substring search would
# stay true even if a later job escalated above it.
ci_top_permissions="$(permissions_blocks "$CI_WORKFLOW" | head -n 2)"
[[ "$ci_top_permissions" == $'permissions:\n  contents: read' ]] \
    || fail 'CI must declare top-level permissions: contents: read'

for image_workflow in "$WORKFLOW_DIR/base-image.yml" "$WORKFLOW_DIR/e2e-image.yml"; do
    require_file "$image_workflow"
    image_write_scopes="$(write_scopes "$image_workflow")"
    while IFS= read -r scope; do
        [[ -z "$scope" ]] && continue
        [[ "$scope" == 'packages' ]] \
            || fail "image workflow requests an unnecessary write scope (${scope}): $image_workflow"
    done <<<"$image_write_scopes"
    assert_contains "$image_workflow" '      contents: read' 'image workflow must read repository contents'
    [[ "$image_write_scopes" == 'packages' ]] \
        || fail "image workflow must grant exactly the packages: write scope: $image_workflow"
done

automerge_write_scopes="$(write_scopes "$AUTOMERGE_WORKFLOW")"
for required_scope in contents pull-requests; do
    grep -Fxq -- "$required_scope" <<<"$automerge_write_scopes" \
        || fail "automerge must grant ${required_scope}: write"
done
while IFS= read -r scope; do
    [[ -z "$scope" ]] && continue
    [[ "$scope" == 'contents' || "$scope" == 'pull-requests' ]] \
        || fail "automerge requests an unnecessary write scope (${scope})"
done <<<"$automerge_write_scopes"
assert_contains "$AUTOMERGE_WORKFLOW" '  checks: read' 'automerge needs checks read for the CI gate'
if permissions_blocks "$AUTOMERGE_WORKFLOW" | grep -Eq '^[[:space:]]*(actions|id-token|packages|deployments|security-events):'; then
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

wait_gate="$(
    awk '
        /- name: Wait for CI checks/ { in_gate = 1 }
        in_gate { print }
        in_gate && /- name: Checkout main/ { exit }
    ' "$AUTOMERGE_WORKFLOW"
)"
if ! grep -Fq -- "          check-name: 'CI gate (push)'" <<<"$wait_gate"; then
    fail 'automerge must wait for the exact push aggregate CI check'
fi
if grep -Fq -- "          check-name: 'CI gate'" <<<"$wait_gate"; then
    fail 'automerge must not use the ambiguous aggregate check name'
fi
if ! grep -Fq -- '          checks-discovery-timeout: 2100' <<<"$wait_gate"; then
    fail 'automerge check discovery must allow the 25-minute CI budget plus headroom'
fi
if grep -Eq '^[[:space:]]*check-regexp:' <<<"$wait_gate"; then
    fail 'automerge must not use a subset regex for the CI gate'
fi
if grep -Eq '^[[:space:]]*timeout:' <<<"$wait_gate"; then
    fail 'wait-on-check must not rely on an unsupported generic timeout input'
fi
if grep -Eq '^[[:space:]]*fail-on-no-checks:[[:space:]]*true' <<<"$wait_gate" \
    && ! grep -Eq '^[[:space:]]*check-name:[[:space:]]*.+' <<<"$wait_gate"; then
    fail 'fail-on-no-checks is ineffective without the exact check-name filter'
fi

# checks-discovery-timeout is only a discovery bound. The automerge job also
# needs a hard total bound: 25m CI + 35m discovery + 5m checkout/merge buffer.
automerge_job="$(
    awk '
        /^  automerge:[[:space:]]*$/ { in_job = 1 }
        in_job && /^  [[:alnum:]_-]+:[[:space:]]*$/ && $0 !~ /^  automerge:/ { exit }
        in_job { print }
    ' "$AUTOMERGE_WORKFLOW"
)"
[[ -n "$automerge_job" ]] || fail 'automerge must define a total job timeout'
automerge_timeout_minutes="$(
    awk '/^[[:space:]]+timeout-minutes:[[:space:]]*[0-9]+[[:space:]]*$/ { print $2; exit }' <<<"$automerge_job"
)"
[[ "$automerge_timeout_minutes" =~ ^[0-9]+$ ]] || fail 'automerge timeout-minutes must be a positive integer'
if (( automerge_timeout_minutes < 65 )); then
    fail 'automerge total timeout must cover 25m CI + 35m discovery + 5m merge buffer'
fi
if grep -Eq '^[[:space:]]+timeout:' <<<"$automerge_job"; then
    fail 'automerge must use job-level timeout-minutes, not an unsupported step timeout input'
fi

frontend_ci_job="$(
    awk '
        /^  frontend:[[:space:]]*$/ { in_job = 1 }
        in_job && /^  [[:alnum:]_-]+:[[:space:]]*$/ && $0 !~ /^  frontend:/ { exit }
        in_job { print }
    ' "$CI_WORKFLOW"
)"
[[ -n "$frontend_ci_job" ]] || fail 'CI must define the frontend job'
assert_contains_text "$frontend_ci_job" '        run: pnpm lint:e2e' \
    'frontend CI must lint the Playwright E2E sources'
assert_contains_text "$frontend_ci_job" \
    '        run: pnpm exec tsc -p tsconfig.tests.json --noEmit --pretty false' \
    'frontend CI must type-check the E2E-inclusive test sources'

e2e_ci_job="$(
    awk '
        /^  e2e:[[:space:]]*$/ { in_job = 1 }
        in_job && /^  [[:alnum:]_-]+:[[:space:]]*$/ && $0 !~ /^  e2e:/ { exit }
        in_job { print }
    ' "$CI_WORKFLOW"
)"
[[ -n "$e2e_ci_job" ]] || fail 'CI must define the E2E job'
# The rclone regression is a real gate only while the security-contract job
# executes it; an unwired script is dead coverage (TST-2).
security_contract_job="$(
    awk '
        /^  security-contract:[[:space:]]*$/ { in_job = 1 }
        in_job && /^  [[:alnum:]_-]+:[[:space:]]*$/ && $0 !~ /^  security-contract:/ { exit }
        in_job { print }
    ' "$CI_WORKFLOW"
)"
[[ -n "$security_contract_job" ]] || fail 'CI must define the security-contract job'
for contract_step in \
    'bash tests/infrastructure/ci-security-contract.sh' \
    'bash tests/infrastructure/rclone-sync-regression.sh' \
    'bash tests/infrastructure/verify-image-nonroot.sh' \
    'bash tests/infrastructure/verify-image-freshness.sh' \
    'bash tests/infrastructure/image-pin-freshness-regression.sh'; do
    assert_contains_text "$security_contract_job" "        run: $contract_step" \
        "the security-contract job must run $contract_step"
done
assert_contains_text "$e2e_ci_job" \
    "    if: github.event_name == 'push' || (github.event_name == 'pull_request' && github.actor != 'dependabot[bot]' && github.event.pull_request.head.repo.fork == false)" \
    'E2E must run on push and normal same-repository PRs, but skip Dependabot PR-side secret-dependent runs'
if grep -Fq 'github.event.pull_request.head.repo.fork == false' <<<"$e2e_ci_job" \
    && ! grep -Fq "github.actor != 'dependabot[bot]'" <<<"$e2e_ci_job"; then
    fail 'E2E condition must distinguish Dependabot PRs from normal same-repository PRs'
fi

# Meilisearch readiness is deliberately bounded at every call site. The helper
# owns the maximums; CI and the local E2E harness pass the documented 60/2/1
# total/request/poll-second contract rather than relying on an unbounded wait.
if [[ "$(grep -Fc -- 'run: bash scripts/wait-for-meilisearch.sh' "$CI_WORKFLOW" || true)" -ne 2 ]]; then
    fail 'backend PHPUnit and E2E CI must each invoke the bounded Meilisearch wait'
fi
for ci_meili_timeout in \
    'MEILISEARCH_READY_TIMEOUT_SECONDS: "60"' \
    'MEILISEARCH_REQUEST_TIMEOUT_SECONDS: "2"' \
    'MEILISEARCH_READY_POLL_SECONDS: "1"'; do
    if [[ "$(grep -Fc -- "$ci_meili_timeout" "$CI_WORKFLOW" || true)" -ne 2 ]]; then
        fail "both CI Meilisearch wait call sites must set $ci_meili_timeout"
    fi
done
assert_contains "$CI_WORKFLOW" '          MEILISEARCH_HEALTH_URL: http://127.0.0.1:7701/health' \
    'backend CI Meilisearch wait must use the host-network health endpoint'
assert_contains "$CI_WORKFLOW" '          MEILISEARCH_HEALTH_URL: http://meilisearch:7700/health' \
    'E2E CI Meilisearch wait must use the service health endpoint'

assert_contains "$WAIT_FOR_MEILISEARCH" 'readonly MAX_TIMEOUT_SECONDS=300' \
    'Meilisearch total wait must remain bounded to 300 seconds'
assert_contains "$WAIT_FOR_MEILISEARCH" 'readonly MAX_POLL_SECONDS=30' \
    'Meilisearch retry interval must remain bounded to 30 seconds'
assert_contains "$WAIT_FOR_MEILISEARCH" 'readonly MAX_REQUEST_TIMEOUT_SECONDS=30' \
    'Meilisearch request timeout must remain bounded to 30 seconds'
assert_contains "$WAIT_FOR_MEILISEARCH" \
    'validate_positive_integer MEILISEARCH_READY_TIMEOUT_SECONDS "$timeout_seconds" "$MAX_TIMEOUT_SECONDS"' \
    'Meilisearch total timeout must be validated against its bound'
assert_contains "$WAIT_FOR_MEILISEARCH" \
    'validate_positive_integer MEILISEARCH_READY_POLL_SECONDS "$poll_seconds" "$MAX_POLL_SECONDS"' \
    'Meilisearch retry interval must be validated against its bound'
assert_contains "$WAIT_FOR_MEILISEARCH" \
    'validate_positive_integer MEILISEARCH_REQUEST_TIMEOUT_SECONDS "$request_timeout_seconds" "$MAX_REQUEST_TIMEOUT_SECONDS"' \
    'Meilisearch request timeout must be validated against its bound'

assert_contains "$E2E_UP_SCRIPT" \
    'readonly E2E_MEILISEARCH_READY_TIMEOUT_SECONDS="${E2E_MEILISEARCH_READY_TIMEOUT_SECONDS:-60}"' \
    'local E2E harness must default to a 60-second Meilisearch wait'
assert_contains "$E2E_UP_SCRIPT" \
    'readonly E2E_MEILISEARCH_REQUEST_TIMEOUT_SECONDS="${E2E_MEILISEARCH_REQUEST_TIMEOUT_SECONDS:-2}"' \
    'local E2E harness must default to a 2-second Meilisearch request timeout'
assert_contains "$E2E_UP_SCRIPT" \
    'readonly E2E_MEILISEARCH_READY_POLL_SECONDS="${E2E_MEILISEARCH_READY_POLL_SECONDS:-1}"' \
    'local E2E harness must default to a 1-second Meilisearch poll interval'
assert_contains "$E2E_UP_SCRIPT" \
    '    MEILISEARCH_READY_TIMEOUT_SECONDS="$E2E_MEILISEARCH_READY_TIMEOUT_SECONDS" \' \
    'local E2E harness must pass its bounded total Meilisearch timeout'
assert_contains "$E2E_UP_SCRIPT" \
    '    MEILISEARCH_REQUEST_TIMEOUT_SECONDS="$E2E_MEILISEARCH_REQUEST_TIMEOUT_SECONDS" \' \
    'local E2E harness must pass its bounded Meilisearch request timeout'
assert_contains "$E2E_UP_SCRIPT" \
    '    MEILISEARCH_READY_POLL_SECONDS="$E2E_MEILISEARCH_READY_POLL_SECONDS" \' \
    'local E2E harness must pass its bounded Meilisearch poll interval'
assert_contains "$E2E_UP_SCRIPT" \
    '    bash "$ROOT/scripts/wait-for-meilisearch.sh"; then' \
    'local E2E harness must invoke the bounded Meilisearch wait helper'

ci_gate_job="$(
    awk '
        /^  ci-gate:[[:space:]]*$/ { in_gate = 1 }
        in_gate && /^  [[:alnum:]_-]+:[[:space:]]*$/ && $0 !~ /^  ci-gate:/ { exit }
        in_gate { print }
    ' "$CI_WORKFLOW"
)"
[[ -n "$ci_gate_job" ]] || fail 'CI must define the required ci-gate job'
assert_contains_text "$ci_gate_job" '    name: CI gate (${{ github.event_name }})' \
    'CI gate must expose distinct push and pull_request aggregate names'
assert_contains_text "$ci_gate_job" \
    "    if: \${{ always() && (github.event_name == 'push' || (github.event_name == 'pull_request' && github.actor != 'dependabot[bot]')) }}" \
    'CI gate must stay fail-closed for push/normal PRs and skip only Dependabot PR-side runs'
assert_contains_text "$ci_gate_job" '    needs:' \
    'CI gate must retain its dependency aggregate'
assert_contains "$CI_WORKFLOW" '  push:' 'CI must retain the push aggregate used by Dependabot automerge'
assert_contains "$CI_WORKFLOW" '  pull_request:' 'CI must retain the normal pull-request aggregate'

# The pinned prod image runs as `USER www-data` (uid 1000) and both test jobs
# bind-mount the runner-owned workspace. Without an explicit root override the
# jobs cannot write .env, storage/logs or vendor/, and they die before any test
# runs — which is exactly how the backend job failed after the image went
# public. This only relaxes the throwaway CI test container; the non-root
# guarantee for the deployed artifact stays in deployment/Dockerfile, the
# compose `user:` directive and verify-image-nonroot.sh.
assert_contains "$CI_WORKFLOW" '      options: --user root' \
    'The e2e container job must override the non-root image user, or checkout and pnpm install fail on the mounted workspace'
assert_contains "$CI_WORKFLOW" '            --user root \' \
    'The backend php container must override the non-root image user, or composer install and artisan key:generate fail on the mounted workspace'
ci_gate_needs="$(
    awk '
        /^[[:space:]]+needs:[[:space:]]*$/ { in_needs = 1; next }
        in_needs && /^[[:space:]]*-[[:space:]]+/ { print; next }
        in_needs { exit }
    ' <<<"$ci_gate_job"
)"
for required_job in security-contract backend frontend e2e; do
    if ! grep -Eq "^[[:space:]]*-[[:space:]]+${required_job}[[:space:]]*$" <<<"$ci_gate_needs"; then
        fail "CI gate must need $required_job"
    fi
done
for result_mapping in \
    'SECURITY_CONTRACT_RESULT: ${{ needs.security-contract.result }}' \
    'BACKEND_RESULT: ${{ needs.backend.result }}' \
    'FRONTEND_RESULT: ${{ needs.frontend.result }}' \
    'E2E_RESULT: ${{ needs.e2e.result }}'; do
    assert_contains_text "$ci_gate_job" "          $result_mapping" \
        "CI gate must expose the required result for ${result_mapping%%:*}"
done
for result_assertion in \
    '[[ "$SECURITY_CONTRACT_RESULT" == "success" ]]' \
    '[[ "$BACKEND_RESULT" == "success" ]]' \
    '[[ "$FRONTEND_RESULT" == "success" ]]' \
    '[[ "$E2E_RESULT" == "success" ]]'; do
    assert_contains_text "$ci_gate_job" "          $result_assertion" \
        'CI gate must fail unless every required job result is success'
done

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

# 4. No workflow may declare an artifact upload or a Playwright artifact path,
# and no `run:` block may interpolate github.event directly (untrusted metadata
# must go through env). Detection is structural: any indentation, any artifact
# action, quoted or unquoted paths.
for workflow in "${workflow_files[@]}"; do
    if message="$(artifact_upload_violation "$workflow")"; then
        fail "$message"
    fi
    if message="$(artifact_path_violation "$workflow")"; then
        fail "$message"
    fi
    if message="$(run_block_violation "$workflow")"; then
        fail "$message"
    fi
done
if grep -R -Eq -- '--reporter[=[:space:]]+[^[:space:]]*html|--reporter[=[:space:]]+list,html' "$WORKFLOW_DIR"; then
    fail 'CI enables the Playwright HTML reporter'
fi
if grep -R -Eq -- 'PW_TRACE[[:space:]]*=[[:space:]]*1' "$WORKFLOW_DIR"; then
    fail 'CI enables Playwright tracing'
fi

# 4b. Synthetic fixtures prove the probes above actually fail closed: a vacuum
# guard looks green while enforcing nothing. Each bad fixture MUST be rejected,
# and the clean fixture MUST pass so a probe is not trivially always-failing.
fixture_dir="$TMP_ROOT/fixtures"
mkdir -p "$fixture_dir"

cat > "$fixture_dir/write-all.yml" <<'YAML'
name: fixture
on: push
permissions: write-all
jobs:
  probe:
    runs-on: ubuntu-latest
    steps:
      - run: echo ok
YAML
if ! write_all_violation "$fixture_dir/write-all.yml" >/dev/null; then
    fail 'the write-all fixture was not rejected by the permission probe'
fi

cat > "$fixture_dir/job-escalation.yml" <<'YAML'
name: fixture
on: push
permissions:
  contents: read
jobs:
  probe:
    runs-on: ubuntu-latest
    permissions:
      contents: write
    steps:
      - run: echo ok
YAML
if [[ -z "$(write_scopes "$fixture_dir/job-escalation.yml")" ]]; then
    fail 'the per-job write escalation fixture was not rejected by the permission probe'
fi

cat > "$fixture_dir/artifact-upload.yml" <<'YAML'
name: fixture
on: push
jobs:
  probe:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/upload-artifact@0123456789012345678901234567890123456789
        with:
          path: "test-results/"
YAML
if ! artifact_upload_violation "$fixture_dir/artifact-upload.yml" >/dev/null; then
    fail 'the artifact-upload fixture was not rejected'
fi
if ! artifact_path_violation "$fixture_dir/artifact-upload.yml" >/dev/null; then
    fail 'the quoted artifact-path fixture was not rejected'
fi

cat > "$fixture_dir/run-event.yml" <<'YAML'
name: fixture
on: pull_request
jobs:
  probe:
    runs-on: ubuntu-latest
    steps:
      - run: 'echo "${{ github.event.pull_request.number }}"'
YAML
if ! run_block_violation "$fixture_dir/run-event.yml" >/dev/null; then
    fail 'the github.event run-block fixture was not rejected'
fi

cat > "$fixture_dir/clean.yml" <<'YAML'
name: fixture
on: pull_request
permissions:
  contents: read
jobs:
  probe:
    runs-on: ubuntu-latest
    permissions:
      contents: read
    steps:
      - run: 'echo "${{ github.event_name }}"'
YAML
if write_all_violation "$fixture_dir/clean.yml" >/dev/null \
    || [[ -n "$(write_scopes "$fixture_dir/clean.yml")" ]] \
    || artifact_upload_violation "$fixture_dir/clean.yml" >/dev/null \
    || artifact_path_violation "$fixture_dir/clean.yml" >/dev/null \
    || run_block_violation "$fixture_dir/clean.yml" >/dev/null; then
    fail 'the clean fixture was rejected by a contract probe'
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
assert_contains "$PLAYWRIGHT_CONFIG" '    timeout: 120000,' \
    'Playwright per-test budget must remain bounded at 120 seconds'
assert_contains "$PLAYWRIGHT_CONFIG" '    globalTimeout: 1500000,' \
    'Playwright CI whole-run budget must remain bounded at 25 minutes'
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
