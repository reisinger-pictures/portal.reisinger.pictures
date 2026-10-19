<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Http\Controllers\FtpController;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use App\Services\PhotoProcessingService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class FtpImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('ftp_inbox');
        Storage::fake('photos');
    }

    protected function tearDown(): void
    {
        // Model listeners registered by a test (a vetoing `deleting`, a
        // `created` callback that grows the source) are process-wide and would
        // otherwise leak into the next test.
        Photo::flushEventListeners();

        parent::tearDown();
    }

    public function test_photographer_can_get_ftp_status()
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/management/ftp/status');
        $response->assertStatus(200)->assertJsonStructure(['ftp_folder', 'file_count']);
    }

    public function test_client_cannot_access_ftp()
    {
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/management/ftp/status');
        $response->assertStatus(403);
    }

    public function test_photographer_cannot_set_target_to_other_brand_gallery()
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        $otherGallery = Gallery::factory()->create(['brand' => 'test-brand']);

        $token = auth('api')->login($user);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/target', ['gallery_id' => $otherGallery->id]);

        $response->assertStatus(403);
    }

    public function test_photographer_can_set_target_to_own_brand_gallery()
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        $gallery = Gallery::factory()->create(['brand' => Brand::B2B]);
        $user->galleries()->attach($gallery->id);

        $token = auth('api')->login($user);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/target', ['gallery_id' => $gallery->id]);

        $response->assertStatus(200);
    }

    public function test_photographer_cannot_process_other_brand_gallery()
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        $otherGallery = Gallery::factory()->create(['brand' => 'test-brand']);
        $user->update(['current_ftp_gallery_id' => $otherGallery->id]);

        $token = auth('api')->login($user);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/process');

        $response->assertStatus(403);
    }

    public function test_set_target_rejects_nonexistent_gallery()
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        $token = auth('api')->login($user);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/target', ['gallery_id' => 'non-existent']);

        $response->assertStatus(422);
    }

    public function test_process_removes_inbox_file_only_after_storage_processing_and_database_success(): void
    {
        [$user, $sourcePath] = $this->createImportContext();

        $this->processAs($user)
            ->assertOk()
            ->assertJson(['success' => true, 'processed' => 1]);

        $this->assertFalse(Storage::disk('ftp_inbox')->exists($sourcePath));
        $this->assertDatabaseCount('photos', 1);
        $this->assertDatabaseHas('photos', [
            'gallery_id' => $user->current_ftp_gallery_id,
            'title' => 'source',
        ]);
    }

    public function test_process_rejects_an_oversized_file_before_opening_any_source(): void
    {
        [$user, $sourcePath] = $this->createImportContext();
        Storage::disk('ftp_inbox')->put($sourcePath, str_repeat('x', 65));
        config([
            'ftp.import.max_files' => 2,
            'ftp.import.max_file_bytes' => 64,
            'ftp.import.max_batch_bytes' => 128,
        ]);

        $controller = $this->injectCountingController();

        $this->processAs($user)
            ->assertStatus(413)
            ->assertJsonFragment([
                'error' => 'Die FTP-Datei ist mit 65 Byte größer als das Limit von 64 Byte pro Datei. Verkleinere das Bild oder erhöhe FTP_IMPORT_MAX_FILE_BYTES.',
            ]);

        // "Before staging" is the claim, so it is asserted directly: the source
        // was never opened, which a post-staging abort could not satisfy.
        $this->assertSame(0, $controller->openCount);
        $this->assertTrue(Storage::disk('ftp_inbox')->exists($sourcePath));
        $this->assertSame([], Storage::disk('photos')->allFiles());
        $this->assertDatabaseCount('photos', 0);
    }

    public function test_process_imports_one_batch_and_leaves_the_rest_in_the_inbox(): void
    {
        [$user, $firstPath] = $this->createImportContext();
        $secondPath = $this->putSampleImage($user, 'second.jpg');
        $this->mockFastImageProcessing(1);
        config([
            'ftp.import.max_files' => 1,
            'ftp.import.max_file_bytes' => 1024 * 1024,
            'ftp.import.max_batch_bytes' => 2 * 1024 * 1024,
        ]);

        $this->processAs($user)
            ->assertOk()
            ->assertJson(['success' => true, 'processed' => 1]);

        $remaining = array_values(array_filter(
            [$firstPath, $secondPath],
            static fn (string $path): bool => Storage::disk('ftp_inbox')->exists($path),
        ));

        $this->assertCount(1, $remaining, 'Exactly one source must stay for the next run.');
        $this->assertDatabaseCount('photos', 1);
        $this->assertCount(1, Storage::disk('photos')->allFiles());
    }

    public function test_process_imports_a_batch_that_is_exactly_at_the_file_limit(): void
    {
        [$user, $firstPath] = $this->createImportContext();
        $secondPath = $this->putSampleImage($user, 'second.jpg');
        config([
            'ftp.import.max_files' => 2,
            'ftp.import.max_file_bytes' => 1024 * 1024,
            'ftp.import.max_batch_bytes' => 2 * 1024 * 1024,
        ]);

        $this->processAs($user)
            ->assertOk()
            ->assertJson(['success' => true, 'processed' => 2]);

        $this->assertFalse(Storage::disk('ftp_inbox')->exists($firstPath));
        $this->assertFalse(Storage::disk('ftp_inbox')->exists($secondPath));
        $this->assertDatabaseCount('photos', 2);
        $this->assertCount(2, Storage::disk('photos')->allFiles());
    }

    public function test_process_stops_the_batch_at_the_aggregate_byte_budget(): void
    {
        [$user, $firstPath] = $this->createImportContext();
        $secondPath = $this->putSampleImage($user, 'second.jpg');
        $sampleBytes = $this->sampleImageBytes();
        $this->mockFastImageProcessing(1);
        config([
            'ftp.import.max_files' => 2,
            'ftp.import.max_file_bytes' => $sampleBytes,
            'ftp.import.max_batch_bytes' => (2 * $sampleBytes) - 1,
        ]);

        $this->processAs($user)
            ->assertOk()
            ->assertJson(['success' => true, 'processed' => 1]);

        $remaining = array_values(array_filter(
            [$firstPath, $secondPath],
            static fn (string $path): bool => Storage::disk('ftp_inbox')->exists($path),
        ));

        $this->assertCount(1, $remaining, 'The file that no longer fits must wait for the next run.');
        $this->assertDatabaseCount('photos', 1);
    }

    public function test_process_rejects_a_first_file_above_the_aggregate_byte_budget_before_opening_any_source(): void
    {
        [$user, $sourcePath] = $this->createImportContext();
        $sampleBytes = $this->sampleImageBytes();
        config([
            'ftp.import.max_files' => 20,
            'ftp.import.max_file_bytes' => $sampleBytes,
            'ftp.import.max_batch_bytes' => $sampleBytes - 1,
        ]);

        $controller = $this->injectCountingController();

        $response = $this->processAs($user)->assertStatus(413);

        $error = $response->json('error');
        $this->assertIsString($error);
        $this->assertStringContainsString('Gesamtbudget', $error);
        $this->assertStringContainsString('FTP_IMPORT_MAX_BATCH_BYTES', $error);

        $this->assertSame(0, $controller->openCount);
        $this->assertTrue(Storage::disk('ftp_inbox')->exists($sourcePath));
        $this->assertSame([], Storage::disk('photos')->allFiles());
        $this->assertDatabaseCount('photos', 0);
    }

    public function test_process_bounds_the_stream_if_a_source_grows_after_size_preflight(): void
    {
        [$user, $sourcePath] = $this->createImportContext();
        $sourceAbsolutePath = Storage::disk('ftp_inbox')->path($sourcePath);
        $sourceBytes = filesize($sourceAbsolutePath);
        $this->assertIsInt($sourceBytes);
        config([
            'ftp.import.max_files' => 2,
            'ftp.import.max_file_bytes' => $sourceBytes,
            'ftp.import.max_batch_bytes' => $sourceBytes,
        ]);

        $controller = new class(app(PhotoProcessingService::class), $sourceAbsolutePath) extends FtpController
        {
            private bool $sourceGrew = false;

            public function __construct(PhotoProcessingService $photoService, private string $sourcePath)
            {
                parent::__construct($photoService);
            }

            protected function openImportSourceStream(string $path)
            {
                if (! $this->sourceGrew && $path === $this->sourcePath) {
                    $contents = file_get_contents($path);
                    if (! is_string($contents)) {
                        throw new \RuntimeException('Test source could not be read.');
                    }
                    file_put_contents($path, $contents.'growth');
                    $this->sourceGrew = true;
                }

                return parent::openImportSourceStream($path);
            }
        };
        $this->app->instance(FtpController::class, $controller);

        $this->processAs($user)
            ->assertStatus(413)
            ->assertJsonStructure(['error']);

        $this->assertTrue(Storage::disk('ftp_inbox')->exists($sourcePath));
        $this->assertSame([], Storage::disk('photos')->allFiles());
        $this->assertDatabaseCount('photos', 0);
    }

    public function test_process_keeps_a_source_that_grows_during_image_processing(): void
    {
        [$user, $sourcePath] = $this->createImportContext();
        $sourceAbsolutePath = Storage::disk('ftp_inbox')->path($sourcePath);
        config([
            'ftp.import.max_files' => 2,
            'ftp.import.max_file_bytes' => 1024 * 1024,
            'ftp.import.max_batch_bytes' => 2 * 1024 * 1024,
        ]);

        $photoService = $this->createMock(PhotoProcessingService::class);
        $photoService->expects($this->once())
            ->method('processImage')
            ->willReturnCallback(function () use ($sourceAbsolutePath): array {
                file_put_contents($sourceAbsolutePath, 'growth', FILE_APPEND);

                return ['width' => 1, 'height' => 1, 'title' => 'source'];
            });
        $this->app->instance(PhotoProcessingService::class, $photoService);

        $this->processAs($user)
            ->assertStatus(422)
            ->assertHeaderMissing('Retry-After')
            ->assertJsonStructure(['error']);

        $this->assertTrue(Storage::disk('ftp_inbox')->exists($sourcePath));
        $this->assertSame([], Storage::disk('photos')->allFiles());
        $this->assertDatabaseCount('photos', 0);
    }

    public function test_process_imports_the_staged_snapshot_when_the_source_vanishes_during_image_processing(): void
    {
        [$user, $sourcePath] = $this->createImportContext();
        $sourceAbsolutePath = Storage::disk('ftp_inbox')->path($sourcePath);

        $photoService = $this->createMock(PhotoProcessingService::class);
        $photoService->expects($this->once())
            ->method('processImage')
            ->willReturnCallback(function () use ($sourceAbsolutePath): array {
                if (! unlink($sourceAbsolutePath)) {
                    throw new \RuntimeException('Test source could not be removed.');
                }

                return ['width' => 1, 'height' => 1, 'title' => 'source'];
            });
        $this->app->instance(PhotoProcessingService::class, $photoService);

        $this->processAs($user)
            ->assertOk()
            ->assertJson(['success' => true, 'processed' => 1]);

        $this->assertFalse(Storage::disk('ftp_inbox')->exists($sourcePath));
        $this->assertDatabaseCount('photos', 1);
        $this->assertCount(1, Storage::disk('photos')->allFiles());
    }

    public function test_process_retains_inbox_file_when_photo_processing_throws(): void
    {
        [$user, $sourcePath] = $this->createImportContext();

        $photoService = $this->createMock(PhotoProcessingService::class);
        $photoService->expects($this->once())
            ->method('processImage')
            ->willThrowException(new \RuntimeException('simulated processing failure'));
        $this->app->instance(PhotoProcessingService::class, $photoService);

        $this->assertProcessThrows($user, 'simulated processing failure');

        $this->assertTrue(Storage::disk('ftp_inbox')->exists($sourcePath));
        $this->assertDatabaseCount('photos', 0);
        $this->assertSame([], Storage::disk('photos')->allFiles());
    }

    public function test_process_retains_inbox_file_when_photo_storage_put_fails(): void
    {
        [$user, $sourcePath] = $this->createImportContext();

        $manager = Storage::getFacadeRoot();
        $failingDisk = Mockery::mock(Filesystem::class);
        $failingDisk->shouldReceive('put')->once()->andReturn(false);
        $failingDisk->shouldReceive('delete')->once()->andReturn(true);

        Storage::shouldReceive('disk')
            ->andReturnUsing(function (?string $name = null) use ($manager, $failingDisk) {
                return $name === 'photos' ? $failingDisk : $manager->disk($name);
            });

        $this->assertProcessThrows($user, 'Foto konnte nicht in den Photo-Speicher geschrieben werden.');

        $this->assertTrue(Storage::disk('ftp_inbox')->exists($sourcePath));
        $this->assertDatabaseCount('photos', 0);
    }

    public function test_process_retains_inbox_file_when_database_transaction_fails(): void
    {
        [$user, $sourcePath] = $this->createImportContext();

        Photo::creating(static function (): void {
            throw new \RuntimeException('simulated database failure');
        });

        $this->assertProcessThrows($user, 'simulated database failure');

        $this->assertTrue(Storage::disk('ftp_inbox')->exists($sourcePath));
        $this->assertDatabaseCount('photos', 0);
        $this->assertSame([], Storage::disk('photos')->allFiles());
    }

    public function test_process_answers_a_held_lock_with_409_and_retry_after(): void
    {
        [$user] = $this->createImportContext();

        $lock = Cache::lock(FtpController::importLockName($user->id), 60);
        $this->assertTrue($lock->get());

        try {
            // 409 is reserved for this case: a competing run, retryable after a
            // known time. The source-changed rejection is 422 instead, so the
            // two no longer share a status.
            $this->processAs($user)
                ->assertStatus(409)
                ->assertHeader('Retry-After', '300');
        } finally {
            $lock->release();
        }
    }

    public function test_process_treats_a_stale_stat_cache_as_a_vanished_source_and_keeps_the_committed_photo(): void
    {
        [$user, $sourcePath] = $this->createImportContext();

        // The third probe is the post-commit check. `present` true with no size
        // is the exact symptom of a stat cache that still answers is_file() for
        // a file another process removed while the fresh filesize() says gone.
        // The old ordering read that as "changed" and deleted an already
        // committed row together with its stored file.
        $controller = new StaleStatCacheFtpController(app(PhotoProcessingService::class), staleAfter: 3);
        $this->app->instance(FtpController::class, $controller);

        $this->processAs($user)
            ->assertOk()
            ->assertJson(['success' => true, 'processed' => 1]);

        $this->assertDatabaseCount('photos', 1);
        $this->assertCount(1, Storage::disk('photos')->allFiles());
        $this->assertFalse(Storage::disk('ftp_inbox')->exists($sourcePath));
    }

    public function test_process_rolls_back_a_committed_photo_when_the_source_grows_after_the_commit(): void
    {
        [$user, $sourcePath] = $this->createImportContext();
        $sourceAbsolutePath = Storage::disk('ftp_inbox')->path($sourcePath);

        // `created` fires inside the transaction; the post-commit check runs
        // after it. That is the only window that reaches check 3 — the other
        // growth test stops at the pre-commit check.
        Photo::created(static function () use ($sourceAbsolutePath): void {
            file_put_contents($sourceAbsolutePath, 'growth', FILE_APPEND);
        });

        $this->processAs($user)
            ->assertStatus(422)
            ->assertJsonStructure(['error']);

        $this->assertTrue(Storage::disk('ftp_inbox')->exists($sourcePath));
        $this->assertDatabaseCount('photos', 0);
        $this->assertSame([], Storage::disk('photos')->allFiles());
    }

    public function test_process_keeps_the_row_and_its_files_when_the_rollback_delete_is_refused(): void
    {
        [$user, $sourcePath] = $this->createImportContext();
        $sourceAbsolutePath = Storage::disk('ftp_inbox')->path($sourcePath);

        Photo::created(static function () use ($sourceAbsolutePath): void {
            file_put_contents($sourceAbsolutePath, 'growth', FILE_APPEND);
        });
        Photo::deleting(static fn (): bool => false);

        // A row that refuses to delete still owns its files. The rollback fails
        // loudly instead of removing files that a surviving row references — the
        // deliberate counterpart to the transaction, documented at the call
        // site.
        $this->assertProcessThrows($user, 'FTP-Fotodatensatz konnte nach einer Quelländerung nicht entfernt werden.');

        $this->assertDatabaseCount('photos', 1);
        $this->assertCount(1, Storage::disk('photos')->allFiles());
        $this->assertTrue(Storage::disk('ftp_inbox')->exists($sourcePath));
    }

    public function test_process_empties_a_fifty_file_inbox_in_three_batches_of_at_most_twenty(): void
    {
        [$user] = $this->createImportContext();
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

        foreach ([20, 20, 10] as $expected) {
            $this->processAs($user)
                ->assertOk()
                ->assertJson(['success' => true, 'processed' => $expected]);
        }

        $this->assertSame([], Storage::disk('ftp_inbox')->files($user->ftp_slug));
        $this->assertDatabaseCount('photos', 50);
        $this->assertCount(50, Storage::disk('photos')->allFiles());
    }

    public function test_process_falls_back_to_the_documented_defaults_when_the_limits_are_empty_or_invalid(): void
    {
        [$user] = $this->createImportContext();
        for ($index = 2; $index <= 21; $index++) {
            $this->putSampleImage($user, sprintf('default-%02d.jpg', $index));
        }

        $this->mockFastImageProcessing(20);
        config([
            'ftp.import.max_files' => '',
            'ftp.import.max_file_bytes' => 'not-a-number',
            'ftp.import.max_batch_bytes' => 0,
        ]);

        // The documented defaults are 20 / 20 MiB / 100 MiB. A rescue to 1 (the
        // old behaviour) would import one file; a 413 would import none.
        $this->processAs($user)
            ->assertOk()
            ->assertJson(['success' => true, 'processed' => 20]);

        $this->assertCount(1, Storage::disk('ftp_inbox')->files($user->ftp_slug));
        $this->assertDatabaseCount('photos', 20);
    }

    public function test_status_reports_an_unreadable_inbox_as_a_named_error_instead_of_a_type_error(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));
        Storage::disk('ftp_inbox')->makeDirectory($user->ftp_slug);

        $this->app->instance(
            FtpController::class,
            new UnreadableInboxFtpController(app(PhotoProcessingService::class)),
        );

        $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($user)])
            ->getJson('/api/management/ftp/status')
            ->assertStatus(500)
            ->assertJson(['error' => 'Der FTP-Posteingang konnte nicht gelesen werden.']);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function createImportContext(): array
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
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
        Storage::disk('ftp_inbox')->put(
            $sourcePath,
            file_get_contents(base_path('tests/Fixtures/sample.jpg'))
        );

        return [$user, $sourcePath];
    }

    private function putSampleImage(User $user, string $filename): string
    {
        $sourcePath = $user->ftp_slug.'/'.$filename;
        $contents = file_get_contents(base_path('tests/Fixtures/sample.jpg'));
        $this->assertIsString($contents);
        Storage::disk('ftp_inbox')->put($sourcePath, $contents);

        return $sourcePath;
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

    private function injectCountingController(): CountingFtpController
    {
        $controller = new CountingFtpController(app(PhotoProcessingService::class));
        $this->app->instance(FtpController::class, $controller);

        return $controller;
    }

    private function processAs(User $user)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.auth('api')->login($user),
        ])->postJson('/api/management/ftp/process');
    }

    private function assertProcessThrows(User $user, string $expectedMessage): void
    {
        $this->withoutExceptionHandling();

        try {
            $this->processAs($user);
        } catch (\Throwable $exception) {
            $this->assertInstanceOf(\RuntimeException::class, $exception);
            $this->assertSame($expectedMessage, $exception->getMessage());

            return;
        }

        $this->fail('Expected the FTP process request to throw.');
    }
}

