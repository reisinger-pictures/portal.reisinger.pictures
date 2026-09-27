<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for FTP/SFTP camera password resets (P1-M33).
 *
 * The show-once flow of feature doc 7.3 makes the reset the *only* recovery
 * path for a lost camera password — there is nothing to restore. It is *not*,
 * however, a way to mint credentials: a reset **replaces** the password, so at
 * any moment there is exactly one valid credential per account and no reset ever
 * creates a second one. What is unobserved without this table is how often an
 * account rotates the credential a working camera is holding, and each rotation
 * is a read-modify-write against the live SFTPGo API. SftpGoClient (P1-M22) and
 * FtpCredentialService (P1-M23) guarantee that a password never reaches a log,
 * and neither of them says *how often* one may be reset. This table is the
 * observation half of the answer; the rate limit is the other half
 * (FtpCredentialService::RESET_LIMIT_PER_HOUR).
 *
 * Schema decision, documented up front as required by backend/AGENTS.md:
 * - `id`             surrogate key, matching the other audit table
 *                    (`contract_audit_logs`, V021).
 * - `user_id`        FK to `users` with ON DELETE CASCADE. An audit row without
 *                    an account would be unattributable, and a row that outlives
 *                    a deleted account would be a dangling reference. The
 *                    account identity is `users.ftp_slug`; the FK to the user
 *                    keeps "which account" answerable through the join.
 * - `ip`             VARCHAR(45), nullable. 45 is the longest textual form of an
 *                    IPv6 address, the same choice `contract_audit_logs` makes.
 *                    Nullable because not every caller has a trustworthy
 *                    address: a console/queue reset has none, and storing a
 *                    placeholder string would be a lie.
 * - `success`        NOT NULL boolean. Both outcomes are recorded: a reset that
 *                    failed against the service is *more* interesting for an
 *                    audit than one that succeeded. A nullable or defaulted
 *                    column would make "unknown" indistinguishable from
 *                    "not attempted".
 * - `reset_at`       NOT NULL, defaults to the current timestamp. Explicit
 *                    rather than Eloquent's `created_at`, because the value is
 *                    the audit fact itself, not a bookkeeping artifact of the
 *                    write. It is a single timestamp column, so both
 *                    `UPDATED_AT` and `CREATED_AT` are null on the model and
 *                    `reset_at` carries no `updated_at` companion.
 *
 * Index: `('user_id', 'reset_at')`. This is the one query the table exists for
 * — "show me this account's resets, newest first" — and the composite order also
 * serves "count this account's resets since X", which is what a human checking
 * whether the per-hour limit is being hit will ask. A lone `user_id` index would
 * be redundant: every index that has to read `reset_at` can use this one.
 *
 * Backfill: none possible and none needed. The table is new and a row only ever
 * means "somebody asked for a reset at some point", which cannot be reconstructed
 * for the past — a historical row would assert something the portal did not
 * witness. Existing accounts therefore start with an empty trail, which is the
 * truthful state, not a gap.
 *
 * Rollback: a table drop. The rows are the audit record, so this is the one
 * place where the rollback does lose data — it is the documented contract for
 * "this feature is not wanted", not a routine operation. No secret is involved:
 * the table holds no password, and could not be made to hold one (there is no
 * column for it, and the writing path never receives one).
 *
 * The guard makes a replay on an already-migrated schema a no-op instead of a
 * failure, so a retry after a partially committed MySQL/MariaDB run continues.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ftp_password_resets')) {
            return;
        }

        Schema::create('ftp_password_resets', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('ip', 45)->nullable();
            $table->boolean('success');
            $table->timestamp('reset_at')->useCurrent();

            $table->index(['user_id', 'reset_at']);
        });
    }

    /**
     * Pure table drop, implemented although down() is never executed in this
     * repository: the rollback decision above is part of the documented
     * contract, and an unverified rollback is not a contract. `dropIfExists`
     * makes a second run — or a run after a partial drop — a no-op.
     */
    public function down(): void
    {
        Schema::dropIfExists('ftp_password_resets');
    }
};
