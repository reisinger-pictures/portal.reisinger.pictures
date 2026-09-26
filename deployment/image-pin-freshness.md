# Image pin freshness policy (INFRA-10)

The two container images consumed by this repository are built outside the
application pipelines and published as **mutable tags**:

| Image | Published tags | Built by | Consumed by |
|-------|----------------|----------|-------------|
| `portal-base` | `8.5`, `latest` | `.github/workflows/base-image.yml` (push to `main`, nightly cron, manual) | `deployment/docker-compose.yml` (production) and `.github/workflows/ci.yml` (backend job) |
| `portal-e2e` | `<playwright-version>`, `latest` | `.github/workflows/e2e-image.yml` (push to `main`, weekly cron, manual) | `.github/workflows/ci.yml` (e2e job `container:`) |

Since the owner decision of 2026-09-26 the consumers pin a **concrete tag**
(`portal-base:8.5`, `portal-e2e:<playwright-version>`) and never `latest`. The
earlier `@sha256` digest pin was dropped: a digest froze an image to one build
and required a manual update for every patch release, so a base-image security
fix stayed on the tag while the repository kept booting the older digest.

## The gap this policy closes

Pinning a tag has one honest downside: a **mutable tag is not an immutable
pin**. The digest guarantee — the consumer boots exactly the bytes that were
reviewed — is intentionally gone, and a tag can move under the consumer. That
trade was accepted because the tag delivers rebuilds automatically.

What the tag does *not* fix is a workflow that silently stopped running (cron
disabled, build broken, a secret expired): the tag still resolves, but it now
points at an **old artifact**. Nothing changes at the consumer, and the non-root
artifact gate (`verify-image-nonroot.sh`) happily inspects that old artifact —
a correct image, just a stale one. The remaining, and only, barrier is the
artifact's age, which this policy enforces.

## The gate

`tests/infrastructure/verify-image-freshness.sh`:

1. resolves the consumed tag from the repository's own pins
   (`deployment/docker-compose.yml` for `portal-base`, `.github/workflows/ci.yml`
   for `portal-base` and `portal-e2e`) and rejects `latest`;
2. resolves the digest the tag currently points at, anonymously;
3. reads the artifact's own `created` timestamp from the published image config;
4. **age ≤ `MAX_IMAGE_PIN_AGE_DAYS`** → pass; the gate prints which artifact
   (`image:tag -> digest`) is how old and confirms it is inside the window.
5. **age > `MAX_IMAGE_PIN_AGE_DAYS`** → **FAIL**; the tag must be rebuilt and
   republished.

The artifact's age is read from the published image config `created` field, so a
workflow that silently stopped rebuilding trips the gate even though the tag
still resolves. The registry inspect is anonymous: a package that is not
anonymously readable fails the gate instead of being skipped.

**Documented maximum pin age: 14 days** (`DEFAULT_MAX_PIN_AGE_DAYS` in the
script; overridable with `MAX_IMAGE_PIN_AGE_DAYS`). 14 days bounds exposure to
a base-image security fix while leaving room for the nightly/weekly rebuild
cadence.

The gate runs in the `security-contract` CI job, next to
`verify-image-nonroot.sh`. Both inspect the registry anonymously; a private
package fails the job instead of silently skipping it.

## Publishing a new artifact

There is no digest pin to bump any more; a rebuild under the consumed tag
refreshes the artifact every consumer follows:

- `portal-base`: `base-image.yml` republishes `8.5` (and `latest`). Production
  and the backend CI job pick the new build up on the next pull.
- `portal-e2e`: `e2e-image.yml` republishes `portal-e2e:<playwright-version>`
  (and `latest`). `ci.yml` pins that version tag (`1.62.1` today, from
  `frontend/package.json`). When Playwright moves to a new minor and the old
  version tag stops being rebuilt, the age gate fails and the consumer must move
  to the new version tag in `ci.yml`.
- Verify locally after a change (both need network access):
  `bash tests/infrastructure/verify-image-nonroot.sh` and
  `bash tests/infrastructure/verify-image-freshness.sh`.

`provenance: true` and `sbom: true` are set on both build-push steps so each
published artifact carries a signed provenance attestation and an SBOM.
