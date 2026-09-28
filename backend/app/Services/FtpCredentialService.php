<?php

namespace App\Services;

use App\Exceptions\FtpAuditWriteException;
use App\Exceptions\FtpCredentialException;
use App\Models\FtpPasswordReset;
use App\Models\User;
use App\Support\FtpInboxDirectory;
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
 * Password shape `/^[a-km-zA-HJ-NP-Z2-9]{10,12}$/`: `[a-zA-Z0-9]` minus the
 * five ambiguous symbols (`0`, `O`, `1`, `l`, `I`) — 57 symbols. Three
 * decisions are folded into that one line, and all three are deliberate
 * rather than incidental:
 *
 * - **No special characters.** Unchanged from the original rule: a camera cannot
 *   type `-`, `&` or `!` into its configuration screen, so the alphabet is
 *   strictly alphanumeric.
 * - **Mixed case is allowed, which reverses the original rule** (owner decision,
 *   2026-09-27). The original rationale — mixed case would turn a keyboard
 *   layout into a support case — was a considered trade-off, recorded here
 *   because it is being *overridden*, not because it was an oversight. It
 *   assumes a human retyping the password. The password is displayed in a
 *   monospace face, so characters are read one at a time and the case is
 *   legible; nobody has to retype it from memory. **Do not restore the
 *   lowercase-only alphabet from that sentence** — the mixed-case shape was
 *   requested, the entropy cost below is the price that was accepted for it,
 *   and the ambiguous five are excluded as the second half of the same deal.
 * - **The ambiguous five are removed, not merely made visible.** On a camera
 *   screen a misread `0`/`O` or `1`/`l` is not a mistake the photographer can
 *   notice, it is a failed authentication and a regeneration. Rendering them
 *   readably is not enough, so they do not enter the alphabet at all.
 *
 * The length range is 10–12 characters, drawn per call, so a leaked password
 * cannot be narrowed down by length. Entropy is `length × log2(57)`, and
 * `log2(57) ≈ 5.833` bits per character: **10 characters are ~58.3 bits, 12 are
 * ~70.0**. The previous shape was 36 symbols at 16–24 characters, `log2(36) ≈
 * 5.170`, i.e. **~82.7 to ~124.1 bits**.
 *
 * So the new range does *not* sit inside the old one — it sits entirely below
 * it, the floor by ~24 bits and the ceiling by ~54. Stated plainly, because a
 * docblock that hides this is worse than no docblock: **the minimum strength of
 * a camera password went down**, and that is a real reduction in brute-force
 * space, not a rounding difference. It was accepted knowingly (owner decision,
 * 2026-09-27) for two reasons. An online attacker gains nothing from the
 * alphabet: the hourly quota below bounds how fast one account can rotate its
 * **own** credential, and this class never stores the password, so there is no
 * offline target here at all — an offline attack would have to target SFTPGo's
 * own credential store, a separate system with its own protection that this
 * class neither writes nor reads. What the reduction buys is the point of the
 * change: 16–24 characters is "extrem schwer auf der Kamera einzugeben", and a
 * password that cannot be entered is a camera that never uploads.
 *
 * The string is 37 % shorter at the bottom of the old range (16 → 10) and 50 %
 * at the top (24 → 12). That shortening is the *compensation* for mixed case,
 * not a side effect of the alphabet.
 *
 * The reset is the third leg of that flow and the only recovery path (P1-M33).
 * Because the old password is gone for good, an unthrottled reset endpoint lets
 * one account rotate the credential of a working camera as often as it likes —
 * so `resetAndShow()` enforces a per-account quota and writes an audit row for
 * every attempt it lets through. Neither half is optional: the quota bounds how
 * much disruption one account can cause, the audit row is what makes a reset
 * attributable afterwards.
 */
class FtpCredentialService
{
    public const PASSWORD_PATTERN = '/^[a-km-zA-HJ-NP-Z2-9]{10,12}$/';

    public const PASSWORD_MIN_LENGTH = 10;

    public const PASSWORD_MAX_LENGTH = 12;

