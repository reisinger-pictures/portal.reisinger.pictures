<?php

namespace App\Jobs;

use App\Enums\FtpImportStatus;
use App\Models\User;
use App\Services\FtpInboxImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Imports one batch of the FTP inbox and re-enqueues itself while work remains (D-36).
 *
 * This is the background half of D-36: an SFTPGo upload event (or the one-shot
 * catch-up run when the photographer opens the FTP page) starts the chain, and
 * the chain drains the inbox in `ftp.import.max_files`-sized batches without a
 * single further click.
 *
 * It calls `FtpInboxImporter::importBatch()` — the same method the HTTP trigger
 * calls — so the budgets, the staging, the destination cleanup and the D-37
 * quarantine cannot differ between the two entry points.
 *
 * ## Why the chain ends, always
 *
 * A self-re-enqueueing job is an unbounded loop unless every exit is named. The
 * exits are:
 *
 * 1. **Nothing left to do.** An empty result (no inbox, no importable file) ends
 *    the chain.
 * 2. **A rejected batch.** 413/422/unreadable/held lock/missing target import
 *    nothing, so re-enqueueing would select the very same files again. The chain
 *    stops and logs; the next event or page open starts a fresh one.
 * 3. **No progress.** A run that reports a remainder but neither imported nor
 *    quarantined anything has done nothing the next run would not repeat. This
 *    is the guard against "counting an attempt" being mistaken for progress.
 * 4. **`ftp.import.chain_max_batches`.** A hard cap per trigger (default 25
 *    batches = 500 files). Reached only with work left, so it is logged as a
 *    warning — it means a second trigger is needed, not that data was lost.
 * 5. **`ftp.import.chain_max_failures`.** Exceptions (storage full, database
 *    down) are retried by `failed()`, bounded by the counter carried in the job
 *    payload (default 3 attempts). Only thrown failures count: a *rejected* file
 *    is case 2 and must not be retried, or a permanently broken upload would
 *    spin forever.
 *
 * ## Why two photographers cannot double-import
 *
 * There is **one** lock, and it lives in the importer, not on this job:
 * `Cache::lock('ftp-process:{user}')` (P1-M28, unchanged) serializes the
 * read → copy → Photo row → unlink sequence itself, for the background chain and
 * for the manual HTTP trigger alike. It is keyed per account, so a second
 * photographer is never blocked by the first, and it is non-blocking: the run
 * that loses the race gets `FtpImportStatus::Locked` and this job ends the chain
 * with a **named** warning (`handle()` below). No work is lost and nothing ends
 * silently.
 *
 * ## Why there is no `WithoutOverlapping` middleware on the job
 *
 * An earlier revision guarded the job with
 * `WithoutOverlapping('ftp-import:{user}')->dontRelease()`. That is a silent
 * chain-breaker under `QUEUE_CONNECTION=sync`: the self-dispatch below runs
 * **inline, while this job still holds the middleware lock**, so the inner job's
 * middleware cannot acquire it and `dontRelease()` drops the job without running
 * `handle()`, without a log line and without a released job — the chain stops
 * after one batch and the files stay, while the outside sees a success. It is
 * invisible in production (exactly one worker) and immediate locally, in CI and
 * in the E2E stack, and a second worker reproduces it in production too.
 *
 * The importer's lock alone is sufficient and never silent, so the middleware is
 * gone rather than merely logged: `FtpImportBatchTest` pins both the sync-queue
 * drain (50 files in three batches) and the visible lock exit.
 */
class ImportFtpBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * One attempt, no queue-level retry.
     *
     * Laravel's retry would re-run the batch on its own schedule with no memory
     * of how often it already failed. The chain owns that decision instead
     * (`failed()` below), so the bound is per trigger and observable in the
     * payload rather than implicit in the queue configuration.
     */
    public int $tries = 1;

    /**
     * @param  string  $userId  the photographer whose inbox is drained
     * @param  int  $batchNumber  the 1-based position of this run in the chain
     * @param  int  $failedBatches  how many runs of this chain already threw
     * @param  bool  $uploadEvent  whether this job was started by an SFTPGo upload event
     */
    public function __construct(
        public readonly string $userId,
        public readonly int $batchNumber = 1,
        public readonly int $failedBatches = 0,
        public readonly bool $uploadEvent = false,
    ) {}

    /**
     * No queue middleware.
     *
     * The only guard this job needs is the importer's lock, which the importer
     * takes and releases inside `importBatch()` — not across the self-dispatch.
     * A job-level `WithoutOverlapping` would be held across the inline
     * self-dispatch under `sync` and would drop the next batch silently; see the
     * class docblock. Returning an empty list is deliberate and pinned by
     * `FtpImportBatchTest::test_the_job_declares_no_overlap_middleware`.
     */
    public function middleware(): array
    {
        return [];
    }

    public function handle(FtpInboxImporter $importer): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            // The account was deleted between the event and the run. Nothing to
            // import into and nobody to tell: the chain ends.
            Log::info('FTP-Import-Kette beendet: Konto existiert nicht mehr.', [
                'user_id' => $this->userId,
                'batch' => $this->batchNumber,
            ]);

            return;
        }

        if ($this->uploadEvent) {
            // Mark before selecting or acquiring the import lock. If another
            // batch owns it, that batch sees the generation change after unlock
            // and carries the event through its already-bounded chain.
            $importer->markUploadEvent($user);
        }

        $result = $importer->importBatch($user);

        if ($result->status === FtpImportStatus::Imported || $result->status === FtpImportStatus::Empty) {
            $this->continueOrFinish(
                $importer,
                $result->hasRemaining,
                $result->isProgress() || $result->eventHandoff,
                $result->imported,
                $result->quarantined,
                resetFailureCount: $result->isProgress(),
            );

            return;
        }

        if ($result->status === FtpImportStatus::Locked) {
            // The named lock exit. Another run of this account is draining the
            // same inbox, so the files are not lost — but this chain must say so
            // instead of vanishing. `quarantined` is carried because the losing
            // run may already have moved a file aside during selection; without
            // it that move would be invisible in the log.
            Log::warning('FTP-Import-Kette beendet: ein anderer Import dieses Kontos hält die Sperre; die restlichen Dateien verarbeitet der laufende Import.', [
                'user_id' => $this->userId,
                'batch' => $this->batchNumber,
                'error' => $result->error,
                'imported' => $result->imported,
                'quarantined' => $result->quarantined,
            ]);

            return;
        }

        Log::warning('FTP-Import-Kette beendet: Batch abgelehnt.', [
            'user_id' => $this->userId,
            'batch' => $this->batchNumber,
            'status' => $result->status->value,
            'error' => $result->error,
            'imported' => $result->imported,
            'quarantined' => $result->quarantined,
        ]);

        if ($result->eventHandoff) {
            // Keep the rejection observable, but do not strand an upload event
            // that arrived during this locked run. The follow-up is bounded by
            // the same batch limit as successful continuation. A handoff alone
            // is not physical progress and must not reset the thrown-failure
            // budget; an import or quarantine does reset it.
            $this->continueOrFinish(
                $importer,
                $result->hasRemaining,
                true,
                $result->imported,
                $result->quarantined,
                resetFailureCount: $result->isProgress(),
            );
        }
    }

    /**
     * Retry a thrown failure, bounded by `ftp.import.chain_max_failures`.
     *
     * The counter travels in the payload of the *next* job, so the bound holds
     * even if the worker restarts between two attempts. A success resets it
     * (see `continueOrFinish()`): a chain that made progress and then hit a
     * transient error gets its full budget again.
     */
    public function failed(?Throwable $exception): void
    {
        $limits = app(FtpInboxImporter::class)->chainLimits();
        $attempts = $this->failedBatches + 1;

        if ($attempts >= $limits['max_failures']) {
            Log::error('FTP-Import-Kette nach wiederholtem Fehlschlag abgebrochen.', [
                'user_id' => $this->userId,
                'batch' => $this->batchNumber,
                'failed_attempts' => $attempts,
                'max_failures' => $limits['max_failures'],
                'exception' => $exception?->getMessage(),
            ]);

            return;
        }

        Log::warning('FTP-Import-Batch fehlgeschlagen; die Kette versucht es erneut.', [
            'user_id' => $this->userId,
            'batch' => $this->batchNumber,
            'failed_attempts' => $attempts,
            'max_failures' => $limits['max_failures'],
            'exception' => $exception?->getMessage(),
        ]);

        // The batch number does not advance: a retried attempt is the same run,
        // not progress. Only `chain_max_failures` bounds this path.
        self::dispatch($this->userId, $this->batchNumber, $attempts);
    }

    /**
     * Decide whether the chain continues after progress or an event handoff.
     */
    private function continueOrFinish(
        FtpInboxImporter $importer,
        bool $hasRemaining,
        bool $progressed,
        int $imported,
        int $quarantined,
        bool $resetFailureCount = true,
    ): void {
        if (! $hasRemaining) {
            Log::info('FTP-Import-Kette abgeschlossen.', [
                'user_id' => $this->userId,
                'batches' => $this->batchNumber,
                'last_batch_imported' => $imported,
                'last_batch_quarantined' => $quarantined,
            ]);

            return;
        }

        if (! $progressed) {
            // A remainder with neither an import nor a quarantine: the next run
            // would do exactly the same nothing. Ending the chain here is the
            // rule from D-36 — a batch that cannot import must not re-enqueue.
            Log::warning('FTP-Import-Kette beendet: Batch hat nichts importiert und nichts quarantänisiert.', [
                'user_id' => $this->userId,
                'batch' => $this->batchNumber,
            ]);

            return;
        }

        $limits = $importer->chainLimits();

        if ($this->batchNumber >= $limits['max_batches']) {
            Log::warning('FTP-Import-Kette am Batch-Limit gestoppt; die Inbox ist nicht leer.', [
                'user_id' => $this->userId,
                'batches' => $this->batchNumber,
                'max_batches' => $limits['max_batches'],
                'last_batch_imported' => $imported,
                'last_batch_quarantined' => $quarantined,
            ]);

            return;
        }

        // Physical progress resets a transient failure budget from an earlier
        // run. An external event alone continues the bounded chain without
        // erasing that budget.
        self::dispatch($this->userId, $this->batchNumber + 1, $resetFailureCount ? 0 : $this->failedBatches);
    }
}
