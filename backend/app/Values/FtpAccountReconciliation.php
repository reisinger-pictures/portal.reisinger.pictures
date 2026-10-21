<?php

namespace App\Values;

/**
 * The outcome of one account reconciliation (P1-M30).
 *
 * The reconciliation answers one question — "does an SFTPGo account exist for
 * this slug, and is it usable?" — and the answer is the only thing the caller
 * needs: `FtpCredentialService::reconcileAccount()` writes the state, the
 * command counts the outcomes. Three fields carry it:
 *
 * - `status` — the portal status **after** the run (`active`, `revoked`,
 *   `pending`, or the status that was left untouched). It is the state a
 *   photographer would see in the inbox.
 * - `outcome` — what the reconciliation found, which is *not* the same question
 *   as the status: a `revoked` account that exists again is `divergent`, not
 *   `active`, because the reconciliation refuses to undo a deliberate
 *   revocation (§7.16).
 * - `reason` — what SFTPGo said, as a stable machine-readable token. The
 *   command prints it next to the slug, so an operator can see *why* a row
 *   changed without reading a sentence.
 *
 * The distinction between `outcome` and `status` exists because the two
 * questions have different answers: "the account is usable" (`active`) is a
 * fact about SFTPGo, "the portal state is now `revoked`" is a fact about the
 * database. Counting them separately is what lets the command say "2 accounts
 * are active, 1 was withdrawn, 1 diverged" instead of a single blurred number.
 */
final class FtpAccountReconciliation
{
    /** SFTPGo has the account and it is enabled — the portal state is `active`. */
    public const OUTCOME_ACTIVE = 'active';

    /** The account is gone or disabled — the access was withdrawn. */
    public const OUTCOME_WITHDRAWN = 'withdrawn';

    /** Nothing had to be corrected (the state already matched, or must not move). */
    public const OUTCOME_UNCHANGED = 'unchanged';

    /**
     * A revoked account exists in SFTPGo again — a manual intervention that the
     * reconciliation reports instead of undoing.
     */
    public const OUTCOME_DIVERGENT = 'divergent';

    /** SFTPGo has the account and it is enabled. */
    public const REASON_ACCOUNT_FOUND = 'account_found';

    /** SFTPGo does not know the account (404). */
    public const REASON_ACCOUNT_MISSING = 'account_missing';

    /** SFTPGo has the account, but it is disabled — the access was withdrawn. */
    public const REASON_ACCOUNT_DISABLED = 'account_disabled';

    /**
     * The portal revoked the account, but SFTPGo has it again. The status
     * stays `revoked`; the operator has to decide what to do.
     */
    public const REASON_REVOKED_ACCOUNT_FOUND = 'revoked_account_found';

    private function __construct(
        public readonly string $status,
        public readonly string $outcome,
        public readonly string $reason,
        public readonly bool $changed,
    ) {}

    /** The account is usable; the portal state is `active`. */
    public static function active(string $reason, bool $changed): self
    {
        return new self('active', self::OUTCOME_ACTIVE, $reason, $changed);
    }

    /**
     * The access was withdrawn. The status is `revoked` when the portal
     * claimed an active account, and `pending` when the portal only had an
     * unknown (`error`) state — an account that does not exist is not a
     * revocation, it is a state in which a credential may be requested again.
     */
    public static function withdrawn(string $status, string $reason, bool $changed): self
    {
        return new self($status, self::OUTCOME_WITHDRAWN, $reason, $changed);
    }

    /** The state was already correct, or must not be touched. */
    public static function unchanged(string $status, string $reason): self
    {
        return new self($status, self::OUTCOME_UNCHANGED, $reason, false);
    }

    /**
     * The portal and the service disagree in the one direction the
     * reconciliation does not resolve on its own: a revoked account that
     * exists again. The status stays `revoked` — the revocation was a
     * deliberate decision, and a cache correction is not the place to undo it.
     */
    public static function divergent(string $status, string $reason): self
    {
        return new self($status, self::OUTCOME_DIVERGENT, $reason, false);
    }
}
