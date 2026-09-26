<?php

namespace App\Services;

use App\Exceptions\FtpAuditWriteException;
use App\Exceptions\FtpCredentialException;
use App\Models\FtpPasswordReset;
use App\Models\User;
use App\Support\FtpSlug;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

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
 *
 * The reset is the third leg of that flow and the only recovery path (P1-M33).
 * Because the old password is gone for good, an unthrottled reset endpoint is an
 * unlimited, unobserved mint for valid camera credentials — so `resetAndShow()`
 * enforces a per-account quota and writes an audit row for every attempt it
 * lets through. Neither half is optional: the quota bounds how many credentials
 * exist, the audit row is what makes a reset attributable afterwards.
 */
class FtpCredentialService
{
    public const PASSWORD_PATTERN = '/^[a-z0-9]{16,24}$/';

    public const PASSWORD_MIN_LENGTH = 16;

    public const PASSWORD_MAX_LENGTH = 24;

    /**
     * Resets allowed per account and per hour (P1-M33).
     *
     * Three is a deliberate number and deliberately a constant rather than an
     * ops knob: it is a security rule from the task board, not a capacity
     * setting, and a value in `config/app.php` could be shipped as 0 — which
     * would silently turn the recovery path off. A photographer who really lost
     * a password three times in an hour is a support case, not a legitimate
     * third attempt, and the limit costs nothing in normal operation because a
     * reset that succeeds is not repeated.
     */
    public const RESET_LIMIT_PER_HOUR = 3;

    public const RESET_WINDOW_SECONDS = 3600;

    /**
     * Rate-limiter key for one account's reset quota.
     *
     * Keyed by the user id, which is a globally unique UUID — the same key the
     * import lock uses (`FtpController::importLockName`), and for the same
     * reason: the account is user-level, so the user id is exactly the scope
     * the quota has to cover. A slug would be wrong: P1-M34 leaves it open
     * whether `users.ftp_slug` or the SFTPGo store is authoritative, and a slug
     * key would let a rename silently hand the account a fresh quota.
     *
     * Public so a test can address the exact bucket the service counts in,
     * rather than guessing at a key format.
     */
    public static function resetQuotaKey(string $userId): string
    {
        return 'ftp-password-reset:'.$userId;
    }

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
     * Deletes the SFTPGo account for the given slug (P1-M34, slug-change-as-reset).
     *
     * Called before the user row is updated, so the account is already gone when
     * the portal commits the new slug. When SFTPGo is unreachable the caller
     * aborts the transaction — the user row must not be updated if the account
     * could not be deleted.
     */
    public function deleteUser(string $slug): void
    {
        $this->sftpGo->deleteUser($slug);
    }

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
     * Rotates the camera password and returns the new one **once** (P1-M33).
     *
     * Same show-once contract as `provisionAndShow()`: the password goes to
     * SFTPGo, comes back to the caller, and is nowhere else — not in a column,
     * not in a cache entry, not in the log, not in the audit row.
     *
     * Two guards, in this order:
     *
     * 1. **Quota, before anything happens.** The check runs first so a rejected
     *    call generates no password, contacts no service and writes no audit
     *    row: it is not a reset attempt, it is the refusal of one. Note that a
     *    rejected call still increments the counter, so hammering the button
     *    cannot push the window forward indefinitely — the same deliberate
     *    choice `CheckoutRiskService` makes.
     * 2. **Audit row, for every attempt that got past the quota** — successful or
     *    not. A failed reset is the more interesting row: it is what a repeated
     *    failure against a live service looks like from the outside. The row is
     *    written from a method that never receives the password, so there is no
     *    path by which the secret could reach the table.
     *
     * A failure of the audit write itself is *not* swallowed. On the success
     * path that means a broken database can answer 500 after the password was
     * already rotated, so the photographer never sees the new password and has
     * to reset again — deliberately. The alternative, logging and carrying on,
     * turns the audit trail into something that can be skipped exactly when the
     * system is unhealthy, which is when it is worth having.
     *
     * @param  string|null  $ip  Request address for the audit row, or null when
     *                           the caller has no trustworthy one (console, job).
     */
    public function resetAndShow(User $user, ?string $ip = null): string
    {
        $this->assertResetQuotaAvailable($user);

        try {
            $username = $this->accountNameFor($user);
            $password = $this->generateCameraPassword();
            $this->sftpGo->resetPassword($username, $password);
        } catch (Throwable $exception) {
            // Every refusal from here on is a failed reset, including the
            // portal-side preconditions (no account name, a name that cannot be
            // an account). Those never reach SFTPGo, but the attempt is exactly
            // what an audit trail has to show.
            $this->recordResetAttempt($user, $ip, false);

            throw $exception;
        }

        $this->recordResetAttempt($user, $ip, true);

        return $password;
    }

    /**
     * Throws `FtpCredentialException::rate_limited` once the account has used up
     * its hourly quota. `hit()` returns the count *including* this call, so
     * `> RESET_LIMIT_PER_HOUR` is what makes calls 1..RESET_LIMIT_PER_HOUR pass
     * and the next one fail.
     */
    private function assertResetQuotaAvailable(User $user): void
    {
        $key = self::resetQuotaKey((string) $user->getKey());
        $attempts = (int) RateLimiter::hit($key, self::RESET_WINDOW_SECONDS);

        if ($attempts > self::RESET_LIMIT_PER_HOUR) {
            throw FtpCredentialException::rateLimited(
                self::RESET_LIMIT_PER_HOUR,
                RateLimiter::availableIn($key),
            );
        }
    }

    /**
     * Appends one row to the P1-M33 audit trail. Called for every attempt that
     * passed the quota, so the table is a complete record of the attempts and
     * not a sample.
     */
    private function recordResetAttempt(User $user, ?string $ip, bool $success): void
    {
        $row = FtpPasswordReset::query()->create([
            'user_id' => $user->getKey(),
            'reset_at' => now(),
            'ip' => $ip,
            'success' => $success,
        ]);

        // `create()` returns an unsaved model when a `creating` listener vetoes
        // the insert instead of throwing, so a vetoed write would otherwise be
        // indistinguishable from a successful one — an unaudited rotation, which
        // is the exact hole this trail exists to close. The password has already
        // changed at this point, so the failure has to be loud rather than
        // silently tolerated.
        if (! $row->exists) {
            throw FtpAuditWriteException::forPasswordReset();
        }
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
