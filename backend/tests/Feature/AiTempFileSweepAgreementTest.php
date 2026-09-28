<?php

namespace Tests\Feature;

use App\Services\AIService;
use App\Support\TempDirectory;
use Illuminate\Support\Facades\File;
use Tests\Support\UsesIsolatedTempDirectory;
use Tests\TestCase;

/**
 * Regression (AIS-8): AIService creates its temporary image below the same
 * `filesystems.temp_dir` base that `app:cleanup-temp` scans. Writer and
 * janitor resolving different directories is precisely the regression this
 * covers: the sweep would report success while the AI temp file survives.
 */
class AiTempFileSweepAgreementTest extends TestCase
{
    use UsesIsolatedTempDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpIsolatedTempDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedTempDirectory();

        parent::tearDown();
    }

    public function test_ai_temp_directory_is_inside_the_directory_the_cleanup_sweeps(): void
    {
        $sweptBase = (string) config('filesystems.temp_dir');
        $aiDirectory = TempDirectory::path('ai');

        $this->assertStringStartsWith(
            rtrim($sweptBase, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR,
            $aiDirectory,
            'AIService must write below the directory app:cleanup-temp scans.'
        );
    }

    public function test_cleanup_temp_removes_an_orphaned_ai_temp_file(): void
    {
        $aiDirectory = $this->isolatedTempDir('ai');
        $orphan = $aiDirectory.DIRECTORY_SEPARATOR.AIService::DEFAULT_TEMPORARY_PREFIX.'orphan';

        File::ensureDirectoryExists($aiDirectory);
        File::put($orphan, 'left behind by a hard kill');
        touch($orphan, now()->subDays(2)->getTimestamp());

        $this->artisan('app:cleanup-temp')->assertExitCode(0);

        $this->assertFileDoesNotExist($orphan);
    }
}
