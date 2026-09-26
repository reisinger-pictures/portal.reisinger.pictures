#!/usr/bin/env bash
# Pinned-digest freshness gate (INFRA-10).
#
# Policy (documented here and in deployment/image-pin-freshness.md):
#   `base-image.yml` and `e2e-image.yml` push mutable tags (portal-base:8.5,
#   portal-e2e:latest). Only the sha256 digest is an immutable consumer pin, so
#   a rebuild reaches GHCR but never CI or production until the pin is bumped.
#   This gate resolves the digest the consumed tag currently points at and
#   compares it with the repository pin:
#     * pin == tag digest              -> fresh.
#     * pin != tag digest, pin age     -> the consumer is behind; allowed only
#       <= MAX_IMAGE_PIN_AGE_DAYS        while the pinned artifact is younger
#                                        than the documented maximum age. Older
#                                        than that is a hard failure.
#   The pinned artifact's age is read from the published image config `created`
#   field, so a workflow that silently stopped rebuilding also trips the gate.
#
# The registry inspect is anonymous on purpose (the packages are public); a
# 401/403 fails closed instead of degrading to a skip, exactly like
# verify-image-nonroot.sh.
#
# Bash 3.2 compatible: no mapfile/readarray.
set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
COMPOSE_FILE="$ROOT_DIR/deployment/docker-compose.yml"
CI_WORKFLOW="$ROOT_DIR/.github/workflows/ci.yml"
BASE_DOCKERFILE="$ROOT_DIR/deployment/Dockerfile"
E2E_DOCKERFILE="$ROOT_DIR/deployment/Dockerfile.e2e"

readonly DEFAULT_REGISTRY_BASE_URL='https://ghcr.io'
readonly HTTP_TIMEOUT_SECONDS=30
readonly MANIFEST_ACCEPT='application/vnd.oci.image.index.v1+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.docker.distribution.manifest.v2+json'
# Documented maximum freshness window for a consumer pin. Overridable for the
# fixture regression; the default is the production policy.
readonly DEFAULT_MAX_PIN_AGE_DAYS=14

REGISTRY_BASE_URL="${IMAGE_REGISTRY_BASE_URL:-$DEFAULT_REGISTRY_BASE_URL}"
MAX_PIN_AGE_DAYS="${MAX_IMAGE_PIN_AGE_DAYS:-$DEFAULT_MAX_PIN_AGE_DAYS}"

WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/verify-image-freshness.XXXXXX")"
trap 'rm -rf "$WORK_DIR"' EXIT
BODY_FILE="$WORK_DIR/body"
HEADER_FILE="$WORK_DIR/headers"
HTTP_STATUS=''
anonymous_token=''
current_image=''

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

require_file() {
    [[ -f "$1" ]] || fail "required file is missing: $1"
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "required command is not available: $1"
}

http_get() {
    local url="$1"
    shift
    : >"$BODY_FILE"
    : >"$HEADER_FILE"
    if ! HTTP_STATUS="$(curl --silent --show-error --location \
        --max-time "$HTTP_TIMEOUT_SECONDS" \
        --dump-header "$HEADER_FILE" \
        --output "$BODY_FILE" \
        --write-out '%{http_code}' \
        "$@" "$url")"; then
        fail "the registry request failed: $url"
    fi
}

http_get_with_token() {
    local url="$1"
    shift
    if [[ -n "$anonymous_token" ]]; then
        http_get "$url" -H "Authorization: Bearer $anonymous_token" "$@"
    else
        http_get "$url" "$@"
    fi
}

