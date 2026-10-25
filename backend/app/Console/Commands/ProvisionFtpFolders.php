<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\FtpInboxDirectory;
use App\Support\FtpSlug;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Creates the per-user FTP inbox directories on disk (P1-M24).
 *
 * SFTPGo does not create them: "you have to create the folder on disk
 * yourself". This command does it, so no host-side manual step is needed
 * before a photographer can upload.
 *
 * Ownership and the setgid bit are set with the group only — the directory
 * owner stays whoever created it. A `chown` of the user would be wrong: the
 * creating process is the web process, the host tree belongs to 1002:82, and
 * the group (with setgid) is what keeps new files readable by the importing
 * process. Since D-1 (owner decision 2026-10-25) both processes run as the
 * tree's uid, so the owner the kernel assigns is the right one as well.
 */
class ProvisionFtpFolders extends Command
{
    protected $signature = 'ftp:provision-folders
                            {--dry-run : Report what would change without touching the filesystem}
                            {--fix-permissions : Also correct owner/group/mode on existing directories}';

    protected $description = 'Create the per-user FTP inbox directories (ftp/<ftp_slug>) with the ownership the import needs';

    public function handle(): int
    {
        $root = config('filesystems.disks.ftp_inbox.root');

        if (! is_string($root) || $root === '' || ! str_starts_with($root, '/')) {
            $this->error('FTP_STORAGE_PATH must be an absolute path, got: '.var_export($root, true));
            $this->error('The provisioning path is derived from the ftp_inbox disk root (feature doc 7.2).');

            return self::FAILURE;
        }

        $disk = Storage::disk('ftp_inbox');
        $dryRun = (bool) $this->option('dry-run');
        $fixPermissions = (bool) $this->option('fix-permissions');

        $created = 0;
        $skipped = 0;
        $failed = 0;

        $users = User::query()
            ->whereNotNull('ftp_slug')
            ->where('ftp_slug', '!=', '')
            ->orderBy('id')
            ->get();

        foreach ($users as $user) {
            $slug = (string) $user->ftp_slug;

            if (! FtpSlug::isValid($slug)) {
                // Not an error: a pre-M21 value or a brand placeholder. The slug
                // reset flow (P1-M34) will provision the correct directory.
                $this->warn("skip user {$user->id}: slug '{$slug}' does not match the format rule");
                $skipped++;

                continue;
            }

            $relative = $slug;

            if ($dryRun) {
                $exists = $disk->exists($relative);
                $this->line($exists ? "  would keep    {$relative}/" : "  would create  {$relative}/");
                $exists ? $skipped++ : $created++;

                continue;
            }

            try {
                if (! $disk->exists($relative)) {
                    $disk->makeDirectory($relative);
                    $this->info("created {$relative}/");
                    $created++;
                } else {
                    $skipped++;
                }

                if ($fixPermissions) {
                    $this->applySetgid($disk, $relative);
                }
            } catch (\Throwable $e) {
                $this->error("failed {$relative}/: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%d created, %d already present, %d skipped, %d failed (of %d users with a slug)',
            $created,
            $skipped,
            $skipped,
            $failed,
            $users->count()
        ));

        if ($failed > 0) {
            $this->error('Some directories could not be created. The camera upload for those users will fail.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->line('Dry run: nothing was written.');
        }

        return self::SUCCESS;
    }

    /**
     * The setgid bit is what makes this work: without it a file created by
     * SFTPGo lands in the directory's own group instead of `webgroup`, and the
     * importing backend loses read access to the upload.
     *
     * The mode is applied, the owner is not. Chowning to another uid inside a
     * container is how the 2026-09-26 ownership incident happened (an
     * entrypoint ran `chown -R` over the mapped website tree).
     */
    private function applySetgid(Filesystem $disk, string $relative): void
    {
        $path = $disk->path($relative);
        $current = @fileperms($path);

        if ($current === false) {
            return;
        }

        // The same mode the slug write path uses (D-2), from one constant: two
        // copies would let this repair run silently downgrade a directory the
        // write path just created.
        $desired = FtpInboxDirectory::MODE;
        if (($current & 07777) !== $desired) {
            @chmod($path, $desired);
            $this->line(sprintf('  mode %o -> %o on %s/', $current & 07777, $desired, $relative));
        }
    }
}
