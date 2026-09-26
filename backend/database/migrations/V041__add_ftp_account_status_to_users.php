<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the FTP/SFTP account provisioning status to `users`.
 *
 * Source of truth is a column, not a live query against SFTPGo
 * (features/infrastructure/19-ftp-upload-pipeline.md §7.4). `status()` is a read
 * path for the photographer: a live query would make the UI depend on the
 * service, while the import has to keep working while SFTPGo is down (§7.5).
 * Both at once is not possible, and a live query would additionally require a
 * credential for read operations only — which would undo the secret-surface
 * reduction of P1-M23.
 *
 * Schema decision, documented up front as required by backend/AGENTS.md:
 * - `ftp_account_status`  enum(pending|active|error), NOT NULL, default pending
 * - `ftp_provisioned_at`  timestamp, nullable
 * - `ftp_account_error`   text, nullable
 * - No index: the columns are only ever read through the primary key, so an
 *   index would be pure write overhead without a query that uses it.
 *
 * Backfill: the column default IS the backfill. Every pre-existing row — every
 * existing photographer included — becomes 'pending' through the DDL itself, on
 * MySQL/MariaDB, PostgreSQL and SQLite alike, because all three fill an added
 * column from its default. No separate `UPDATE users ...` is issued on purpose:
 * that statement would be a second full-table write on the production users
 * table and could only ever produce the same value. 'pending' is also the
 * correct state for a non-photographer, who never receives an FTP account.
 * The state is genuinely unknown for the backfilled rows — they were never
 * provisioned through SFTPGo.
 *
 * Rollback: a pure column drop. No data is lost, because the portal stores no
 * FTP password at all (P1-M23), so these columns never hold a secret.
 *
 * The columns are a cache, not the truth: deleting the account in SFTPGo by
 * hand leaves them stale. That is why reconciliation is an explicit
 * `reconcileAccount()` path (P1-M30) instead of a silent live query.
 *
 * Every DDL step is individually guarded so a retry after a partially committed
 * MySQL/MariaDB run continues instead of failing on an existing column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'ftp_account_status')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->enum('ftp_account_status', ['pending', 'active', 'error'])
                    ->default('pending');
            });
        }

        if (! Schema::hasColumn('users', 'ftp_provisioned_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('ftp_provisioned_at')->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'ftp_account_error')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->text('ftp_account_error')->nullable();
            });
        }
    }

    /**
     * Pure column drop, implemented although down() is never executed in this
     * repository: §7.4 makes the rollback part of the documented contract, and
     * an unverified rollback is not a contract. The guard makes a second run —
     * or a run after a partial drop — a no-op instead of a failure.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'ftp_account_error')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('ftp_account_error');
            });
        }

        if (Schema::hasColumn('users', 'ftp_provisioned_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('ftp_provisioned_at');
            });
        }

        if (Schema::hasColumn('users', 'ftp_account_status')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('ftp_account_status');
            });
        }
    }
};