    /**
     * The `ftp_account_status` values the revocation path has to tell apart
     * (§7.16). The column is a string with a closed set; only the two states
     * that decide whether an account exists are named here. `active` is the
     * value the invariant is built on ("an account exists iff the status is
     * active"), `revoked` the state written when it stops existing.
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    /**
     * The two states that are *not* a usable account and are not a deliberate
     * revocation, named for the reset path (P1-M58). They sit here rather than
     * as literals in `resetAndShow()` because the branch that reads them decides
     * whether a photographer gets a credential at all, and a literal would let a
     * typo degrade into "always reset" without any type to catch it.
     *
     * `pending` is the column default and means "never provisioned" — which is
     * the state every photographer who never changed their slug is in. `error`
     * means the last provisioning attempt failed, so the real outcome is unknown
     * and overwriting it would be a guess.
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_ERROR = 'error';

    /**
     * Resets allowed per account and per hour (P1-M33).
     *
     * Ten is a deliberate number and deliberately a constant rather than an ops
     * knob: it is a security rule, not a capacity setting, and a value in
     * `config/app.php` could be shipped as 0 — which would silently turn the
     * recovery path off.
     *
     * **Raising it from three to ten weakens the guard, and that is the honest
     * headline** (owner decision, 2026-09-27). Three was measured against a
     * photographer who already knows what they are doing, and it fails the case
     * that actually arrives: someone at a camera, fumbling a dial, mistyping a
     * password they cannot re-read. Every retry re-issues the credential, so a
     * low quota turns a transcription problem into an hour-long lockout with no
     * way out. The trade was accepted deliberately, not by accident — the
     * compensation is the audit row below, and the two have to be read
     * together: a wider quota means a longer trail, not a quieter one.
     *
     * What the quota bounds is worth stating precisely, because the obvious
     * phrasing is wrong. A reset **replaces** the password, so at any moment
     * there is exactly one valid credential per account and no reset ever mints
     * a second one. The limit is therefore *not* a cap on how many credentials
     * exist. It bounds two real things:
     *
     * 1. **Disruption of a working camera.** Every rotation invalidates the
     *    password the camera is holding, so an unthrottled endpoint lets one
     *    account knock an uploader offline as often as it clicks.
     * 2. **Load on the SFTPGo API.** Each rotation is a read-modify-write
     *    (`findUser()` then the update) against a live service, so the endpoint
     *    would otherwise be an amplifier for unauthenticated-ish request volume.
     *
     * The compensating control is the audit row, and it does not scale with the
     * number: `recordResetAttempt()` writes one row for every attempt that got
     * past the quota, successful or not. Raising the limit from three to ten
     * lengthens the trail and weakens nothing about attribution — a burst of ten
     * resets is ten attributable rows, not one buried row.
     */
    public const RESET_LIMIT_PER_HOUR = 10;

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
     * string factory produces. `Str::random()` yields base64 with `/`, `+` and
     * `=` stripped, so it hands over 62 alphanumeric symbols; this class drops
     * the five a camera screen renders ambiguously (`0`, `O`, `1`, `l`, `I`) and
     * keeps 57. Its character class is the exact complement of the one in
     * PASSWORD_PATTERN, deliberately: filter and verification are two halves of
     * one contract, and a class written in two shapes has to be read twice to be
     * checked.
     *
     * The mixed case the factory produces is **kept** (see the class docblock) —
     * lowercasing here would quietly undo the owner decision, which is why there
     * is no `Str::lower()` left in the generation path.
     */
    private const NON_CAMERA_CHARACTERS = '/[^a-km-zA-HJ-NP-Z2-9]/';

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
     * Ends the camera account when the photographer role goes away (§7.16).
     *
     * The invariant this restores: an SFTPGo account exists **iff**
     * `users.ftp_account_status` is `active`. Losing the role therefore means
     * the account has to go and the status has to stop claiming it is there.
     * `ftp_provisioned_at` deliberately stays — it records that provisioning
     * happened; `ftp_revoked_at` records when it ended.
     *
     * Failure and order, same as the slug change (P1-M34): the external delete
     * comes first, so if SFTPGo is unreachable the exception propagates and
     * **nothing** is written. The caller aborts the role change or the delete.
     * A workforce without the photographer role that cannot reach SFTPGo is a
     * worse state than a refused role change, because the credential would stay
     * live on a directory that still holds photographs.
     *
     * Idempotent by construction:
     * - an already-revoked account returns without touching the service or the
     *   timestamp, so a repeated call cannot move `ftp_revoked_at`;
     * - a never-provisioned account (`pending`/`error`) has no account to
     *   delete — there is no external call and no failure — and is marked
     *   revoked directly. Nothing else would be truthful: the role that could
     *   request credentials is gone.
     *
     * Deliberately not swallowed: a `not_found` from SFTPGo. Only `active`
     * triggers the delete, and by the invariant that state means the account
     * exists; a 404 is a real divergence (P1-M34) and must surface rather than
     * be hidden behind an idempotent-looking revoke.
     */
    public function revoke(User $user): void
    {
        if ($user->ftp_account_status === self::STATUS_REVOKED) {
            return;
        }

        if ($user->ftp_account_status === self::STATUS_ACTIVE) {
            $this->deleteUser($this->accountNameFor($user));
        }

        $user->forceFill([
            'ftp_account_status' => self::STATUS_REVOKED,
            'ftp_revoked_at' => now(),
        ])->save();
    }

