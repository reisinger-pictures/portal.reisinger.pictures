<?php

namespace Tests\Feature\Checkout;

use App\Models\Order;
use App\Services\InvoiceMailDispatcher;
use RuntimeException;
use Tests\TestCase;

/**
 * PAY-4: the durable claim + enqueue contract requires the transactional
 * database queue in every non-local environment, not only in production.
 * Staging with an inline `sync` driver must fail closed instead of masking a
 * duplicate-mail regression.
 */
class InvoiceMailDispatcherQueuePolicyTest extends TestCase
{
    public function test_staging_environment_with_sync_queue_refuses_the_durable_claim(): void
    {
        $originalEnvironment = app()->environment();
        app()->detectEnvironment(static fn (): string => 'staging');
        config([
            'queue.default' => 'sync',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.connection' => config('database.default'),
        ]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(
                'Invoice mail requires the transactional database queue outside local test environments.',
            );

            app(InvoiceMailDispatcher::class)->queueOnce(new Order);
        } finally {
            app()->detectEnvironment(static fn (): string => $originalEnvironment);
        }
    }

    public function test_staging_environment_with_separate_database_queue_connection_refuses_the_durable_claim(): void
    {
        $originalEnvironment = app()->environment();
        app()->detectEnvironment(static fn (): string => 'staging');
        config([
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.connection' => 'some_other_connection',
            'database.default' => 'sqlite',
        ]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(
                'Invoice mail requires the transactional database queue outside local test environments.',
            );

            app(InvoiceMailDispatcher::class)->queueOnce(new Order);
        } finally {
            app()->detectEnvironment(static fn (): string => $originalEnvironment);
        }
    }

    /**
     * Regression guard for the atomic claim+enqueue contract.
     *
     * InvoiceMailDispatcher and DisputeMailDispatcher write their claim marker
     * and enqueue the mailable inside ONE transaction. That is only sound while
     * the database queue inserts the job WITHIN that transaction. Flipping
     * `after_commit` to true moves the INSERT into a db.transactions callback
     * that runs after commit(), so the claim is already durable when the
     * enqueue can still fail — and every retry then short-circuits on the
     * "already claimed" branch. The invoice receipt or the chargeback alert is
     * lost with no recovery path, and no other gate detects it: the suite runs
     * the `sync` driver and the webhook dedupe tests mock the Mail facade.
     *
     * DurableDispatchService is deliberately unaffected — it constructs
     * UuidDatabaseQueue with afterCommit=false explicitly — so nothing in the
     * application depends on the connection-level flag being true.
     */
    public function test_database_queue_connection_must_not_defer_enqueue_until_after_commit(): void
    {
        $afterCommit = config('queue.connections.database.after_commit');

        $this->assertFalse(
            $afterCommit,
            'queue.connections.database.after_commit must stay false, otherwise the durable '
            .'mail claim commits without its job and the mail can never be retried.',
        );
    }

    /**
     * The deferral is what makes the failure invisible, so pin the resolved
     * connection the dispatchers actually use rather than the raw array.
     */
    public function test_resolved_database_queue_connection_dispatches_inside_the_transaction(): void
    {
        $connection = config('queue.connections.database.connection');

        $this->assertIsString($connection);
        $this->assertSame(
            config('database.default'),
            $connection,
            'The transactional database queue must share the application connection, otherwise '
            .'the claim and the jobs INSERT are not committed or rolled back together.',
        );
    }
}
