<?php

namespace App\Services;

use App\Exceptions\FtpCredentialException;
use App\Models\User;
use App\Support\FtpSlug;
use Illuminate\Support\Str;

/**
 * Camera password flow (P1-M23): generate, hand over, show once, discard.
 *
 * The portal stores **no** FTP password. SFTPGo keeps it, PHP generates it and
 * shows it exactly once; a lost password is replaced via
 * `SftpGoClient::resetPassword()`, never recovered. That is the whole reason
 * for the switch away from pure-ftpd (feature doc 7.3) and it is why there is
 * no `ftp_credentials` table and no FTP-related use of
 * `FILE_ENCRYPTION_KEY`.
 *
 * Password shape `^[a-z0-9]{16,24}$`, because a camera cannot type a special
 * character into its configuration screen and mixed case would turn a keyboard
 * layout into a support case. 16 characters from a 36-symbol alphabet are
 * ~82.7 bits, 24 are ~124 bits — the range is drawn per call, so a leaked
 * password cannot be narrowed down by length.
 */
class FtpCredentialService
{
    public const PASSWORD_PATTERN = '/^[a-z0-9]{16,24}$/';

    public const PASSWORD_MIN_LENGTH = 16;

    public const PASSWORD_MAX_LENGTH = 24;

    /**
     * Everything outside the camera alphabet is removed, whatever the random
     * string factory produces. `Str::random()` is a base64 alphabet with mixed
     * case, so lowercasing and filtering are what turn it into a camera-safe
     * string; the final check against PASSWORD_PATTERN is the guarantee.
     */
    private const NON_CAMERA_CHARACTERS = '/[^a-z0-9]/';

    private const GENERATION_ATTEMPTS = 8;

    public function __construct(
        private readonly SftpGoClient $sftpGo,
    ) {}

    /**
     * A camera-usable password. Verified by construction, not by hope: the
     * result is returned only after it matched the pattern.
     */
    public function generateCameraPassword(): string
    {
        $length = random_int(self::PASSWORD_MIN_LENGTH, self::PASSWORD_MAX_LENGTH);

        for ($attempt = 0; $attempt < self::GENERATION_ATTEMPTS; $attempt++) {
            // 64 raw characters leave ample margin after filtering, so the
            // length check below practically never needs a second attempt.
            $candidate = (string) preg_replace(
                self::NON_CAMERA_CHARACTERS,
                '',
                Str::lower(Str::random(64)),
            );
            $candidate = substr($candidate, 0, $length);

            if (strlen($candidate) === $length && preg_match(self::PASSWORD_PATTERN, $candidate) === 1) {
                return $candidate;
            }
        }

        throw FtpCredentialException::generationFailed();
    }

    /**
     * Provisions the account and returns the password **once**. Nothing is
     * written: no password column, no cache entry, no file. The provisioning
     * status column on `users` is P1-M30 — until it exists this service touches
     * no column at all, which is exactly the "no secret at rest" property.
     */
    public function provisionAndShow(User $user): string
    {
        $username = $this->accountNameFor($user);
        $password = $this->generateCameraPassword();

        $this->sftpGo->provisionUser($username, $password, $this->homeDirectoryFor($username));

        return $password;
    }

    /**
     * The SFTPGo account name for a user. There is no fallback to the primary
     * key: `FtpController` tolerates a missing slug for the inbox path, but an
     * account named after a UUID would be a name nobody can type on a camera.
     *
     * The format rule is `App\Support\FtpSlug::isValid()` (P1-M21) — one
     * definition, shared with the profile form and with SftpGoClient.
     */
    private function accountNameFor(User $user): string
    {
        $slug = $user->ftp_slug;

        if (! is_string($slug) || $slug === '') {
            throw FtpCredentialException::missingAccountName();
        }

        if (! FtpSlug::isValid($slug)) {
            throw FtpCredentialException::unusableAccountName($slug);
        }

        return $slug;
    }

    /**
     * The home directory as the service must see it.
     *
     * It is derived from the `ftp_inbox` disk root, not from a second constant:
     * both containers mount the same host directory at the same in-container
     * path (`/home/webadmin/websites/ftp` -> `/var/www/ftp`, see
     * `deployment/docker-compose.yml`), so the portal and SFTPGo agree on one
     * string. The disk stays local on purpose — no `sftp` Flysystem driver
     * (feature doc 7.8, P1-M25).
     */
    private function homeDirectoryFor(string $username): string
    {
        $root = config('filesystems.disks.ftp_inbox.root');

        if (! is_string($root) || $root === '' || ! str_starts_with($root, '/')) {
            throw FtpCredentialException::unusableInboxPath();
        }

        return rtrim($root, '/').'/'.$username;
    }
}
