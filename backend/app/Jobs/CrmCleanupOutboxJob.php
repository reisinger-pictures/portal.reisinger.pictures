<?php

namespace App\Jobs;

use App\Services\ModelFileStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Durable fallback for a CRM cleanup dispatch that could not reach the queue.
 *
 * The row is written directly to the existing database jobs table by
 * DurableDispatchService. It runs the same underlying operation as the
 * original queue job, while keeping the fallback attempt and terminal failure
 * independently auditable.
 */
final class CrmCleanupOutboxJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public const FILE_CLEANUP = 'file_cleanup';

    public const CUSTOMER_SEARCH = 'customer_search';

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120, 300, 600];

    protected string $operation;

    /** @var array<int, string> */
    protected array $paths;

    protected string $reason;

    protected ?string $customerId;

    protected string $searchOperation;

    /**
     * @param  array<int, string>  $paths
     */
    private function __construct(
        string $operation,
        array $paths,
        string $reason,
        ?string $customerId,
        string $searchOperation,
    ) {
        $this->operation = $operation;
        $this->paths = $paths;
        $this->reason = $reason;
        $this->customerId = $customerId;
        $this->searchOperation = $searchOperation;
    }

    /**
     * @param  array<int, string>  $paths
     */
    public static function forFileCleanup(array $paths, string $reason, ?string $customerId = null): self
    {
        return new self(
            self::FILE_CLEANUP,
            array_values(array_unique(array_filter(
                $paths,
                static fn (mixed $path): bool => is_string($path) && $path !== '',
            ))),
            $reason,
            $customerId,
            '',
        );
    }

    public static function forCustomerSearch(string $customerId, string $operation): self
    {
        return new self(
            self::CUSTOMER_SEARCH,
            [],
            '',
            $customerId,
            $operation,
        );
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function customerId(): ?string
    {
        return $this->customerId;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * @return array<int, string>
     */
    public function paths(): array
    {
        return $this->paths;
    }

    public function searchOperation(): string
    {
        return $this->searchOperation;
    }

    public function handle(ModelFileStore $fileStore): void
    {
        $job = $this->underlyingJob();

        if ($job instanceof DeleteModelFilesJob) {
            $job->handle($fileStore);
        } else {
            $job->handle();
        }

        Log::info('crm.cleanup_outbox.completed', $this->auditContext());
    }

    public function failed(Throwable $exception): void
    {
        Log::error('crm.cleanup_outbox.failed', array_merge($this->auditContext(), [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]));

        try {
            $this->underlyingJob()->failed($exception);
        } catch (Throwable $auditException) {
            Log::error('crm.cleanup_outbox.underlying_audit_failed', [
                'operation' => $this->operation,
                'customer_id' => $this->customerId,
                'exception' => $auditException::class,
                'message' => $auditException->getMessage(),
            ]);
        }
    }

    private function underlyingJob(): DeleteModelFilesJob|SyncCustomerSearchJob
    {
        if ($this->operation === self::FILE_CLEANUP) {
            return new DeleteModelFilesJob($this->paths, $this->reason, $this->customerId);
        }

        return new SyncCustomerSearchJob((string) $this->customerId, $this->searchOperation);
    }

    /**
     * @return array<string, int|string|null>
     */
    private function auditContext(): array
    {
        return [
            'operation' => $this->operation,
            'customer_id' => $this->customerId,
            'reason' => $this->reason,
            'path_count' => count($this->paths),
            'search_operation' => $this->searchOperation === '' ? null : $this->searchOperation,
        ];
    }
}
