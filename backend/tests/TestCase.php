<?php

namespace Tests;

use App\Enums\Brand;
use App\Support\BrandRegistry;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! app()->bound('brand.context')) {
            BrandRegistry::set(Brand::B2B);
        }
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