    /**
     * A camera-usable password. Verified by construction, not by hope: the
     * result is returned only after it matched the pattern.
     */
    public function generateCameraPassword(): string
    {
        $length = random_int(self::PASSWORD_MIN_LENGTH, self::PASSWORD_MAX_LENGTH);

        for ($attempt = 0; $attempt < self::GENERATION_ATTEMPTS; $attempt++) {
            // 64 raw characters leave ample margin after filtering (57 of the
            // 62 alphanumeric symbols `Str::random()` produces survive), so the
            // length check below practically never needs a second attempt.
            $candidate = (string) preg_replace(
                self::NON_CAMERA_CHARACTERS,
                '',
                Str::random(64),
            );
            $candidate = substr($candidate, 0, $length);

            if (strlen($candidate) === $length && preg_match(self::PASSWORD_PATTERN, $candidate) === 1) {
                return $candidate;
            }
        }

        throw FtpCredentialException::generationFailed();
    }

    /**
     * Provisions the account, records that it exists, and returns the password
     * **once**.
     *
     * Nothing *secret* is written: no password column, no cache entry, no file.
     * The two columns that are written are the opposite of a secret — they are
     * what makes `ftp_account_status` true. A SFTPGo account exists exactly when
     * the column says `active` (19-ftp 7.16), so provisioning that left the
     * column alone would let the portal report `pending` or `revoked` while the
     * service holds a live credential, and the inbox would offer the wrong next
     * step. V041 created the column; this is the write that keeps it honest.
     *
     * Re-entry is the same transition, not a special case: a photographer who
     * was revoked and gets the role back provisions again, the column returns to
     * `active`, and `ftp_revoked_at` stays as the record of the past revocation.
     *
     * **The inbox directory exists before the account does (D-2).** SFTPGo does
     * not create `ftp/<slug>` ("you have to create the folder on disk
     * yourself"), so `ensure()` runs *before* `provisionUser()`. The order is
     * the guarantee, not a detail: the SFTPGo Admin API has no rollback for a
     * created user, so an account created first and then failed on `mkdir()`
     * would be exactly the state D-2 exists to make unrepresentable — a live
     * account pointing at a folder that is not there, which from the portal
     * looks like an empty inbox rather than an error. The reverse residue is
     * harmless: a directory without an account is an empty folder that the next
     * call reuses.
     *
     * `forceFill()` because neither column is mass-assignable — they are set by
     * this service and by `revoke()`, never from request input.
     */
    public function provisionAndShow(User $user): string
    {
        $username = $this->accountNameFor($user);
        $password = $this->generateCameraPassword();

        // Fails closed: on a filesystem error this throws before any request
        // leaves the process, so the status stays `pending` and no account is
        // created (D-2). The same guarantee the slug write path gives.
        FtpInboxDirectory::ensure($username);

        $this->sftpGo->provisionUser($username, $password, $this->homeDirectoryFor($username));

        $user->forceFill([
            'ftp_account_status' => self::STATUS_ACTIVE,
            'ftp_provisioned_at' => now(),
        ])->save();

        return $password;
    }

