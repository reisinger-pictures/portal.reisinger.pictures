<?php

namespace Tests\Support;

use App\Support\TempDirectory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Isolates a test class from the shared temp directory.
 *
 * ParaTest runs separate test classes in separate worker processes. The
 * database is isolated per worker through SQLite :memory:, the filesystem is
 * not, so any test that writes into the real storage/app/private/temp shares
 * one flat namespace with whatever class happens to run next to it. That is
 * not hypothetical: two import test classes used the same cache file names in
 * the same absolute directory and produced intermittent failures whose symptom
 * depended on which worker won the race.
 *
 * This trait points filesystems.temp_dir at a directory unique to the test, so
 * every consumer resolves underneath it. Call setUpIsolatedTempDirectory() from
 * setUp() and tearDownIsolatedTempDirectory() from tearDown().
 *
 * Two independent guarantees, both needed:
 *
 * 1. The unique base keeps this class away from other test classes.
 * 2. TempDirectory's per-consumer subdirectory keeps the code under test away
 *    from the other consumers sharing the same base — which matters for a test
 *    that mirrors production path handling, so the mirror has to use the same
 *    resolver the production code uses rather than a hand-built path.
 */
trait UsesIsolatedTempDirectory
{
    /**
     * Per-test base directory. Consumers resolve to subdirectories below it.
     */
    private string $isolatedTempBaseDir;

    protected function setUpIsolatedTempDirectory(): void
    {
        $this->isolatedTempBaseDir = storage_path('app/private/testing/temp-'.Str::uuid());

        config(['filesystems.temp_dir' => $this->isolatedTempBaseDir]);
    }

    protected function tearDownIsolatedTempDirectory(): void
    {
        File::deleteDirectory($this->isolatedTempBaseDir);
    }

    /**
     * Absolute path of a consumer's directory inside this test's base.
     *
     * Use this instead of building a path by hand whenever the test is
     * standing in for production code, so the test cannot drift away from the
     * layout the code actually uses.
     */
    protected function isolatedTempDir(string $consumer): string
    {
        return TempDirectory::path($consumer);
    }

    /**
     * Absolute path for a file this test creates itself and no production
     * consumer owns — a fixture fed straight into an UploadedFile, for
     * example.
     *
     * Prefer isolatedTempDir() whenever the artifact stands in for something
     * the application writes, so the test keeps asserting against the real
     * layout. Reach for this only when there is genuinely no consumer.
     */
    protected function isolatedTempPath(string $relativePath): string
    {
        return $this->isolatedTempBaseDir.DIRECTORY_SEPARATOR.ltrim($relativePath, '/\\');
    }
}
