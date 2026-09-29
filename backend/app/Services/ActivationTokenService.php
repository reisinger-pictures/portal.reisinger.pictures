<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Issues the one-time token of `password_reset_tokens` — the link that lets a
 * person without a password set one (registration, admin-created account,
 * portal account from a model registration).
 *
 * ## Why this is a claim and not an upsert
 *
 * Every issuance used to be `updateOrInsert(['email' => …], ['token' => …])`,
 * in three places. That makes the **last** issuance win and silently invalidates
 * the link an earlier one already handed out: the person clicks the mail from
 * the first request, `Hash::check()` compares against the second token's hash
 * and answers "ungültig oder abgelaufen" — for a link that was valid a minute
 * ago. This is the only duplicate class in the mail paths whose consequence is a
 * *destroyed user action* (D-17, AGENTS.md §14).
 *
 * The rule this class implements instead: **a still valid token is never
 * replaced.** `issue()` therefore returns `null` when one is already live, and
 * the caller must then not send a mail — the honest answer is that the act is
 * already done, which is also why the response stays a success.
 *
 * ## Why the claim is atomic
 *
 * A read-then-write ("does a row exist?") would reopen the very race the claim
 * closes, so both branches are single statements whose affected-row count is
 * the claim:
 *
 * - **No row:** `insertOrIgnore`. `password_reset_tokens.email` is the primary
 *   key (V001), so at most one concurrent caller can insert and get `1`.
 * - **Row exists:** a conditional `UPDATE` restricted to rows that are expired
 *   (`created_at` NULL or older than the broker lifetime). Only the caller whose
 *   `UPDATE` matches one row took the token over; a concurrent takeover returns
 *   `0` and gets `null`.
 *
 * ## The price, stated plainly
 *
 * Skipping the mail means the person must find the *earlier* mail. That mail is
 * still valid and still works — `AuthController::resetPassword()` resolves the
 * account by email at click time — but it can expire while it waits. The window
 * is the broker lifetime (`config('auth.passwords.users.expire')`, 60 minutes),
 * and the alternative was a link that dies the moment a second click arrives.
 *
 * Deliberately **not** done (D-17): a blanket `ShouldBeUnique` over the mailable.
 * It would deduplicate *sends* and say nothing about the token row, which is
 * the state that has to be consistent.
 */
class ActivationTokenService
{
    /**
     * Claims the activation token slot for one email address.
     *
     * @return string|null the plaintext token when this call won the claim and
     *                     the caller must mail it, or `null` when a still valid
     *                     token already exists — in which case the caller must
     *                     not overwrite it and must not send a second mail
     */
    public function issue(string $email): ?string
    {
        $token = Str::random(64);
        $issuedAt = Carbon::now();

        $inserted = DB::table('password_reset_tokens')->insertOrIgnore([
            'email' => $email,
            'token' => Hash::make($token),
            'created_at' => $issuedAt,
        ]);

        if ($inserted === 1) {
            return $token;
        }

        $expiredBefore = Carbon::now()->subMinutes($this->lifetimeMinutes());

        $takenOver = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->where(function ($query) use ($expiredBefore): void {
                // A NULL `created_at` cannot be proven fresh, so it counts as
                // expired — otherwise such a row would be unclaimable forever.
                $query->whereNull('created_at')->orWhere('created_at', '<=', $expiredBefore);
            })
            ->update([
                'token' => Hash::make($token),
                'created_at' => $issuedAt,
            ]);

        return $takenOver === 1 ? $token : null;
    }

    /**
     * The lifetime of an activation link, from the same config the reset
     * endpoint validates against (`AuthController::resetPassword()` compares
     * `created_at` against it).
     *
     * The two comparisons differ at the boundary, and the difference is
     * deliberate and load-bearing rather than a rounding accident: the claim
     * takes over at `created_at <= now - lifetime` (this `where('created_at',
     * '<=', $expiredBefore)`), while `resetPassword()` calls a token expired
     * only at `created_at < now - lifetime` (its `Carbon::parse(...)->lt(...)`).
     * `created_at` is stored to the second, so the two agree on every
     * timestamp except the boundary second itself — a sliver in which
     * `issue()` may replace a token the reset endpoint would still have
     * accepted. **"Still valid" is therefore two statements, not one:**
     * a `null` return means *a token the claim treats as live exists*, not
     * *a token the reset endpoint would honour*.
     */
    public function lifetimeMinutes(): int
    {
        return (int) config('auth.passwords.users.expire', 60);
    }
}
