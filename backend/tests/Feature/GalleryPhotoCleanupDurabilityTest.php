<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\CrmCleanupOutboxJob;
use App\Jobs\DeleteGalleryFolderJob;
use App\Jobs\DeletePhotoFilesJob;
use App\Jobs\SyncGalleryPhotoSearchJob;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use App\Services\DurableDispatchService;
use App\Services\GalleryPhotoCleanupService;
use App\Services\ModelFileStore;
use Illuminate\Contracts\Bus\Dispatcher as DispatcherContract;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Regression coverage for the DB/filesystem boundary of public gallery and
 * photo deletion. The encrypted model-file path has separate coverage in the
 * CRM cleanup tests and is intentionally not reused here.
 */
class GalleryPhotoCleanupDurabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryStorageDisk('photos');
        config([
            'scout.driver' => 'null',
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

    public function test_manual_gallery_delete_commits_before_folder_cleanup_and_rolls_back_on_db_failure(): void
    {
        Queue::fake();
        $gallery = Gallery::factory()->create();
        $galleryId = (string) $gallery->getKey();
        Storage::disk('photos')->makeDirectory($galleryId);
        Storage::disk('photos')->put("{$galleryId}/original.jpg", 'image');

        Gallery::deleting(static fn (): bool => false);

        $this->actingAs($this->admin(), 'api')
            ->deleteJson("/api/management/galleries/{$galleryId}")
            ->assertStatus(500);

        $this->assertDatabaseHas('galleries', ['id' => $galleryId]);
        Storage::disk('photos')->assertExists("{$galleryId}/original.jpg");
        $this->assertDatabaseCount('jobs', 0);
        Queue::assertNotPushed(DeleteGalleryFolderJob::class);
    }

    public function test_durable_intent_rolls_back_with_the_business_transaction(): void
    {
        Queue::fake();
        $gallery = Gallery::factory()->create();
        $galleryId = (string) $gallery->getKey();
        Storage::disk('photos')->put("{$galleryId}/original.jpg", 'image');

        try {
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
                    'rollback_boundary',
                );
                $this->assertDatabaseCount('jobs', 2);
                throw new RuntimeException('force business rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('force business rollback', $exception->getMessage());
        }

        $this->assertDatabaseHas('galleries', ['id' => $galleryId]);
        $this->assertDatabaseCount('jobs', 0);
        Storage::disk('photos')->assertExists("{$galleryId}/original.jpg");
        Queue::assertNotPushed(DeleteGalleryFolderJob::class);
    }

    public function test_manual_gallery_delete_dispatches_after_commit_and_succeeds_idempotently(): void
    {
        $gallery = Gallery::factory()->create();
        $galleryId = (string) $gallery->getKey();
        Storage::disk('photos')->makeDirectory($galleryId);
        Storage::disk('photos')->put("{$galleryId}/original.jpg", 'image');

        $this->actingAs($this->admin(), 'api')
            ->deleteJson("/api/management/galleries/{$galleryId}")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('galleries', ['id' => $galleryId]);
        Storage::disk('photos')->assertMissing($galleryId);
        $this->assertDatabaseCount('jobs', 0);

        // A duplicate delivery after the successful attempt is a no-op.
        (new DeleteGalleryFolderJob($galleryId))->handle();
        Storage::disk('photos')->assertMissing($galleryId);
    }

    public function test_fallback_intent_write_failure_rolls_back_gallery_and_is_critical(): void
    {
        config(['queue.connections.database.table' => 'missing_jobs_table']);
        Log::spy();
        $gallery = Gallery::factory()->create();
        $galleryId = (string) $gallery->getKey();
        Storage::disk('photos')->put("{$galleryId}/original.jpg", 'image');

        $this->actingAs($this->admin(), 'api')
            ->deleteJson("/api/management/galleries/{$galleryId}")
            ->assertStatus(500);

        $this->assertDatabaseHas('galleries', ['id' => $galleryId]);
        Storage::disk('photos')->assertExists("{$galleryId}/original.jpg");
        Log::shouldHaveReceived('critical')
            ->with('gallery.folder_cleanup.fallback_failed', Mockery::on(
                static fn (array $context): bool => $context['gallery_id'] === $galleryId
                    && isset($context['exception'])
            ))
            ->once();
    }

    public function test_manual_photo_delete_rolls_back_files_and_intent_when_row_deletion_fails(): void
    {
        Queue::fake();
        $gallery = Gallery::factory()->create();
        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
        ]);
        $filename = $photo->filename;
        $path = "{$gallery->id}/{$filename}";
        Storage::disk('photos')->put($path, 'image');

        Photo::deleting(static fn (): bool => false);

        $this->actingAs($this->admin(), 'api')
            ->deleteJson("/api/photos/{$photo->id}")
            ->assertStatus(500);

        $this->assertDatabaseHas('photos', ['id' => $photo->id]);
        Storage::disk('photos')->assertExists($path);
        $this->assertDatabaseCount('jobs', 0);
        Queue::assertNotPushed(DeletePhotoFilesJob::class);
    }

    public function test_manual_photo_delete_uses_a_distinct_durable_operation_after_commit(): void
    {
        Queue::fake();
        $gallery = Gallery::factory()->create();
        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
        ]);
        $filename = $photo->filename;
        $path = "{$gallery->id}/{$filename}";
        Storage::disk('photos')->put($path, 'image');

        $this->actingAs($this->admin(), 'api')
            ->deleteJson("/api/photos/{$photo->id}")
            ->assertOk();

        $this->assertDatabaseMissing('photos', ['id' => $photo->id]);
        Queue::assertPushed(DeletePhotoFilesJob::class, static function (DeletePhotoFilesJob $job) use ($gallery, $photo, $filename): bool {
            return $job->galleryId() === (string) $gallery->id
                && $job->filename() === $filename
                && $job->photoId() === (string) $photo->id;
        });
        Queue::assertPushed(SyncGalleryPhotoSearchJob::class, static function (SyncGalleryPhotoSearchJob $job) use ($photo): bool {
            return $job->modelType() === SyncGalleryPhotoSearchJob::PHOTO
                && $job->modelId() === (string) $photo->id
                && $job->operation() === SyncGalleryPhotoSearchJob::REMOVE;
        });
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_manual_photo_delete_success_removes_public_derivatives_after_commit(): void
    {
        $gallery = Gallery::factory()->create();
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $filename = $photo->filename;
        $paths = [
            "{$gallery->id}/{$filename}",
            "{$gallery->id}/_watermarked/{$filename}",
            "{$gallery->id}/_thumbs/800/{$photo->id}.webp",
        ];
        foreach ($paths as $path) {
            Storage::disk('photos')->put($path, 'image');
        }

        $this->actingAs($this->admin(), 'api')
            ->deleteJson("/api/photos/{$photo->id}")
            ->assertOk();

        $this->assertDatabaseMissing('photos', ['id' => $photo->id]);
        foreach ($paths as $path) {
            Storage::disk('photos')->assertMissing($path);
        }
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_queue_failure_leaves_a_retryable_outbox_row_for_manual_gallery_delete(): void
    {
        $this->failQueueDispatch();
        Log::spy();
        $gallery = Gallery::factory()->create();
        $galleryId = (string) $gallery->getKey();
        Storage::disk('photos')->put("{$galleryId}/original.jpg", 'image');

        $this->actingAs($this->admin(), 'api')
            ->deleteJson("/api/management/galleries/{$galleryId}")
            ->assertOk();

        $rows = DB::table('jobs')->get();
        $this->assertCount(2, $rows);
        $commands = [];
        foreach ($rows as $row) {
            $this->assertTrue(Str::isUuid($row->id));
            $command = $this->payloadCommand($row->payload);
            $this->assertInstanceOf(CrmCleanupOutboxJob::class, $command);
            $commands[] = $command;
        }

        $operations = array_map(
            static fn (CrmCleanupOutboxJob $command): string => $command->operation(),
            $commands,
        );
        $this->assertContains(CrmCleanupOutboxJob::GALLERY_FOLDER, $operations);
        $this->assertContains(CrmCleanupOutboxJob::GALLERY_SEARCH, $operations);
        foreach ($commands as $command) {
            if ($command->operation() === CrmCleanupOutboxJob::GALLERY_FOLDER) {
                $this->assertSame($galleryId, $command->galleryId());
                $this->assertSame([], $command->paths());
            }
        }
        Storage::disk('photos')->assertExists("{$galleryId}/original.jpg");

        Log::shouldHaveReceived('error')
            ->with('gallery.folder_cleanup.dispatch_failed', Mockery::on(
                static fn (array $context): bool => $context['gallery_id'] === $galleryId
                    && isset($context['fallback_job_id'])
            ))
            ->once();
        Log::shouldHaveReceived('error')
            ->with('gallery.search_cleanup.dispatch_failed', Mockery::on(
                static fn (array $context): bool => $context['gallery_id'] === $galleryId
                    && isset($context['fallback_job_id'])
            ))
            ->once();
    }

    public function test_queue_failure_leaves_a_retryable_outbox_row_for_manual_photo_delete(): void
    {
        $this->failQueueDispatch();
        $gallery = Gallery::factory()->create();
        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
        ]);
        $filename = $photo->filename;

        $this->actingAs($this->admin(), 'api')
            ->deleteJson("/api/photos/{$photo->id}")
            ->assertOk();

        $rows = DB::table('jobs')->get();
        $this->assertCount(2, $rows);
        $commands = [];
        foreach ($rows as $row) {
            $command = $this->payloadCommand($row->payload);
            $this->assertInstanceOf(CrmCleanupOutboxJob::class, $command);
            $commands[] = $command;
        }

        $operations = array_map(
            static fn (CrmCleanupOutboxJob $command): string => $command->operation(),
            $commands,
        );
        $this->assertContains(CrmCleanupOutboxJob::PHOTO_FILES, $operations);
        $this->assertContains(CrmCleanupOutboxJob::PHOTO_SEARCH, $operations);
        foreach ($commands as $command) {
            if ($command->operation() === CrmCleanupOutboxJob::PHOTO_FILES) {
                $this->assertSame((string) $gallery->id, $command->galleryId());
                $this->assertSame($filename, $command->filename());
                $this->assertSame((string) $photo->id, $command->photoId());
            } elseif ($command->operation() === CrmCleanupOutboxJob::PHOTO_SEARCH) {
                $this->assertSame((string) $photo->id, $command->photoId());
            }
        }
    }

    public function test_durable_intent_is_written_before_commit_and_survives_a_missing_after_commit_callback(): void
    {
        $realConnection = DB::connection();
        $capturedCallback = null;
        $deferredConnection = Mockery::mock(Connection::class);
        $deferredConnection->shouldReceive('transactionLevel')
            ->once()
            ->andReturn(1);
        $deferredConnection->shouldReceive('afterCommit')
            ->once()
            ->andReturnUsing(static function (callable $callback) use (&$capturedCallback): void {
                $capturedCallback = $callback;
            });

        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->andReturnUsing(
            static function (...$arguments) use ($deferredConnection, $realConnection): Connection {
                return $arguments === [] ? $deferredConnection : $realConnection;
            },
        );
        $dispatcher = Mockery::mock(DispatcherContract::class);
        $service = new DurableDispatchService($dispatcher, $resolver, app());
        $galleryId = (string) Str::uuid();

        $service->afterCommitDurably(
            new DeleteGalleryFolderJob($galleryId),
            CrmCleanupOutboxJob::forGalleryFolder($galleryId, 'post_commit_window'),
            'gallery.folder_cleanup',
            ['gallery_id' => $galleryId, 'reason' => 'post_commit_window'],
        );

        $this->assertNotNull($capturedCallback);
        $row = DB::table('jobs')->first();
        $this->assertNotNull($row);
        $this->assertTrue(Str::isUuid($row->id));
        $this->assertSame(
            CrmCleanupOutboxJob::GALLERY_FOLDER,
            $this->payloadCommand($row->payload)->operation(),
        );

        // Simulate a process crash: the callback is not run. The committed
        // intent remains available for a worker instead of being lost.
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_committed_intent_survives_a_process_stop_before_cleanup_callback(): void
    {
        $gallery = Gallery::factory()->create();
        $galleryId = (string) $gallery->getKey();
        Storage::disk('photos')->put("{$galleryId}/original.jpg", 'image');

        try {
            DB::transaction(function () use ($galleryId): void {
                // Register a callback before the cleanup callback. Simulate a
                // process-level failure after COMMIT but before the cleanup
                // callback gets a chance to run.
                DB::afterCommit(static function (): void {
                    throw new RuntimeException('simulated post-commit process stop');
                });
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
                    'post_commit_stop',
                );
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated post-commit process stop', $exception->getMessage());
        }

        $this->assertDatabaseMissing('galleries', ['id' => $galleryId]);
        $this->assertDatabaseCount('jobs', 2);
        Storage::disk('photos')->assertExists("{$galleryId}/original.jpg");
    }

    public function test_gallery_fallback_worker_retries_after_a_transient_disk_failure(): void
    {
        $realDispatcher = app(DispatcherContract::class);
        $this->failQueueDispatch();
        $galleryId = (string) Str::uuid();
        Storage::disk('photos')->put("{$galleryId}/original.jpg", 'image');

        app(GalleryPhotoCleanupService::class)->afterGalleryDelete($galleryId, 'worker_retry');
        $this->assertDatabaseCount('jobs', 2);

        $realDisk = Storage::disk('photos');
        $failingDisk = Mockery::mock();
        $failingDisk->shouldReceive('exists')->once()->andReturn(true);
        $failingDisk->shouldReceive('deleteDirectory')->once()->andReturn(false);
        Storage::set('photos', $failingDisk);
        $this->app->instance(DispatcherContract::class, $realDispatcher);
        $this->app->forgetInstance(DurableDispatchService::class);
        $this->app->forgetInstance(GalleryPhotoCleanupService::class);
        config(['queue.default' => 'database']);

        $this->artisan('queue:work', [
            'database',
            '--queue' => 'default',
            '--stop-when-empty' => true,
        ]);

        $reserved = DB::table('jobs')->first();
        $this->assertNotNull($reserved);
        $this->assertSame(1, (int) $reserved->attempts);
        $this->assertDatabaseCount('failed_jobs', 0);

        Storage::set('photos', $realDisk);
        DB::table('jobs')->where('id', $reserved->id)->update(['available_at' => time()]);
        $this->artisan('queue:work', [
            'database',
            '--queue' => 'default',
            '--stop-when-empty' => true,
        ]);

        Storage::disk('photos')->assertMissing($galleryId);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_photo_outbox_operation_is_idempotent_and_audits_completion(): void
    {
        $galleryId = (string) Str::uuid();
        $photoId = (string) Str::uuid();
        $path = "{$galleryId}/photo.jpg";
        Storage::disk('photos')->put($path, 'image');
        $job = CrmCleanupOutboxJob::forPhotoFiles(
            $galleryId,
            'photo.jpg',
            $photoId,
            'idempotent_photo',
        );
        Log::spy();

        $job->handle(app(ModelFileStore::class));
        $job->handle(app(ModelFileStore::class));

        Storage::disk('photos')->assertMissing($path);
        Log::shouldHaveReceived('info')
            ->with('crm.cleanup_outbox.completed', Mockery::on(
                static fn (array $context): bool => $context['operation'] === CrmCleanupOutboxJob::PHOTO_FILES
                    && $context['gallery_id'] === $galleryId
                    && $context['photo_id'] === $photoId
            ))
            ->twice();
    }

    public function test_scheduled_cleanup_keeps_files_and_reports_db_failure_without_a_queue_intent(): void
    {
        $gallery = Gallery::factory()->create(['expires_at' => now()->subMonths(4)]);
        $galleryId = (string) $gallery->getKey();
        Storage::disk('photos')->put("{$galleryId}/original.jpg", 'image');
        Gallery::deleting(static fn (): bool => false);

        $this->artisan('app:cleanup-galleries')
            ->assertExitCode(1);

        $this->assertDatabaseHas('galleries', ['id' => $galleryId]);
        Storage::disk('photos')->assertExists("{$galleryId}/original.jpg");
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_scheduled_cleanup_queue_failure_keeps_a_retryable_folder_outbox_row(): void
    {
        $this->failQueueDispatch();
        $gallery = Gallery::factory()->create(['expires_at' => now()->subMonths(4)]);
        $galleryId = (string) $gallery->getKey();
        Storage::disk('photos')->put("{$galleryId}/original.jpg", 'image');

        $this->artisan('app:cleanup-galleries')
            ->assertExitCode(0);

        $rows = DB::table('jobs')->get();
        $this->assertCount(2, $rows);
        $operations = [];
        foreach ($rows as $row) {
            $command = $this->payloadCommand($row->payload);
            $this->assertInstanceOf(CrmCleanupOutboxJob::class, $command);
            $operations[] = $command->operation();
        }
        $this->assertContains(CrmCleanupOutboxJob::GALLERY_FOLDER, $operations);
        $this->assertContains(CrmCleanupOutboxJob::GALLERY_SEARCH, $operations);
        Storage::disk('photos')->assertExists("{$galleryId}/original.jpg");
    }

    public function test_scheduled_cleanup_dispatches_folder_cleanup_after_commit_and_preserves_success(): void
    {
        $expired = Gallery::factory()->create(['expires_at' => now()->subMonths(4)]);
        $valid = Gallery::factory()->create(['expires_at' => now()->addMonth()]);
        Storage::disk('photos')->put("{$expired->id}/original.jpg", 'image');
        Storage::disk('photos')->put("{$valid->id}/original.jpg", 'image');

        $this->artisan('app:cleanup-galleries')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('galleries', ['id' => $expired->id]);
        $this->assertDatabaseHas('galleries', ['id' => $valid->id]);
        Storage::disk('photos')->assertMissing((string) $expired->id);
        Storage::disk('photos')->assertExists((string) $valid->id);
        $this->assertDatabaseCount('jobs', 0);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));

        return $user;
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
