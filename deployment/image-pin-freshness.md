# Image pin freshness policy (INFRA-10)

The two container images consumed by this repository are built outside the
application pipelines and published as **mutable tags**:

| Image | Published tags | Built by | Consumed by |
|-------|----------------|----------|-------------|
| `portal-base` | `8.5`, `latest` | `.github/workflows/base-image.yml` (push to `main`, nightly cron, manual) | `deployment/docker-compose.yml` (production) and `.github/workflows/ci.yml` (backend job) |
| `portal-e2e` | `latest`, `<playwright-version>` | `.github/workflows/e2e-image.yml` (push to `main`, weekly cron, manual) | `.github/workflows/ci.yml` (e2e job `container:`) |

Only the **sha256 digest** is an immutable consumer pin. A mutable tag is
useful for publishing, not for consuming. Every consumer therefore pins the
digest (`reference@sha256:...`), and the only place that may change is the
repository pin itself.

## The gap this policy closes

Because nothing updated the consumer digests, a rebuild reached GHCR but never
CI or production: security fixes in the base image (PHP, extensions, exiftool)
stayed on the tag while the repository kept booting the older digest. The
non-root artifact gate (`verify-image-nonroot.sh`) happily passed on the stale
pin, because it inspects the pinned digest — a correct artifact, just an old
one.

## The gate

`tests/infrastructure/verify-image-freshness.sh` resolves, for each consumed
tag, the digest the tag currently points at (anonymous registry inspect) and
compares it with the repository pin:

- **pin == tag digest** → fresh.
- **pin != tag digest and pinned artifact age ≤ `MAX_IMAGE_PIN_AGE_DAYS`** →
  the consumer is behind, but inside the documented grace window; the gate
  prints a `behind:` line and passes.
- **pin != tag digest and pinned artifact age > `MAX_IMAGE_PIN_AGE_DAYS`**, or
  the pinned artifact itself is older than the maximum → **FAIL**. The pin must
  be bumped to the current digest.

The pinned artifact's age is read from the published image config `created`
field, so a workflow that silently stopped rebuilding also trips the gate.

**Documented maximum pin age: 14 days** (`DEFAULT_MAX_PIN_AGE_DAYS` in the
script; overridable with `MAX_IMAGE_PIN_AGE_DAYS`). 14 days bounds exposure to
a base-image security fix while leaving room for the nightly/weekly rebuild
cadence.

The gate runs in the `security-contract` CI job, next to
`verify-image-nonroot.sh`. Both inspect the registry anonymously; a private
package fails the job instead of silently skipping it.

## Bumping a pin

1. Read the current tag digest, for example with
   `docker buildx imagetools inspect <reference>:<tag>`, or from the
   `verify-image-freshness.sh` `behind:` line.
2. Update **every** consumer of that image:
   - `portal-base`: `deployment/docker-compose.yml`,
     `.github/workflows/ci.yml`, and `deployment/Dockerfile.e2e` (`FROM`).
   - `portal-e2e`: `.github/workflows/ci.yml` only.
3. Run `bash tests/infrastructure/verify-image-nonroot.sh` and
   `bash tests/infrastructure/verify-image-freshness.sh` locally (both need
   network access).

`provenance: true` and `sbom: true` are set on both build-push steps so each
published digest carries a signed provenance attestation and an SBOM.
