<?php

namespace Tests;

use App\Enums\Brand;
use App\Support\BrandRegistry;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Factory;
use PHPOpenSourceSaver\JWTAuth\JWT;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetJwtClaimState();

        if (! app()->bound('brand.context')) {
            BrandRegistry::set(Brand::B2B);
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
