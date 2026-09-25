<?php

namespace App\Services;

use App\Jobs\CrmCleanupOutboxJob;
use App\Support\UuidDatabaseQueue;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Dispatches CRM cleanup work and persists a retryable outbox row when the
 * configured queue cannot accept the original job.
 *
 * The existing V001 jobs table is the durable store. Its UUID primary key is
 * a queue-row detail, not a CRM identity or a new business-level outbox key.
 * The fallback writer is deliberately transaction-bound: a row written while
 * a business transaction is open is committed with that transaction and is
 * discarded on rollback, unless the caller explicitly needs rollback cleanup.
 */
final class DurableDispatchService
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly ConnectionResolverInterface $database,
        private readonly Container $container,
    ) {}

    /**
     * Dispatch after the current transaction outcome when one is open, or
     * dispatch immediately otherwise. If dispatch fails, persist the CRM
     * cleanup fallback.
     *
     * @param  array<string, mixed>  $context
     */
    public function dispatchNow(
        ShouldQueue $job,
        CrmCleanupOutboxJob $fallback,
        string $event,
        array $context = [],
    ): void {
        $attempt = function () use ($job, $fallback, $event, $context): void {
            $this->dispatch($job, $fallback, $event, $context, true);
        };

        try {
            $connection = $this->database->connection();
            if ($connection->transactionLevel() > 0) {
                // Rollback cleanup is for an external file. Do not enqueue the
                // normal job inside a transaction: a successful insert would be
                // rolled back together with the business write.
                $connection->afterCommit($attempt);
                $connection->afterRollBack($attempt);

                return;
            }

            $attempt();
        } catch (Throwable $exception) {
            Log::error($event.'.defer_failed', array_merge($context, [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]));

            $this->persistFallback($fallback, $event, $context, $exception, true);
        }
    }

    /**
     * Register the primary dispatch after the current transaction commits.
     *
     * The callback writes a fallback directly if dispatch fails. Registering a
     * second after-commit callback from inside the first one can silently lose
     * the fallback when the transaction manager has already begun executing
     * callbacks. A registration failure is handled in the current transaction
     * instead: a later commit makes the row durable, while a rollback discards
     * it because the CRM mutation itself did not commit.
     *
     * @param  array<string, mixed>  $context
     */
    public function afterCommit(
        ShouldQueue $job,
        CrmCleanupOutboxJob $fallback,
        string $event,
        array $context = [],
    ): void {
        $dispatch = function () use ($job, $fallback, $event, $context): void {
            try {
                $this->dispatch($job, $fallback, $event, $context, false);
            } catch (Throwable $exception) {
                // dispatch() normally handles its own errors. Keep the callback
                // fail-safe if a logging or container failure escapes it.
                Log::critical($event.'.after_commit_failed', array_merge($context, [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]));

                $this->persistFallback($fallback, $event, $context, $exception, false);
            }
        };

        try {
            $connection = $this->database->connection();
            if ($connection->transactionLevel() > 0) {
                $connection->afterCommit($dispatch);
            } else {
                $dispatch();
            }
        } catch (Throwable $exception) {
            Log::error($event.'.defer_failed', array_merge($context, [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]));

            $this->persistFallback($fallback, $event, $context, $exception, false);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function dispatch(
        ShouldQueue $job,
        CrmCleanupOutboxJob $fallback,
        string $event,
        array $context,
        bool $surviveRollback,
    ): void {
        try {
            $this->dispatcher->dispatch($job);
            Log::info($event.'.queued', $context);
        } catch (Throwable $exception) {
            // The primary queue may have accepted the row before throwing. The
            // CRM operations are idempotent, so a duplicate fallback is safe.
            Log::error($event.'.dispatch_failed', array_merge($context, [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]));

            $this->persistFallback($fallback, $event, $context, $exception, $surviveRollback);
        }
    }

    /**
     * Insert the fallback into the existing database jobs table.
     *
     * For a normal business transaction, direct insertion is intentional: the
     * row is committed exactly when the business change commits. Rollback
     * cleanup uses both after-commit and after-rollback callbacks so an
     * external file can still be retried when the surrounding transaction is
     * rolled back.
     *
     * @param  array<string, mixed>  $context
     */
    private function persistFallback(
        CrmCleanupOutboxJob $fallback,
        string $event,
        array $context,
        Throwable $dispatchException,
        bool $surviveRollback,
    ): void {
        $written = false;
        $write = function () use (&$written, $fallback, $event, $context, $dispatchException): void {
            if ($written) {
                return;
            }

            try {
                // The fallback is part of the current transaction. The custom
                // writer is configured with afterCommit=false below, so this
                // cannot become a second, silently dropped after-commit task.
                $fallbackJobId = $this->databaseQueue()->push($fallback);
                $written = true;

                Log::warning($event.'.fallback_persisted', array_merge($context, [
                    'fallback_job_id' => $fallbackJobId,
                    'dispatch_exception' => $dispatchException::class,
                    'dispatch_message' => $dispatchException->getMessage(),
                ]));
            } catch (Throwable $fallbackException) {
                // The request must not be reported as a failed CRM mutation
                // after its business transaction has committed. Keep a
                // critical, PII-free event for operator escalation.
                Log::critical($event.'.fallback_failed', array_merge($context, [
                    'dispatch_exception' => $dispatchException::class,
                    'dispatch_message' => $dispatchException->getMessage(),
                    'fallback_exception' => $fallbackException::class,
                    'fallback_message' => $fallbackException->getMessage(),
                ]));
            }
        };

        try {
            $connection = $this->database->connection();
            if ($connection->transactionLevel() === 0) {
                $write();

                return;
            }

            if ($surviveRollback) {
                $connection->afterCommit($write);
                $connection->afterRollBack($write);

                return;
            }

            // This is either an after-commit callback or a failed callback
            // registration. A direct write is transaction-safe and avoids the
            // callback-inside-callback dead end described above.
            $write();
        } catch (Throwable $scheduleException) {
            Log::critical($event.'.fallback_schedule_failed', array_merge($context, [
                'dispatch_exception' => $dispatchException::class,
                'dispatch_message' => $dispatchException->getMessage(),
                'schedule_exception' => $scheduleException::class,
                'schedule_message' => $scheduleException->getMessage(),
            ]));

            // A partial callback registration must not prevent a best-effort
            // durable write. The $written guard prevents duplicate rows when a
            // previously registered callback still runs.
            $write();
        }
    }

    private function databaseQueue(): UuidDatabaseQueue
    {
        $config = config('queue.connections.database');
        if (! is_array($config) || ($config['driver'] ?? null) !== 'database') {
            throw new RuntimeException('The database queue connection is not configured.');
        }

        $connectionName = $config['connection'] ?? null;
        $table = $config['table'] ?? 'jobs';
        $queue = $config['queue'] ?? 'default';
        $retryAfter = $config['retry_after'] ?? 90;

        if (! is_string($table) || $table === '') {
            throw new RuntimeException('The database queue table is not configured.');
        }

        if (! is_string($queue) || $queue === '') {
            throw new RuntimeException('The database queue name is not configured.');
        }

        // The fallback is an outbox row, not a second deferred dispatch. Keep
        // it in the caller's transaction so commit/rollback has one clear
        // durability boundary.
        $queueConnection = new UuidDatabaseQueue(
            $this->database->connection($connectionName),
            $table,
            $queue,
            (int) $retryAfter,
            false,
        );
        $queueConnection->setContainer($this->container);
        $queueConnection->setConnectionName('database');
        $queueConnection->setConfig($config);

        return $queueConnection;
    }
}
