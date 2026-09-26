<?php

namespace Tests\Feature;

use App\Jobs\CrmCleanupOutboxJob;
use App\Jobs\DeleteModelFilesJob;
use App\Jobs\SyncCustomerSearchJob;
use App\Models\Customer;
use App\Models\ModelProfile;
use App\Services\CustomerSearchSyncService;
use App\Services\DurableDispatchService;
use App\Services\ModelFileCleanupService;
use App\Services\ModelFileStore;
use App\Services\ModelProfileEraser;
use Illuminate\Contracts\Bus\Dispatcher as DispatcherContract;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use Mockery;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class CrmCleanupDispatchFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'queue.default' => 'sync',
            'queue.connections.database' => [
                'driver' => 'database',
                'connection' => null,
                'table' => 'jobs',
                'queue' => 'default',
                'retry_after' => 90,
                'after_commit' => false,
            ],
            'scout.driver' => 'null',
        ]);
        Storage::fake('local');
    }

    public function test_file_cleanup_dispatch_failure_persists_a_uuid_outbox_job(): void
    {
        $this->failQueuePush();
        Log::spy();

        app(ModelFileCleanupService::class)->afterCommit(
            'model-age-proofs/customer-1/proof.jpg',
            'test_dispatch',
            'customer-1',
        );

        $row = $this->fallbackRow();
        $command = $this->payloadCommand($row->payload);
        $this->assertTrue(Str::isUuid($row->id));
        $this->assertInstanceOf(CrmCleanupOutboxJob::class, $command);
        $this->assertSame(5, json_decode($row->payload, true)['maxTries']);
        $this->assertSame('30,60,120,300,600', json_decode($row->payload, true)['backoff']);

        Log::shouldHaveReceived('warning')
            ->with('model.file_cleanup.fallback_persisted', Mockery::on(
                static fn (array $context): bool => $context['customer_id'] === 'customer-1'
                    && $context['path_count'] === 1
                    && isset($context['fallback_job_id'])
            ))
            ->once();
    }

    public function test_customer_search_dispatch_failure_persists_a_retryable_outbox_job(): void
    {
        $this->failQueuePush();
        Log::spy();

        app(CustomerSearchSyncService::class)->defer(
            'customer-1',
            SyncCustomerSearchJob::REMOVE,
        );

        $row = $this->fallbackRow();
        $command = $this->payloadCommand($row->payload);
        $this->assertInstanceOf(CrmCleanupOutboxJob::class, $command);
        $this->assertSame(CrmCleanupOutboxJob::CUSTOMER_SEARCH, $command->operation());
        $this->assertSame('customer-1', $command->customerId());
        $this->assertSame(SyncCustomerSearchJob::REMOVE, $command->searchOperation());

        Log::shouldHaveReceived('warning')
            ->with('customer.search_sync.fallback_persisted', Mockery::on(
                static fn (array $context): bool => $context['customer_id'] === 'customer-1'
                    && $context['operation'] === SyncCustomerSearchJob::REMOVE
            ))
            ->once();
    }

    public function test_durable_intent_is_registered_before_commit_and_rolls_back_with_the_transaction(): void
    {
        $this->failQueuePush();

        try {
            DB::transaction(function (): void {
                app(ModelFileCleanupService::class)->afterCommit(
                    'model-age-proofs/customer-1/proof.jpg',
                    'after_commit',
                    'customer-1',
                );

                // The durable intent exists before COMMIT so a process death in
                // the post-commit window cannot lose the cleanup.
                $this->assertDatabaseCount('jobs', 1);

                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('force rollback', $exception->getMessage());
        }

        // A rollback discards the intent together with the business write.
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_real_erasure_persists_durable_file_and_search_intents_that_survive_a_missing_dispatch(): void
    {
        $this->failQueuePush(2);
        Log::spy();

        $customer = Customer::withoutSyncingToSearch(fn (): Customer => Customer::factory()->create([
            'brand' => 'rp',
            'is_model' => true,
            'email' => 'observer-fallback@example.com',
        ]));
        $customerId = (string) $customer->getKey();
        $proof = "model-age-proofs/{$customerId}/proof.jpg";
        Storage::disk('local')->put($proof, 'encrypted-placeholder');
        $profile = ModelProfile::create([
            'customer_id' => $customerId,
            'catalog_version' => 'v1',
            'answers' => [],
            'age_proof_required' => true,
            'age_proof_path' => $proof,
            'age_proof_uploaded_at' => now(),
            'submitted_at' => now(),
        ]);

        $jobsAtDeleteBoundary = null;
        Customer::deleted(function () use (&$jobsAtDeleteBoundary): void {
            $jobsAtDeleteBoundary = DB::table('jobs')->count();
        });

        $stats = app(ModelProfileEraser::class)->erase($customer, 'dispatch_failure_regression');

        $this->assertTrue($stats['had_age_proof']);
        // The durable intents are written before COMMIT: the deletion event
        // already sees both the file-cleanup and the search-removal intent.
        $this->assertNotNull($jobsAtDeleteBoundary);
        $this->assertSame(2, $jobsAtDeleteBoundary);
        $this->assertDatabaseMissing('customers', ['id' => $customerId]);
        $this->assertDatabaseMissing('model_profiles', ['id' => $profile->id]);
        Storage::disk('local')->assertExists($proof);
        $this->assertDatabaseCount('jobs', 2);

        $fileCommands = [];
        $searchCommands = [];
        foreach (DB::table('jobs')->get() as $row) {
            $this->assertTrue(Str::isUuid($row->id));
            $command = $this->payloadCommand($row->payload);
            $this->assertInstanceOf(CrmCleanupOutboxJob::class, $command);

            if ($command->operation() === CrmCleanupOutboxJob::FILE_CLEANUP) {
                $fileCommands[] = $command;
            } elseif ($command->operation() === CrmCleanupOutboxJob::CUSTOMER_SEARCH) {
                $searchCommands[] = $command;
            }
        }

        $this->assertCount(1, $fileCommands);
        $this->assertSame([$proof], $fileCommands[0]->paths());
        $this->assertSame('model_profile_erase', $fileCommands[0]->reason());
        $this->assertSame($customerId, $fileCommands[0]->customerId());

        $this->assertCount(1, $searchCommands);
        $this->assertSame($customerId, $searchCommands[0]->customerId());
        $this->assertSame(SyncCustomerSearchJob::REMOVE, $searchCommands[0]->searchOperation());

        Log::shouldHaveReceived('warning')
            ->with('model.file_cleanup.fallback_persisted', Mockery::on(
                static fn (array $context): bool => $context['customer_id'] === $customerId
                    && $context['reason'] === 'model_profile_erase'
            ))
            ->once();
        Log::shouldHaveReceived('warning')
            ->with('customer.search_sync.fallback_persisted', Mockery::on(
                static fn (array $context): bool => $context['customer_id'] === $customerId
                    && $context['operation'] === SyncCustomerSearchJob::REMOVE
            ))
            ->once();
    }

    public function test_database_worker_retries_a_fallback_file_cleanup_job(): void
    {
        $path = 'model-age-proofs/customer-1/proof.jpg';
        Storage::disk('local')->put($path, 'encrypted-placeholder');

        $realStore = app(ModelFileStore::class);
        $failingStore = Mockery::mock(ModelFileStore::class);
        $failingStore->shouldReceive('delete')->twice()->andThrow(new RuntimeException('temporary disk outage'));
        $this->app->instance(ModelFileStore::class, $failingStore);

        app(ModelFileCleanupService::class)->immediately($path, 'disk_retry', 'customer-1');
        $this->app->instance(ModelFileStore::class, $realStore);

        $this->assertDatabaseCount('jobs', 1);
        config(['queue.default' => 'database']);
        Artisan::call('queue:work', [
            'database',
            '--queue' => 'default',
            '--stop-when-empty' => true,
        ]);

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_immediate_dispatch_is_deferred_until_the_transaction_outcome(): void
    {
        $path = 'model-age-proofs/customer-1/outcome.jpg';
        Storage::disk('local')->put($path, 'encrypted-placeholder');

        $realStore = app(ModelFileStore::class);
        $failingStore = Mockery::mock(ModelFileStore::class);
        $failingStore->shouldReceive('delete')->once()->andThrow(new RuntimeException('temporary disk outage'));
        $this->app->instance(ModelFileStore::class, $failingStore);
        $service = app(ModelFileCleanupService::class);
        Queue::fake();

        try {
            DB::transaction(function () use ($service, $path): void {
                $service->immediately($path, 'outcome_cleanup', 'customer-1');
                Queue::assertNotPushed(DeleteModelFilesJob::class);
                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('force rollback', $exception->getMessage());
        }

        $this->app->instance(ModelFileStore::class, $realStore);
        Queue::assertPushed(DeleteModelFilesJob::class);
    }

    public function test_immediate_fallback_survives_a_rollback_transaction(): void
    {
        $path = 'model-age-proofs/customer-1/rollback.jpg';
        Storage::disk('local')->put($path, 'encrypted-placeholder');

        $realStore = app(ModelFileStore::class);
        $failingStore = Mockery::mock(ModelFileStore::class);
        $failingStore->shouldReceive('delete')->twice()->andThrow(new RuntimeException('temporary disk outage'));
        $this->app->instance(ModelFileStore::class, $failingStore);
        $service = app(ModelFileCleanupService::class);

        try {
            DB::transaction(function () use ($service, $path): void {
                $service->immediately($path, 'rollback_cleanup', 'customer-1');
                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('force rollback', $exception->getMessage());
        }

        $this->app->instance(ModelFileStore::class, $realStore);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_terminal_worker_failure_is_persisted_and_keeps_the_underlying_audit_contract(): void
    {
        $realDispatcher = app(DispatcherContract::class);
        $this->failQueuePush();
        app(CustomerSearchSyncService::class)->defer(
            'customer-1',
            SyncCustomerSearchJob::REMOVE,
        );
        $this->app->instance(DispatcherContract::class, $realDispatcher);

        $row = $this->fallbackRow();
        DB::table('jobs')->where('id', $row->id)->update(['attempts' => 4]);

        $engine = Mockery::mock(Engine::class);
        $engine->shouldReceive('delete')
            ->once()
            ->andThrow(new RuntimeException('scout unavailable'));
        $manager = Mockery::mock(EngineManager::class);
        $manager->shouldReceive('engine')->once()->andReturn($engine);
        $this->app->instance(EngineManager::class, $manager);
        Log::spy();
        config(['queue.default' => 'database']);
        $this->resetQueueWorkerFailureListener();

        Artisan::call('queue:work', [
            'database',
            '--queue' => 'default',
            '--stop-when-empty' => true,
        ]);

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
        $failed = DB::table('failed_jobs')->first();
        $this->assertNotNull($failed);
        $this->assertTrue(Str::isUuid($failed->uuid));
        $this->assertSame('database', $failed->connection);
        $this->assertSame('default', $failed->queue);
        $this->assertStringContainsString('scout unavailable', $failed->exception);

        $command = $this->payloadCommand($failed->payload);
        $this->assertInstanceOf(CrmCleanupOutboxJob::class, $command);
        $this->assertSame(CrmCleanupOutboxJob::CUSTOMER_SEARCH, $command->operation());
        $this->assertSame('customer-1', $command->customerId());
        $this->assertSame(SyncCustomerSearchJob::REMOVE, $command->searchOperation());

        Log::shouldHaveReceived('error')
            ->with('crm.cleanup_outbox.failed', Mockery::on(
                static fn (array $context): bool => $context['customer_id'] === 'customer-1'
                    && $context['search_operation'] === SyncCustomerSearchJob::REMOVE
            ))
            ->once();
        Log::shouldHaveReceived('error')
            ->with('customer.search_sync.failed', Mockery::on(
                static fn (array $context): bool => $context['customer_id'] === 'customer-1'
                    && $context['operation'] === SyncCustomerSearchJob::REMOVE
            ))
            ->once();
    }

    public function test_search_sync_intent_write_failure_is_critical_and_aborts_the_business_transaction(): void
    {
        $this->failQueuePush();
        config(['queue.connections.database.table' => 'missing_jobs_table']);
        Log::spy();

        $thrown = null;
        try {
            app(CustomerSearchSyncService::class)->defer('customer-1');
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        // A durable cleanup/de-index intent is mandatory. When it cannot be
        // written the operation aborts instead of committing without a retry
        // path, and the failure is audited as critical.
        $this->assertInstanceOf(QueryException::class, $thrown);
        $this->assertDatabaseCount('jobs', 0);
        Log::shouldHaveReceived('critical')
            ->with('customer.search_sync.fallback_failed', Mockery::on(
                static fn (array $context): bool => $context['customer_id'] === 'customer-1'
                    && isset($context['exception'])
            ))
            ->once();
    }

    public function test_worker_respects_backoff_before_a_later_retry_succeeds(): void
    {
        $path = 'model-age-proofs/customer-1/retry.jpg';
        Storage::disk('local')->put($path, 'encrypted-placeholder');
        $realDispatcher = app(DispatcherContract::class);
        $this->failQueuePush();

        app(ModelFileCleanupService::class)->afterCommit($path, 'retry_backoff', 'customer-1');
        $row = $this->fallbackRow();
        $realStore = app(ModelFileStore::class);
        $failingStore = Mockery::mock(ModelFileStore::class);
        $failingStore->shouldReceive('delete')
            ->once()
            ->andThrow(new RuntimeException('temporary worker outage'));
        $this->app->instance(ModelFileStore::class, $failingStore);
        $this->app->instance(DispatcherContract::class, $realDispatcher);
        config(['queue.default' => 'database']);
        $firstAttemptAt = time();

        Artisan::call('queue:work', [
            'database',
            '--queue' => 'default',
            '--stop-when-empty' => true,
        ]);

        $reserved = DB::table('jobs')->first();
        $this->assertNotNull($reserved);
        $this->assertSame(1, (int) $reserved->attempts);
        $this->assertGreaterThanOrEqual($firstAttemptAt + 30, (int) $reserved->available_at);
        $this->assertDatabaseCount('failed_jobs', 0);

        $this->app->instance(ModelFileStore::class, $realStore);
        DB::table('jobs')->where('id', $reserved->id)->update(['available_at' => time()]);
        Artisan::call('queue:work', [
            'database',
            '--queue' => 'default',
            '--stop-when-empty' => true,
        ]);

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_production_worker_tries_does_not_shorten_the_outbox_job_budget(): void
    {
        $realDispatcher = app(DispatcherContract::class);
        $this->failQueuePush();
        app(ModelFileCleanupService::class)->afterCommit(
            'model-age-proofs/customer-1/production-budget.jpg',
            'production_budget',
            'customer-1',
        );
        $row = $this->fallbackRow();
        DB::table('jobs')->where('id', $row->id)->update(['attempts' => 2]);

        $failingStore = Mockery::mock(ModelFileStore::class);
        $failingStore->shouldReceive('delete')
            ->once()
            ->andThrow(new RuntimeException('temporary worker outage'));
        $this->app->instance(ModelFileStore::class, $failingStore);
        $this->app->instance(DispatcherContract::class, $realDispatcher);
        config(['queue.default' => 'database']);
        $attemptedAt = time();

        Artisan::call('queue:work', [
            'database',
            '--queue' => 'default',
            '--tries' => 3,
            '--stop-when-empty' => true,
        ]);

        $reserved = DB::table('jobs')->first();
        $this->assertNotNull($reserved);
        $this->assertSame(3, (int) $reserved->attempts);
        $this->assertGreaterThanOrEqual($attemptedAt + 120, (int) $reserved->available_at);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_successful_primary_cleanup_does_not_create_a_fallback_row(): void
    {
        $path = 'model-age-proofs/customer-1/success.jpg';
        Storage::disk('local')->put($path, 'encrypted-placeholder');
        Log::spy();

        app(ModelFileCleanupService::class)->afterCommit($path, 'success', 'customer-1');

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseCount('jobs', 0);
        Log::shouldHaveReceived('info')
            ->with('model.file_cleanup.queued', Mockery::on(
                static fn (array $context): bool => $context['customer_id'] === 'customer-1'
                    && $context['reason'] === 'success'
            ))
            ->once();
    }

    public function test_empty_file_cleanup_is_a_noop(): void
    {
        $path = 'model-age-proofs/customer-1/noop.jpg';
        Storage::disk('local')->put($path, 'encrypted-placeholder');

        app(ModelFileCleanupService::class)->afterCommit([''], 'noop', 'customer-1');

        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_file_outbox_cleanup_is_idempotent_and_audits_success(): void
    {
        $path = 'model-age-proofs/customer-1/idempotent.jpg';
        Storage::disk('local')->put($path, 'encrypted-placeholder');
        $store = app(ModelFileStore::class);
        $job = CrmCleanupOutboxJob::forFileCleanup([$path, $path, ''], 'idempotent', 'customer-1');
        Log::spy();

        $this->assertSame([$path], $job->paths());
        $job->handle($store);
        $job->handle($store);

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseCount('jobs', 0);
        Log::shouldHaveReceived('info')
            ->with('crm.cleanup_outbox.completed', Mockery::on(
                static fn (array $context): bool => $context['operation'] === CrmCleanupOutboxJob::FILE_CLEANUP
                    && $context['customer_id'] === 'customer-1'
                    && $context['path_count'] === 1
            ))
            ->twice();
    }

    public function test_after_commit_registration_failure_still_writes_a_transaction_bound_fallback(): void
    {
        $realConnection = DB::connection();
        $failingConnection = Mockery::mock(Connection::class);
        $failingConnection->shouldReceive('transactionLevel')
            ->once()
            ->andReturn(1);
        $failingConnection->shouldReceive('afterCommit')
            ->once()
            ->andThrow(new RuntimeException('transaction callback unavailable'));

        $noArgumentCalls = 0;
        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->andReturnUsing(
            function (...$arguments) use (&$noArgumentCalls, $failingConnection, $realConnection): Connection {
                if ($arguments === []) {
                    $noArgumentCalls++;

                    return $noArgumentCalls === 1 ? $failingConnection : $realConnection;
                }

                return $realConnection;
            },
        );
        $dispatcher = Mockery::mock(DispatcherContract::class);
        $service = new DurableDispatchService($dispatcher, $resolver, app());
        Log::spy();

        $service->afterCommit(
            new DeleteModelFilesJob(['model-age-proofs/customer-1/callback.jpg'], 'callback_failure', 'customer-1'),
            CrmCleanupOutboxJob::forFileCleanup(
                ['model-age-proofs/customer-1/callback.jpg'],
                'callback_failure',
                'customer-1',
            ),
            'model.file_cleanup',
            ['customer_id' => 'customer-1', 'reason' => 'callback_failure', 'path_count' => 1],
        );

        $this->assertDatabaseCount('jobs', 1);
        Log::shouldHaveReceived('error')
            ->with('model.file_cleanup.defer_failed', Mockery::on(
                static fn (array $context): bool => $context['customer_id'] === 'customer-1'
            ))
            ->once();
        Log::shouldHaveReceived('warning')
            ->with('model.file_cleanup.fallback_persisted', Mockery::on(
                static fn (array $context): bool => $context['customer_id'] === 'customer-1'
                    && isset($context['fallback_job_id'])
            ))
            ->once();
    }

    public function test_database_primary_dispatch_uses_the_existing_uuid_jobs_schema(): void
    {
        $path = 'model-age-proofs/customer-1/primary.jpg';
        Storage::disk('local')->put($path, 'encrypted-placeholder');
        config(['queue.default' => 'database']);

        app(ModelFileCleanupService::class)->afterCommit(
            $path,
            'primary_database_dispatch',
            'customer-1',
        );

        $row = $this->fallbackRow();
        $this->assertTrue(Str::isUuid($row->id));
        $this->assertSame('default', $row->queue);
        $this->assertInstanceOf(DeleteModelFilesJob::class, $this->payloadCommand($row->payload));

        Artisan::call('queue:work', [
            'database',
            '--queue' => 'default',
            '--stop-when-empty' => true,
        ]);

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_dispatch_failure_fallback_uses_the_existing_uuid_jobs_schema(): void
    {
        config(['queue.default' => 'database']);
        $this->failQueuePush();

        app(ModelFileCleanupService::class)->afterCommit(
            'model-age-proofs/customer-1/schema.jpg',
            'schema_contract',
            'customer-1',
        );

        $row = $this->fallbackRow();
        $this->assertTrue(Str::isUuid($row->id));
        $this->assertSame('default', $row->queue);
        $this->assertInstanceOf(CrmCleanupOutboxJob::class, $this->payloadCommand($row->payload));
    }

    public function test_file_cleanup_is_durable_when_the_pending_post_commit_callback_is_lost(): void
    {
        $durable = $this->durableServiceWithLostCallback($captured);
        $service = new ModelFileCleanupService(app(ModelFileStore::class), $durable);

        $service->afterCommit(
            'model-age-proofs/customer-1/lost-callback.jpg',
            'lost_callback',
            'customer-1',
        );

        // Without the durable variant no row would exist: the only registration
        // was an after-commit callback that was never executed.
        $this->assertNotNull($captured);
        $row = $this->fallbackRow();
        $this->assertTrue(Str::isUuid($row->id));
        $command = $this->payloadCommand($row->payload);
        $this->assertInstanceOf(CrmCleanupOutboxJob::class, $command);
        $this->assertSame(CrmCleanupOutboxJob::FILE_CLEANUP, $command->operation());
    }

    public function test_customer_search_sync_is_durable_when_the_pending_post_commit_callback_is_lost(): void
    {
        $durable = $this->durableServiceWithLostCallback($captured);
        $service = new CustomerSearchSyncService($durable);

        $service->defer('customer-1', SyncCustomerSearchJob::REMOVE);

        $this->assertNotNull($captured);
        $row = $this->fallbackRow();
        $command = $this->payloadCommand($row->payload);
        $this->assertInstanceOf(CrmCleanupOutboxJob::class, $command);
        $this->assertSame(CrmCleanupOutboxJob::CUSTOMER_SEARCH, $command->operation());
        $this->assertSame('customer-1', $command->customerId());
        $this->assertSame(SyncCustomerSearchJob::REMOVE, $command->searchOperation());
    }

    /**
     * Build a DurableDispatchService whose connection reports an open
     * transaction and captures the after-commit callback without running it.
     * That models a process death in the COMMIT-to-callback window.
     */
    private function durableServiceWithLostCallback(?callable &$captured): DurableDispatchService
    {
        $captured = null;
        $realConnection = DB::connection();
        $deferredConnection = Mockery::mock(Connection::class);
        $deferredConnection->shouldReceive('transactionLevel')->andReturn(1);
        $deferredConnection->shouldReceive('afterCommit')->andReturnUsing(
            static function (callable $callback) use (&$captured): void {
                $captured = $callback;
            },
        );

        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->andReturnUsing(
            static function (...$arguments) use ($deferredConnection, $realConnection): Connection {
                return $arguments === [] ? $deferredConnection : $realConnection;
            },
        );

        return new DurableDispatchService(app(DispatcherContract::class), $resolver, app());
    }

    private function resetQueueWorkerFailureListener(): void
    {
        // WorkCommand keeps this listener registration in a static property.
        // PHPUnit rebuilds the application between tests, so a listener left by
        // an earlier queue:work test no longer belongs to the current event
        // dispatcher. Reset it for this terminal-failure integration test.
        (new ReflectionProperty(WorkCommand::class, 'hasRegisteredListeners'))
            ->setValue(null, false);
    }

    private function failQueuePush(?int $dispatchCount = null): void
    {
        $dispatcher = Mockery::mock(DispatcherContract::class);
        $expectation = $dispatcher->shouldReceive('dispatch')
            ->andThrow(new RuntimeException('queue transport unavailable'));
        if ($dispatchCount !== null) {
            $expectation->times($dispatchCount);
        }

        $this->app->instance(DispatcherContract::class, $dispatcher);
        $this->app->forgetInstance(DurableDispatchService::class);
        $this->app->forgetInstance(ModelFileCleanupService::class);
        $this->app->forgetInstance(CustomerSearchSyncService::class);
    }

    private function fallbackRow(): object
    {
        $row = DB::table('jobs')->first();
        $this->assertNotNull($row);

        return $row;
    }

    private function payloadCommand(string $payload): object
    {
        $decoded = json_decode($payload, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertArrayHasKey('command', $decoded['data']);

        return unserialize($decoded['data']['command']);
    }
}
