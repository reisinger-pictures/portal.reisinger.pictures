<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleanupTempFiles extends Command
{
    private const MAX_FAILURE_DETAILS = 20;

    protected $signature = 'app:cleanup-temp';

    protected $description = 'Löscht temporäre Dateien (Caches & Artefakte), die älter als 24 Stunden sind.';

    private int $deletedCount = 0;

    private int $failedCount = 0;

    /**
     * @var array<int, array{path: string, reason: string, exception?: string, message?: string}>
     */
    private array $failureDetails = [];

    public function handle(): int
    {
        $this->deletedCount = 0;
        $this->failedCount = 0;
        $this->failureDetails = [];

        $tempDir = (string) config('filesystems.temp_dir', storage_path('app/private/temp'));

        if (is_dir($tempDir)) {
            try {
                $files = File::allFiles($tempDir);
            } catch (Throwable $exception) {
                $this->recordFailure($tempDir, 'listing_failed', $exception);
                $files = [];
            }

            $cutoffTimestamp = now()->subDay()->getTimestamp();

            foreach ($files as $file) {
                $path = $file->getPathname();

                // Lösche Dateien, die älter als 24 Stunden sind.
                if ($file->getMTime() >= $cutoffTimestamp) {
                    continue;
                }

                try {
                    $deleted = File::delete($path);

                    if (! $deleted) {
                        $this->recordFailure($path, 'delete_returned_false');

                        continue;
                    }

                    // A successful adapter return is not enough if a partially
                    // failed driver left the file behind.
                    if (File::exists($path)) {
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
            $failureDetails = array_slice($this->failureDetails, 0, self::MAX_FAILURE_DETAILS);
            Log::error('Automated cleanup: temp-file deletion failures', [
                'deleted_count' => $this->deletedCount,
                'failed_count' => $this->failedCount,
                'failures' => $failureDetails,
                'failures_omitted' => $this->failedCount - count($failureDetails),
            ]);

            $failureLabel = $this->failedCount === 1 ? 'Löschvorgang' : 'Löschvorgänge';
            $this->error(
                "Temp-Ordner bereinigt: {$this->deletedCount} alte Dateien gelöscht; "
                ."{$this->failedCount} {$failureLabel} fehlgeschlagen."
            );

            return self::FAILURE;
        }

        $this->info("Temp-Ordner bereinigt: {$this->deletedCount} alte Dateien gelöscht.");
        Log::info("Temp-Ordner bereinigt: {$this->deletedCount} alte Dateien gelöscht.");

        return self::SUCCESS;
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
}
