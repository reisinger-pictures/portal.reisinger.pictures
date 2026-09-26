#!/usr/bin/env bash
# Artifact-level gate for P1-I5: the tag-pinned portal-base image that
# production boots - and the tag-pinned portal-e2e image that receives the
# CI secrets - must not run as root.
#
# The static half of this policy lives in
# backend/tests/Feature/InfrastructureSupplyChainPolicyTest.php: the Dockerfiles
# declare a non-root USER and every consumer pins one identical concrete tag. That
# half is not sufficient on its own, because it only describes the *source*.
# `USER www-data` entered deployment/Dockerfile on 2026-09-24, while the digest
# production pinned until commit d7f3596 had been built on 2026-08-20 from an
# image config with an empty Config.User that ran as uid 0. Source says nothing
# about an already published artifact, so this script reads each artifact's own
# config back from the registry and fails when its runtime user is root.
#
# Both images are checked. portal-e2e receives CI secrets (Stripe/JWT/Admin) and
# was previously unpinned by any gate; a typo or a stale digest was undetectable.
# It is consumed only by .github/workflows/ci.yml (the e2e job `container:`).
#
# The manifest is requested *by the consumed tag*, so the config blob the
# registry resolves it to is the artifact the consumer boots today; the digest
# the registry reports is kept for the failure messages, not compared against a
# repository pin (this gate no longer requires a digest pin).
#
# The inspect is deliberately anonymous - no docker login, no credential from
# ~/.docker/config.json, no Authorization header on the first request. A
# registry that answers 401/403 therefore FAILS this gate with an actionable
# message instead of being skipped: an unverifiable non-root claim is exactly
# the gap this gate exists to close, and a guard that quietly degrades to a
# no-op is worse than no guard at all.
#
# Bash 3.2 compatible: no mapfile/readarray (macOS system bash aborts on those
# with "command not found" instead of verifying anything).
set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
COMPOSE_FILE="$ROOT_DIR/deployment/docker-compose.yml"
CI_WORKFLOW="$ROOT_DIR/.github/workflows/ci.yml"
BASE_DOCKERFILE="$ROOT_DIR/deployment/Dockerfile"
E2E_DOCKERFILE="$ROOT_DIR/deployment/Dockerfile.e2e"

readonly DEFAULT_REGISTRY_BASE_URL='https://ghcr.io'
readonly HTTP_TIMEOUT_SECONDS=30
readonly MANIFEST_ACCEPT='application/vnd.oci.image.index.v1+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.docker.distribution.manifest.v2+json'

# Test seam: pointing this at a local fixture registry exercises the full
# token -> manifest -> config -> user path without weakening the default, which
# always inspects the real registry anonymously. Every non-default run announces
# itself so a fixture result can never be mistaken for a real artifact result.
REGISTRY_BASE_URL="${IMAGE_REGISTRY_BASE_URL:-$DEFAULT_REGISTRY_BASE_URL}"

WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/verify-image-nonroot.XXXXXX")"
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
    local file="$1"
    [[ -f "$file" ]] || fail "required file is missing: $file"
}

require_command() {
    local command_name="$1"
    command -v "$command_name" >/dev/null 2>&1 \
        || fail "required command is not available: $command_name"
}

# uid 0 is root regardless of how it is spelled. "root", "0", "0:0" and
# "root:root" are the forms a Dockerfile or a registry config can produce.
is_root_identity() {
    local user="$1"
    local uid="${user%%:*}"

    [[ "$uid" == 'root' || "$uid" == '0' || "$uid" == '0.0' ]]
}

# $1: url, remaining arguments: extra curl arguments. Stores the response in
# $BODY_FILE / $HEADER_FILE and the status in $HTTP_STATUS.
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

# Same request, replayed with the anonymous token once the registry has issued
# one for the public pull scope. Kept as its own call site so a token can
# never leak into a request that must stay unauthenticated first.
http_get_with_token() {
    local url="$1"
    shift

    if [[ -n "$anonymous_token" ]]; then
        http_get "$url" -H "Authorization: Bearer $anonymous_token" "$@"
    else
        http_get "$url" "$@"
    fi
}

