<?php

namespace App\Services;

use App\Jobs\SyncCustomerSearchJob;
use App\Models\Customer;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Defers customer search side effects until the surrounding database commit.
 *
 * Search engines are not transactional. Updating Scout while a CRM transaction is
 * still open can therefore publish an e-mail/profile state which is subsequently
 * rolled back. The queued job contains only the customer id and reads the
 * canonical row when it runs, so a retry cannot resurrect stale data.
 */
class CustomerSearchSyncService
{
    /**
     * Run a customer write without Scout's model observer.
     */
    public function withoutSync(Closure $callback): mixed
    {
        return Customer::withoutSyncingToSearch($callback);
    }

    /**
     * Queue an index or remove operation after the current commit.
     *
     * Dispatch failures are logged instead of being allowed to turn a committed
     * CRM request into a false 500. With the database queue the job is durable;
     * operators can inspect and retry it through failed_jobs.
     */
    public function defer(Customer|string $customer, string $operation = SyncCustomerSearchJob::INDEX): void
    {
        if (! in_array($operation, [SyncCustomerSearchJob::INDEX, SyncCustomerSearchJob::REMOVE], true)) {
            throw new InvalidArgumentException("Unsupported customer search operation: {$operation}");
        }

        $customerId = $customer instanceof Customer
            ? (string) $customer->getKey()
            : (string) $customer;

        if ($customerId === '') {
            return;
        }

        $dispatch = function () use ($customerId, $operation): void {
            try {
                SyncCustomerSearchJob::dispatch($customerId, $operation);
                Log::info('customer.search_sync.queued', [
                    'customer_id' => $customerId,
                    'operation' => $operation,
                ]);
            } catch (Throwable $exception) {
                // A queue outage must not make the already committed request look
                // failed. Keep a structured event for operators/retry tooling.
                Log::error('customer.search_sync.dispatch_failed', [
                    'customer_id' => $customerId,
                    'operation' => $operation,
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
            Log::error('customer.search_sync.defer_failed', [
                'customer_id' => $customerId,
                'operation' => $operation,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