# Fetches $1 (a manifest URL) anonymously. If the registry answers 401, obtains
# an anonymous pull token and replays the request. On return $HTTP_STATUS,
# $BODY_FILE and $HEADER_FILE describe the final manifest response. Fails closed
# when the package is not anonymously readable.
ensure_anonymous_token() {
    local manifest_url="$1"
    http_get "$manifest_url" -H "Accept: $MANIFEST_ACCEPT"
    if [[ "$HTTP_STATUS" == '200' ]]; then
        return 0
    fi
    if [[ "$HTTP_STATUS" == '401' ]]; then
        local challenge token_realm token_service token_scope token_query
        challenge="$(grep -i '^www-authenticate:' "$HEADER_FILE" | tail -n 1 | tr -d '\r')"
        token_realm="$(sed -nE 's/.*realm="([^"]+)".*/\1/p' <<<"$challenge")"
        token_service="$(sed -nE 's/.*service="([^"]+)".*/\1/p' <<<"$challenge")"
        token_scope="$(sed -nE 's/.*scope="([^"]+)".*/\1/p' <<<"$challenge")"
        [[ -n "$token_realm" && -n "$token_scope" ]] \
            || fail "the registry issued a 401 without a usable Bearer challenge while inspecting ${current_image}"
        token_query="$(python3 -c \
            'import sys, urllib.parse; print(urllib.parse.urlencode({"service": sys.argv[1], "scope": sys.argv[2]}))' \
            "$token_service" "$token_scope")"
        http_get "$token_realm?$token_query"
        [[ "$HTTP_STATUS" == '200' ]] \
            || fail "the token endpoint answered HTTP $HTTP_STATUS while inspecting ${current_image}"
        anonymous_token="$(python3 -c \
            'import json, sys
document = json.load(sys.stdin)
token = document.get("token") or document.get("access_token")
if not token:
    sys.exit("no token")
print(token)' <"$BODY_FILE")" || fail "the token response carried no pull token"
        http_get "$manifest_url" -H "Accept: $MANIFEST_ACCEPT" -H "Authorization: Bearer $anonymous_token"
        if [[ "$HTTP_STATUS" == '401' || "$HTTP_STATUS" == '403' ]]; then
            fail "the pinned ${current_image} artifact is not anonymously inspectable: the registry answered HTTP ${HTTP_STATUS}; the registry packages must be public"
        fi
        return 0
    fi
    fail "the pinned ${current_image} artifact is not anonymously inspectable: ${REGISTRY_BASE_URL} answered HTTP ${HTTP_STATUS}; the registry packages must be public"
}

# Resolves the single pinned digest for $1 from the remaining files (same
# resolver as verify-image-nonroot.sh: the repository pin is the source of
# truth, never a second hardcoded copy).
resolve_pin() {
    local image_name="$1"
    shift
    local digests=()
    local digest
    while IFS= read -r digest; do
        [[ -n "$digest" ]] || continue
        digests+=("$digest")
    done < <(
        grep -ohE "ghcr\.io/[A-Za-z0-9._-]+/${image_name}(:[^[:space:]\"']+)?@sha256:[0-9a-f]{64}" "$@" \
            | sed -E 's/.*@(sha256:[0-9a-f]{64})$/\1/' \
            | sort -u
    )
    ((${#digests[@]} > 0)) || fail "no digest-pinned ${image_name} reference found in: $*"
    ((${#digests[@]} == 1)) || fail "the pinned ${image_name} digests disagree: ${digests[*]}"
    printf '%s' "${digests[0]}"
}

# Digest age in whole days from a published image `created` timestamp.
created_age_days() {
    python3 - "$1" <<'PY'
import datetime
import re
import sys

raw = sys.argv[1]
match = re.match(r"(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d+))?", raw)
if not match:
    print(-1)
    raise SystemExit(0)
base = datetime.datetime.strptime(match.group(1), "%Y-%m-%dT%H:%M:%S").replace(
    tzinfo=datetime.timezone.utc
)
fraction = match.group(2) or ""
if fraction:
    base = base.replace(microsecond=int((fraction + "000000")[:6]))
now = datetime.datetime.now(datetime.timezone.utc)
print((now - base).days)
PY
}

# Fetches the image config `created` timestamp for a pinned digest. Expects the
# image config blob to be readable anonymously.
created_for_digest() {
    local image_repository="$1"
    local pinned_digest="$2"
    local manifest_url config_digest resolved manifest_kind
    manifest_url="$REGISTRY_BASE_URL/v2/$image_repository/manifests/$pinned_digest"
    ensure_anonymous_token "$manifest_url"
    [[ "$HTTP_STATUS" == '200' ]] \
        || fail "unexpected HTTP $HTTP_STATUS while fetching the manifest of ${pinned_digest}"

    resolved="$(resolve_manifest_config)" \
        || fail "could not resolve the config digest of ${pinned_digest}"
    read -r manifest_kind config_digest <<<"$resolved"
    if [[ "$manifest_kind" == 'index' ]]; then
        http_get_with_token "$REGISTRY_BASE_URL/v2/$image_repository/manifests/$config_digest" -H "Accept: $MANIFEST_ACCEPT"
        [[ "$HTTP_STATUS" == '200' ]] \
            || fail "unexpected HTTP $HTTP_STATUS while resolving the platform manifest of ${pinned_digest}"
        resolved="$(resolve_manifest_config)" || fail "could not resolve the platform config of ${pinned_digest}"
        read -r manifest_kind config_digest <<<"$resolved"
    fi

    http_get_with_token "$REGISTRY_BASE_URL/v2/$image_repository/blobs/$config_digest"
    [[ "$HTTP_STATUS" == '200' ]] || fail "unexpected HTTP $HTTP_STATUS while fetching the config of ${pinned_digest}"
    python3 -c \
        'import json, sys; print(json.load(sys.stdin).get("created", ""))' <"$BODY_FILE"
}

resolve_manifest_config() {
    python3 -c \
        'import json, sys
manifest = json.load(sys.stdin)
media_type = manifest.get("mediaType", "")
if "image.index" in media_type or "manifest.list" in media_type:
    for entry in manifest.get("manifests") or []:
        platform = entry.get("platform") or {}
        if platform.get("os") == "linux" and platform.get("architecture") == "amd64":
            print("index", entry["digest"])
            break
    else:
        sys.exit("the image index has no linux/amd64 manifest")
else:
    print("manifest", manifest["config"]["digest"])' <"$BODY_FILE"
}

# Verifies one consumed image against the digest its consumed tag points at.
# $1 image, $2 consumed tag, $3.. consumer files.
verify_freshness() {
    local image_name="$1"
    local consumed_tag="$2"
    shift 2
    local sources=("$@")

    current_image="$image_name"
    anonymous_token=''

    local pinned_digest
    pinned_digest="$(resolve_pin "$image_name" "${sources[@]}")"

    local pinned_reference image_repository
    pinned_reference="$(grep -ohE "ghcr\.io/[A-Za-z0-9._-]+/${image_name}(:[^[:space:]\"']+)?@${pinned_digest}" \
        "${sources[@]}" | sort -u | head -n 1)"
    [[ -n "$pinned_reference" ]] || fail "could not reconstruct the pinned ${image_name} reference"
    local pinned_path="${pinned_reference#*/}"
    pinned_path="${pinned_path%@*}"
    image_repository="${pinned_path%:*}"

    # Current digest the consumed tag points at.
    local tag_url
    tag_url="$REGISTRY_BASE_URL/v2/$image_repository/manifests/$consumed_tag"
    ensure_anonymous_token "$tag_url"
    [[ "$HTTP_STATUS" == '200' ]] \
        || fail "unexpected HTTP $HTTP_STATUS while resolving the ${image_name}:${consumed_tag} tag"
    local tag_digest
    tag_digest="$(sed -nE 's/^[Dd]ocker-[Cc]ontent-[Dd]igest:[[:space:]]*(sha256:[0-9a-f]{64}).*$/\1/p' \
        "$HEADER_FILE" | tr -d '\r' | tail -n 1)"
    [[ -n "$tag_digest" ]] \
        || fail "the registry did not return a Docker-Content-Digest for ${image_name}:${consumed_tag}"

    if [[ "$tag_digest" == "$pinned_digest" ]]; then
        printf 'fresh: %s:%s pin %s matches the published tag\n' "$image_name" "$consumed_tag" "$pinned_digest"
        return 0
    fi

    local created age
    created="$(created_for_digest "$image_repository" "$pinned_digest")"
    [[ -n "$created" ]] || fail "the ${image_name} config carried no 'created' timestamp"
    age="$(created_age_days "$created")"
    [[ "$age" =~ ^-?[0-9]+$ ]] || fail "could not compute the age of the pinned ${image_name} artifact"

    if (( age > MAX_PIN_AGE_DAYS )); then
        fail "stale ${image_name} pin: the consumer pins ${pinned_digest} (built ${created}, ${age} days old) while ${image_name}:${consumed_tag} already points at ${tag_digest}. \
Bump the pin in ${sources[*]} to the current digest; the documented maximum pin age is ${MAX_PIN_AGE_DAYS} days."
    fi

    printf 'behind: %s pin is %d day(s) old and %s:%s already moved to %s (within the documented %d-day grace)\n' \
        "$image_name" "$age" "$image_name" "$consumed_tag" "$tag_digest" "$MAX_PIN_AGE_DAYS"
}

require_command curl
require_command python3
require_file "$COMPOSE_FILE"
require_file "$CI_WORKFLOW"
require_file "$BASE_DOCKERFILE"
require_file "$E2E_DOCKERFILE"

if [[ "$REGISTRY_BASE_URL" != "$DEFAULT_REGISTRY_BASE_URL" ]]; then
    printf 'NOTICE: registry endpoint overridden with %s - this is NOT a ghcr.io freshness result\n' \
        "$REGISTRY_BASE_URL" >&2
fi

verify_freshness 'portal-base' '8.5' "$COMPOSE_FILE" "$CI_WORKFLOW"
verify_freshness 'portal-e2e' 'latest' "$CI_WORKFLOW"