/**
 * Counts how often a source stream is opened, so a test can prove that a
 * rejection happened *before* staging instead of merely producing the same
 * observable end state.
 */
class CountingFtpController extends FtpController
{
    public int $openCount = 0;

    protected function openImportSourceStream(string $path)
    {
        $this->openCount++;

        return parent::openImportSourceStream($path);
    }
}

/**
 * Simulates PHP's stat cache going stale across processes: `is_file()` still
 * reports the file as present while a fresh `filesize()` says it is gone. The
 * symptom cannot be produced deterministically on every host (a `rm` in a
 * subprocess was measured to leave the cache fresh here), so the seam hands the
 * controller exactly the value pair the stale cache would yield.
 */
class StaleStatCacheFtpController extends FtpController
{
    private int $probes = 0;

    public function __construct(PhotoProcessingService $photoService, private readonly int $staleAfter = 3)
    {
        parent::__construct($photoService);
    }

    protected function statSource(string $path): array
    {
        $this->probes++;

        if ($this->probes >= $this->staleAfter) {
            return ['present' => true, 'size' => false];
        }

        return parent::statSource($path);
    }
}

/**
 * Makes an existing inbox directory behave as unreadable, which a `chmod`
 * fixture cannot do reliably in CI because the suite runs as root there.
 */
class UnreadableInboxFtpController extends FtpController
{
    protected function countInboxImages(string $inboxPath): ?int
    {
        return null;
    }
}