    /**
     * Issues the calling photographer a camera password, **once** (P1-M33,
     * extended for first-time provisioning in P1-M58).
     *
     * The one action the inbox offers is "Neues Kamera-Passwort", and it has to
     * work for all three account states the photographer can be in. Which one it
     * is decides what this method does, and the table is closed — a state that
     * is not named here cannot get a credential:
     *
     * | `ftp_account_status` | what happens |
     * |---|---|
     * | `pending` | **provision** (`provisionAndShow()`), password shown once |
     * | `active`  | **rotate** the password of the existing account |
     * | `revoked` | refuse, nothing reaches SFTPGo |
     * | `error`   | refuse, nothing reaches SFTPGo |
     *
     * `pending` has to provision, not rotate: `SftpGoClient::resetPassword()` is
     * a read-modify-write that starts with `findUser()`, so it answers 404 for an
     * account that was never created. Before this branch existed, a photographer
     * who never changed their slug had no way to get an account *at all* — the
     * status said `pending`, the UI said "request your credentials first", and
     * the only endpoint 404'd.
     *
     * `revoked` must not be revocable again by a click, and that is the reason
     * the reset is not simply "provision whatever is not active". The revocation
     * was a deliberate decision (a lost role, §7.16); re-creating the account
     * would hand a withdrawn photographer a working SFTPGo access again with one
     * click, and the whole guard would be worthless. `error` is refused for the
     * same reason in the other direction: the last provisioning attempt failed, so
     * the real state is unknown, and a reset must not overwrite it with a guess.
     *
     * Same show-once contract as `provisionAndShow()` for both successful paths:
     * the password goes to SFTPGo, comes back to the caller, and is nowhere else
     * — not in a column, not in a cache entry, not in the log, not in the audit
     * row.
     *
     * Three guards, in this order:
     *
     * 1. **Quota, before anything happens.** The check runs first so a rejected
     *    call generates no password, contacts no service and writes no audit
     *    row: it is not a reset attempt, it is the refusal of one. Note that a
     *    rejected call still increments the counter, so hammering the button
     *    cannot push the window forward indefinitely — the same deliberate
     *    choice `CheckoutRiskService` makes. It also covers the `pending` branch,
     *    which is why first-time provisioning cannot be used to mint credentials
     *    around the quota.
     * 2. **State, inside the `try` and before anything is sent.** Placement is
     *    the point, not a detail: the `catch` below audits every refusal that
     *    comes from there as a *failed attempt*, and a click on "new password" for
     *    a `revoked` account is exactly such an attempt. Checking before the
     *    `try` would refuse the same states and leave the interesting click
     *    unaudited — the hole this trail exists to close.
     * 3. **Audit row, for every attempt that got past the quota** — successful or
     *    not. A failed reset is the more interesting row: it is what a repeated
     *    failure against a live service looks like from the outside. The row is
     *    written from a method that never receives the password, so there is no
     *    path by which the secret could reach the table.
     *
     * The `pending` branch is the one path that writes no audit row, and that is
     * deliberate rather than an oversight: an account *creation* is not a reset,
     * and this table is the reset trail. `provisionAndShow()` is also
     * fail-closed on its own — the inbox directory is ensured *before* the
     * account is requested (D-2), and it writes `active` + `ftp_provisioned_at`
     * only after SFTPGo has accepted the account, so neither a filesystem
     * failure nor an unreachable service can leave the status claiming an
     * account that is not usable.
     *
     * A failure of the audit write itself is *not* swallowed. On the rotation
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

        if ($user->ftp_account_status === self::STATUS_PENDING) {
            return $this->provisionAndShow($user);
        }

        try {
            $this->assertPasswordMayBeReissued($user);
            $username = $this->accountNameFor($user);
            $password = $this->generateCameraPassword();
            $this->sftpGo->resetPassword($username, $password);
        } catch (Throwable $exception) {
            // Every refusal from here on is a failed reset, including the
            // portal-side preconditions (an account state that may not be
            // re-issued, no account name, a name that cannot be an account).
            // Those never reach SFTPGo, but the attempt is exactly what an audit
            // trail has to show.
            $this->recordResetAttempt($user, $ip, false);

            throw $exception;
        }

        $this->recordResetAttempt($user, $ip, true);

        return $password;
    }

    /**
     * Only an `active` account has a password that may be replaced.
     *
     * Written as a positive allow rather than as a list of refusals on purpose:
     * a state that is added to the enum by a later migration is then refused by
     * default instead of silently inheriting the rotation. The column is
     * `NOT NULL` with default `pending` since V041, so there is no "unknown"
     * value to accommodate — and if there ever were, refusing is the right answer
     * anyway.
     */
    private function assertPasswordMayBeReissued(User $user): void
    {
        if ($user->ftp_account_status === self::STATUS_ACTIVE) {
            return;
        }

        throw FtpCredentialException::notResettable((string) $user->ftp_account_status);
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
