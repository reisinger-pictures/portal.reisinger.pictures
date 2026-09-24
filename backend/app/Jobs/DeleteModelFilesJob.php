<?php

namespace App\Jobs;

use App\Services\ModelFileStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeleteModelFilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120, 300, 600];

    /** @var array<int, string> */
    protected array $paths;

    protected string $reason;

    protected ?string $customerId;

    /**
     * @param  array<int, string>  $paths
     */
    public function __construct(array $paths, string $reason, ?string $customerId = null)
    {
        $this->paths = array_values(array_unique(array_filter(
            $paths,
            static fn (mixed $path): bool => is_string($path) && $path !== '',
        )));
        $this->reason = $reason;
        $this->customerId = $customerId;
    }

    public function handle(ModelFileStore $fileStore): void
    {
        if ($this->paths === []) {
            return;
        }

        $fileStore->delete($this->paths);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('model.file_cleanup.failed', [
            'customer_id' => $this->customerId,
            'reason' => $this->reason,
            'path_count' => count($this->paths),
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
