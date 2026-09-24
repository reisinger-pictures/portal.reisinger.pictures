<?php

namespace App\Jobs;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class SyncCustomerSearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const INDEX = 'index';

    public const REMOVE = 'remove';

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120, 300, 600];

    protected string $customerId;

    protected string $operation;

    public function __construct(string $customerId, string $operation)
    {
        if (! in_array($operation, [self::INDEX, self::REMOVE], true)) {
            throw new InvalidArgumentException("Unsupported customer search operation: {$operation}");
        }

        $this->customerId = $customerId;
        $this->operation = $operation;
    }

    public function customerId(): string
    {
        return $this->customerId;
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function handle(): void
    {
        if ($this->operation === self::REMOVE) {
            $this->removeFromIndex();

            return;
        }

        $customer = Customer::find($this->customerId);
        if ($customer === null) {
            // A queued index job may run after a later hard delete. Remove the
            // stale document instead of silently retaining PII.
            $this->removeFromIndex();

            return;
        }

        $customer->searchableSync();
    }

    public function failed(Throwable $exception): void
    {
        Log::error('customer.search_sync.failed', [
            'customer_id' => $this->customerId,
            'operation' => $this->operation,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    private function removeFromIndex(): void
    {
        $customer = new Customer;
        $customer->setAttribute($customer->getKeyName(), $this->customerId);
        $customer->unsearchableSync();
    }
}
