<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\FtpImportStatus;
use App\Enums\UserRole;
use App\Http\Controllers\FtpController;
use App\Models\Gallery;
use App\Models\Role;
use App\Models\User;
use App\Services\FtpInboxImporter;
use App\Services\PhotoProcessingService;
use Fiber;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Quarantine for uploads the import cannot process (D-37).
 *
 * The failure this suite pins is not "a bad file is rejected" — it is "a bad
 * file blocks every later run". The inbox walk reads in directory order and
 * stops at the first file above the per-file budget (413) or at a file that is
 * not a decodable image (422). A file that is never removed therefore sits at
 * the head of that order and `processed` stays 0 forever, which contradicts
 * D-35's "repeated triggering drains the inbox" and leaves the photographer with
 * no tool in the UI (the inbox has no delete function; the way out would be
 * SFTP).
 *
 * D-37's answer: after a configurable number of failed attempts the file moves
 * into a `_quarantine` subfolder next to it. It is **never deleted**, the run
 * continues with the remaining files, and the folder is inside the photographer's
 * SFTP home so it is findable at all.
 */
class FtpQuarantineTest extends TestCase
{
    use RefreshDatabase;

    private const QUARANTINE = FtpInboxImporter::QUARANTINE_DIRECTORY;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('ftp_inbox');
        Storage::fake('photos');
    }

    public function test_an_oversized_file_is_quarantined_after_the_configured_attempts_and_the_rest_is_imported(): void
    {
        [$user] = $this->importContext();
        $sampleBytes = $this->sampleImageBytes();
        $oversizedPath = $this->putOversizedFile($user, $sampleBytes + 1);
        $this->putSampleImage($user, 'good-1.jpg');
        $this->putSampleImage($user, 'good-2.jpg');

        // Three importable files: the context's `source.jpg` plus the two added
        // here. The oversized one is the only file the run cannot import.
        $this->mockFastImageProcessing(3);
        config([
            'ftp.import.max_files' => 20,
            'ftp.import.max_file_bytes' => $sampleBytes,
            'ftp.import.max_batch_bytes' => 10 * $sampleBytes,
            'ftp.import.quarantine_after_attempts' => 3,
        ]);

        // Attempts 1 and 2: still the documented 413, nothing imported, the file
        // stays. That is the SEC-14 contract and it must not soften just because
        // a quarantine exists.
        foreach ([1, 2] as $attempt) {
            $this->processAs($user)->assertStatus(413);
            $this->assertTrue(
                Storage::disk('ftp_inbox')->exists($oversizedPath),
                "Attempt {$attempt} must leave the file in the inbox.",
            );
            $this->assertDatabaseCount('photos', 0);
        }

        // Attempt 3: the file moves aside and the run continues with the rest.
        $this->processAs($user)
            ->assertOk()
            ->assertJson(['success' => true, 'processed' => 3, 'quarantined' => 1]);

        $this->assertFalse(Storage::disk('ftp_inbox')->exists($oversizedPath));
        $this->assertTrue(Storage::disk('ftp_inbox')->exists($user->ftp_slug.'/'.self::QUARANTINE.'/'.basename($oversizedPath)));
        $this->assertDatabaseCount('photos', 3);
        $this->assertSame([], $this->inboxImageFiles($user));
    }

    public function test_a_quarantined_file_is_never_deleted_and_keeps_its_bytes(): void
    {
        [$user] = $this->importContext();
        $sampleBytes = $this->sampleImageBytes();
        $oversizedPath = $this->putOversizedFile($user, $sampleBytes + 1);
        $originalBytes = Storage::disk('ftp_inbox')->get($oversizedPath);

        $this->mockFastImageProcessing(1);
        config([
            'ftp.import.max_files' => 20,
            'ftp.import.max_file_bytes' => $sampleBytes,
            'ftp.import.max_batch_bytes' => 10 * $sampleBytes,
            'ftp.import.quarantine_after_attempts' => 1,
        ]);

        $this->processAs($user)->assertOk();

        $quarantined = $user->ftp_slug.'/'.self::QUARANTINE.'/'.basename($oversizedPath);
        $this->assertTrue(Storage::disk('ftp_inbox')->exists($quarantined));
        $this->assertSame($originalBytes, Storage::disk('ftp_inbox')->get($quarantined));
    }

    public function test_an_undecodable_file_is_quarantined_and_the_run_continues(): void
    {
        [$user, $sourcePath] = $this->importContext();
        Storage::disk('ftp_inbox')->put($sourcePath, '<!doctype html><title>not an image</title>');
        $this->putSampleImage($user, 'good.jpg');

        $this->mockFastImageProcessing(1);
        config([
            'ftp.import.max_files' => 20,
            'ftp.import.max_file_bytes' => 1024 * 1024,
            'ftp.import.max_batch_bytes' => 10 * 1024 * 1024,
            'ftp.import.quarantine_after_attempts' => 2,
        ]);

        // Attempt 1 is the documented 422: the file is rejected, not moved.
        $this->processAs($user)->assertStatus(422);
        $this->assertTrue(Storage::disk('ftp_inbox')->exists($sourcePath));

        // Attempt 2 moves it aside and imports what is left.
        $this->processAs($user)->assertOk();

        $this->assertFalse(Storage::disk('ftp_inbox')->exists($sourcePath));
        $this->assertTrue(Storage::disk('ftp_inbox')->exists($user->ftp_slug.'/'.self::QUARANTINE.'/source.jpg'));
        $this->assertDatabaseCount('photos', 1);
        $this->assertSame([], $this->inboxImageFiles($user));
    }

    /**
     * A quarantined file is not collected by a later run.
     *
     * The folder is inside the inbox on purpose (findable over SFTP), so the walk
     * has to skip it by name. Without that, the next run would import the very
     * file the quarantine moved aside — or try to, forever. The assertion does not
     * use `inboxImageFiles()`, which filters the folder out by path and could
     * therefore never turn red; it counts the quarantine folder directly.
     */
    public function test_a_quarantined_file_is_not_picked_up_by_a_later_run(): void
    {
        [$user, $sourcePath] = $this->importContext();
        Storage::disk('ftp_inbox')->put($sourcePath, '<!doctype html><title>not an image</title>');
        $this->putSampleImage($user, 'good.jpg');

        $this->mockFastImageProcessing(1);
        config([
            'ftp.import.max_files' => 20,
            'ftp.import.max_file_bytes' => 1024 * 1024,
            'ftp.import.max_batch_bytes' => 10 * 1024 * 1024,
            'ftp.import.quarantine_after_attempts' => 1,
        ]);

        $this->processAs($user)
            ->assertOk()
            ->assertJson(['success' => true, 'processed' => 1, 'quarantined' => 1]);

        $quarantined = $user->ftp_slug.'/'.self::QUARANTINE.'/source.jpg';
        $this->assertTrue(Storage::disk('ftp_inbox')->exists($quarantined));

        // Second run: nothing in the inbox, and above all nothing from the
        // quarantine folder is imported or moved again.
        $this->processAs($user)
            ->assertOk()
            ->assertJson(['success' => true, 'processed' => 0, 'quarantined' => 0]);

        $this->assertDatabaseCount('photos', 1);
        $this->assertTrue(Storage::disk('ftp_inbox')->exists($quarantined));
        $this->assertCount(1, Storage::disk('ftp_inbox')->files($user->ftp_slug.'/'.self::QUARANTINE));
    }

    /**
     * A repeated camera file name must not overwrite an earlier quarantined file.
     *
     * The docblock of the name helper promises that two quarantined files of one
     * shooting both survive, but no test held it. The move reserves its target
     * with an exclusive create before renaming, because a bare `file_exists()`
     * check plus `rename()` would let a second run replace the first on POSIX.
     */
    public function test_a_second_quarantine_of_the_same_name_does_not_overwrite_the_first(): void
    {
        [$user, $sourcePath] = $this->importContext();
        Storage::disk('ftp_inbox')->put($sourcePath, '<!doctype html><title>not an image</title>');

        // An earlier quarantine of a file with the same base name, with its bytes.
        $existing = $user->ftp_slug.'/'.self::QUARANTINE.'/source.jpg';
        Storage::disk('ftp_inbox')->put($existing, 'first quarantined bytes');

        config([
            'ftp.import.max_files' => 20,
            'ftp.import.max_file_bytes' => 1024 * 1024,
            'ftp.import.max_batch_bytes' => 10 * 1024 * 1024,
            'ftp.import.quarantine_after_attempts' => 1,
        ]);

        $this->processAs($user)->assertOk();

        // The first file keeps its bytes, and a second, differently named file
        // joined it.
        $this->assertSame('first quarantined bytes', Storage::disk('ftp_inbox')->get($existing));

        $files = Storage::disk('ftp_inbox')->files($user->ftp_slug.'/'.self::QUARANTINE);
        $this->assertCount(2, $files);

        $added = array_values(array_filter(
            $files,
            static fn (string $path): bool => $path !== $existing,
        ));
        $this->assertCount(1, $added);
        $this->assertStringContainsString('source.jpg', $added[0]);
    }

    /**
     * A present file whose size cannot be read counts an attempt and is moved
     * aside after the threshold.
     *
     * This is the M-2 regression: before the fix such a file was skipped on every
     * run and never quarantined, so it stayed in the inbox forever — exactly the
     * state D-37 rejects — while the docblock, `.env.example` and the feature doc
     * claimed it was quarantined. A *vanished* file is still only skipped (there
     * is nothing to move); this test models the present case by reporting
     * `fileSize() === false` for one path while `isRegularFile()` stays true.
     */
    public function test_a_present_file_with_an_unreadable_size_is_quarantined_after_the_threshold(): void
    {
        [$user, $sourcePath] = $this->importContext();
        $this->putSampleImage($user, 'good.jpg');

        config([
            'ftp.import.max_files' => 20,
            'ftp.import.max_file_bytes' => 1024 * 1024,
            'ftp.import.max_batch_bytes' => 10 * 1024 * 1024,
            'ftp.import.quarantine_after_attempts' => 2,
        ]);

        // The mock must exist before the importer is constructed: the importer
        // captures the service in its constructor.
        $this->mockFastImageProcessing(1);

        $importer = new class(app(PhotoProcessingService::class)) extends FtpInboxImporter
        {
            public string $unreadablePath = '';

            protected function fileSize(string $path): int|false
            {
                if ($path === $this->unreadablePath) {
                    return false;
                }

                return parent::fileSize($path);
            }
        };
        $importer->unreadablePath = Storage::disk('ftp_inbox')->path($sourcePath);

        // Attempt 1: counted but below the threshold, so it is skipped and the
        // run continues with the importable file.
        $first = $importer->importBatch($user);
        $this->assertSame(FtpImportStatus::Imported, $first->status);
        $this->assertSame(1, $first->imported);
        $this->assertSame(0, $first->quarantined);
        $this->assertTrue(Storage::disk('ftp_inbox')->exists($sourcePath));

        // Attempt 2 reaches the threshold: the file moves aside.
        $second = $importer->importBatch($user);
        $this->assertSame(1, $second->quarantined);
        $this->assertFalse(Storage::disk('ftp_inbox')->exists($sourcePath));
        $this->assertTrue(Storage::disk('ftp_inbox')->exists($user->ftp_slug.'/'.self::QUARANTINE.'/source.jpg'));
    }

    public function test_concurrent_attempts_for_one_file_are_serialized_and_reach_the_threshold(): void
    {
        [$user] = $this->importContext();
        $path = '/same-file.jpg';
        $concurrentAttempts = 4;
        config(['ftp.import.quarantine_after_attempts' => $concurrentAttempts]);

        $coordinator = new FtpAttemptCounterLockCoordinator;
        $importer = new class(app(PhotoProcessingService::class), $coordinator) extends FtpInboxImporter
        {
            private bool $suspendedAfterRead = false;

            public function recordAttempt(User $user, string $path): bool
            {
                return $this->quarantineOnFinalAttempt($user, $path);
            }

            protected function quarantineAttemptLock(string $key): Lock
            {
                return new FtpAttemptCounterLock($this->coordinator);
            }

            protected function afterQuarantineAttemptRead(int $attempts): void
            {
                if (! $this->suspendedAfterRead && Fiber::getCurrent() !== null) {
                    $this->suspendedAfterRead = true;
                    Fiber::suspend();
                }
            }

            public function __construct(PhotoProcessingService $photoService, private readonly FtpAttemptCounterLockCoordinator $coordinator)
            {
                parent::__construct($photoService);
            }
        };

        $fibers = [];
        for ($attempt = 0; $attempt < $concurrentAttempts; $attempt++) {
            $fibers[] = new Fiber(fn (): bool => $importer->recordAttempt($user, $path));
        }

        // Pause the first caller after its read, then queue the rest on the same
        // key's lock. Releasing the first resumes each waiter in order. Without
        // serialization they all read zero before any write and this test ends
        // with one instead of four.
        $fibers[0]->start();
        foreach (array_slice($fibers, 1) as $fiber) {
            $fiber->start();
        }
        $fibers[0]->resume();

        foreach ($fibers as $fiber) {
            $this->assertTrue($fiber->isTerminated());
        }

        $counterKey = 'ftp-import-attempts:'.$user->id.':'.sha1($path);
        $this->assertSame($concurrentAttempts, Cache::get($counterKey));
        $this->assertSame(
            1,
            count(array_filter($fibers, static fn (Fiber $fiber): bool => $fiber->getReturn())),
            'Exactly the threshold-reaching attempt makes the quarantine decision.',
        );
    }

    /**
     * A quarantine that happened before the lock was lost is reported.
     *
     * The oversized-file move happens during selection, before the import lock is
     * taken. If that lock is then held by another run, the `Locked` result must
     * still carry the count — otherwise the move is invisible to the chain's log
     * and to the HTTP caller, and the file looks as if it were still in the inbox.
     */
    public function test_a_quarantine_before_a_lost_lock_is_visible_in_the_locked_result(): void
    {
        [$user] = $this->importContext();
        $sampleBytes = $this->sampleImageBytes();
        $this->putOversizedFile($user, $sampleBytes + 1);

        config([
            'ftp.import.max_files' => 20,
            'ftp.import.max_file_bytes' => $sampleBytes,
            'ftp.import.max_batch_bytes' => 10 * $sampleBytes,
            'ftp.import.quarantine_after_attempts' => 1,
        ]);

        $lock = Cache::lock(FtpController::importLockName($user->id), 300);
        $this->assertTrue($lock->get());

        try {
            $result = app(FtpInboxImporter::class)->importBatch($user);
        } finally {
            $lock->release();
        }

        $this->assertSame(FtpImportStatus::Locked, $result->status);
        $this->assertSame(1, $result->quarantined, 'The quarantine before the lock must be carried into the Locked result.');
    }

    /**
     * A source that changes while it is read is *not* a quarantine reason: that
     * is the documented "the camera is still writing, retry" case, and moving a
     * file that is still being written would quarantine a healthy upload.
     */
    public function test_a_source_that_changes_while_reading_is_not_quarantined(): void
    {
        [$user, $sourcePath] = $this->importContext();
        $absolute = Storage::disk('ftp_inbox')->path($sourcePath);

        config([
            'ftp.import.max_files' => 20,
            'ftp.import.max_file_bytes' => 1024 * 1024,
            'ftp.import.max_batch_bytes' => 10 * 1024 * 1024,
            // Quarantine on the very first counted attempt, so a changed source
            // that *were* counted would move immediately.
            'ftp.import.quarantine_after_attempts' => 1,
        ]);

        $controller = new class(app(PhotoProcessingService::class), $absolute) extends FtpController
        {
            private bool $grown = false;

            public function __construct(PhotoProcessingService $photoService, private readonly string $sourcePath)
            {
                parent::__construct($photoService);
            }

            protected function openImportSourceStream(string $path)
            {
                if (! $this->grown && $path === $this->sourcePath) {
                    $contents = file_get_contents($path);
                    if (is_string($contents)) {
                        file_put_contents($path, $contents.'growth');
                    }
                    $this->grown = true;
                }

                return parent::openImportSourceStream($path);
            }
        };
        $this->app->instance(FtpController::class, $controller);

        $this->processAs($user)->assertStatus(422);

        $this->assertTrue(Storage::disk('ftp_inbox')->exists($sourcePath));
        $this->assertFalse(Storage::disk('ftp_inbox')->exists($user->ftp_slug.'/'.self::QUARANTINE));
    }

    /**
     * The folder is inside the photographer's SFTP home (so it is findable) but
     * it is not part of `file_count`: the two numbers mean different things —
     * "waiting for the import" and "already tried, needs a human". A single
     * counter would let a quarantined upload read as "nothing arrived".
     */
    public function test_the_quarantine_folder_is_reported_separately_from_the_inbox_file_count(): void
    {
        [$user] = $this->importContext();
        $sampleBytes = $this->sampleImageBytes();
        $this->putOversizedFile($user, $sampleBytes + 1);
        $this->putSampleImage($user, 'good.jpg');

        $this->mockFastImageProcessing(2);
        config([
            'ftp.import.max_files' => 20,
            'ftp.import.max_file_bytes' => $sampleBytes,
            'ftp.import.max_batch_bytes' => 10 * $sampleBytes,
            'ftp.import.quarantine_after_attempts' => 1,
        ]);

        $this->processAs($user)->assertOk();

        $status = $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($user)])
            ->getJson('/api/management/ftp/status');

        $status->assertOk();
        $this->assertSame(0, $status->json('file_count'));
        $this->assertSame(1, $status->json('quarantine_count'));
        $this->assertSame('/'.$user->ftp_slug, $status->json('ftp_folder'));
    }

    /**
     * A move that fails must not read as success.
     *
     * The failure modes are a read-only mount or a permission fault on the
     * quarantine directory — both leave the file exactly where it was. Answering
     * 200 with `quarantined: 0` would claim a drained inbox that is not drained,
     * so the run falls back to the documented rejection and the next attempt
     * tries the move again.
     */
    public function test_a_failed_quarantine_move_falls_back_to_the_documented_rejection(): void
    {
        [$user, $sourcePath] = $this->importContext();
        Storage::disk('ftp_inbox')->put($sourcePath, '<!doctype html><title>not an image</title>');

        config([
            'ftp.import.max_files' => 20,
            'ftp.import.max_file_bytes' => 1024 * 1024,
            'ftp.import.max_batch_bytes' => 10 * 1024 * 1024,
            'ftp.import.quarantine_after_attempts' => 1,
        ]);

        $importer = new class(app(PhotoProcessingService::class)) extends FtpInboxImporter
        {
            protected function moveToQuarantine(User $user, string $inboxPath, string $path): bool
            {
                return false;
            }
        };

        $result = $importer->importBatch($user);

        $this->assertSame(FtpImportStatus::SourceUnprocessable, $result->status);
        $this->assertSame(0, $result->quarantined);
        $this->assertTrue(Storage::disk('ftp_inbox')->exists($sourcePath));
        $this->assertFalse(Storage::disk('ftp_inbox')->exists($user->ftp_slug.'/'.self::QUARANTINE));
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

    private function putOversizedFile(User $user, int $bytes): string
    {
        $path = $user->ftp_slug.'/too-large.jpg';
        Storage::disk('ftp_inbox')->put($path, str_repeat('x', $bytes));

        return $path;
    }

    private function putSampleImage(User $user, string $filename): string
    {
        $path = $user->ftp_slug.'/'.$filename;
        Storage::disk('ftp_inbox')->put($path, $this->sampleImageContents());

        return $path;
    }

    /** @return list<string> */
    private function inboxImageFiles(User $user): array
    {
        $files = [];

        foreach (Storage::disk('ftp_inbox')->files($user->ftp_slug) as $path) {
            if (str_contains($path, self::QUARANTINE.'/')) {
                continue;
            }

            if (in_array(pathinfo($path, PATHINFO_EXTENSION), ['jpg', 'jpeg', 'JPG', 'JPEG'], true)) {
                $files[] = $path;
            }
        }

        return $files;
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

    private function processAs(User $user)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.auth('api')->login($user),
        ])->postJson('/api/management/ftp/process');
    }
}

