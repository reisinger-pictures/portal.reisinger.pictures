<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A bound document was refused under the context the caller claims.
 *
 * One exception type covers a context mismatch, a missing or malformed binding
 * envelope and a decryption failure on purpose: callers must not be able to
 * tell "wrong context" from "wrong key" and then fall back to delivering the
 * bytes anyway. Every path through BoundFileEncryption::read() fails closed.
 *
 * The message only ever names the caller-supplied storage path — never
 * document plaintext and never the bound or expected tenant/document IDs.
 */
final class DocumentContextMismatchException extends RuntimeException
{
    public static function contextMismatch(string $path): self
    {
        return new self("Document context mismatch for [{$path}]; refusing to read.");
    }

    public static function invalidEnvelope(string $path): self
    {
        return new self("Document at [{$path}] has no valid context binding envelope; refusing to read.");
    }

    public static function unreadable(string $path, ?Throwable $previous = null): self
    {
        return new self("Document at [{$path}] could not be decrypted; refusing to read.", 0, $previous);
    }
}