# The package exists but is not readable without credentials. Anonymous
# inspectability is a hard precondition of this gate, so this is a failure -
# never a pass and never a skip.
fail_not_publicly_readable() {
    local status="$1"
    local phase="$2"

    fail "the pinned ${current_image} artifact is not anonymously inspectable: \
${REGISTRY_BASE_URL} answered HTTP ${status} while ${phase}. \
The registry packages must be public: this gate reads the published image \
config without any credential on purpose, because a private package hides the \
runtime user of the very image the consumer boots - that is the exact blind spot \
of the static Dockerfile check (P1-I5: the pinned portal-base image ran as root \
while the source already said 'USER www-data'). Set the org packages \
${current_image} (and both portal-base and portal-e2e) to 'public' in the GHCR \
package settings. Treat this as a real failure: the non-root property of the \
pinned image is UNVERIFIED until an anonymous inspect succeeds."
}

# Resolves the single pinned tag for $1 from the remaining files.
# Emits the tag (e.g. "8.5") on stdout; fails closed on zero or on a
# disagreement. Reads the repository's own pins, never a second hardcoded copy.
resolve_pin() {
    local image_name="$1"
    shift

    local tags=()
    local tag
    while IFS= read -r tag; do
        [[ -n "$tag" ]] || continue
        tags+=("$tag")
    done < <(
        grep -ohE "ghcr\.io/[A-Za-z0-9._-]+/${image_name}:[^[:space:]\"'@]+" "$@" \
            | sed -E 's/.*:([^[:space:]@]+)$/\1/' \
            | sort -u
    )

    ((${#tags[@]} > 0)) \
        || fail "no tag-pinned ${image_name} reference found in the consumer files: $*"
    ((${#tags[@]} == 1)) \
        || fail "the pinned ${image_name} tags disagree, this gate cannot pick one: ${tags[*]}"

    printf '%s' "${tags[0]}"
}

# Verifies one consumed image: expected runtime user (from source), the
# consumed tag (from the consumer files), anonymous inspectability, and the
# published Config.User.
verify_image() {
    local image_name="$1"
    shift
    local dockerfile="$1"
    shift
    local sources=("$@")

    current_image="$image_name"
    anonymous_token=''
    HTTP_STATUS=''

    # 1. The expected runtime user comes from the repository source, never from
    # a second hardcoded copy in this script: the artifact must agree with the
    # USER that the image's Dockerfile actually builds.
    local expected_user
    expected_user="$(sed -nE 's/^[[:space:]]*USER[[:space:]]+([^[:space:]]+).*/\1/p' "$dockerfile" | tail -n 1)"
    [[ -n "$expected_user" ]] \
        || fail "${dockerfile#${ROOT_DIR}/} declares no USER instruction, so the image would run as root"
    if is_root_identity "$expected_user"; then
        fail "${dockerfile#${ROOT_DIR}/} builds the image as root (USER ${expected_user}); \
the pinned ${image_name} artifact would inherit it"
    fi
    printf 'source: %s declares USER %s\n' "${dockerfile#${ROOT_DIR}/}" "$expected_user"

    # 2. The reference is resolved from the repository's own pins. There is
    # exactly one tag by construction, so this script can never drift away
    # from what the consumer actually boots.
    local pinned_tag
    pinned_tag="$(resolve_pin "$image_name" "${sources[@]}")"

    local pinned_reference
    pinned_reference="$(grep -ohE "ghcr\.io/[A-Za-z0-9._-]+/${image_name}:${pinned_tag}" \
        "${sources[@]}" | sort -u | head -n 1)"
    [[ -n "$pinned_reference" ]] || fail "could not reconstruct the pinned ${image_name} reference"

    local pinned_host pinned_path image_repository
    pinned_host="${pinned_reference%%/*}"
    pinned_path="${pinned_reference#*/}"
    pinned_path="${pinned_path%:*}"
    # A v2 API path never carries a tag - and neither does a pull scope, which
    # is always repository:<owner>/<name>:pull. Keeping the tag here silently
    # turns every request into a 404.
    image_repository="${pinned_path%:*}"
    printf 'pin: %s (resolved from %d consumer reference(s))\n' "$pinned_reference" "${#sources[@]}"

    # 3. Anonymous manifest fetch. The first request carries no Authorization
    # header at all; a bearer token is only used if the registry itself hands one
    # out for the public pull scope.
    local manifest_url
    manifest_url="$REGISTRY_BASE_URL/v2/$image_repository/manifests/$pinned_tag"
    http_get "$manifest_url" -H "Accept: $MANIFEST_ACCEPT"

    if [[ "$HTTP_STATUS" == '401' ]]; then
        local challenge token_realm token_service token_scope token_query
        challenge="$(grep -i '^www-authenticate:' "$HEADER_FILE" | tail -n 1 | tr -d '\r')"
        token_realm="$(sed -nE 's/.*realm="([^"]+)".*/\1/p' <<<"$challenge")"
        token_service="$(sed -nE 's/.*service="([^"]+)".*/\1/p' <<<"$challenge")"
        token_scope="$(sed -nE 's/.*scope="([^"]+)".*/\1/p' <<<"$challenge")"
        [[ -n "$token_realm" && -n "$token_scope" ]] \
            || fail "the registry issued a 401 without a usable Bearer challenge, so an anonymous pull is impossible"

        token_query="$(python3 -c \
            'import sys, urllib.parse; print(urllib.parse.urlencode({"service": sys.argv[1], "scope": sys.argv[2]}))' \
            "$token_service" "$token_scope")"
        http_get "$token_realm?$token_query"
        if [[ "$HTTP_STATUS" == '401' || "$HTTP_STATUS" == '403' ]]; then
            fail_not_publicly_readable "$HTTP_STATUS" 'requesting an anonymous pull token'
        fi
        [[ "$HTTP_STATUS" == '200' ]] \
            || fail "the token endpoint answered HTTP $HTTP_STATUS: $token_realm"

        anonymous_token="$(python3 -c \
            'import json, sys
document = json.load(sys.stdin)
token = document.get("token") or document.get("access_token")
if not token:
    sys.exit("the token response carried no token")
print(token)' <"$BODY_FILE")" \
            || fail "the token response did not contain a pull token"

        http_get "$manifest_url" -H "Accept: $MANIFEST_ACCEPT" -H "Authorization: Bearer $anonymous_token"
        if [[ "$HTTP_STATUS" == '401' || "$HTTP_STATUS" == '403' ]]; then
            fail_not_publicly_readable "$HTTP_STATUS" "fetching the manifest of ${pinned_tag}"
        fi
    elif [[ "$HTTP_STATUS" == '403' ]]; then
        fail_not_publicly_readable "$HTTP_STATUS" 'fetching the manifest without credentials'
    fi

    if [[ "$HTTP_STATUS" == '404' ]]; then
        fail "the pinned tag ${pinned_tag} is not published in ${pinned_host} (HTTP 404); \
rebuild and push ${image_name} or correct the pin before this gate can prove anything"
    fi
    [[ "$HTTP_STATUS" == '200' ]] || fail "unexpected HTTP $HTTP_STATUS while fetching ${manifest_url}"

    # Defensive: a tag manifest must still be content-addressed by the registry.
    # There is no requested digest to compare against any more (the consumer
    # pins a tag, not a digest), so this only fails closed when the registry
    # answers without a usable Docker-Content-Digest.
    local served_digest
    served_digest="$(sed -nE 's/^[Dd]ocker-[Cc]ontent-[Dd]igest:[[:space:]]*(sha256:[0-9a-f]{64}).*$/\1/p' \
        "$HEADER_FILE" | tr -d '\r' | tail -n 1)"
    [[ -n "$served_digest" ]] \
        || fail "the registry did not return a Docker-Content-Digest while tag ${pinned_tag} was requested"

    # 4. A multi-platform image is published as an index; follow it to the
    # linux/amd64 manifest so the inspected config is the one the runners use.
    # Whether the document is an index is decided by its media type, never by
    # comparing digests: a single-platform image resolves straight to a config.
    local resolved manifest_kind config_digest
    resolved="$(resolve_manifest_config)" \
        || fail "could not resolve the image config digest from the manifest of ${pinned_tag}"
    read -r manifest_kind config_digest <<<"$resolved"

    if [[ "$manifest_kind" == 'index' ]]; then
        local platform_manifest_url
        platform_manifest_url="$REGISTRY_BASE_URL/v2/$image_repository/manifests/$config_digest"
        http_get_with_token "$platform_manifest_url" -H "Accept: $MANIFEST_ACCEPT"
        [[ "$HTTP_STATUS" == '200' ]] \
            || fail "unexpected HTTP $HTTP_STATUS while fetching the linux/amd64 manifest $config_digest"
        resolved="$(resolve_manifest_config)" || fail "could not resolve the config digest of $config_digest"
        read -r manifest_kind config_digest <<<"$resolved"
        [[ "$manifest_kind" == 'manifest' ]] || fail "the linux/amd64 entry of the image index is itself an index"
    fi
    printf 'index: %s -> %s\n' "$manifest_kind" "$config_digest"

    # 5. The image config is the authoritative statement of the runtime user.
    local config_url
    config_url="$REGISTRY_BASE_URL/v2/$image_repository/blobs/$config_digest"
    http_get_with_token "$config_url"
    if [[ "$HTTP_STATUS" == '401' || "$HTTP_STATUS" == '403' ]]; then
        fail_not_publicly_readable "$HTTP_STATUS" 'fetching the image config blob'
    fi
    [[ "$HTTP_STATUS" == '200' ]] || fail "unexpected HTTP $HTTP_STATUS while fetching $config_url"

    local actual_user image_created
    actual_user="$(python3 -c \
        'import json, sys
config = json.load(sys.stdin)
print((config.get("config") or {}).get("User") or "")' <"$BODY_FILE")" \
        || fail "the image config blob is not readable JSON"

    image_created="$(python3 -c \
        'import json, sys; print(json.load(sys.stdin).get("created", "unknown"))' <"$BODY_FILE")"

    if [[ -z "$actual_user" ]]; then
        fail "the pinned ${image_name} artifact (tag ${pinned_tag}) has an EMPTY Config.User, \
which means it runs as root (uid 0). This is the exact P1-I5 regression: a source \
that declared 'USER www-data' while the published image ran as root. Rebuild \
${image_name} from a Dockerfile that sets USER before publishing."
    fi
    if is_root_identity "$actual_user"; then
        fail "the pinned ${image_name} artifact (tag ${pinned_tag}) runs as root (Config.User=${actual_user}). \
A root container is not an acceptable runtime: keep 'USER ${expected_user}' in \
${dockerfile#${ROOT_DIR}/} as the last USER instruction of the final stage and republish."
    fi
    if [[ "$actual_user" != "$expected_user" ]]; then
        fail "the pinned ${image_name} artifact (tag ${pinned_tag}) runs as Config.User=${actual_user}, \
but ${dockerfile#${ROOT_DIR}/} declares USER ${expected_user}. The published artifact does \
not match the reviewed source; rebuild and republish from the current Dockerfile."
    fi

    printf 'artifact: %s built %s\n' "$pinned_tag" "$image_created"
    printf 'PASS: pinned %s artifact runs as the non-root user %s\n' "$image_name" "$actual_user"
}

# Resolves the config digest from the manifest currently in $BODY_FILE.
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

require_command curl
require_command python3
require_file "$COMPOSE_FILE"
require_file "$CI_WORKFLOW"
require_file "$BASE_DOCKERFILE"
require_file "$E2E_DOCKERFILE"

if [[ "$REGISTRY_BASE_URL" != "$DEFAULT_REGISTRY_BASE_URL" ]]; then
    printf 'NOTICE: registry endpoint overridden with %s - this is NOT a ghcr.io artifact result\n' \
        "$REGISTRY_BASE_URL" >&2
fi

# portal-base is consumed by production (docker-compose.yml) and the CI backend
# job (ci.yml). portal-e2e is consumed only by the CI e2e job's container image.
verify_image 'portal-base' "$BASE_DOCKERFILE" "$COMPOSE_FILE" "$CI_WORKFLOW"
verify_image 'portal-e2e' "$E2E_DOCKERFILE" "$CI_WORKFLOW"
