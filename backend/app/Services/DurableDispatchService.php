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
 * Dispatches cleanup work and persists a retryable outbox row when the
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
     * Register a post-commit dispatch with a transaction-bound durable intent.
     *
     * The fallback row is written before the business transaction commits. It
     * is removed only after the primary dispatch succeeds. Consequently a
     * process crash between COMMIT and callback execution leaves a retryable
     * outbox row behind, while a rollback removes both the business mutation
     * and the intent. This is intentionally separate from the legacy
     * afterCommit() method: CRM model cleanup keeps its established fallback
     * timing and compatibility contract.
     *
     * @param  array<string, mixed>  $context
     */
    public function afterCommitDurably(
        ShouldQueue $job,
        CrmCleanupOutboxJob $fallback,
        string $event,
        array $context = [],
    ): void {
        $connection = $this->database->connection();
        if ($connection->transactionLevel() === 0) {
            $this->dispatch($job, $fallback, $event, $context, false);

            return;
        }

        try {
            // This row is deliberately written in the business transaction.
            // It is an intent, not a second deferred dispatch: after a
            // successful primary dispatch it is removed from the same jobs
            // table, and a crash leaves it available to the worker.
            $fallbackJobId = $this->writeFallbackIntent($fallback, $event, $context);
        } catch (Throwable $exception) {
            Log::critical($event.'.fallback_failed', array_merge($context, [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]));

            // Do not commit a destructive business mutation without a durable
            // cleanup path. The caller can report the failure to the operator.
            throw $exception;
        }

        $dispatch = function () use ($job, $event, $context, $fallbackJobId): void {
            try {
                $this->dispatcher->dispatch($job);
                Log::info($event.'.queued', $context);
            } catch (Throwable $exception) {
                // The pre-commit intent remains in jobs. It is deliberately not
                // removed when the primary transport fails or may have accepted
                // the job before throwing.
                Log::error($event.'.dispatch_failed', array_merge($context, [
                    'fallback_job_id' => $fallbackJobId,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]));
                Log::warning($event.'.fallback_persisted', array_merge($context, [
                    'fallback_job_id' => $fallbackJobId,
                    'dispatch_exception' => $exception::class,
                    'dispatch_message' => $exception->getMessage(),
                ]));

                return;
            }

            try {
                $this->deleteFallbackIntent($fallbackJobId);
                Log::info($event.'.fallback_intent_released', array_merge($context, [
                    'fallback_job_id' => $fallbackJobId,
                ]));
            } catch (Throwable $exception) {
                // A duplicate fallback is safe because all cleanup jobs are
                // idempotent. Keep the row and make the operational leak
                // visible instead of claiming a clean success.
                Log::critical($event.'.fallback_intent_release_failed', array_merge($context, [
                    'fallback_job_id' => $fallbackJobId,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]));
            }
        };

        try {
            $connection->afterCommit($dispatch);
        } catch (Throwable $exception) {
            Log::critical($event.'.after_commit_failed', array_merge($context, [
                'fallback_job_id' => $fallbackJobId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]));

            // The intent is already part of the surrounding transaction. If
            // callback registration itself fails, leave that intent to become
            // the post-commit worker path; rolling the business mutation back
            // would only discard an otherwise durable cleanup request.
            return;
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
            // cleanup operations are idempotent, so a duplicate fallback is safe.
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

    /**
     * Persist the durable intent before the surrounding transaction commits.
     *
     * @param  array<string, mixed>  $context
     */
    private function writeFallbackIntent(
        CrmCleanupOutboxJob $fallback,
        string $event,
        array $context,
    ): string {
        $fallbackJobId = (string) $this->databaseQueue()->push($fallback);
        if ($fallbackJobId === '') {
            throw new RuntimeException('The durable cleanup intent did not receive a queue id.');
        }

        Log::info($event.'.fallback_intent_persisted', array_merge($context, [
            'fallback_job_id' => $fallbackJobId,
        ]));

        return $fallbackJobId;
    }

    /**
     * Remove a durable intent only after the primary dispatch succeeded.
     */
    private function deleteFallbackIntent(string $fallbackJobId): void
    {
        $config = config('queue.connections.database');
        if (! is_array($config) || ($config['driver'] ?? null) !== 'database') {
            throw new RuntimeException('The database queue connection is not configured.');
        }

        $table = $config['table'] ?? 'jobs';
        if (! is_string($table) || $table === '') {
            throw new RuntimeException('The database queue table is not configured.');
        }

        $this->database
            ->connection($config['connection'] ?? null)
            ->table($table)
            ->where('id', $fallbackJobId)
            ->delete();
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
