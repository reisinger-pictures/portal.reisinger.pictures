<?php

namespace App\Services;

use App\Jobs\DeleteModelFilesJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ModelFileCleanupService
{
    public function __construct(private readonly ModelFileStore $fileStore) {}

    /**
     * Queue deletion after the current transaction commits.
     *
     * The database queue is the durable retry path in the normal deployment. A
     * sync-queue development/test run still gets deterministic deletion, while
     * dispatch/engine failures are recorded instead of being reported as a
     * successful cleanup.
     *
     * @param  string|array<int, string>  $paths
     */
    public function afterCommit(string|array $paths, string $reason, ?string $customerId = null): void
    {
        $paths = $this->normalizePaths($paths);
        if ($paths === []) {
            return;
        }

        $dispatch = function () use ($paths, $reason, $customerId): void {
            try {
                DeleteModelFilesJob::dispatch($paths, $reason, $customerId);
                Log::info('model.file_cleanup.queued', [
                    'customer_id' => $customerId,
                    'reason' => $reason,
                    'path_count' => count($paths),
                ]);
            } catch (Throwable $exception) {
                Log::error('model.file_cleanup.dispatch_failed', [
                    'customer_id' => $customerId,
                    'reason' => $reason,
                    'path_count' => count($paths),
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        };

        try {
            $connection = DB::connection();
            if ($connection->transactionLevel() > 0) {
                $connection->afterCommit($dispatch);
            } else {
                $dispatch();
            }
        } catch (Throwable $exception) {
            Log::error('model.file_cleanup.defer_failed', [
                'customer_id' => $customerId,
                'reason' => $reason,
                'path_count' => count($paths),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
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

        try {
            DeleteModelFilesJob::dispatch($paths, $reason, $customerId);
        } catch (Throwable $exception) {
            Log::error('model.file_cleanup.retry_dispatch_failed', [
                'customer_id' => $customerId,
                'reason' => $reason,
                'path_count' => count($paths),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
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
