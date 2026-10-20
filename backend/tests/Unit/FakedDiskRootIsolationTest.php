<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Depends;
use Tests\TestCase;

/**
 * The faked disk roots of the suite must be unique per test.
 *
 * `Storage::fake()` roots a disk at a fixed, process-independent path and
 * empties that directory on every call. Two PHP processes that fake the same
 * disk therefore shared one directory: one process' `cleanDirectory()` deleted
 * the files of the other, and a disk-wide assertion saw files the test never
 * wrote — measured as 8 of 10 concurrent runs failing with a foreign
 * `model-age-proofs/foreign/file1.bin`.
 *
 * `Tests\TestCase` installs a per-test token, so every faked disk gets its own
 * root. These tests pin that invariant, so a future change that drops the
 * token fails here instead of in a flaky concurrent run.
 *
 * Every assertion in this class can fail: against a `TestCase` without the
 * per-test token the roots are the shared `storage/framework/testing/disks/<disk>`
 * and the class reports three failures.
 */
class FakedDiskRootIsolationTest extends TestCase
{
    /**
     * The faked disk root of the test that is running.
     */
    private ?string $currentRoot = null;

    /**
     * The faked disk roots of the tests of this class that have finished.
     *
     * @var list<string>
     */
    private static array $recordedRoots = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Every test of this class fakes the disk, so every test has a root
        // that the next test can be compared with.
        Storage::fake('local');

        $this->currentRoot = $this->rootOf('local');
    }

    protected function tearDown(): void
    {
        if ($this->currentRoot !== null) {
            self::$recordedRoots[] = $this->currentRoot;
        }

        parent::tearDown();
    }

    public function test_a_faked_disk_is_rooted_below_a_per_test_directory(): void
    {
        $root = $this->rootOf('local');

        // The process-wide root is the one that made concurrent runs delete
        // each other's files. It must never be used again.
        $this->assertNotSame(storage_path('framework/testing/disks/local'), $root);
        $this->assertStringStartsWith(storage_path('framework/testing/disks/local_test_'), $root);
        $this->assertDirectoryExists($root);
    }

    public function test_the_configuration_and_the_faked_disk_name_the_same_root(): void
    {
        // Code that reads the disk root from the configuration has to see the
        // per-test root, not the process-wide one.
        $this->assertSame($this->rootOf('local'), config('filesystems.disks.local.root'));
    }

    public function test_the_two_disks_of_one_test_share_the_per_test_token(): void
    {
        Storage::fake('photos');

        $local = $this->rootOf('local');
        $photos = $this->rootOf('photos');

        // The token identifies the *test*, not the disk: the two disks of this
        // test live in one per-test namespace.
        $this->assertNotSame(
            '',
            $this->perTestToken($local),
            'The local root must carry a per-test token.',
        );
        $this->assertSame(
            $this->perTestToken($local),
            $this->perTestToken($photos),
            'Both disks of one test must carry the same per-test token.',
        );

        // Neither disk may use the process-wide root that concurrent runs
        // share — that root is what made two PHP processes delete each
        // other's files.
        $this->assertNotSame(storage_path('framework/testing/disks/local'), $local);
        $this->assertNotSame(storage_path('framework/testing/disks/photos'), $photos);
    }

    /**
     * The root this test got, handed to the test that compares it with its
     * own root.
     */
    public function test_the_faked_disk_root_of_this_test(): string
    {
        $root = $this->rootOf('local');

        $this->assertDirectoryExists($root);

        return $root;
    }

    /**
     * Two tests of the same class must not share a faked disk root.
     *
     * The other test's root comes from the dependency above, so the two roots
     * come from two different tests — and therefore from two different
     * per-test tokens.
     */
    #[Depends('test_the_faked_disk_root_of_this_test')]
    public function test_two_tests_never_share_the_same_faked_disk_root(string $otherTestRoot): void
    {
        $root = $this->rootOf('local');

        $this->assertNotSame(
            $otherTestRoot,
            $root,
            'Two tests must never share a faked disk root: the per-test token is missing.',
        );

        // The same claim against every test of this class that has already
        // finished — including the dependency above.
        $this->assertNotContains(
            $root,
            self::$recordedRoots,
            'This test shares its faked disk root with an earlier test of this class.',
        );
    }

    /**
     * The root of the finished test must be gone, so the per-test roots do not
     * pile up in `storage/framework/testing/disks`.
     */
    #[Depends('test_the_faked_disk_root_of_this_test')]
    public function test_the_faked_disk_root_of_the_previous_test_is_removed(string $previousRoot): void
    {
        $this->assertDirectoryDoesNotExist($previousRoot);
    }

    /**
     * The absolute root of a faked disk, without a trailing separator.
     */
    private function rootOf(string $disk): string
    {
        return rtrim(Storage::disk($disk)->path(''), '/');
    }

    /**
     * The per-test token in a faked disk root, or an empty string when the
     * root carries none.
     */
    private function perTestToken(string $root): string
    {
        $marker = '_test_';
        $basename = basename($root);
        $position = strpos($basename, $marker);

        return $position === false ? '' : substr($basename, $position + strlen($marker));
    }
}
