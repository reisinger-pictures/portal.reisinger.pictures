<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class InvalidateWatermarkCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const CACHE_PATHS = [
        '_watermarked',
        '_thumbs/_watermarked',
    ];

    private const MAX_FAILURE_DETAILS = 20;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120];

    private int $deletedCount = 0;

    private int $failedCount = 0;

    /**
     * @var array<int, array{path: string, reason: string, exception?: string, message?: string}>
     */
    private array $failureDetails = [];

    public function handle(): void
    {
        $this->deletedCount = 0;
        $this->failedCount = 0;
        $this->failureDetails = [];

        try {
            $disk = Storage::disk('photos');
            $directories = $disk->directories();
        } catch (Throwable $exception) {
            $this->recordFailure('photo directories', 'listing_failed', $exception);
            $this->logFailure();

            throw new RuntimeException('Failed to list watermark cache directories', 0, $exception);
        }

        foreach ($directories as $dir) {
            $dir = (string) $dir;

            // Only gallery folders (UUIDs) contain generated watermark caches.
            if (! Str::isUuid($dir)) {
                continue;
            }

            foreach (self::CACHE_PATHS as $cachePath) {
                $path = $dir.'/'.$cachePath;

                try {
                    $deleted = $disk->deleteDirectory($path);

                    if (! $deleted) {
                        $this->recordFailure($path, 'delete_returned_false');

                        continue;
                    }

                    // Verify the result because the photos disk is configured
                    // with `throw => false` and may report a partial deletion.
                    if ($disk->exists($path)) {
                        $this->recordFailure($path, 'path_still_exists');

                        continue;
                    }

                    $this->deletedCount++;
                } catch (Throwable $exception) {
                    $this->recordFailure($path, 'delete_threw', $exception);
                }
            }
        }

        if ($this->failedCount > 0) {
            $this->logFailure();

            throw new RuntimeException("Failed to delete {$this->failedCount} watermark cache directories");
        }

        Log::info('Automated cleanup: watermark cache cleanup completed', [
            'deleted_count' => $this->deletedCount,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $failureDetails = array_slice($this->failureDetails, 0, self::MAX_FAILURE_DETAILS);

        Log::error('Queue job failed', [
            'job' => self::class,
            'failed_count' => $this->failedCount,
            'failures' => $failureDetails,
            'failures_omitted' => max(0, $this->failedCount - count($failureDetails)),
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    private function recordFailure(string $path, string $reason, ?Throwable $exception = null): void
    {
        $this->failedCount++;

        if (count($this->failureDetails) >= self::MAX_FAILURE_DETAILS) {
            return;
        }

        $detail = [
            'path' => $path,
            'reason' => $reason,
        ];

        if ($exception !== null) {
            $detail['exception'] = $exception::class;
            $detail['message'] = $exception->getMessage();
        }

        $this->failureDetails[] = $detail;
    }

    private function logFailure(): void
    {
        $failureDetails = array_slice($this->failureDetails, 0, self::MAX_FAILURE_DETAILS);

        Log::error('Automated cleanup: watermark cache deletion failures', [
            'deleted_count' => $this->deletedCount,
            'failed_count' => $this->failedCount,
            'failures' => $failureDetails,
            'failures_omitted' => $this->failedCount - count($failureDetails),
        ]);
    }
}
