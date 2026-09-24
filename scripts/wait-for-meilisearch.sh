#!/usr/bin/env bash
# Wait for a Meilisearch /health endpoint to become available.
#
# The wait is deliberately bounded by a wall-clock deadline. Each individual
# HTTP request and retry interval is bounded as well, so a cold-start failure
# cannot leave the E2E setup hanging forever.
set -euo pipefail

readonly DEFAULT_HEALTH_URL="http://127.0.0.1:7701/health"
readonly DEFAULT_TIMEOUT_SECONDS=60
readonly DEFAULT_POLL_SECONDS=1
readonly DEFAULT_REQUEST_TIMEOUT_SECONDS=2
readonly MAX_TIMEOUT_SECONDS=300
readonly MAX_POLL_SECONDS=30
readonly MAX_REQUEST_TIMEOUT_SECONDS=30

health_url="${MEILISEARCH_HEALTH_URL:-$DEFAULT_HEALTH_URL}"
timeout_seconds="${MEILISEARCH_READY_TIMEOUT_SECONDS:-$DEFAULT_TIMEOUT_SECONDS}"
poll_seconds="${MEILISEARCH_READY_POLL_SECONDS:-$DEFAULT_POLL_SECONDS}"
request_timeout_seconds="${MEILISEARCH_REQUEST_TIMEOUT_SECONDS:-$DEFAULT_REQUEST_TIMEOUT_SECONDS}"

log() {
    printf '[meilisearch] %s\n' "$*"
}

fail() {
    printf '[meilisearch] ERROR: %s\n' "$*" >&2
    exit 1
}

validate_positive_integer() {
    local name="$1"
    local value="$2"
    local maximum="$3"

    [[ "$value" =~ ^[1-9][0-9]*$ ]] \
        || fail "${name} must be a positive integer (got: ${value})"
    (( value <= maximum )) \
        || fail "${name} must be <= ${maximum} seconds (got: ${value})"
}

validate_positive_integer MEILISEARCH_READY_TIMEOUT_SECONDS "$timeout_seconds" "$MAX_TIMEOUT_SECONDS"
validate_positive_integer MEILISEARCH_READY_POLL_SECONDS "$poll_seconds" "$MAX_POLL_SECONDS"
validate_positive_integer MEILISEARCH_REQUEST_TIMEOUT_SECONDS "$request_timeout_seconds" "$MAX_REQUEST_TIMEOUT_SECONDS"
command -v curl >/dev/null 2>&1 || fail "curl is required to check Meilisearch readiness"

last_error=""
deadline=$((SECONDS + timeout_seconds))
log "Waiting for Meilisearch at ${health_url} (timeout: ${timeout_seconds}s, interval: ${poll_seconds}s)"

while (( SECONDS < deadline )); do
    remaining=$((deadline - SECONDS))
    request_timeout=$request_timeout_seconds
    if (( request_timeout > remaining )); then
        request_timeout=$remaining
    fi
    if (( request_timeout < 1 )); then
        break
    fi

    response=""
    if response="$(curl --fail --silent --show-error \
        --connect-timeout "$request_timeout" \
        --max-time "$request_timeout" \
        "$health_url" 2>&1)"; then
        log "Meilisearch is ready at ${health_url}"
        exit 0
    fi
    last_error="$response"

    remaining=$((deadline - SECONDS))
    if (( remaining <= 0 )); then
        break
    fi
    sleep_for=$poll_seconds
    if (( sleep_for > remaining )); then
        sleep_for=$remaining
    fi
    sleep "$sleep_for"
done

printf '[meilisearch] ERROR: Meilisearch did not become ready within %ss\n' "$timeout_seconds" >&2
printf '[meilisearch] health endpoint: %s\n' "$health_url" >&2
printf '[meilisearch] last health response/error: %s\n' "${last_error:-<empty>}" >&2
exit 1
