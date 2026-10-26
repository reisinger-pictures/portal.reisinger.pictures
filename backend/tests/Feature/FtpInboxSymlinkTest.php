<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\FtpImportStatus;
use App\Enums\UserRole;
use App\Http\Controllers\FtpController;
use App\Models\Gallery;
use App\Models\Role;
use App\Models\User;
use App\Services\FtpInboxImporter;
use App\Services\PhotoProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * MEDIA-5 and MEDIA-6 (D-59): the inbox walk must not follow symlinks out of the inbox.
 *
 * The camera-facing SFTPGo account has broad per-directory permissions, but
 * `common.symlink_mode = 0` prevents the photographer from creating symlinks.
 * The host-side importer nevertheless runs without a chroot, so these tests
 * pin both the per-entry checks and D-59's fail-closed inbox-path containment.
 *
 * This suite pins the safe behaviour, not the implementation: nothing outside
 * the inbox is imported, the regular upload path keeps working, and a
 * subdirectory link is never traversed. Per-entry links are rejected directly
 * rather than resolved: a `realpath()` failure for a vanished entry must not
 * silently skip an otherwise real upload.
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

    private ?string $symlinkedDiskRoot = null;

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
        if ($this->symlinkedDiskRoot !== null && is_link($this->symlinkedDiskRoot)) {
            unlink($this->symlinkedDiskRoot);
        }

        // The fake disks are discarded by Storage::fake(); clean the separately
        // created outside fixture, including the nested target used by the path
        // traversal regression.
        File::deleteDirectory($this->outsideDirectory);

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

    public function test_a_missing_inbox_remains_a_quiet_empty_result(): void
    {
        config(['services.ftp_transport.upload_path' => '/nested/inbox']);
        $user = $this->createImportContext();

        $inbox = $this->inboxPath($user);
        $ancestor = dirname($inbox);
        $this->assertTrue(rmdir($inbox));
        $this->assertTrue(rmdir($ancestor));
        Log::spy();

        $result = app(FtpInboxImporter::class)->importBatch($user);

        $this->assertSame(FtpImportStatus::Empty, $result->status);
        $this->assertSame(0, app(FtpInboxImporter::class)->countInboxImages($inbox, $user));
        Log::shouldNotHaveReceived('error');

        $this->actingAs($user, 'api');
        $response = app(FtpController::class)->status();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $response->getData(true)['file_count']);
    }

    public function test_a_dangling_ancestor_symlink_is_rejected_and_logged(): void
    {
        config(['services.ftp_transport.upload_path' => '/nested/inbox']);
        $user = $this->createImportContext();
        $this->createDanglingInboxAncestorSymlink($user);

        $this->mockPhotoProcessing();

        $this->assertInboxPathRejected($user, 'symlinked inbox');
        $this->assertNothingImported();
    }

    public function test_status_reports_a_dangling_ancestor_symlink_as_unreadable(): void
    {
        config(['services.ftp_transport.upload_path' => '/nested/inbox']);
        $user = $this->createImportContext();
        $inbox = $this->createDanglingInboxAncestorSymlink($user);

        $this->assertFalse(is_link($inbox));
        $this->assertNull(app(FtpInboxImporter::class)->countInboxImages($inbox, $user));

        $this->actingAs($user, 'api');
        $response = app(FtpController::class)->status();
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(
            ['error' => 'Der FTP-Posteingang konnte nicht gelesen werden.'],
            $response->getData(true),
        );
    }

    public function test_a_dangling_account_home_symlink_is_rejected_and_logged(): void
    {
        config(['services.ftp_transport.upload_path' => '/nested/inbox']);
        $user = $this->createImportContext();
        $this->createDanglingAccountHomeSymlink($user);

        $this->mockPhotoProcessing();

        $this->assertInboxPathRejected($user, 'symlinked inbox');
        $this->assertNothingImported();
    }

    public function test_status_reports_a_dangling_account_home_symlink_as_unreadable(): void
    {
        config(['services.ftp_transport.upload_path' => '/nested/inbox']);
        $user = $this->createImportContext();
        $inbox = $this->createDanglingAccountHomeSymlink($user);

        $this->assertNull(app(FtpInboxImporter::class)->countInboxImages($inbox, $user));

        $this->actingAs($user, 'api');
        $response = app(FtpController::class)->status();
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(
            ['error' => 'Der FTP-Posteingang konnte nicht gelesen werden.'],
            $response->getData(true),
        );
    }

    public function test_a_symlinked_ftp_inbox_disk_root_is_a_trusted_anchor(): void
    {
        config(['services.ftp_transport.upload_path' => '/nested/inbox']);

        $targetRoot = $this->outsideDirectory.DIRECTORY_SEPARATOR.'real-disk-root';
        $this->assertTrue(mkdir($targetRoot));
        $this->symlinkedDiskRoot = $this->outsideDirectory.DIRECTORY_SEPARATOR.'symlinked-disk-root';
        $this->assertTrue(symlink($targetRoot, $this->symlinkedDiskRoot));

        config(['filesystems.disks.ftp_inbox.root' => $this->symlinkedDiskRoot]);
        Storage::set('ftp_inbox', Storage::build([
            'driver' => 'local',
            'root' => $this->symlinkedDiskRoot,
            'throw' => false,
        ]));

        $user = $this->createImportContext();
        $inbox = $this->inboxPath($user);
        $this->assertTrue(is_link(rtrim(Storage::disk('ftp_inbox')->path(''), DIRECTORY_SEPARATOR)));
        $this->assertSame(0, app(FtpInboxImporter::class)->countInboxImages($inbox, $user));

        $this->putSampleImage($user->ftp_slug.'/nested/inbox/source.jpg');
        $this->mockPhotoProcessing();

        $result = app(FtpInboxImporter::class)->importBatch($user);

        $this->assertSame(1, $result->imported);
        $this->assertDatabaseCount('photos', 1);
    }

    public function test_a_dangling_inbox_symlink_is_rejected_and_logged(): void
    {
        config(['services.ftp_transport.upload_path' => '/incoming']);
        $user = $this->createImportContext();
        $this->createDanglingInboxSymlink($user);

        $this->mockPhotoProcessing();

        $this->assertInboxPathRejected($user, 'symlinked inbox');
        $this->assertNothingImported();
    }

    public function test_status_reports_a_dangling_inbox_symlink_as_unreadable(): void
    {
        config(['services.ftp_transport.upload_path' => '/incoming']);
        $user = $this->createImportContext();
        $inbox = $this->createDanglingInboxSymlink($user);

        $this->assertNull(app(FtpInboxImporter::class)->countInboxImages($inbox, $user));

        $this->actingAs($user, 'api');
        $response = app(FtpController::class)->status();
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(
            ['error' => 'Der FTP-Posteingang konnte nicht gelesen werden.'],
            $response->getData(true),
        );
    }

    public function test_a_symlinked_inbox_inside_the_account_home_is_rejected_and_logged(): void
    {
        config(['services.ftp_transport.upload_path' => '/incoming']);
        $user = $this->createImportContext();

        $home = Storage::disk('ftp_inbox')->path((string) $user->ftp_slug);
        $inbox = $this->inboxPath($user);
        $target = $home.DIRECTORY_SEPARATOR.'alternate-inbox';
        $this->assertTrue(mkdir($target));
        $source = $target.DIRECTORY_SEPARATOR.'source.jpg';
        $this->writeSampleImage($source);
        $this->assertTrue(rmdir($inbox));
        $this->assertTrue(symlink($target, $inbox));

        $this->mockPhotoProcessing();

        $this->assertInboxPathRejected($user, 'symlinked inbox');
        $this->assertNothingImported();
        $this->assertFileExists($source);
        $this->assertSame($this->sampleBytes(), filesize($source));
    }

    public function test_an_inbox_resolving_outside_the_account_home_is_rejected_and_logged(): void
    {
        config(['services.ftp_transport.upload_path' => '/nested/inbox']);
        $user = $this->createImportContext();

        $inbox = $this->inboxPath($user);
        $nested = dirname($inbox);
        $this->assertTrue(rmdir($inbox));
        $this->assertTrue(rmdir($nested));

        $sourceDirectory = $this->outsideDirectory.DIRECTORY_SEPARATOR.'inbox';
        $this->assertTrue(mkdir($sourceDirectory));
        $source = $sourceDirectory.DIRECTORY_SEPARATOR.'source.jpg';
        $this->writeSampleImage($source);
        $this->assertTrue(symlink($this->outsideDirectory, $nested));
        $this->assertFalse(is_link($inbox), 'Only the parent path should be linked in this fixture.');
        $this->assertTrue(is_dir($inbox));

        $this->mockPhotoProcessing();
        $this->assertNull(app(FtpInboxImporter::class)->countInboxImages($inbox, $user));
        $this->assertInboxPathRejected($user, 'symlinked inbox');
        $this->assertNothingImported();
        $this->assertFileExists($source);
        $this->assertSame($this->sampleBytes(), filesize($source));
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

    private function createDanglingInboxSymlink(User $user): string
    {
        $inbox = $this->inboxPath($user);
        $this->assertTrue(rmdir($inbox));

        $target = $this->outsideDirectory.DIRECTORY_SEPARATOR.'missing-target';
        $this->assertTrue(symlink($target, $inbox));
        $this->assertTrue(is_link($inbox));
        $this->assertFalse(is_dir($inbox));

        return $inbox;
    }

    private function createDanglingInboxAncestorSymlink(User $user): string
    {
        $inbox = $this->inboxPath($user);
        $ancestor = dirname($inbox);
        $this->assertTrue(rmdir($inbox));
        $this->assertTrue(rmdir($ancestor));

        $target = $this->outsideDirectory.DIRECTORY_SEPARATOR.'missing-ancestor-target';
        $this->assertTrue(symlink($target, $ancestor));
        $this->assertTrue(is_link($ancestor));
        clearstatcache(true, $inbox);
        $this->assertFalse(is_link($inbox));
        $this->assertFalse(is_dir($inbox));

        return $inbox;
    }

    private function createDanglingAccountHomeSymlink(User $user): string
    {
        $inbox = $this->inboxPath($user);
        $home = Storage::disk('ftp_inbox')->path((string) $user->ftp_slug);
        $this->assertTrue(File::deleteDirectory($home));

        $target = $this->outsideDirectory.DIRECTORY_SEPARATOR.'missing-account-home-target';
        $this->assertTrue(symlink($target, $home));
        $this->assertTrue(is_link($home));
        clearstatcache(true, $inbox);
        $this->assertFalse(is_link($inbox));
        $this->assertFalse(is_dir($inbox));

        return $inbox;
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

    private function assertInboxPathRejected(User $user, string $reason): void
    {
        Log::spy();

        $result = app(FtpInboxImporter::class)->importBatch($user);

        $this->assertSame(FtpImportStatus::UnreadableInbox, $result->status);
        $this->assertSame('Der FTP-Posteingang konnte nicht gelesen werden.', $result->error);
        $this->assertSame(0, $result->imported);

        Log::shouldHaveReceived('error')->once()->withArgs(
            static fn (string $message, array $context): bool => str_contains($message, 'Posteingang')
                && ($context['user_id'] ?? null) === $user->id
                && ($context['reason'] ?? null) === $reason,
        );
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
