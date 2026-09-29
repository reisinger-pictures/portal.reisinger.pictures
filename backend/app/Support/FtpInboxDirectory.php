<?php

namespace App\Support;

use App\Exceptions\FtpCredentialException;

/**
 * The per-slug FTP inbox directory (`ftp/<ftp_slug>`) on disk.
 *
 * SFTPGo does not create it: its own documentation is explicit — "you have to
 * create the folder on disk yourself". Per owner decision D-2 (AGENTS.md §14)
 * the software does it, at the moment a slug is set, and a slug is never stored
 * without its directory. A slug whose folder is missing is not visible as an
 * error from the portal: it looks like an empty inbox.
 *
 * This class is the single place that knows the path, the mode and the failure
 * shape, so the slug write path and the `ftp:provision-folders` repair path
 * cannot disagree about them.
 *
 * ## Ownership without root
 *
 * The web process is not root (deployment: backend `user: "1000:82"`, sftpgo
 * `user: "1002:82"` per D-1) and it must not `chown` a foreign target — a
 * `chown -R` over the website tree is explicitly forbidden (feature doc 7.9,
 * incident 2026-09-26). Neither is needed here:
 *
 * - The **group** is inherited from the parent `ftp/` because that directory
 *   carries the **setgid** bit, and the new directory inherits setgid with it.
 *   So uploads SFTPGo writes later also get `webgroup`, and the importing
 *   backend can read them. The **group write** bit in `MODE` is the other half:
 *   the uploading and the importing process are both in `webgroup` and neither
 *   is the directory's owner, so it is group write that lets them create and
 *   `unlink` inside the directory. Setgid plus group write is the whole
 *   mechanism — it makes the pipeline work with no `chown` at all, and
 *   world-writable is not part of it.
 * - The **owner** of the new directory is the creating process's uid, set by the
 *   kernel rather than by us. That is exactly why the deployment has to run the
 *   web process as the tree's uid (D-1: 1002). If it does not, the group is
 *   still right and the mode still allows access; only the owner differs, and no
 *   code here can repair that without the privilege it deliberately does not
 *   assume.
 */
final class FtpInboxDirectory
{
    /**
     * `2775` = rwxrwsr-x: owner `rwx`, group `rws`, other `r-x`.
     *
     * The setgid bit (`02000`) is the load-bearing part: without it an upload
     * lands in the creating process's own group instead of `webgroup`, and the
     * importing backend loses access to the file. The group write bit is the
     * second half of the same argument: the uploading process and the importing
     * process are both in `webgroup` and neither is the directory's owner (the
     * deployment runs them under one group, see the `2775+setgid` convention in
     * `deployment/`), and neither may `chown`. Group write is what lets them
     * create and `unlink` inside the directory; setgid keeps that true for
     * everything they put in it.
     *
     * World-writable is deliberately **not** set. `2777` was the previous value
     * and bought nothing here: every process that needs to write is either the
     * owner or in the group, so `other` gets no more than read and traverse.
     * There is no legitimate reason for a third party to create or remove
     * uploads in this directory.
     *
     * One constant: the slug write path and `ftp:provision-folders
     * --fix-permissions` both read it, so a repair run cannot silently downgrade
     * a directory the write path just created.
     */
    public const MODE = 02775;

    /**
     * The absolute host path of the inbox root.
     *
     * Throws when the configured root is not absolute: a relative root would
     * make the directory land wherever the process's working directory happens
     * to be, which is the opposite of a well-defined home directory.
     */
    public static function root(): string
    {
        $root = config('filesystems.disks.ftp_inbox.root');

        if (! is_string($root) || $root === '' || ! str_starts_with($root, '/')) {
            throw FtpCredentialException::unusableInboxPath();
        }

        return rtrim($root, '/');
    }

    /** The absolute path of one slug's inbox directory. */
    public static function pathFor(string $slug): string
    {
        return self::root().'/'.$slug;
    }

    /**
     * Ensures `ftp/<slug>` exists and returns its absolute path.
     *
     * Idempotent. An existing directory is left untouched — its mode is the
     * operator's business, and `ftp:provision-folders --fix-permissions` is the
     * place that corrects it, not a profile save.
     *
     * Creation is deliberately **not** recursive. The parent is the `ftp/` bind
     * mount the deployment provides; if it is missing, that is a deployment fault
     * and has to surface as "No such file or directory" instead of a fresh
     * directory tree quietly invented outside the mount.
     *
     * The mode is applied with `chmod()` as well as `mkdir()` because the umask
     * masks the bits passed to `mkdir()`: without the explicit call the setgid
     * bit could silently be missing, and with it the group inheritance.
     */
    public static function ensure(string $slug): string
    {
        $path = self::pathFor($slug);

        if (is_dir($path)) {
            return $path;
        }

        // The reason is captured from the warning itself: `error_get_last()` is
        // not populated when a custom error handler (Laravel installs one) is
        // active, so this is what keeps the operating system's own wording
        // ("Permission denied", "No such file or directory", "Read-only file
        // system") in the message. Returning `true` swallows the warning instead
        // of letting the framework turn it into an exception.
        $reason = 'unbekannter Fehler';
        set_error_handler(function (int $severity, string $message) use (&$reason): bool {
            $reason = $message;

            return true;
        });

        try {
            $created = mkdir($path, self::MODE);
        } finally {
            restore_error_handler();
        }

        if (! $created) {
            throw FtpCredentialException::couldNotCreateInboxDirectory($path, $reason);
        }

        // The umask masks the bits passed to `mkdir()`, so the mode is applied
        // explicitly — without it the setgid bit could silently be missing, and
        // with it the group inheritance.
        @chmod($path, self::MODE);

        return $path;
    }
}
