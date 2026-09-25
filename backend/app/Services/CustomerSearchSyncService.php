<?php

namespace App\Services;

use App\Jobs\CrmCleanupOutboxJob;
use App\Jobs\SyncCustomerSearchJob;
use App\Models\Customer;
use Closure;
use InvalidArgumentException;

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
    public function __construct(private readonly DurableDispatchService $dispatch) {}

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
     * Dispatch failures are persisted as a retryable CRM cleanup outbox job
     * instead of being allowed to turn a committed CRM request into a false
     * 500. The existing jobs table is the durable fallback; terminal worker
     * failures remain visible through failed_jobs.
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

        $this->dispatch->afterCommit(
            new SyncCustomerSearchJob($customerId, $operation),
            CrmCleanupOutboxJob::forCustomerSearch($customerId, $operation),
            'customer.search_sync',
            [
                'customer_id' => $customerId,
                'operation' => $operation,
            ],
        );
    }
}
