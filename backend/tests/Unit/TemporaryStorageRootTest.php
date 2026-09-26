<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TemporaryStorageRootTest extends TestCase
{
    public function test_temporary_storage_roots_are_unique_and_cleaned(): void
    {
        $firstRoot = $this->useTemporaryStorageDisk('temporary-root-a');
        $secondRoot = $this->useTemporaryStorageDisk('temporary-root-b');

        $temporaryDirectory = realpath(sys_get_temp_dir());
        $this->assertNotFalse($temporaryDirectory);
        $this->assertStringStartsWith(
            $temporaryDirectory.DIRECTORY_SEPARATOR.'portal-test-storage-',
            $firstRoot,
        );
        $this->assertStringStartsWith(
            $temporaryDirectory.DIRECTORY_SEPARATOR.'portal-test-storage-',
            $secondRoot,
        );
        $this->assertNotSame($firstRoot, $secondRoot);
        $this->assertDirectoryExists($firstRoot);
        $this->assertDirectoryExists($secondRoot);

        Storage::disk('temporary-root-a')->put('artifact.txt', 'test artifact');
        $this->assertFileExists($firstRoot.DIRECTORY_SEPARATOR.'artifact.txt');

        $this->cleanupTemporaryStorageRoot($firstRoot);
        $this->cleanupTemporaryStorageRoot($secondRoot);

        $this->assertDirectoryDoesNotExist($firstRoot);
        $this->assertDirectoryDoesNotExist($secondRoot);
    }
}
