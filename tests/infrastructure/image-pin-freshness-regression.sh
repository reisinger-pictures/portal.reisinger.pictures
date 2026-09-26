#!/usr/bin/env bash
# Regression for the INFRA-10 tag-pinned freshness gate.
#
# A real registry is not required: a tiny fixture registry plus a synthetic
# repository proves both outcomes of verify-image-freshness.sh.
#   * stale: the artifact the consumed tag resolves to was built long ago ->
#     the gate MUST fail, because the age barrier is the only protection left
#     after the digest pin was dropped.
#   * fresh: the consumed tag resolves to a recently built artifact -> the gate
#     MUST pass.
# It also asserts the documented policy constants and the provenance/sbom
# attestations, so the policy cannot be silently removed.
set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
FRESHNESS_GATE="$ROOT_DIR/tests/infrastructure/verify-image-freshness.sh"
TMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/image-pin-freshness-regression.XXXXXX")"
trap 'rm -rf "$TMP_ROOT"' EXIT

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

[[ -f "$FRESHNESS_GATE" ]] || fail "freshness gate not found: $FRESHNESS_GATE"

# --- static policy assertions -------------------------------------------------
grep -Fq -- 'readonly DEFAULT_MAX_PIN_AGE_DAYS=14' "$FRESHNESS_GATE" \
    || fail 'the documented maximum pin age must stay at 14 days'
if grep -Eq 'sha256:[0-9a-f]{64}' "$FRESHNESS_GATE"; then
    fail 'the freshness gate must resolve tags from the repository, not carry its own digest'
fi
if grep -Eq '\|\|[[:space:]]*true' "$FRESHNESS_GATE"; then
    fail 'the freshness gate must fail closed, not degrade to a no-op'
fi
for workflow in base-image e2e-image; do
    grep -Fq -- '          provenance: true' "$ROOT_DIR/.github/workflows/$workflow.yml" \
        || fail "$workflow.yml must attach build provenance"
    grep -Fq -- '          sbom: true' "$ROOT_DIR/.github/workflows/$workflow.yml" \
        || fail "$workflow.yml must attach an SBOM"
done

# --- synthetic repository -----------------------------------------------------
FIXTURE_REPO="$TMP_ROOT/repo"
mkdir -p "$FIXTURE_REPO/tests/infrastructure" "$FIXTURE_REPO/deployment" "$FIXTURE_REPO/.github/workflows"
cp "$FRESHNESS_GATE" "$FIXTURE_REPO/tests/infrastructure/verify-image-freshness.sh"

cat > "$FIXTURE_REPO/deployment/docker-compose.yml" <<'YAML'
services:
  backend:
    image: ghcr.io/reisinger-pictures/portal-base:8.5
YAML
cat > "$FIXTURE_REPO/deployment/Dockerfile" <<'DOCKERFILE'
FROM scratch
USER www-data
DOCKERFILE
cat > "$FIXTURE_REPO/deployment/Dockerfile.e2e" <<'DOCKERFILE'
FROM scratch
USER root
USER www-data
DOCKERFILE
cat > "$FIXTURE_REPO/.github/workflows/ci.yml" <<'YAML'
jobs:
  backend:
    steps:
      - run: docker run ghcr.io/reisinger-pictures/portal-base:8.5
  e2e:
    container:
      image: ghcr.io/reisinger-pictures/portal-e2e:1.62.1
YAML

# --- fixture registry ---------------------------------------------------------
cat > "$TMP_ROOT/fixture-registry.py" <<'PYTHON'
import datetime
import json
import os
import re
import socketserver
from http.server import BaseHTTPRequestHandler, HTTPServer

MODE = os.environ["FIXTURE_MODE"]
PORT_FILE = os.environ["FIXTURE_PORT_FILE"]
BASE_DIGEST = "a" * 64
E2E_DIGEST = "b" * 64
CONFIG_DIGEST = "d" * 64
# "fresh" is a build from right now; "stale" is far past the 14-day window.
NOW = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
STALE = "2000-01-01T00:00:00Z"


