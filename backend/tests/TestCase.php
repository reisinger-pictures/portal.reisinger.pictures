<?php

namespace Tests;

use App\Enums\Brand;
use App\Support\BrandRegistry;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Factory;
use PHPOpenSourceSaver\JWTAuth\JWT;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * The token that gives the faked disk roots of this test a unique name.
     */
    private ?string $fakedDiskToken = null;

    /**
     * The disks whose faked roots are removed when this test ends.
     *
     * @var list<string>
     */
    private array $fakedDiskNames = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolateFakedDiskRoots();

        $this->resetJwtClaimState();

        if (! app()->bound('brand.context')) {
            BrandRegistry::set(Brand::B2B);
        }
    }

    protected function tearDown(): void
    {
        $this->restoreParallelTestingToken();

        parent::tearDown();
    }

    /**
     * Give every faked disk of this test its own root directory.
     *
     * `Storage::fake()` roots a disk at a fixed, process-independent path
     * (`storage/framework/testing/disks/<disk>`) and *empties* that directory
     * on every call. Two PHP processes that fake the same disk therefore
     * share one directory: the `cleanDirectory()` of one process deletes the
     * files of the other, and a disk-wide assertion sees files the test
     * never wrote. The failure is not hypothetical — a foreign file
     * (`model-age-proofs/foreign/file1.bin`) made
     * `ModelRegistrationTest::test_failed_age_proof_metadata_write_removes_uploaded_file`
     * fail in 8 of 10 concurrent runs.
     *
     * Laravel's own answer to this is the parallel-testing token: it suffixes
     * the fake root with `_<token>`. That token identifies a *worker
     * process*, so two independent runs in the same checkout still share the
     * root. A per-test token makes the root unique per test and per process
     * at the same time, so a disk assertion can only ever see the files of
     * its own test.
     *
     * The token is installed *after* `parent::setUp()`, because the framework
     * resolves the token while it boots the test environment (compiled view
     * path, cache prefix, test database). Those must stay stable for the
     * whole worker process — only `Storage::fake()` needs the per-test value.
     */
    private function isolateFakedDiskRoots(): void
    {
        $token = (string) Str::uuid();
        $this->fakedDiskToken = $token;

        // The disk list is read before the test runs: a test may replace the
        // whole `filesystems.disks` configuration, and the cleanup must still
        // know every root that was faked.
        $this->fakedDiskNames = array_keys((array) config('filesystems.disks'));

        ParallelTesting::resolveTokenUsing(static fn (): string => $token);

        // The configuration and the faked disk must name the same directory:
        // code that reads the disk root from the configuration has to see the
        // per-test root, not the process-wide one.
        config(['filesystems.disks.local.root' => $this->fakedDiskRoot('local')]);

        $this->beforeApplicationDestroyed(function () use ($token): void {
            $this->removeFakedDiskRoots($token);
        });
    }

    /**
     * Hand the parallel-testing token back to the framework.
     *
     * The framework resolves the token for the compiled view path, the cache
     * prefix and the test database. Those are resolved while the test
     * environment boots and while it is torn down, so the per-test token must
     * not be active there.
     */
    private function restoreParallelTestingToken(): void
    {
        ParallelTesting::resolveTokenUsing(null);

        $this->fakedDiskToken = null;
    }

    /**
     * The root directory a faked disk of this test uses.
     */
    protected function fakedDiskRoot(string $disk): string
    {
        return storage_path('framework/testing/disks/'.$disk.'_test_'.$this->fakedDiskToken);
    }

    /**
     * Remove the faked disk roots of this test.
     *
     * Without this the roots would pile up in `storage/framework/testing/disks`
     * — one directory per test — and fill the disk.
     */
    private function removeFakedDiskRoots(string $token): void
    {
        $filesystem = new Filesystem;

        foreach ($this->fakedDiskNames as $disk) {
            $root = storage_path('framework/testing/disks/'.$disk.'_test_'.$token);

            if ($filesystem->isDirectory($root)) {
                $filesystem->deleteDirectory($root);
            }
        }
    }

    /**
     * Reset the jwt-auth claim state of the current test.
     *
     * The jwt-auth `Factory` is a container singleton whose claims collection
     * *accumulates*: `make()` adds the new claims to the existing collection
     * instead of replacing it, and `Manager::decode()` merges the whole
     * collection into the payload it decodes. A guest token issued in a test
     * therefore leaves its `guest_id`, `guest_invite_id` and
     * `transient_galleries` claims in that collection, and every token that
     * is built or decoded afterwards *in the same test* inherits them. The
     * inline custom claims of the `JWT`/`JWTAuth` singletons survive in the
     * same way until the next `claims()` call.
     *
     * A fresh application per test empties the collection again, so the leak
     * does not cross a test boundary — but it does cross every token and
     * every request inside one test. The reset makes the invariant explicit
     * and keeps it true if the application is ever reused.
     */
    protected function resetJwtClaimState(): void
    {
        app(Factory::class)->emptyClaims();
        app(Factory::class)->customClaims([]);
        app(JWT::class)->claims([]);
        app(JWTAuth::class)->claims([]);
    }

    /**
     * Issue a CSRF token through the real API path and return the cookie value
     * plus the headers a browser would send.
     *
     * The token is minted by the middleware itself, so this helper cannot drift
     * from the signing format: a request without the cookie is rejected, and the
     * first response of any `/api/*` call carries the signed token.
     *
     * @return array{cookie: string, headers: array<string, string>}
     */
    protected function csrfCredentials(): array
    {
        $token = (string) $this->getJson('/api/ping')->getCookie('XSRF-TOKEN', false)?->getValue();
        if ($token === '') {
            throw new RuntimeException('The API did not issue an XSRF-TOKEN cookie.');
        }

        return [
            'cookie' => $token,
            'headers' => [
                'X-XSRF-TOKEN' => $token,
                // The test client builds absolute URLs from `app.url`, so this
                // is exactly the effective request origin.
                'Origin' => rtrim((string) config('app.url'), '/'),
            ],
        ];
    }

    /**
     * Register a local disk below a user-owned, per-test system temp root.
     *
     * Storage::fake uses a fixed project-local root that may contain artifacts
     * owned by another UID, so this helper never falls back to that tree.
     */
    protected function useTemporaryStorageDisk(string $disk): string
    {
        $temporaryDirectory = realpath(sys_get_temp_dir());
        if ($temporaryDirectory === false) {
            throw new RuntimeException('Unable to resolve the system temporary directory.');
        }

        $root = $temporaryDirectory.DIRECTORY_SEPARATOR.'portal-test-storage-'.Str::uuid();
        if (! @mkdir($root, 0700)) {
            throw new RuntimeException("Unable to create temporary storage root [{$root}].");
        }

        $this->beforeApplicationDestroyed(function () use ($root): void {
            $this->cleanupTemporaryStorageRoot($root);
        });

        Storage::set($disk, Storage::build([
            'driver' => 'local',
            'root' => $root,
            'throw' => false,
        ]));

        return $root;
    }

    /**
     * Point the `ftp_inbox` disk at a real, writable, per-test directory and
     * return its absolute path.
     *
     * The slug write path creates `ftp/<ftp_slug>` on disk before it commits the
     * slug (D-2), so any test that changes a slug needs a root the process can
     * actually write to. A fixed `/var/www/ftp` cannot serve: it does not exist
     * on a developer machine and is not creatable there — nor in CI, where the
     * suite runs as root but into a workspace-owned tree.
     *
     * A real directory, not a fake disk: the behaviour under test *is*
     * filesystem behaviour, and `Storage::fake()` would not exercise it. The
     * config value is moved with the directory, so `homeDirectoryFor()` and the
     * provisioning path agree on one path.
     */
    protected function useTemporaryFtpInboxRoot(): string
    {
        $temporaryDirectory = realpath(sys_get_temp_dir());
        if ($temporaryDirectory === false) {
            throw new RuntimeException('Unable to resolve the system temporary directory.');
        }

        $root = $temporaryDirectory.DIRECTORY_SEPARATOR.'portal-ftp-inbox-'.Str::uuid();
        if (! @mkdir($root, 0700)) {
            throw new RuntimeException("Unable to create temporary FTP inbox root [{$root}].");
        }

        $this->beforeApplicationDestroyed(function () use ($root): void {
            $this->cleanupTemporaryStorageRoot($root);
        });

        config(['filesystems.disks.ftp_inbox.root' => $root]);

        return $root;
    }

    protected function cleanupTemporaryStorageRoot(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }

        (new Filesystem)->deleteDirectory($root);

        if (is_dir($root)) {
            throw new RuntimeException("Unable to clean temporary storage root [{$root}].");
        }
    }
}
