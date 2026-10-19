<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\FtpImportStatus;
use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\Role;
use App\Models\User;
use App\Services\FtpInboxImporter;
use App\Services\PhotoProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The stat-cache **order** of every file probe (fixed 2026-10-18).
 *
 * The rule is that `clearstatcache(true, $path)` runs *before* `is_file()`. With
 * the opposite order, an `is_file()` could answer from a stat cached earlier
 * while the following `filesize()` read freshly, so a source another process
 * (SFTPGo, a parallel worker) had already removed looked "changed". On the
 * post-commit check that deleted an already committed photo together with its
 * stored file and thumbnail.
 *
 * Why this file exists at all: the existing SEC-14 seam replaces `statSource()`
 * wholesale, so deleting the `clearstatcache()` call kept the suite green. The
 * symptom cannot be reproduced deterministically here either — a `rm` in a
 * subprocess was measured to leave PHP's stat cache fresh on this host, and a
 * `rename` of the parent directory invalidates it as well — so the claim that
 * *is* checkable is the order itself. This test records the probe sequence of a
 * subclass of the importer and fails as soon as the invalidation moves, no
 * matter whether it is deleted or reordered.
 */
class FtpStatCacheOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('ftp_inbox');
        Storage::fake('photos');
    }

    public function test_one_probe_clears_the_cache_then_checks_existence_then_size(): void
    {
        $importer = new OrderRecordingImporter(app(PhotoProcessingService::class));
        $path = $this->temporaryFile('abc');

        $probe = $importer->statSource($path);

        $this->assertSame(['present' => true, 'size' => 3], $probe);
        $this->assertSame(['clear', 'is_file', 'filesize'], $importer->calls);
    }

    public function test_a_missing_path_clears_the_cache_then_checks_existence_only(): void
    {
        $importer = new OrderRecordingImporter(app(PhotoProcessingService::class));

        $probe = $importer->statSource('/definitely/not/there/source.jpg');

        $this->assertSame(['present' => false, 'size' => false], $probe);
        $this->assertSame(['clear', 'is_file'], $importer->calls);
    }

    /**
     * The whole import path, not just the probe method: selection, staging and
     * both `sourceHasNotChanged()` calls go through the same seams, so the
     * assertion covers every existence check a run performs.
     */
    public function test_every_existence_check_of_a_real_import_run_is_preceded_by_a_cache_invalidation(): void
    {
        [$user] = $this->importContext();
        $importer = new OrderRecordingImporter(app(PhotoProcessingService::class));

        $result = $importer->importBatch($user);

        $this->assertSame(FtpImportStatus::Imported, $result->status);
        $this->assertSame(1, $result->imported);

        $this->assertContains('is_file', $importer->calls);
        $this->assertContains('filesize', $importer->calls);

        $previous = null;
        foreach ($importer->calls as $call) {
            if ($call === 'is_file') {
                $this->assertSame(
                    'clear',
                    $previous,
                    'Every is_file() must be preceded by clearstatcache(true, $path).',
                );
            }

            $previous = $call;
        }
    }

    /**
     * The invalidation helper must actually call `clearstatcache()`.
     *
     * The order test above records that `forgetStatCache()` is *called* before
     * every `is_file()`, but a subclass that records the call still records it
     * when the production body is empty — so deleting the `clearstatcache()` call
     * kept the suite green while the docblock claimed the opposite. This test
     * reads the method body itself: emptying it, or moving the call into another
     * method, turns red.
     */
    public function test_the_invalidation_helper_contains_the_clearstatcache_call(): void
    {
        $method = new \ReflectionMethod(FtpInboxImporter::class, 'forgetStatCache');
        $file = $method->getFileName();
        $this->assertIsString($file);

        $lines = file($file);
        $this->assertIsArray($lines);

        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $this->assertStringContainsString(
            'clearstatcache(true, $path);',
            $body,
            'forgetStatCache() must call clearstatcache(true, $path); removing or moving the call is red.',
        );
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ftp-stat-order');
        $this->assertIsString($path);
        file_put_contents($path, $contents);

        return $path;
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

    private function sampleImageContents(): string
    {
        $contents = file_get_contents(base_path('tests/Fixtures/sample.jpg'));
        $this->assertIsString($contents);

        return $contents;
    }
}

/**
 * Records the order of the three file-system primitives the importer uses.
 *
 * It overrides the *primitives*, not `statSource()`: that is the whole point —
 * the composition of the three is the claim under test, so it must stay in the
 * production class.
 */
class OrderRecordingImporter extends FtpInboxImporter
{
    /** @var list<string> */
    public array $calls = [];

    protected function forgetStatCache(string $path): void
    {
        $this->calls[] = 'clear';
        parent::forgetStatCache($path);
    }

    protected function isRegularFile(string $path): bool
    {
        $this->calls[] = 'is_file';

        return parent::isRegularFile($path);
    }

    protected function fileSize(string $path): int|false
    {
        $this->calls[] = 'filesize';

        return parent::fileSize($path);
    }
}
