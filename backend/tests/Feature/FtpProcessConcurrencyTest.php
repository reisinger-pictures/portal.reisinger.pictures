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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Concurrency guard for FtpController::process() (P1-M28).
 *
 * The pipeline copies a file out of the inbox and only then unlinks it, so two
 * runs that each hold their own glob() snapshot of the same file would produce
 * two Photo rows and two stored files from one upload. These tests pin both
 * halves of the guard: a competing call is rejected without doing any work, and
 * a rejected call inside a running import leaves the single import's side effects
 * exactly one.
 */
class FtpProcessConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('ftp_inbox');
        Storage::fake('photos');
    }

    public function test_a_second_concurrent_call_is_rejected_with_409_and_imports_nothing(): void
    {
        [$user, $sourcePath] = $this->createImportContext();

        // A competing run holds the documented lock identity. Reaching the
        // endpoint with that lock held is exactly the race the guard exists for.
        $lock = Cache::lock(FtpController::importLockName($user->id), 300);
        $this->assertTrue($lock->get());

        try {
            $response = $this->processAs($user);
        } finally {
            $lock->release();
        }

        $response->assertStatus(409);
        $response->assertJsonStructure(['error']);
        $response->assertHeader('Retry-After');
        $this->assertStringNotContainsString('processed', $response->getContent());

        // Nothing was imported and, above all, the source file is still there
        // for the run that actually owns it.
        $this->assertDatabaseCount('photos', 0);
        $this->assertTrue(Storage::disk('ftp_inbox')->exists($sourcePath));
        $this->assertSame(0, $this->storedPhotoCount());
    }

    public function test_a_rejected_competing_call_does_not_leave_the_lock_behind(): void
    {
        [$user] = $this->createImportContext();

        $lock = Cache::lock(FtpController::importLockName($user->id), 300);
        $this->assertTrue($lock->get());

        try {
            $this->processAs($user)->assertStatus(409);
        } finally {
            $lock->release();
        }

        $this->assertFalse(Cache::lock(FtpController::importLockName($user->id))->isLocked());

        // The photographer can simply retry once the running import is done.
        $this->processAs($user)
            ->assertOk()
            ->assertJson(['success' => true, 'processed' => 1]);
        $this->assertDatabaseCount('photos', 1);
    }

    public function test_a_concurrent_second_call_neither_duplicates_the_photo_nor_the_unlink(): void
    {
        [$user, $sourcePath] = $this->createImportContext();
        $token = 'Bearer '.auth('api')->login($user);

        $metadataExtractions = 0;
        $realService = new PhotoProcessingService;
        $service = Mockery::mock(PhotoProcessingService::class)->makePartial();
        $service->shouldReceive('processImage')->andReturnUsing(
            function (string $targetPath, string $thumbPath, Gallery $gallery) use ($realService, &$metadataExtractions): array {
                $metadataExtractions++;

                return $realService->processImage($targetPath, $thumbPath, $gallery);
            }
        );
        $this->app->instance(PhotoProcessingService::class, $service);

        // Fire a second, genuinely concurrent request while the first run is
        // halfway through its single file: the Photo row is written but the
        // inbox copy has not been unlinked yet, which is the window in which a
        // missing guard produces a duplicate.
        //
        // The hook fires exactly once. Without the guard the competing call
        // would import the same file and trigger the hook again, and the
        // resulting request re-entry would abort the process with a stack
        // overflow instead of failing an assertion — a regression must be a
        // readable failure, not a fatal.
        $competingStatus = null;
        $competingBody = null;
        $fired = false;
        Photo::creating(function () use ($token, &$competingStatus, &$competingBody, &$fired): void {
            if ($fired) {
                return;
            }

            $fired = true;
            $competing = $this->withHeaders(['Authorization' => $token])
                ->postJson('/api/management/ftp/process');
            $competingStatus = $competing->getStatusCode();
            $competingBody = $competing->getContent();
        });

        $response = $this->processAs($user);

        $response->assertOk()->assertJson(['success' => true, 'processed' => 1]);

        // The competing call was turned away, not served.
        $this->assertSame(409, $competingStatus);
        $this->assertIsString($competingBody);
        $this->assertStringContainsString('error', (string) $competingBody);

        // Exactly one copy of every side effect of the import.
        $this->assertSame(1, $metadataExtractions);
        $this->assertDatabaseCount('photos', 1);
        $this->assertDatabaseHas('photos', [
            'gallery_id' => $user->current_ftp_gallery_id,
            'user_id' => $user->id,
            'title' => 'source',
        ]);
        $this->assertSame(1, $this->storedPhotoCount());

        // The single unlink happened once and the inbox is empty afterwards.
        $this->assertFalse(Storage::disk('ftp_inbox')->exists($sourcePath));
        $this->assertSame(0, $this->inboxImageCount($user));
    }

    public function test_a_file_claimed_by_a_competing_run_mid_loop_is_not_imported_twice(): void
    {
        [$user] = $this->createImportContext();

        $first = file_get_contents(base_path('tests/Fixtures/sample.jpg'));
        // The second file carries the same image with one trailing byte, so the
        // two are distinguishable by checksum without needing a second fixture.
        $second = $first."\n";
        Storage::disk('ftp_inbox')->delete($user->ftp_slug.'/source.jpg');
        Storage::disk('ftp_inbox')->put($user->ftp_slug.'/a.jpg', $first);
        Storage::disk('ftp_inbox')->put($user->ftp_slug.'/b.jpg', $second);

        // Models a competing run claiming the remaining file while this run is
        // still busy with the first one. Importing it anyway would create a
        // photo the competing run has already created.
        $service = Mockery::mock(PhotoProcessingService::class)->makePartial();
        $service->shouldReceive('processImage')->andReturnUsing(
            function (string $targetPath) use ($user): array {
                $this->claimEveryInboxFileExcept($user, md5_file($targetPath));

                // An empty title lets the controller fall back to the original
                // filename, so the assertion can name the file that survived.
                return [];
            }
        );
        $this->app->instance(PhotoProcessingService::class, $service);

        // glob() order is filesystem-dependent, so the assertion is the
        // invariant, not a specific file: exactly one of the two was still
        // available when its turn came.
        $response = $this->processAs($user);
        $response->assertOk()->assertJson(['success' => true, 'processed' => 1]);

        $this->assertDatabaseCount('photos', 1);
        $this->assertSame(1, $this->storedPhotoCount());
        $this->assertContains(Photo::sole()->title, ['a', 'b']);
        $this->assertSame(0, $this->inboxImageCount($user));
    }

    public function test_two_different_photographers_are_not_blocked_by_each_other(): void
    {
        [$firstUser] = $this->createImportContext();
        [$secondUser, $secondSourcePath] = $this->createImportContext();

        $lock = Cache::lock(FtpController::importLockName($firstUser->id), 300);
        $this->assertTrue($lock->get());

        try {
            $this->processAs($secondUser)
                ->assertOk()
                ->assertJson(['success' => true, 'processed' => 1]);
        } finally {
            $lock->release();
        }

        $this->assertDatabaseCount('photos', 1);
        $this->assertFalse(Storage::disk('ftp_inbox')->exists($secondSourcePath));
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

    private function processAs(User $user)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.auth('api')->login($user),
        ])->postJson('/api/management/ftp/process');
    }

    /**
     * Number of files the import actually wrote to the photo disk. A duplicated
     * file shows up here even if the duplicate Photo row were later removed.
     */
    private function storedPhotoCount(): int
    {
        $files = [];

        foreach (Storage::disk('photos')->allFiles() as $path) {
            if (! str_contains($path, '_thumbs/')) {
                $files[] = $path;
            }
        }

        return count($files);
    }

    private function inboxImageCount(User $user): int
    {
        $inbox = Storage::disk('ftp_inbox')->path($user->ftp_slug);
        $found = glob($inbox.'/*.{jpg,jpeg,JPG,JPEG}', GLOB_BRACE);

        return $found === false ? 0 : count($found);
    }

    /**
     * Removes every inbox file whose content is not the one just written to the
     * photo disk, i.e. everything the loop has not claimed yet. Uses the copy
     * on the photo disk to identify the current file, so the simulation does not
     * depend on glob() ordering.
     */
    private function claimEveryInboxFileExcept(User $user, string|false $currentChecksum): void
    {
        foreach (Storage::disk('ftp_inbox')->allFiles($user->ftp_slug) as $path) {
            $absolute = Storage::disk('ftp_inbox')->path($path);
            if (md5_file($absolute) === $currentChecksum) {
                continue;
            }

            Storage::disk('ftp_inbox')->delete($path);
        }
    }
}
