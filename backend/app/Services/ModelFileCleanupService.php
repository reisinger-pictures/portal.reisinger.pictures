<?php

namespace App\Services;

use App\Jobs\CrmCleanupOutboxJob;
use App\Jobs\DeleteModelFilesJob;
use Illuminate\Support\Facades\Log;
use Throwable;

class ModelFileCleanupService
{
    public function __construct(
        private readonly ModelFileStore $fileStore,
        private readonly DurableDispatchService $dispatch,
    ) {}

    /**
     * Queue deletion after the current transaction commits.
     *
     * The database queue is the durable retry path in the normal deployment. If
     * dispatch itself fails, a UUID-keyed CRM cleanup outbox row is written to
     * the existing jobs table so the worker can retry it. A sync-queue
     * development/test run still gets deterministic deletion.
     *
     * @param  string|array<int, string>  $paths
     */
    public function afterCommit(string|array $paths, string $reason, ?string $customerId = null): void
    {
        $paths = $this->normalizePaths($paths);
        if ($paths === []) {
            return;
        }

        $this->dispatch->afterCommit(
            new DeleteModelFilesJob($paths, $reason, $customerId),
            CrmCleanupOutboxJob::forFileCleanup($paths, $reason, $customerId),
            'model.file_cleanup',
            [
                'customer_id' => $customerId,
                'reason' => $reason,
                'path_count' => count($paths),
            ],
        );
    }

    /**
     * Best-effort rollback cleanup for a newly stored file.
     *
     * A failed unlink is logged and handed to the same durable job. The original
     * transaction exception is deliberately not replaced by a cleanup exception.
     *
     * @param  string|array<int, string>  $paths
     */
    public function immediately(string|array $paths, string $reason, ?string $customerId = null): void
    {
        $paths = $this->normalizePaths($paths);
        if ($paths === []) {
            return;
        }

        try {
            $this->fileStore->delete($paths);

            return;
        } catch (Throwable $exception) {
            Log::error('model.file_cleanup.immediate_failed', [
                'customer_id' => $customerId,
                'reason' => $reason,
                'path_count' => count($paths),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        $this->dispatch->dispatchNow(
            new DeleteModelFilesJob($paths, $reason, $customerId),
            CrmCleanupOutboxJob::forFileCleanup($paths, $reason, $customerId),
            'model.file_cleanup',
            [
                'customer_id' => $customerId,
                'reason' => $reason,
                'path_count' => count($paths),
            ],
        );
    }

    /**
     * @param  string|array<int, string>  $paths
     * @return array<int, string>
     */
    private function normalizePaths(string|array $paths): array
    {
        $paths = is_array($paths) ? $paths : [$paths];

        return array_values(array_unique(array_filter(
            $paths,
            static fn (mixed $path): bool => is_string($path) && $path !== '',
        )));
    }
}