def tag_digest(repo):
    return BASE_DIGEST if repo.endswith("portal-base") else E2E_DIGEST


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_args):
        pass

    def _send(self, code, body=b"", headers=None):
        self.send_response(code)
        for key, value in (headers or {}).items():
            self.send_header(key, value)
        if body:
            self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        if body:
            self.wfile.write(body)

    def do_GET(self):
        path = self.path
        if path.startswith("/token"):
            self._send(200, json.dumps({"token": "fixture"}).encode(), {"Content-Type": "application/json"})
            return

        manifest = re.match(r"^/v2/(.+)/manifests/([^?]+)$", path)
        if manifest:
            repo, ref = manifest.group(1), manifest.group(2)
            if "authorization" not in {key.lower() for key in self.headers}:
                realm = "http://127.0.0.1:%d/token" % self.server.server_port
                self._send(401, b"", {
                    "WWW-Authenticate": 'Bearer realm="%s",service="fixture",scope="repository:%s:pull"' % (realm, repo),
                })
                return
            digest = ref if ref.startswith("sha256:") else "sha256:" + tag_digest(repo)
            body = json.dumps({
                "mediaType": "application/vnd.oci.image.manifest.v1+json",
                "config": {"digest": "sha256:" + CONFIG_DIGEST},
            }).encode()
            self._send(200, body, {
                "Content-Type": "application/vnd.oci.image.manifest.v1+json",
                "Docker-Content-Digest": digest,
            })
            return

        if re.match(r"^/v2/(.+)/blobs/sha256:[0-9a-f]+$", path):
            created = NOW if MODE == "fresh" else STALE
            self._send(200, json.dumps({"created": created}).encode(), {"Content-Type": "application/json"})
            return

        self._send(404, b"not found")


class FixtureServer(HTTPServer):
    def server_bind(self):
        # Skip HTTPServer's socket.getfqdn() reverse lookup: on some hosts it
        # blocks for tens of seconds and the readiness probe would time out.
        socketserver.TCPServer.server_bind(self)
        host, port = self.server_address[:2]
        self.server_name = host
        self.server_port = port


server = FixtureServer(("127.0.0.1", 0), Handler)
with open(PORT_FILE, "w", encoding="utf-8") as handle:
    handle.write(str(server.server_port))
server.serve_forever()
PYTHON

run_case() {
    local mode="$1"
    local expected_exit="$2"
    local expected_text="$3"
    local port_file="$TMP_ROOT/port-$mode"
    local output_file="$TMP_ROOT/output-$mode"

    rm -f "$port_file"
    FIXTURE_MODE="$mode" FIXTURE_PORT_FILE="$port_file" \
        python3 "$TMP_ROOT/fixture-registry.py" &
    local server_pid=$!

    local attempt=0
    while [[ ! -s "$port_file" ]]; do
        attempt=$((attempt + 1))
        (( attempt < 100 )) || fail "the fixture registry did not start (mode=$mode)"
        sleep 0.1
    done
    local port
    port="$(<"$port_file")"

    set +e
    IMAGE_REGISTRY_BASE_URL="http://127.0.0.1:$port" MAX_IMAGE_PIN_AGE_DAYS=14 \
        bash "$FIXTURE_REPO/tests/infrastructure/verify-image-freshness.sh" >"$output_file" 2>&1
    local status=$?
    set -e

    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true

    if [[ "$status" -ne "$expected_exit" ]]; then
        fail "freshness gate exited $status for mode=$mode (expected $expected_exit): $(cat "$output_file")"
    fi
    grep -Fq -- "$expected_text" "$output_file" \
        || fail "freshness gate output for mode=$mode did not contain '$expected_text': $(cat "$output_file")"
}

# Old artifact behind the tag -> hard failure. Fresh artifact -> pass.
run_case stale 1 'stale portal-base artifact'
run_case fresh 0 'fresh: portal-base'

printf 'PASS: image pin freshness gate regression (stale artifact fails, fresh artifact passes)\n'
