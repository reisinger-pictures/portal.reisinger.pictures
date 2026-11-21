# Document context binding (encrypted storage)

**Status:** Contract defined; code and unit tests added in this change. The
backend quality gate runs in CI — this host does not execute backend suites.

## Threat model

Accounting receipts will be stored as encrypted files on the private `local`
disk via `ercsctt/laravel-file-encryption` (AES-256-GCM, chunked). The package
authenticates each chunk with the chunk index as its **only** AAD
(`pack('N', $chunkIndex)` in `encryptChunk()` / `decryptChunk()`). That detects
reordering but knows nothing about the tenant or the document: someone holding
a DB dump or a backup can copy one tenant's ciphertext into another tenant's
storage slot (or repoint a different document at the same blob) and the GCM
tags still verify. The caller would then receive another party's plaintext.

`BoundFileEncryption` closes that gap at the application layer by storing a
versioned context header **inside** the encrypted payload and requiring an
exact match on both IDs at read time. Mismatch, missing/invalid envelope and
decryption failure all raise `DocumentContextMismatchException`; there is no
code path that delivers the bytes anyway.

The binding is independent of, and does not replace:

- **authorization** — the caller must still establish that the requesting user
  may read the document; the binding only proves the ciphertext is in its
  intended slot,
- **A-Trust PDF signatures** — those vouch for who signed the document's
  content,
- **EUR-DATA-HASH** — that vouches for content identity, not storage placement.

Out of scope by design: a caller who controls both the ciphertext and the key
can mint a new envelope; a file replaced *within the same tenant and document
context* is indistinguishable here. Both need separate controls (key hygiene,
content hashes/signatures). Plaintext necessarily exists transiently in PHP
memory during write and read.

## Format

The bytes handed to `FileEncrypter::encryptFile()` are:

```
KUBND:v1:{"tenant_id":"<tenant>","document_id":"<document>"}\n<raw document bytes>
```

- `KUBND:v1:` — fixed, versioned prefix; an unknown version is rejected.
- one single-line JSON object with exactly the two string keys `tenant_id`
  and `document_id`, both non-empty; terminated by the first `\n`.
- everything after that newline is the payload — opaque binary, possibly empty.

Because the header lives inside the GCM ciphertext, an attacker with only a
dump/backup cannot edit the bound IDs without breaking authentication. The
version field leaves room for a future envelope migration without silently
accepting a foreign format.

## Call contract

- `App\Support\BoundFileEncryption::store(string $path, string $plainContents, string $tenantId, string $documentId): void`
  — non-empty IDs; writes to `Storage::disk('local')` at `$path` (an existing
  file at that path is replaced, so callers use their own unique paths). The
  envelope is written to a temporary `.plain-<random>` file with mode `0600`
  and removed in a `finally` block; the marker lets
  `ModelFileStore::cleanupOrphanedTempFiles()` sweep it after a hard crash.
- `read(string $path, string $tenantId, string $documentId): string` — both
  values must match the stored header byte-exactly (`hash_equals`). Fails
  closed with `App\Exceptions\DocumentContextMismatchException` on any
  deviation, invalid/missing envelope, missing file or decrypt error. No
  exception message contains document plaintext or the bound/expected
  tenant/document ID values; messages name only the caller-supplied storage path.
- `isBound(string $path): bool` — format probe only: true when a well-formed
  v1 envelope is present, false for legacy/unbound, corrupted or
  undecryptable files. It performs no context check and must not be used as
  an authorization decision.

Pass IDs from server-side state (tenant resolved from the session/token,
document ID from the database row), never from an untrusted request field, and
keep them stable for the lifetime of the stored file.

`ModelFileStore` is unchanged; it remains the contract for model photos and
age proofs. This class is additive and intended for the upcoming
bookkeeping/receipt storage only.

## Gates

- Unit tests: `backend/tests/Unit/Support/BoundFileEncryptionTest.php`. They
  cover the exact-byte roundtrip, wrong tenant, wrong document, ciphertext
  relocation, malformed envelopes (no plaintext leak), decrypt failure under a
  rotated key, absence of plaintext and temp files on disk, empty-ID rejection
  and the multi-chunk path. They use
  the documented PHPUnit test key (base64 of 32 `X` bytes) and the same
  `FileEncrypter::clearResolvedInstances()` reset as `ModelFileEncryptionTest`.
- Scoped run: `cd backend && php artisan test --filter BoundFileEncryptionTest`.
  The full backend gate `php artisan test` remains authoritative.
- Static checks for this change: `php -l` on every new PHP file and
  `git diff --check`. No suite was run on this host.
