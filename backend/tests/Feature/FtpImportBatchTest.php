<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Http\Controllers\FtpController;
use App\Jobs\ImportFtpBatch;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use App\Services\FtpInboxImporter;
use App\Services\PhotoProcessingService;
use App\Values\FtpImportResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The self-chaining background import (D-36).
 *
 * Every test here asserts the *behaviour of the chain*, not a status code: how
 * many runs happen, how many photos each of them writes, and — most importantly —
 * that the chain stops. A self-re-enqueueing job without a pinned exit is an
 * infinite loop, and an infinite loop in a queue worker is not visible in a
 * response body, so it has to be pinned by counting runs.
 *
 * The chain is driven the way a worker drives it: dispatch once, then execute
 * every job the chain enqueues, in order, until it stops enqueuing.
 */
class FtpImportBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('ftp_inbox');
        Storage::fake('photos');
    }

    public function test_fifty_files_are_drained_in_three_batches_without_a_manual_call(): void
    {
        [$user] = $this->importContext();
        for ($index = 2; $index <= 50; $index++) {
            $this->putSampleImage($user, sprintf('batch-%02d.jpg', $index));
        }

        $sampleBytes = $this->sampleImageBytes();
        $this->mockFastImageProcessing(50);
        config([
            'ftp.import.max_files' => 20,
            'ftp.import.max_file_bytes' => $sampleBytes,
            'ftp.import.max_batch_bytes' => 50 * $sampleBytes,
        ]);

        $batches = $this->drainChain($user);

        $this->assertSame([20, 20, 10], $batches, 'Three batches of 20/20/10, and no fourth run.');
        $this->assertDatabaseCount('photos', 50);
        $this->assertSame([], Storage::disk('ftp_inbox')->files($user->ftp_slug));
    }

    /**
     * A file the import cannot process makes the batch import nothing. If the
     * chain re-enqueued itself, the next run would select the same file and the
     * chain would spin for as long as the worker runs — so this is the exit that
     * makes "no progress" a stop condition rather than a retry.
     */
    public function test_a_batch_that_imports_nothing_does_not_re_enqueue(): void
    {
        [$user, $sourcePath] = $this->importContext();
        Storage::disk('ftp_inbox')->put($sourcePath, '<!doctype html><title>not an image</title>');
        Queue::fake();

        (new ImportFtpBatch($user->id))->handle(app(FtpInboxImporter::class));

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('photos', 0);
        $this->assertTrue(Storage::disk('ftp_inbox')->exists($sourcePath));
    }

    /**
     * D-37 makes the same situation terminal instead of blocking: the
     * unimportable file moves aside and the run continues with the rest. The
     * chain then ends because the inbox holds nothing else — not because it ran
     * out of attempts.
     */
    public function test_a_quarantined_file_ends_the_chain_after_one_run(): void
    {
        [$user, $badPath] = $this->importContext();
        Storage::disk('ftp_inbox')->put($badPath, '<!doctype html><title>not an image</title>');
        $this->putSampleImage($user, 'good.jpg');

        $this->mockFastImageProcessing(1);
        config([
            'ftp.import.max_files' => 20,
            'ftp.import.max_file_bytes' => 1024 * 1024,
            'ftp.import.max_batch_bytes' => 10 * 1024 * 1024,
            'ftp.import.quarantine_after_attempts' => 1,
        ]);

        $batches = $this->drainChain($user);

        $this->assertSame([1], $batches, 'One run: the quarantine is terminal, not a loop.');
        $this->assertDatabaseCount('photos', 1);
        $this->assertSame([], Storage::disk('ftp_inbox')->files($user->ftp_slug));
        $this->assertCount(
            1,
            Storage::disk('ftp_inbox')->files($user->ftp_slug.'/'.FtpInboxImporter::QUARANTINE_DIRECTORY),
        );
    }

    /**
     * The counter lives in the payload, so the bound survives a worker restart
     * and is per trigger. Three attempts, then an error log and no fourth job.
     */
    public function test_a_permanently_failing_batch_stops_the_chain_with_an_error_log(): void
    {
        [$user] = $this->importContext();
        config([
            'ftp.import.max_files' => 20,
            'ftp.import.max_file_bytes' => 1024 * 1024,
            'ftp.import.max_batch_bytes' => 10 * 1024 * 1024,
            'ftp.import.chain_max_failures' => 3,
        ]);

        $photoService = $this->createMock(PhotoProcessingService::class);
        $photoService->method('processImage')->willThrowException(new \RuntimeException('simulated storage failure'));
        $this->app->instance(PhotoProcessingService::class, $photoService);

        Log::spy();

        Queue::fake();
        ImportFtpBatch::dispatch($user->id);

        $importer = app(FtpInboxImporter::class);
        $handled = 0;

        // The worker's view of a failing chain: run the job, hand the exception
        // to `failed()`, and see whether a further attempt appears.
        while ($handled < 10) {
            $pushed = Queue::pushed(ImportFtpBatch::class)->values();

            if ($handled >= $pushed->count()) {
                break;
            }

            $job = $pushed->get($handled);
            $handled++;

            try {
                $job->handle($importer);
                $this->fail('The batch was expected to throw.');
            } catch (\RuntimeException $exception) {
                $job->failed($exception);
            }
        }

        $this->assertSame(3, $handled, 'chain_max_failures = 3 must end the chain after three attempts.');
        $this->assertSame(3, Queue::pushed(ImportFtpBatch::class)->count());
        Log::shouldHaveReceived('error')
            ->withArgs(static fn (mixed $message): bool => is_string($message) && str_contains($message, 'nach wiederholtem Fehlschlag abgebrochen'))
            ->once();
    }

    public function test_the_chain_stops_at_the_configured_batch_limit(): void
    {
        [$user] = $this->importContext();
        for ($index = 2; $index <= 100; $index++) {
            $this->putSampleImage($user, sprintf('many-%03d.jpg', $index));
        }

        $sampleBytes = $this->sampleImageBytes();
        $this->mockFastImageProcessing(40);
        config([
            'ftp.import.max_files' => 20,
            'ftp.import.max_file_bytes' => $sampleBytes,
            'ftp.import.max_batch_bytes' => 100 * $sampleBytes,
            'ftp.import.chain_max_batches' => 2,
        ]);

        $batches = $this->drainChain($user);

        $this->assertSame([20, 20], $batches);
        $this->assertCount(60, Storage::disk('ftp_inbox')->files($user->ftp_slug));
    }

    /**
     * The job must not carry a queue middleware that could drop the self-dispatch.
     *
     * Under `QUEUE_CONNECTION=sync` the self-dispatch inside `handle()` runs
     * inline while this job is still inside its middleware pipeline. A
     * `WithoutOverlapping(...)->dontRelease()` would fail to acquire its own lock
     * there and drop the next batch **silently** — no `handle()`, no log, no
     * released job — so the chain would stop after one batch with the files still
     * in the inbox. The guard belongs to the importer's lock, which the importer
     * takes and releases inside `importBatch()`, never across the dispatch.
     *
     * Asserted as "no middleware" rather than "no `WithoutOverlapping`": any
     * middleware that can swallow the job is the same defect.
     */
    public function test_the_job_declares_no_overlap_middleware(): void
    {
        $this->assertSame([], (new ImportFtpBatch('user-123'))->middleware());
    }

    /**
     * The chain drains the inbox under the sync queue, in more than one batch.
     *
     * This is the regression test for the silent chain-breaker: with the job
     * middleware the second batch was dropped before `handle()` ran, so exactly
     * one batch was imported and the rest stayed. Dispatching once and letting the
     * `sync` driver run every self-dispatch inline is the real local/CI/E2E shape,
     * not a simulation of it.
     */
    public function test_the_chain_drains_the_inbox_under_the_sync_queue(): void
    {
        [$user] = $this->importContext();
        for ($index = 2; $index <= 50; $index++) {
            $this->putSampleImage($user, sprintf('sync-%02d.jpg', $index));
        }

        $sampleBytes = $this->sampleImageBytes();
        $this->mockFastImageProcessing(50);
        config([
            'ftp.import.max_files' => 20,
            'ftp.import.max_file_bytes' => $sampleBytes,
            'ftp.import.max_batch_bytes' => 50 * $sampleBytes,
            'queue.default' => 'sync',
        ]);

        $recorder = new RecordingFtpInboxImporter(app(PhotoProcessingService::class));
        $this->app->instance(FtpInboxImporter::class, $recorder);

        ImportFtpBatch::dispatch($user->id);

        $this->assertCount(3, $recorder->runs, 'One dispatch must drain all 50 files in three inline batches.');
        $this->assertSame([20, 20, 10], $recorder->runs);
        $this->assertDatabaseCount('photos', 50);
        $this->assertSame([], Storage::disk('ftp_inbox')->files($user->ftp_slug));
    }

    public function test_an_upload_event_during_a_locked_import_is_handed_off_without_double_importing(): void
    {
        [$user] = $this->importContext();
        $latePath = $user->ftp_slug.'/arrived-during-import.jpg';
        $postUnlockPath = $user->ftp_slug.'/arrived-after-unlock.jpg';
        $eventDispatched = false;

        config([
            'ftp.import.max_files' => 1,
            'ftp.import.max_file_bytes' => $this->sampleImageBytes(),
            'ftp.import.max_batch_bytes' => 10 * $this->sampleImageBytes(),
            'queue.default' => 'sync',
        ]);

        $photoService = $this->createMock(PhotoProcessingService::class);
        $photoService->expects($this->exactly(3))
            ->method('processImage')
            ->willReturnCallback(function () use ($user, $latePath, &$eventDispatched): array {
                if (! $eventDispatched) {
                    $eventDispatched = true;
                    Storage::disk('ftp_inbox')->put($latePath, $this->sampleImageContents());

                    // The active import already selected its original one-file
                    // batch and still owns the lock. This is the synchronous
                    // queue's exact nested-event shape.
                    (new ImportFtpBatch($user->id, uploadEvent: true))->handle(app(FtpInboxImporter::class));
                }

                return ['width' => 1, 'height' => 1, 'title' => 'source'];
            });

        $importer = new RecordingFtpInboxImporter($photoService);
        $this->app->instance(PhotoProcessingService::class, $photoService);
        $this->app->instance(FtpInboxImporter::class, $importer);

        ImportFtpBatch::dispatch($user->id);

        // First run imports the original snapshot, the nested event loses the
        // lock, and the active chain imports the newly arrived file next.
        $this->assertSame([0, 1, 1], $importer->runs);
        $this->assertDatabaseCount('photos', 2);
        $this->assertSame([], Storage::disk('ftp_inbox')->files($user->ftp_slug));

        // An event after unlock acquires the lock itself and imports normally.
        Storage::disk('ftp_inbox')->put($postUnlockPath, $this->sampleImageContents());
        (new ImportFtpBatch($user->id, uploadEvent: true))->handle($importer);

        $this->assertSame([0, 1, 1, 1], $importer->runs);
        $this->assertDatabaseCount('photos', 3);
        $this->assertSame([], Storage::disk('ftp_inbox')->files($user->ftp_slug));
    }

    public function test_an_upload_event_during_a_rejected_import_is_handed_off_and_keeps_the_rejection_visible(): void
    {
        [$user] = $this->importContext();
        $latePath = $user->ftp_slug.'/z-arrived-during-rejected-import.jpg';
        $badPath = $user->ftp_slug.'/b-unprocessable.jpg';
        Storage::disk('ftp_inbox')->put($badPath, '<!doctype html><title>not an image</title>');
        $eventDispatched = false;

        config([
            'ftp.import.max_files' => 20,
            'ftp.import.max_file_bytes' => $this->sampleImageBytes(),
            'ftp.import.max_batch_bytes' => 10 * $this->sampleImageBytes(),
            'ftp.import.quarantine_after_attempts' => 2,
            'queue.default' => 'sync',
        ]);

        $photoService = $this->createMock(PhotoProcessingService::class);
        $photoService->expects($this->exactly(2))
            ->method('processImage')
            ->willReturnCallback(function () use ($user, $latePath, &$eventDispatched): array {
                if (! $eventDispatched) {
                    $eventDispatched = true;
                    Storage::disk('ftp_inbox')->put($latePath, $this->sampleImageContents());

                    // The event runs under the sync driver while the active
                    // import still owns the lock, then returns Locked.
                    (new ImportFtpBatch($user->id, uploadEvent: true))->handle(app(FtpInboxImporter::class));
                }

                return ['width' => 1, 'height' => 1, 'title' => 'source'];
            });

        $importer = new RecordingFtpInboxImporter($photoService);
        $this->app->instance(PhotoProcessingService::class, $photoService);
        $this->app->instance(FtpInboxImporter::class, $importer);
        Log::spy();

        ImportFtpBatch::dispatch($user->id);

        // The active run imports the original photo, then rejects the bad file.
        // Its event handoff runs one follow-up, which quarantines that file and
        // imports the newly arrived photo exactly once.
        $this->assertSame([0, 1, 1], $importer->runs);
        $this->assertDatabaseCount('photos', 2);
        $this->assertSame([], Storage::disk('ftp_inbox')->files($user->ftp_slug));
        $this->assertCount(
            1,
            Storage::disk('ftp_inbox')->files($user->ftp_slug.'/'.FtpInboxImporter::QUARANTINE_DIRECTORY),
        );

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (mixed $message, mixed $context): bool => is_string($message)
                && str_contains($message, 'Batch abgelehnt')
                && is_array($context)
                && ($context['status'] ?? null) === 'source_unprocessable'
                && ($context['error'] ?? null) === 'Die FTP-Datei ist kein gültiges oder unterstütztes Bild.')
            ->once();
    }

    public function test_a_rejected_upload_event_handoff_queues_follow_up_under_an_async_driver(): void
    {
        [$user] = $this->importContext();
        $latePath = $user->ftp_slug.'/z-arrived-during-async-rejected-import.jpg';
        $badPath = $user->ftp_slug.'/b-unprocessable.jpg';
        Storage::disk('ftp_inbox')->put($badPath, '<!doctype html><title>not an image</title>');
        $eventDispatched = false;

        config([
            'ftp.import.max_files' => 20,
            'ftp.import.max_file_bytes' => $this->sampleImageBytes(),
            'ftp.import.max_batch_bytes' => 10 * $this->sampleImageBytes(),
            'ftp.import.quarantine_after_attempts' => 2,
            'queue.default' => 'database',
        ]);
        Queue::fake();

        $photoService = $this->createMock(PhotoProcessingService::class);
        $photoService->expects($this->exactly(2))
            ->method('processImage')
            ->willReturnCallback(function () use ($user, $latePath, &$eventDispatched): array {
                if (! $eventDispatched) {
                    $eventDispatched = true;
                    Storage::disk('ftp_inbox')->put($latePath, $this->sampleImageContents());

                    // Deterministically model an async event worker starting
                    // while the active job holds the lock. The event marks its
                    // generation, loses the lock, and the rejected active run
                    // must queue a post-unlock follow-up.
                    (new ImportFtpBatch($user->id, uploadEvent: true))->handle(app(FtpInboxImporter::class));
                }

                return ['width' => 1, 'height' => 1, 'title' => 'source'];
            });

        $importer = new RecordingFtpInboxImporter($photoService);
        $this->app->instance(PhotoProcessingService::class, $photoService);
        $this->app->instance(FtpInboxImporter::class, $importer);
        Log::spy();

        (new ImportFtpBatch($user->id))->handle($importer);

        Queue::assertPushed(ImportFtpBatch::class, 1);
        $followUp = Queue::pushed(ImportFtpBatch::class)->first();
        $this->assertInstanceOf(ImportFtpBatch::class, $followUp);
        $this->assertSame(2, $followUp->batchNumber);
        $this->assertSame([0, 1], $importer->runs);
        $this->assertDatabaseCount('photos', 1);
        $this->assertTrue(Storage::disk('ftp_inbox')->exists($latePath));

        // The queue worker runs the handoff only after the active job released
        // its lock; it quarantines the repeatedly rejected source and imports
        // the event's new file without duplicating the first photo.
        $followUp->handle($importer);

        $this->assertSame([0, 1, 1], $importer->runs);
        $this->assertDatabaseCount('photos', 2);
        $this->assertSame([], Storage::disk('ftp_inbox')->files($user->ftp_slug));
        $this->assertCount(
            1,
            Storage::disk('ftp_inbox')->files($user->ftp_slug.'/'.FtpInboxImporter::QUARANTINE_DIRECTORY),
        );

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (mixed $message, mixed $context): bool => is_string($message)
                && str_contains($message, 'Batch abgelehnt')
                && is_array($context)
                && ($context['status'] ?? null) === 'source_unprocessable'
                && ($context['error'] ?? null) === 'Die FTP-Datei ist kein gültiges oder unterstütztes Bild.')
            ->once();
    }

    public function test_a_manual_import_hands_a_concurrent_upload_event_to_the_background_chain(): void
    {
        [$user] = $this->importContext();
        $latePath = $user->ftp_slug.'/arrived-during-manual-import.jpg';
        $eventDispatched = false;

        config([
            'ftp.import.max_files' => 1,
            'ftp.import.max_file_bytes' => $this->sampleImageBytes(),
            'ftp.import.max_batch_bytes' => 10 * $this->sampleImageBytes(),
            'queue.default' => 'sync',
        ]);

        $photoService = $this->createMock(PhotoProcessingService::class);
        $photoService->expects($this->exactly(2))
            ->method('processImage')
            ->willReturnCallback(function () use ($user, $latePath, &$eventDispatched): array {
                if (! $eventDispatched) {
                    $eventDispatched = true;
                    Storage::disk('ftp_inbox')->put($latePath, $this->sampleImageContents());
                    (new ImportFtpBatch($user->id, uploadEvent: true))->handle(app(FtpInboxImporter::class));
                }

                return ['width' => 1, 'height' => 1, 'title' => 'source'];
            });

        $this->app->instance(PhotoProcessingService::class, $photoService);
        $this->app->instance(FtpController::class, new FtpController($photoService));

        $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($user)])
            ->postJson('/api/management/ftp/process')
            ->assertOk()
            ->assertJson(['success' => true, 'processed' => 1]);

        $this->assertDatabaseCount('photos', 2);
        $this->assertSame([], Storage::disk('ftp_inbox')->files($user->ftp_slug));
    }

    public function test_an_async_upload_job_queued_during_import_runs_after_unlock(): void
    {
        [$user] = $this->importContext();
        $latePath = $user->ftp_slug.'/arrived-during-async-import.jpg';
        $eventDispatched = false;

        config([
            'ftp.import.max_files' => 1,
            'ftp.import.max_file_bytes' => $this->sampleImageBytes(),
            'ftp.import.max_batch_bytes' => 10 * $this->sampleImageBytes(),
            'queue.default' => 'database',
        ]);
        Queue::fake();

        $photoService = $this->createMock(PhotoProcessingService::class);
        $photoService->expects($this->exactly(2))
            ->method('processImage')
            ->willReturnCallback(function () use ($user, $latePath, &$eventDispatched): array {
                if (! $eventDispatched) {
                    $eventDispatched = true;
                    Storage::disk('ftp_inbox')->put($latePath, $this->sampleImageContents());
                    ImportFtpBatch::dispatch($user->id, 1, 0, true);
                }

                return ['width' => 1, 'height' => 1, 'title' => 'source'];
            });

        $importer = new RecordingFtpInboxImporter($photoService);
        $this->app->instance(PhotoProcessingService::class, $photoService);
        $this->app->instance(FtpInboxImporter::class, $importer);

        // The asynchronous event job is queued, not run under the active lock.
        // Its own later execution then sees the uploaded file and imports it.
        (new ImportFtpBatch($user->id))->handle($importer);
        $this->assertDatabaseCount('photos', 1);
        $this->assertTrue(Storage::disk('ftp_inbox')->exists($latePath));

        Queue::assertPushed(ImportFtpBatch::class, 1);
        $eventJob = Queue::pushed(ImportFtpBatch::class)->first();
        $this->assertInstanceOf(ImportFtpBatch::class, $eventJob);
        $this->assertTrue($eventJob->uploadEvent);
        $eventJob->handle($importer);

        $this->assertDatabaseCount('photos', 2);
        $this->assertSame([], Storage::disk('ftp_inbox')->files($user->ftp_slug));
    }

    /**
     * The second schranke, behaviourally: while another run holds the import
     * lock, a job imports nothing, does not re-enqueue and — the point of the
     * regression — says so in a log line instead of ending silently.
     */
    public function test_a_held_import_lock_stops_the_job_visibly(): void
    {
        [$user, $sourcePath] = $this->importContext();
        Queue::fake();
        Log::spy();

        $lock = Cache::lock(FtpController::importLockName($user->id), 300);
        $this->assertTrue($lock->get());

        try {
            (new ImportFtpBatch($user->id))->handle(app(FtpInboxImporter::class));
        } finally {
            $lock->release();
        }

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('photos', 0);
        $this->assertTrue(Storage::disk('ftp_inbox')->exists($sourcePath));

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (mixed $message): bool => is_string($message)
                && str_contains($message, 'hält die Sperre'))
            ->once();
    }

    /**
     * A deleted account is a named exit, not a crash and not a silent stop.
     *
     * The event can arrive after the account is gone. There is nothing to import
     * into and nobody to notify, so the chain ends — but it ends with an `info`
     * line that names the account, and it does not re-enqueue.
     */
    public function test_the_chain_ends_visibly_when_the_account_no_longer_exists(): void
    {
        Queue::fake();
        Log::spy();

        (new ImportFtpBatch('deleted-user-id'))->handle(app(FtpInboxImporter::class));

        Queue::assertNothingPushed();

        Log::shouldHaveReceived('info')
            ->withArgs(static fn (mixed $message): bool => is_string($message)
                && str_contains($message, 'Konto existiert nicht mehr'))
            ->once();
    }

    /**
     * Runs the chain the way a worker would and reports how many photos each run
     * wrote, in order.
     *
     * @return list<int>
     */
    private function drainChain(User $user, int $maxRuns = 20): array
    {
        Queue::fake();
        ImportFtpBatch::dispatch($user->id);

        $importer = app(FtpInboxImporter::class);
        $imported = [];
        $handled = 0;

        while ($handled < $maxRuns) {
            $pushed = Queue::pushed(ImportFtpBatch::class)->values();

            if ($handled >= $pushed->count()) {
                break;
            }

            $job = $pushed->get($handled);
            $handled++;

            $before = Photo::query()->count();
            $job->handle($importer);
            $imported[] = Photo::query()->count() - $before;
        }

        return $imported;
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function importContext(): array
    {
        $user = User::factory()->create(['brand' => Brand::B2B, 'ftp_slug' => 'florian']);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        $gallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'type' => 'delivery',
            'apply_metadata_to_photos' => false,
            'restricted_photographers' => true,
        ]);
        $user->galleries()->attach($gallery->id);
        $user->update(['current_ftp_gallery_id' => $gallery->id]);

        $sourcePath = $user->ftp_slug.'/source.jpg';
        Storage::disk('ftp_inbox')->put($sourcePath, $this->sampleImageContents());

        return [$user, $sourcePath];
    }

    private function putSampleImage(User $user, string $filename): string
    {
        $sourcePath = $user->ftp_slug.'/'.$filename;
        Storage::disk('ftp_inbox')->put($sourcePath, $this->sampleImageContents());

        return $sourcePath;
    }

    private function sampleImageContents(): string
    {
        $contents = file_get_contents(base_path('tests/Fixtures/sample.jpg'));
        $this->assertIsString($contents);

        return $contents;
    }

    private function sampleImageBytes(): int
    {
        $bytes = filesize(base_path('tests/Fixtures/sample.jpg'));
        $this->assertIsInt($bytes);

        return $bytes;
    }

    private function mockFastImageProcessing(int $expectedCalls): void
    {
        $photoService = $this->createMock(PhotoProcessingService::class);
        $photoService->expects($this->exactly($expectedCalls))
            ->method('processImage')
            ->willReturn(['width' => 1, 'height' => 1, 'title' => 'source']);
        $this->app->instance(PhotoProcessingService::class, $photoService);
    }
}

/**
 * Records how many photos each run of a real chain imported.
 *
 * It delegates to the production `importBatch()` — only the call is recorded — so
 * the sync-queue test measures the real chain and not a re-implementation of it.
 */
class RecordingFtpInboxImporter extends FtpInboxImporter
{
    /** @var list<int> */
    public array $runs = [];

    public function importBatch(User $user, ?callable $openSource = null, ?callable $statSource = null): FtpImportResult
    {
        $before = Photo::query()->count();
        $result = parent::importBatch($user, $openSource, $statSource);
        $this->runs[] = Photo::query()->count() - $before;

        return $result;
    }
}
