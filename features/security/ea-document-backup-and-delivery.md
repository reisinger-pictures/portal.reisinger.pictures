# EÜR receipts — delivery and backup gates

**Status:** Gates measured on production 2026-11-23 (issue #17). This document
records the evidence; the reproducible commands are named so the measurements
can be repeated.

## Scope

Accounting receipts (Belege) are stored encrypted on the private `local` disk
and delivered only through authenticated, brand-scoped management routes. This
document covers the delivery, storage and backup/restore gates from issue #17.
The cryptographic storage contract is
[`document-context-binding.md`](document-context-binding.md); the secret
handling is [`env-hardening.md`](env-hardening.md).

## Delivery is never anonymous

The EÜR module was rolled out to production 2026-11-23. All 51
`/api/management/ea/*` routes sit behind the authenticated `management`
prefix. Measured on the host with anonymous `curl` (control request in
parentheses):

```
401  /api/management/ea/documents
401  /api/management/ea/documents/1/download
401  /api/management/ea/summary
200  /api/ping
```

Reproduce:

```sh
for p in /api/management/ea/documents /api/management/ea/documents/1/download /api/management/ea/summary; do
  curl -s -o /dev/null -w '%{http_code}  ' "https://portal.reisinger.pictures${p}"; echo "$p";
done
```

Tenant scoping is enforced in code (a foreign brand's document is a `404`) and
covered by `backend/tests/Feature/Ea/EaDocumentApiTest.php` and
`EaDocumentEncryptionApiTest.php` — reproduced locally 2026-11-23: the EÜR
suite passed 221 tests / 1387 assertions.

## Storage path and backup coverage

Receipts are written to `storage/app/private/ea/<brand>/` on the `local`
disk, which resolves to
`/home/webadmin/websites/api-portal.reisinger.pictures/storage/app/private/`
on the host. That tree is inside `SRC_WEBSITES` (`/home/webadmin/websites`),
which the nightly `/usr/local/bin/volume-backup.sh` copies to
`pCloud:Backup/strato-v2/sites/websites` in **Task 5**. Task 5 excludes
`vendor`, `node_modules`, `target`, `dist`, `build`, `.pnpm-store`, `.codegraph`,
`.git`, `ftp/**` and `.DS_Store` — **not** `storage/app/private`, so the
receipt folder is included. No extra named volume or bind-mount entry is
required; the existing website-source backup is responsible for it.

The nightly database dump for the receipt metadata is
`pCloud:Backup/strato-v2/db/portal/portal_db-<date>.sql.gz` (Task 1).

## Restore drill — measured result

A synthetic receipt was written through the **real** application code path
(`App\Support\BoundFileEncryption::store` with `FILE_ENCRYPTION_KEY` from the
host `.env`), copied to the backup destination, restored into the private
storage and verified with the real read path. All test artefacts were removed
afterwards.

Measured 2026-11-23:

| Check | Result |
|---|---|
| Backup object written | `166 B` at `…/sites/websites/api-portal.reisinger.pictures/storage/app/private/ea/rp/restore-drill-<sha>.pdf` |
| Ciphertext SHA-256 (source = restored) | `9e5caf26a411517f90cce69fd306c75cdb30eda11c8adef7740573d28645df24` (identical on both sides) |
| `FileEncrypter::isEncrypted` after restore | `true` |
| `BoundFileEncryption::read` (correct tenant/document context) | `true` — exact plaintext returned |
| Plaintext SHA-256 matches the stored id | `true` (`3b52c456ae50c194ca214b660f53732f0026378b4b3391e251d17307ded81d3c`) |
| Wrong document id | `DocumentContextMismatchException` (rejected) |

This proves read integrity (AES-256-GCM tag), content identity (SHA-256) and
storage context binding (KUBND envelope, wrong-id refusal) after a real
backup/restore round-trip. It uses a synthetic receipt because at measurement
time no owner receipt existed yet; the path, encryption and validation are the
production ones. A repeat with an owner's real receipt is not blocked by any
code change.

## Host access — no blind key acceptance

The host key for `reisinger.pictures` was already present in the local
`known_hosts`; SSH with `StrictHostKeyChecking=yes` and `BatchMode=yes`
succeeded (`HOSTKEY_OK`). Verified fingerprints:

```
reisinger.pictures ED25519 SHA256:DX2uytdOQhTNQHWT7srFDoTfdTC6vDAEbmlcJ3HLht0
```

No `StrictHostKeyChecking=no` or host-key bypass was used at any point.

## CSP mirror, secrets and deployment order

- **CSP mirror:** `./scripts/check-csp-policy.sh` exits 0 against the host;
  it reads the main `Caddyfile` plus the imported `conf/*.caddy` fragments
  (see [`content-security-policy.md`](content-security-policy.md)).
- **Secrets:** `FILE_ENCRYPTION_KEY` and `FILE_ENCRYPTION_PREVIOUS_KEYS` are set
  in the host `.env` and passed into the container; they are never in Git.
  Per owner decision [D-4](../owner-decisions.md#d-4-production-secret-handling)
  no secret manager is used — the production `.env` stays gitignored,
  unencrypted and unrotated. The issue #17 checklist item "Secrets aus
  Secret-Manager" is therefore **not** implemented by owner decision.
- **TLS/mTLS to a Prüfdienst:** no external verification service is deployed and
  **no server-to-server contract exists yet**. The pinned OpenAPI document is
  reference information, not a portal API contract
  ([D-71](../owner-decisions.md#d-71-the-pinned-openapi-contract-is-reference-information-not-a-portal-api-contract));
  the service-token scope requirement was removed
  ([D-72](../owner-decisions.md#d-72-no-service-to-service-scope-tokens-for-the-ku-online-client)).
  A future S2S integration needs its own decision and contract. There is no mTLS
  endpoint to measure, so this gate stays open until such a service and contract
  exist.
- **Deployment order:** build the frontend (`pnpm lint:fix && pnpm build`),
  `./sync.sh` (backend + `dist`), `composer install` on the host when
  `composer.lock` changed (the `composer_init` service), restart `backend`
  (runs `migrate --force`), then health check (`/api/ping`) and a request
  exercising the changed routes. Details in
  [`deployment/README.md`](../../deployment/README.md).
