<?php

namespace App\Values;

use App\Enums\FtpImportStatus;

/**
 * The outcome of one import run, as a value object (D-35/D-36/D-37).
 *
 * Both callers of `FtpInboxImporter::importBatch()` read the same object instead
 * of one of them owning the shape: the controller maps the status to an HTTP
 * code, the job maps it to "continue" or "stop". `imported` and `quarantined`
 * are the two observable side effects of a run, and `hasRemaining` is the only
 * signal the chain needs to decide whether the inbox still holds work — a
 * second directory scan just to answer that question would be a second
 * implementation of the walk.
 *
 * `error` carries the finished German message, because the numbers it names
 * (`20,00 MiB`, `FTP_IMPORT_MAX_FILE_BYTES`) belong to the class that resolved
 * the limits. Rebuilding the sentence in the controller would let the message
 * and the enforced limit drift apart.
 */
final class FtpImportResult
{
    private function __construct(
        public readonly FtpImportStatus $status,
        public readonly int $imported = 0,
        public readonly int $quarantined = 0,
        public readonly bool $hasRemaining = false,
        public readonly ?string $error = null,
        public readonly bool $eventHandoff = false,
    ) {}

    /**
     * The run imported at least one photo.
     *
     * `hasRemaining` is true when the batch walk stopped at a limit instead of
     * at the end of the directory, or a concurrent upload event requested an
     * event handoff. It is deliberately optimistic in the exact-multiple case
     * (a directory holding precisely `max_files` files reports "remaining"
     * once); the extra run imports nothing, finds no work and ends the chain.
     */
    public static function imported(int $imported, int $quarantined, bool $hasRemaining): self
    {
        return new self(FtpImportStatus::Imported, $imported, $quarantined, $hasRemaining);
    }

    /**
     * Nothing was imported.
     *
     * `quarantined` can be non-zero here: a run whose only remaining files were
     * moved to quarantine has done its work and left an empty inbox, which is a
     * terminal state, not a rejection.
     */
    public static function empty(int $quarantined = 0): self
    {
        return new self(FtpImportStatus::Empty, 0, $quarantined, false);
    }

    /**
     * The run stopped before it could import (or after a partial import).
     *
     * `imported` is not always zero: a source that changes in the middle of the
     * import loop ends the run with the photos before it already written and
     * unlinked. That is why the field exists on a rejection too.
     */
    public static function rejected(FtpImportStatus $status, string $error, int $imported = 0, int $quarantined = 0): self
    {
        return new self($status, $imported, $quarantined, false, $error);
    }

    /** Return this outcome with an event-triggered follow-up batch requested. */
    public function withEventHandoff(): self
    {
        return new self($this->status, $this->imported, $this->quarantined, true, $this->error, true);
    }

    /**
     * Whether the run changed something an observer can see.
     *
     * This is the chain's progress test. Counting a failed attempt against a
     * file is deliberately **not** progress: a run that only incremented an
     * attempt counter imported nothing and must not re-enqueue itself, or a
     * permanently broken file would spin the chain.
     */
    public function isProgress(): bool
    {
        return $this->imported > 0 || $this->quarantined > 0;
    }
}