final class FtpAttemptCounterLockCoordinator
{
    private bool $held = false;

    /** @var list<Fiber> */
    private array $waiters = [];

    public function acquire(): bool
    {
        if ($this->held) {
            return false;
        }

        $this->held = true;

        return true;
    }

    public function wait(): void
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            throw new \RuntimeException('A contended test lock requires a Fiber.');
        }

        $this->waiters[] = $fiber;
        Fiber::suspend();
    }

    public function release(): bool
    {
        if (! $this->held) {
            return false;
        }

        $this->held = false;
        $waiter = array_shift($this->waiters);
        if ($waiter instanceof Fiber && ! $waiter->isTerminated()) {
            $waiter->resume();
        }

        return true;
    }
}

final class FtpAttemptCounterLock implements Lock
{
    public function __construct(private readonly FtpAttemptCounterLockCoordinator $coordinator) {}

    public function get($callback = null)
    {
        if (! $this->coordinator->acquire()) {
            return false;
        }

        if (is_callable($callback)) {
            try {
                return $callback();
            } finally {
                $this->release();
            }
        }

        return true;
    }

    public function block($seconds, $callback = null)
    {
        while (! $this->get()) {
            $this->coordinator->wait();
        }

        if (is_callable($callback)) {
            try {
                return $callback();
            } finally {
                $this->release();
            }
        }

        return true;
    }

    public function release(): bool
    {
        return $this->coordinator->release();
    }

    public function owner(): string
    {
        return 'ftp-attempt-counter-test';
    }

    public function forceRelease(): void
    {
        $this->coordinator->release();
    }
}
