<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\FtpInboxDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * P1-M24: the per-user inbox directories have to exist before a camera can
 * upload. SFTPGo does not create them, so the portal does.
 */
class ProvisionFtpFoldersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A real temp directory: the command calls path()/chmod on it, which a
        // fake disk would not support.
        $this->root = sys_get_temp_dir().'/ftp-folders-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0755, true);
        config(['filesystems.disks.ftp_inbox.root' => $this->root]);
    }

    private string $root;

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            ) as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->root);
        }

        parent::tearDown();
    }

    public function test_it_creates_a_directory_for_each_valid_slug(): void
    {
        Storage::disk('ftp_inbox');
        User::factory()->create(['ftp_slug' => 'alpen']);
        User::factory()->create(['ftp_slug' => 'nordsee']);

        $this->artisan('ftp:provision-folders')->assertExitCode(0);

        $this->assertDirectoryExists($this->root.'/alpen');
        $this->assertDirectoryExists($this->root.'/nordsee');
    }

    public function test_it_is_idempotent(): void
    {
        User::factory()->create(['ftp_slug' => 'alpen']);

        $this->artisan('ftp:provision-folders')->assertExitCode(0);
        $this->artisan('ftp:provision-folders')->assertExitCode(0);

        $this->assertDirectoryExists($this->root.'/alpen');
    }

    public function test_it_skips_a_slug_that_violates_the_format_rule(): void
    {
        // A pre-M21 value: the reset flow (P1-M34) will fix it. Creating a
        // directory for it would be wrong, because SFTPGo would refuse the
        // account name anyway.
        User::factory()->create(['ftp_slug' => 'j.doe']);

        $this->artisan('ftp:provision-folders')->assertExitCode(0);

        $this->assertDirectoryDoesNotExist($this->root.'/j.doe');
    }

    public function test_it_ignores_users_without_a_slug(): void
    {
        User::factory()->create(['ftp_slug' => null]);

        $this->artisan('ftp:provision-folders')->assertExitCode(0);
    }

    public function test_dry_run_writes_nothing(): void
    {
        User::factory()->create(['ftp_slug' => 'alpen']);

        $this->artisan('ftp:provision-folders --dry-run')->assertExitCode(0);

        $this->assertDirectoryDoesNotExist($this->root.'/alpen');
    }

    public function test_it_fails_closed_on_a_relative_storage_path(): void
    {
        config(['filesystems.disks.ftp_inbox.root' => 'ftp']);
        User::factory()->create(['ftp_slug' => 'alpen']);

        $this->artisan('ftp:provision-folders')->assertExitCode(1);
        $this->assertDirectoryDoesNotExist('ftp/alpen');
    }

    public function test_fix_permissions_sets_the_setgid_bit(): void
    {
        User::factory()->create(['ftp_slug' => 'alpen']);

        $this->artisan('ftp:provision-folders --fix-permissions')->assertExitCode(0);

        clearstatcache(true, $this->root.'/alpen');
        $perms = fileperms($this->root.'/alpen');
        $this->assertNotFalse($perms);
        // setgid is bit 02000; without it an upload lands in the wrong group
        // and the importing backend cannot read it.
        $this->assertSame(02000, $perms & 02000, 'the setgid bit is what makes new uploads group-readable');
        // The repair target is the same constant the slug write path uses, so
        // the repair cannot widen a directory either path just created (D-2).
        $this->assertSame(FtpInboxDirectory::MODE, $perms & 07777);
    }

    /**
     * The regression the mode change exists for: `2777` was the value the
     * previous constant produced, and a repair run must **narrow** it, not keep
     * it or widen anything. Asserted as an end-to-end file-mode fact rather than
     * against the constant alone, because the previous defect was precisely that
     * the constant and the repair command disagreed.
     */
    public function test_fix_permissions_narrows_a_world_writable_directory(): void
    {
        User::factory()->create(['ftp_slug' => 'alpen']);

        // The state the previous constant left on the deploy host.
        mkdir($this->root.'/alpen', 0700);
        chmod($this->root.'/alpen', 02777);

        $this->artisan('ftp:provision-folders --fix-permissions')->assertExitCode(0);

        clearstatcache(true, $this->root.'/alpen');
        $perms = fileperms($this->root.'/alpen');
        $this->assertNotFalse($perms);
        $this->assertSame(
            FtpInboxDirectory::MODE,
            $perms & 07777,
            'the repair run must bring an existing 2777 directory to the constant',
        );
        $this->assertSame(0, $perms & 0002, 'the repair run must remove world-write');
    }
}
