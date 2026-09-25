<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\CrmCleanupOutboxJob;
use App\Jobs\SyncGalleryPhotoSearchJob;
use App\Jobs\SyncGalleryPhotosSearchJob;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use App\Services\DurableDispatchService;
use App\Services\GalleryPhotoCleanupService;
use Illuminate\Contracts\Bus\Dispatcher as DispatcherContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Scout cleanup must not run from the Gallery/Photo delete transaction when
 * scout.after_commit is false. These tests use a non-null fake engine and the
 * existing UUID jobs table rather than changing global Scout configuration.
 */
class GalleryPhotoScoutCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryStorageDisk('photos');
        config([
            'scout.driver' => 'collection',
            'scout.queue' => false,
            'scout.after_commit' => false,
            'queue.default' => 'sync',
            'queue.connections.database' => [
                'driver' => 'database',
                'connection' => null,
                'table' => 'jobs',
                'queue' => 'default',
                'retry_after' => 90,
                'after_commit' => false,
            ],
        ]);
    }

    public function test_manual_gallery_endpoint_defers_scout_removal_with_fake_driver(): void
    {
        $gallery = Gallery::withoutSyncingToSearch(
            static fn (): Gallery => Gallery::factory()->create(),
        );
        $galleryId = (string) $gallery->getKey();
        $this->fakeScoutEngine(Gallery::class, $galleryId, 1);
        Queue::fake();

        $this->actingAs($this->admin(), 'api')
            ->deleteJson("/api/management/galleries/{$galleryId}")
            ->assertOk();

        $this->assertSame(0, $this->engineCalls);
        $job = Queue::pushed(SyncGalleryPhotoSearchJob::class)->first();
        $this->assertInstanceOf(SyncGalleryPhotoSearchJob::class, $job);
        $job->handle();
        $this->assertSame(1, $this->engineCalls);
    }

    public function test_manual_photo_endpoint_defers_scout_removal_with_fake_driver(): void
    {
        $gallery = Gallery::withoutSyncingToSearch(
            static fn (): Gallery => Gallery::factory()->create(),
        );
        $photo = Photo::withoutSyncingToSearch(
            static fn (): Photo => Photo::factory()->create(['gallery_id' => $gallery->id]),
        );
        $photoId = (string) $photo->getKey();
        $this->fakeScoutEngine(Photo::class, $photoId, 1);
        Queue::fake();

        $this->actingAs($this->admin(), 'api')
            ->deleteJson("/api/photos/{$photoId}")
            ->assertOk();

        $this->assertSame(0, $this->engineCalls);
        $job = Queue::pushed(SyncGalleryPhotoSearchJob::class)->first();
        $this->assertInstanceOf(SyncGalleryPhotoSearchJob::class, $job);
        $job->handle();
        $this->assertSame(1, $this->engineCalls);
    }

    public function test_scheduled_cleanup_defers_scout_removal_with_fake_driver(): void
    {
        $gallery = Gallery::withoutSyncingToSearch(
            static fn (): Gallery => Gallery::factory()->create([
                'expires_at' => now()->subMonths(4),
            ]),
        );
        $galleryId = (string) $gallery->getKey();
        $this->fakeScoutEngine(Gallery::class, $galleryId, 1);
        Queue::fake();

        $this->artisan('app:cleanup-galleries')->assertExitCode(0);

        $this->assertSame(0, $this->engineCalls);
        $job = Queue::pushed(SyncGalleryPhotoSearchJob::class)->first();
        $this->assertInstanceOf(SyncGalleryPhotoSearchJob::class, $job);
        $job->handle();
        $this->assertSame(1, $this->engineCalls);
    }

    public function test_scheduled_cleanup_captures_child_photo_keys_after_commit(): void
    {
        $gallery = Gallery::withoutSyncingToSearch(
            static fn (): Gallery => Gallery::factory()->create([
                'expires_at' => now()->subMonths(4),
            ]),
        );
        $photoIds = [];
        foreach (range(1, 2) as $unused) {
            $photo = Photo::withoutSyncingToSearch(
                static fn (): Photo => Photo::factory()->create(['gallery_id' => $gallery->id]),
            );
            $photoIds[] = (string) $photo->getKey();
        }
        sort($photoIds);

        $deletedKeys = [];
        $engine = Mockery::mock(Engine::class);
        $engine->shouldReceive('delete')
            ->times(2)
            ->andReturnUsing(function ($models) use (&$deletedKeys): void {
                $deletedKeys[] = $models->map(
                    static fn ($model): string => (string) $model->getScoutKey(),
                )->values()->all();
            });
        $this->bindScoutEngine($engine);
        Queue::fake();

        $this->artisan('app:cleanup-galleries')->assertExitCode(0);

        $this->assertSame([], $deletedKeys);
        $job = Queue::pushed(SyncGalleryPhotosSearchJob::class)->first();
        $this->assertInstanceOf(SyncGalleryPhotosSearchJob::class, $job);
        $capturedIds = $job->photoIds();
        sort($capturedIds);
        $this->assertSame($photoIds, $capturedIds);

        $job->handle();
        $job->handle();

        $this->assertCount(2, $deletedKeys);
        $firstPass = array_merge(...$deletedKeys);
        sort($firstPass);
        $this->assertSame($photoIds, array_values(array_unique($firstPass)));
    }

    public function test_gallery_delete_captures_child_photo_keys_and_batches_idempotently(): void
    {
        $gallery = Gallery::withoutSyncingToSearch(
            static fn (): Gallery => Gallery::factory()->create(),
        );
        $photoIds = [];
        for ($index = 0; $index < 205; $index++) {
            $photo = Photo::withoutSyncingToSearch(
                static fn (): Photo => Photo::factory()->create(['gallery_id' => $gallery->id]),
            );
            $photoIds[] = (string) $photo->getKey();
        }
        sort($photoIds);

        $deletedKeys = [];
        $engine = Mockery::mock(Engine::class);
        $engine->shouldReceive('delete')
            ->times(6)
            ->andReturnUsing(function ($models) use (&$deletedKeys): void {
                $keys = $models->map(
                    static fn ($model): string => (string) $model->getScoutKey(),
                )->values()->all();
                $this->assertLessThanOrEqual(SyncGalleryPhotosSearchJob::CHUNK_SIZE, count($keys));
                $deletedKeys[] = $keys;
            });
        $this->bindScoutEngine($engine);
        Queue::fake();

        $this->actingAs($this->admin(), 'api')
            ->deleteJson("/api/management/galleries/{$gallery->id}")
            ->assertOk();

        $this->assertSame([], $deletedKeys);
        $job = Queue::pushed(SyncGalleryPhotosSearchJob::class)->first();
        $this->assertInstanceOf(SyncGalleryPhotosSearchJob::class, $job);
        $capturedIds = $job->photoIds();
        sort($capturedIds);
        $this->assertSame($photoIds, $capturedIds);
        $this->assertDatabaseCount('jobs', 0);

        $job->handle();
        $job->handle();

        $this->assertCount(6, $deletedKeys);
        $firstPass = array_merge(...array_slice($deletedKeys, 0, 3));
        $secondPass = array_merge(...array_slice($deletedKeys, 3, 3));
        sort($firstPass);
        sort($secondPass);
        $this->assertSame($photoIds, $firstPass);
        $this->assertSame($photoIds, $secondPass);
    }

    public function test_gallery_child_search_fallback_retries_and_removes_keys_from_cascaded_photos(): void
    {
        $gallery = Gallery::withoutSyncingToSearch(
            static fn (): Gallery => Gallery::factory()->create(),
        );
        $photoIds = [];
        foreach (range(1, 2) as $unused) {
            $photo = Photo::withoutSyncingToSearch(
                static fn (): Photo => Photo::factory()->create(['gallery_id' => $gallery->id]),
            );
            $photoIds[] = (string) $photo->getKey();
        }
        sort($photoIds);

        $engineCalls = 0;
        $childAttempts = 0;
        $deletedKeys = [];
        $engine = Mockery::mock(Engine::class);
        $engine->shouldReceive('delete')
            ->times(5)
            ->andReturnUsing(function ($models) use (&$engineCalls, &$childAttempts, &$deletedKeys): void {
                $engineCalls++;
                $model = $models->first();
                $keys = $models->map(
                    static fn ($item): string => (string) $item->getScoutKey(),
                )->values()->all();
                $deletedKeys[] = $model instanceof Photo ? $keys : [];

                if ($model instanceof Photo) {
                    $childAttempts++;
                    if ($childAttempts === 1) {
                        throw new RuntimeException('temporary child Scout outage');
                    }
                }
            });
        $this->bindScoutEngine($engine);
        $realDispatcher = app(DispatcherContract::class);
        $this->failQueueDispatch();

        $this->actingAs($this->admin(), 'api')
            ->deleteJson("/api/management/galleries/{$gallery->id}")
            ->assertOk();

        $this->assertSame(0, $engineCalls);
        $this->assertDatabaseMissing('galleries', ['id' => $gallery->id]);
        $this->assertDatabaseCount('jobs', 3);
        $batchRow = DB::table('jobs')->get()->first(
            function (object $row): bool {
                return $this->payloadCommand($row->payload)->operation() === CrmCleanupOutboxJob::GALLERY_PHOTOS_SEARCH;
            },
        );
        $this->assertNotNull($batchRow);
        $batchCommand = $this->payloadCommand($batchRow->payload);
        $capturedIds = $batchCommand->searchIds();
        sort($capturedIds);
        $this->assertSame($photoIds, $capturedIds);

        $this->app->instance(DispatcherContract::class, $realDispatcher);
        $this->app->forgetInstance(DurableDispatchService::class);
        $this->app->forgetInstance(GalleryPhotoCleanupService::class);
        config(['queue.default' => 'database']);

        $workerStartedAt = time();
        $this->runOutboxWorker();
        $workerStoppedAt = time();

        $this->assertSame(1, $childAttempts);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('failed_jobs', 0);

        $releasedBatch = $this->outboxRow(CrmCleanupOutboxJob::GALLERY_PHOTOS_SEARCH);
        $this->assertSame(1, (int) $releasedBatch->attempts, 'The worker must attempt the released intent exactly once.');
        $this->assertNull($releasedBatch->reserved_at, 'A released intent must not stay reserved.');
        $this->assertGreaterThanOrEqual(
            $workerStartedAt + 30,
            (int) $releasedBatch->available_at,
            'The release must use the first step of the declared backoff policy.',
        );
        $this->assertLessThanOrEqual(
            $workerStoppedAt + 30,
            (int) $releasedBatch->available_at,
            'The release must not become available before the first backoff step elapsed.',
        );

        DB::table('jobs')->update(['available_at' => time()]);
        $this->runOutboxWorker();
        $this->assertSame(2, $childAttempts);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);

        $duplicate = new SyncGalleryPhotosSearchJob((string) $gallery->getKey(), $photoIds);
        $duplicate->handle();
        $duplicate->handle();
        $this->assertSame(5, $engineCalls);

        $childKeys = array_merge(...array_filter($deletedKeys));
        sort($childKeys);
        $this->assertSame($photoIds, array_values(array_unique($childKeys)));
    }

    public function test_gallery_scout_removal_is_deferred_until_commit_and_idempotent(): void
    {
        $this->assertSame(5, (new SyncGalleryPhotoSearchJob(SyncGalleryPhotoSearchJob::GALLERY, (string) Str::uuid()))->tries);
        $this->assertSame([30, 60, 120, 300, 600], (new SyncGalleryPhotoSearchJob(SyncGalleryPhotoSearchJob::GALLERY, (string) Str::uuid()))->backoff);

        $gallery = Gallery::withoutSyncingToSearch(
            static fn (): Gallery => Gallery::factory()->create(),
        );
        $galleryId = (string) $gallery->getKey();
        $this->fakeScoutEngine(Gallery::class, $galleryId, 2);
        Queue::fake();

        DB::transaction(function () use ($galleryId): void {
            $lockedGallery = Gallery::query()
                ->whereKey($galleryId)
                ->lockForUpdate()
                ->firstOrFail();
            $deleted = Gallery::withoutSyncingToSearch(
                static fn (): bool => $lockedGallery->delete(),
            );
            $this->assertTrue($deleted);
            app(GalleryPhotoCleanupService::class)->afterGalleryDelete(
                $galleryId,
                'manual_gallery_search',
            );
            $this->assertSame(0, $this->engineCalls);
        });

        // Queue::fake records the post-commit dispatch but does not execute it.
        $this->assertSame(0, $this->engineCalls);
        Queue::assertPushed(SyncGalleryPhotoSearchJob::class, static function (SyncGalleryPhotoSearchJob $job) use ($galleryId): bool {
            return $job->modelType() === SyncGalleryPhotoSearchJob::GALLERY
                && $job->modelId() === $galleryId
                && $job->operation() === SyncGalleryPhotoSearchJob::REMOVE;
        });
        $this->assertDatabaseCount('jobs', 0);

        $job = Queue::pushed(SyncGalleryPhotoSearchJob::class)->first();
        $this->assertInstanceOf(SyncGalleryPhotoSearchJob::class, $job);
        $job->handle();
        $job->handle();
        $this->assertSame(2, $this->engineCalls);
    }

    public function test_photo_scout_removal_is_deferred_and_uses_only_the_photo_key(): void
    {
        $gallery = Gallery::withoutSyncingToSearch(
            static fn (): Gallery => Gallery::factory()->create(),
        );
        $photo = Photo::withoutSyncingToSearch(
            static fn (): Photo => Photo::factory()->create(['gallery_id' => $gallery->id]),
        );
        $photoId = (string) $photo->getKey();
        $this->fakeScoutEngine(Photo::class, $photoId, 2);
        Queue::fake();

        DB::transaction(function () use ($photo): void {
            $lockedPhoto = Photo::query()
                ->whereKey($photo->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $deleted = Photo::withoutSyncingToSearch(
                static fn (): bool => $lockedPhoto->delete(),
            );
            $this->assertTrue($deleted);
            app(GalleryPhotoCleanupService::class)->afterPhotoDelete(
                (string) $lockedPhoto->gallery_id,
                $lockedPhoto->filename,
                (string) $lockedPhoto->getKey(),
                'manual_photo_search',
            );
            $this->assertSame(0, $this->engineCalls);
        });

        $this->assertSame(0, $this->engineCalls);
        Queue::assertPushed(SyncGalleryPhotoSearchJob::class, static function (SyncGalleryPhotoSearchJob $job) use ($photoId): bool {
            return $job->modelType() === SyncGalleryPhotoSearchJob::PHOTO
                && $job->modelId() === $photoId
                && $job->operation() === SyncGalleryPhotoSearchJob::REMOVE;
        });

        $job = Queue::pushed(SyncGalleryPhotoSearchJob::class)->first();
        $this->assertInstanceOf(SyncGalleryPhotoSearchJob::class, $job);
        $job->handle();
        $job->handle();
        $this->assertSame(2, $this->engineCalls);
    }

    public function test_gallery_scout_fallback_retries_after_engine_failure_and_is_idempotent(): void
    {
        $galleryId = (string) Str::uuid();
        $this->fakeScoutEngine(Gallery::class, $galleryId, 4, failFirstAttempt: true);
        $realDispatcher = app(DispatcherContract::class);
        $this->failQueueDispatch();

        // No database row is needed: the retry job must remove a deleted model
        // by index/key alone.
        app(GalleryPhotoCleanupService::class)->afterGalleryDelete($galleryId, 'scout_worker_retry');
        $this->assertDatabaseCount('jobs', 2);

        $this->app->instance(DispatcherContract::class, $realDispatcher);
        $this->app->forgetInstance(DurableDispatchService::class);
        $this->app->forgetInstance(GalleryPhotoCleanupService::class);
        config(['queue.default' => 'database']);

        $workerStartedAt = time();
        $this->runOutboxWorker();
        $workerStoppedAt = time();

        $this->assertSame(1, $this->engineCalls);
        $reserved = $this->outboxRow(CrmCleanupOutboxJob::GALLERY_SEARCH);
        $this->assertSame(1, (int) $reserved->attempts, 'The worker must attempt the released intent exactly once.');
        $this->assertNull($reserved->reserved_at, 'A released intent must not stay reserved.');
        $this->assertGreaterThanOrEqual(
            $workerStartedAt + 30,
            (int) $reserved->available_at,
            'The release must use the first step of the declared backoff policy.',
        );
        $this->assertLessThanOrEqual(
            $workerStoppedAt + 30,
            (int) $reserved->available_at,
            'The release must not become available before the first backoff step elapsed.',
        );
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('failed_jobs', 0);

        $intent = $this->payloadCommand($reserved->payload);
        $this->assertInstanceOf(CrmCleanupOutboxJob::class, $intent);
        $this->assertSame(5, $intent->tries, 'The durable intent must carry the five-try policy.');
        $this->assertSame([30, 60, 120, 300, 600], $intent->backoff);

        DB::table('jobs')->where('id', $reserved->id)->update(['available_at' => time()]);
        $this->runOutboxWorker();

        $this->assertSame(2, $this->engineCalls);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);

        // A duplicate delivery after the row was deleted remains harmless.
        $duplicate = new SyncGalleryPhotoSearchJob(SyncGalleryPhotoSearchJob::GALLERY, $galleryId);
        $duplicate->handle();
        $duplicate->handle();
        $this->assertSame(4, $this->engineCalls);
    }

    private int $engineCalls = 0;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));

        return $user;
    }

    /**
     * Drain the durable outbox with an in-process database worker.
     *
     * The worker runs inside the PHPUnit process, so its own stop conditions
     * must not be able to truncate the queue: `queue:work` defaults to
     * `--memory=128` and `Worker::stopIfNecessary()` silently returns after the
     * first job as soon as the *test runner* has grown past that budget. A full
     * sequential suite sits at roughly 160 MB when it reaches this class, and a
     * paratest worker that already ran other classes is above the limit too.
     * The durability contract is therefore asserted with the memory guard
     * disabled explicitly (0 = off).
     *
     * `--sleep=0` only removes the default 3 s idle wait that `--stop-when-empty`
     * does not need, and the exit code is asserted so a truncated worker can
     * never hide behind a downstream row assertion.
     */
    private function runOutboxWorker(): void
    {
        $this->artisan('queue:work', [
            'database',
            '--queue' => 'default',
            '--stop-when-empty' => true,
            '--memory' => 0,
            '--sleep' => 0,
        ])->assertExitCode(0)->run();
    }

    /**
     * Resolve the single durable intent that belongs to one cleanup operation.
     *
     * The jobs table holds one row per intent, so the row under assertion is
     * addressed by its serialized operation instead of an unordered `first()`.
     */
    private function outboxRow(string $operation): object
    {
        foreach (DB::table('jobs')->get() as $row) {
            $command = $this->payloadCommand($row->payload);
            if ($command instanceof CrmCleanupOutboxJob && $command->operation() === $operation) {
                return $row;
            }
        }

        $this->fail("No durable cleanup intent for operation [{$operation}] is left in the jobs table.");
    }

    private function bindScoutEngine(Engine $engine): void
    {
        $manager = Mockery::mock(EngineManager::class);
        $manager->shouldReceive('engine')->andReturn($engine);
        $this->app->instance(EngineManager::class, $manager);
    }

    private function fakeScoutEngine(
        string $modelClass,
        string $modelId,
        int $expectedCalls,
        bool $failFirstAttempt = false,
    ): void {
        $engine = Mockery::mock(Engine::class);
        $engine->shouldReceive('delete')
            ->times($expectedCalls)
            ->andReturnUsing(function ($models) use ($modelClass, $modelId, $failFirstAttempt): void {
                $this->engineCalls++;
                $model = $models->first();
                $this->assertInstanceOf($modelClass, $model);
                $this->assertSame($modelId, (string) $model->getScoutKey());

                if ($failFirstAttempt && $this->engineCalls === 1) {
                    throw new RuntimeException('temporary Scout outage');
                }
            });

        $this->bindScoutEngine($engine);
    }

    private function failQueueDispatch(): void
    {
        $dispatcher = Mockery::mock(DispatcherContract::class);
        $dispatcher->shouldReceive('dispatch')
            ->andThrow(new RuntimeException('queue transport unavailable'));
        $this->app->instance(DispatcherContract::class, $dispatcher);
        $this->app->forgetInstance(DurableDispatchService::class);
        $this->app->forgetInstance(GalleryPhotoCleanupService::class);
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
