<?php

namespace App\Support;

use App\Exceptions\DocumentContextMismatchException;
use Ercsctt\FileEncryption\Facades\FileEncrypter;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Envelope-level context binding for encrypted accounting documents.
 *
 * The file-encryption package authenticates each chunk with the chunk index as
 * its only AAD (`pack('N', $chunkIndex)` in encryptChunk/decryptChunk). That is
 * enough to detect reordering, but it cannot tie a ciphertext to the tenant and
 * document it belongs to: copy the same ciphertext from one DB dump into
 * another tenant's storage and the GCM tags still verify. This class closes
 * that gap at the application layer.
 *
 * On write, a versioned, single-line JSON context header is prepended to the
 * plaintext *inside* the encrypted payload. The header therefore inherits the
 * AES-256-GCM integrity of the package: an attacker with only a DB dump or a
 * backup cannot rewrite the bound IDs without breaking authentication. On
 * read, the header is parsed and compared byte-exactly with the context the
 * caller supplies; any deviation, missing/invalid envelope or decryption error
 * raises {@see DocumentContextMismatchException}. There is no delivery path
 * that skips the check.
 *
 * This is deliberately independent of A-Trust PDF signatures and of
 * EUR-DATA-HASH: those vouch for the document's content, this vouches for the
 * storage slot the ciphertext is allowed to live in.
 *
 * Storage conventions follow ModelFileStore (private `local` disk, chunked
 * FileEncrypter, `.plain-` temp marker so the existing orphan sweep can clean
 * up after a crash). ModelFileStore itself is untouched.
 */
final class BoundFileEncryption
{
    public const DISK = 'local';

    private const PREFIX = 'KUBND:v1:';

    /**
     * Store plaintext under a context-bound, encrypted envelope.
     *
     * The plaintext only exists in a 0600 temporary file for the duration of
     * the encryption call; that file is always removed in the finally path.
     * The `.plain-` marker lets ModelFileStore::cleanupOrphanedTempFiles()
     * sweep it if the process dies before the finally block runs.
     *
     * @throws InvalidArgumentException when either context value is empty
     */
    public function store(string $path, string $plainContents, string $tenantId, string $documentId): void
    {
        self::assertContext($tenantId, $documentId);

        $header = json_encode([
            'tenant_id' => $tenantId,
            'document_id' => $documentId,
        ], JSON_THROW_ON_ERROR);

        $envelope = self::PREFIX.$header."\n".$plainContents;

        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory(dirname($path));

        $temporary = $path.'.plain-'.bin2hex(random_bytes(8));
        $disk->put($temporary, $envelope);

        try {
            if (! @chmod($disk->path($temporary), 0600)) {
                throw new RuntimeException('Could not restrict permissions on the temporary document file.');
            }

            FileEncrypter::encryptFile($disk->path($temporary), $disk->path($path));
        } finally {
            $disk->delete($temporary);
        }
    }

    /**
     * Read the plaintext only when the stored binding matches both IDs exactly.
     *
     * @throws DocumentContextMismatchException on any mismatch, missing or
     *                                          invalid envelope, or decrypt error
     */
    public function read(string $path, string $tenantId, string $documentId): string
    {
        $envelope = $this->decrypt($path);
        [$boundTenantId, $boundDocumentId, $contents] = $this->parseEnvelope($envelope, $path);

        // hash_equals keeps the comparison timing-safe; more importantly, both
        // sides must match — a partial match is still a refusal.
        if (! hash_equals($boundTenantId, $tenantId) || ! hash_equals($boundDocumentId, $documentId)) {
            throw DocumentContextMismatchException::contextMismatch($path);
        }

        return $contents;
    }

    /**
     * Whether the file carries a well-formed v1 binding envelope.
     *
     * A format probe only: it deliberately does not check any context value and
     * can be used to detect legacy/unbound files. A file that cannot be
     * decrypted (missing, wrong key, corrupted) is reported as unbound.
     */
    public function isBound(string $path): bool
    {
        try {
            $this->parseEnvelope($this->decrypt($path), $path);

            return true;
        } catch (DocumentContextMismatchException) {
            return false;
        }
    }

    private function decrypt(string $path): string
    {
        $absolute = Storage::disk(self::DISK)->path($path);

        if (! is_file($absolute)) {
            throw DocumentContextMismatchException::unreadable($path);
        }

        try {
            return FileEncrypter::decryptedContents($absolute);
        } catch (Throwable $e) {
            throw DocumentContextMismatchException::unreadable($path, $e);
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string} Tenant ID, document ID, payload
     */
    private function parseEnvelope(string $envelope, string $path): array
    {
        if (! str_starts_with($envelope, self::PREFIX)) {
            throw DocumentContextMismatchException::invalidEnvelope($path);
        }

        $headerStart = strlen(self::PREFIX);
        $newline = strpos($envelope, "\n", $headerStart);
        if ($newline === false) {
            throw DocumentContextMismatchException::invalidEnvelope($path);
        }

        try {
            $binding = json_decode(
                substr($envelope, $headerStart, $newline - $headerStart),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            throw DocumentContextMismatchException::invalidEnvelope($path);
        }

        if (! is_array($binding)
            || ! isset($binding['tenant_id'], $binding['document_id'])
            || ! is_string($binding['tenant_id']) || $binding['tenant_id'] === ''
            || ! is_string($binding['document_id']) || $binding['document_id'] === ''
        ) {
            throw DocumentContextMismatchException::invalidEnvelope($path);
        }

        return [$binding['tenant_id'], $binding['document_id'], substr($envelope, $newline + 1)];
    }

    private static function assertContext(string $tenantId, string $documentId): void
    {
        if ($tenantId === '' || $documentId === '') {
            throw new InvalidArgumentException('A bound document requires a non-empty tenant_id and document_id.');
        }
    }
}
