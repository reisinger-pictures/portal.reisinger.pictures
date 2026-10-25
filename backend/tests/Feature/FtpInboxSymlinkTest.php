<?php

namespace Tests\Feature;

use App\Enums\Brand;
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
 * MEDIA-5: the inbox walk must not follow a symlink out of the inbox.
 *
 * The camera-facing SFTPGo account is created with `['/' => ['*']]`
 * (`SftpGoClient::CAMERA_PERMISSIONS`), i.e. the client may create anything in
 * its home directory — including a symlink. The importer then decided what to
 * import with `is_file()`/`fopen()`, and both follow links: a link named
 * `x.jpg` pointing at a file outside the inbox was read, staged and imported as
 * if the client had uploaded that file, and the read happened with the
 * backend's own privileges rather than the client's.
 *
 * This suite pins the safe behaviour, not the implementation: nothing outside
 * the inbox is imported, the regular upload path keeps working, and a
 * subdirectory link is never traversed. The intra-inbox case is asserted as
 * "every symlink is rejected" because that is the implemented rule — the
 * cheapest one that does not need a second path resolution whose failure mode
 * is a silently skipped real upload.
 */
class FtpInboxSymlinkTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A directory outside the fake `ftp_inbox` disk, so a path check against the
     * disk root cannot pass by accident. It is created in the system temp
     * directory rather than in `storage/` because the point is that the target
     * is not reachable from the inbox by any prefix comparison.
     */
    private string $outsideDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('ftp_inbox');
        Storage::fake('photos');

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ftp-inbox-symlink-'.bin2hex(random_bytes(6));

        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            $this->fail('Could not create the symlink target fixture directory.');
        }

        $this->outsideDirectory = $directory;
    }

    protected function tearDown(): void
    {
        // The fake disks are discarded by Storage::fake(); the outside fixture
        // directory is ours, and the links inside the inbox are not files the
        // disk cleanup removes on every platform.
        foreach (scandir($this->outsideDirectory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            @unlink($this->outsideDirectory.DIRECTORY_SEPARATOR.$entry);
        }

        @rmdir($this->outsideDirectory);

        parent::tearDown();
    }

    public function test_a_symlink_to_a_file_outside_the_inbox_is_not_imported(): void
    {
        $user = $this->createImportContext();

        $target = $this->outsideDirectory.DIRECTORY_SEPARATOR.'outside.jpg';
        $this->writeSampleImage($target);

        $link = $this->inboxPath($user).DIRECTORY_SEPARATOR.'linked.jpg';
        $this->assertTrue(symlink($target, $link), 'The fixture symlink could not be created.');

        $this->mockPhotoProcessing();

        $result = app(FtpInboxImporter::class)->importBatch($user);

        $this->assertSame(0, $result->imported, 'A link out of the inbox must not be imported.');
        $this->assertNothingImported();

        // The target is somebody else's file: it must neither be copied into
        // the gallery nor be moved or deleted by the run.
        $this->assertFileExists($target);
        $this->assertSame($this->sampleBytes(), filesize($target));

        $this->assertTrue(
            $this->linkSurvivesInInboxOrQuarantine($user, 'linked.jpg'),
            'A rejected symlink must be left in the inbox or quarantined, never deleted.',
        );
    }

    public function test_a_regular_file_in_the_inbox_is_still_imported(): void
    {
        $user = $this->createImportContext();

        $sourcePath = $user->ftp_slug.'/source.jpg';
        $this->putSampleImage($sourcePath);

        $this->mockPhotoProcessing();

        $result = app(FtpInboxImporter::class)->importBatch($user);

        $this->assertSame(1, $result->imported);
        $this->assertDatabaseCount('photos', 1);
        $this->assertCount(1, Storage::disk('photos')->allFiles());
        $this->assertFalse(Storage::disk('ftp_inbox')->exists($sourcePath));
    }

    public function test_a_directory_symlink_inside_the_inbox_is_not_traversed(): void
    {
        $user = $this->createImportContext();

        $nested = $this->outsideDirectory.DIRECTORY_SEPARATOR.'nested.jpg';
        $this->writeSampleImage($nested);

        $inbox = $this->inboxPath($user);
        $this->assertTrue(symlink($this->outsideDirectory, $inbox.DIRECTORY_SEPARATOR.'album'));
        // The image extension is what the walk filters on, so the interesting
        // case is a directory link that passes that filter.
        $this->assertTrue(symlink($this->outsideDirectory, $inbox.DIRECTORY_SEPARATOR.'album.jpg'));

        $this->mockPhotoProcessing();

        $result = app(FtpInboxImporter::class)->importBatch($user);

        $this->assertSame(0, $result->imported, 'A directory link must not be walked into.');
        $this->assertNothingImported();
        $this->assertFileExists($nested);
    }

    public function test_a_symlink_to_a_file_inside_the_inbox_is_rejected_because_every_symlink_is(): void
    {
        $user = $this->createImportContext();

        // The target is inside the inbox but is not itself a candidate (no
        // image extension), so the link is the only entry the walk can reach.
        // Pointing it at another `*.jpg` would make the outcome depend on
        // directory order: the first one imported is unlinked, and the second
        // entry then either dangles or produces a duplicate.
        $this->putSampleImage($user->ftp_slug.'/original.dat');

        $inbox = $this->inboxPath($user);
        $this->assertTrue(symlink($inbox.DIRECTORY_SEPARATOR.'original.dat', $inbox.DIRECTORY_SEPARATOR.'linked.jpg'));

        $this->mockPhotoProcessing();

        $result = app(FtpInboxImporter::class)->importBatch($user);

        // The link resolves inside the inbox and could be supported. The
        // implementation rejects every symlink instead, so that is what is
        // asserted: no photo, the link stays.
        $this->assertSame(0, $result->imported, 'Every symlink is rejected, including one that resolves inside the inbox.');
        $this->assertNothingImported();
        $this->assertSame(
            $this->sampleBytes(),
            filesize($inbox.DIRECTORY_SEPARATOR.'original.dat'),
            'The link target must not be consumed or deleted.',
        );
        $this->assertTrue(
            $this->linkSurvivesInInboxOrQuarantine($user, 'linked.jpg'),
            'The intra-inbox symlink must be skipped or quarantined, not deleted.',
        );
    }

    private function createImportContext(): User
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

        $inbox = $this->inboxPath($user);
        if (! is_dir($inbox) && ! mkdir($inbox, 0755, true) && ! is_dir($inbox)) {
            $this->fail('Could not create the inbox fixture directory.');
        }

        return $user;
    }

    /** The absolute path the importer walks, including the configured upload path. */
    private function inboxPath(User $user): string
    {
        return app(FtpInboxImporter::class)->inboxPath($user);
    }

    private function putSampleImage(string $relativePath): void
    {
        $contents = file_get_contents(base_path('tests/Fixtures/sample.jpg'));
        $this->assertIsString($contents);
        Storage::disk('ftp_inbox')->put($relativePath, $contents);
    }

    private function writeSampleImage(string $path): void
    {
        $contents = file_get_contents(base_path('tests/Fixtures/sample.jpg'));
        $this->assertIsString($contents);
        $this->assertNotFalse(file_put_contents($path, $contents), 'Could not write the symlink target fixture.');
    }

    private function sampleBytes(): int
    {
        $bytes = filesize(base_path('tests/Fixtures/sample.jpg'));
        $this->assertIsInt($bytes);

        return $bytes;
    }

    /**
     * A stub, not an expectation: the assertion that matters is that no photo
     * row and no photo file appear, which a wrongly imported link would create.
     */
    private function mockPhotoProcessing(): void
    {
        $photoService = $this->createMock(PhotoProcessingService::class);
        $photoService->method('processImage')
            ->willReturn(['width' => 1, 'height' => 1, 'title' => 'source']);
        $this->app->instance(PhotoProcessingService::class, $photoService);
    }

    private function assertNothingImported(): void
    {
        $this->assertDatabaseCount('photos', 0);
        $this->assertSame([], Storage::disk('photos')->allFiles());
    }

    /**
     * Skipped-in-place and quarantined are both acceptable ("skipped or
     * quarantined"); destroyed is not — the symlink is the client's entry, and
     * nothing in the import may delete an upload it did not import.
     */
    private function linkSurvivesInInboxOrQuarantine(User $user, string $name): bool
    {
        $inbox = $this->inboxPath($user);

        if (is_link($inbox.DIRECTORY_SEPARATOR.$name)) {
            return true;
        }

        $quarantine = $inbox.DIRECTORY_SEPARATOR.FtpInboxImporter::QUARANTINE_DIRECTORY;

        foreach (scandir($quarantine) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..'
                && str_ends_with($entry, $name)
                && is_link($quarantine.DIRECTORY_SEPARATOR.$entry)) {
                return true;
            }
        }

        return false;
    }
}
