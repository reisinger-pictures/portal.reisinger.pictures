<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The gallery notification subscription gets its own table.
 *
 * Owner decision 2026-10-20 (D-45 / AD-9, features/tech/07-architectural-decisions.md
 * and features/infrastructure/02-email-system.md §2.1).
 *
 * The defect this migration exists for: `NotificationController::toggleGalleryOptIn()`
 * wrote the subscription into `user_galleries` — the pivot that carries the client
 * grant (`AuthorizationService::getAllowedGalleryIds()`, step 1 "direct assignments").
 * A subscription therefore created a grant. For a delivery gallery the row was
 * honoured as a grant and outlived the org membership that justified it; for a
 * selection gallery it granted nothing (D-39 filters non-delivery galleries out of
 * the reader) but still polluted the grant pivot. Measured on the unmodified tree
 * (backend/tests/Feature/Notification/GalleryNotificationSubscriptionTest.php):
 * the opt-in wrote a `user_galleries` row and `getAllowedGalleryIds()` still
 * returned the gallery after `users.org_id` was cleared.
 *
 * The alternative — an opt-in marker that stays in the grant pivot — is rejected:
 * the pivot would keep the rows and every future reader of `user_galleries` would
 * have to know which rows are grants and which are subscriptions.
 *
 * Schema decision, documented up front as required by backend/AGENTS.md:
 * - `id`          surrogate key (bigint), matching the other association tables
 *                 (`volume_preset_tiers`, V029).
 * - `user_id`     FK to `users` with ON DELETE CASCADE, like `user_galleries`. A
 *                 subscription without an account would be unattributable, and a
 *                 row that outlives a deleted account would be a dangling reference.
 * - `gallery_id`  FK to `galleries` with ON DELETE CASCADE, like `user_galleries`.
 *                 With the gallery, the subscription is gone.
 * - timestamps    `created_at`/`updated_at`, so the moment of the subscription is
 *                 answerable. The legacy pivot carried no timestamp at all.
 *
 * Indexes:
 * - `gallery_notification_subscriptions_user_gallery_unique` — UNIQUE over
 *   `(user_id, gallery_id)`. This is the natural key: one subscription per user
 *   and gallery. It also serves the read path "which galleries has this user
 *   subscribed to" from its leftmost column.
 * - `gallery_notification_subscriptions_gallery_id_index` — over `(gallery_id)`.
 *   This is the query the mail path asks ("who is subscribed to this gallery?");
 *   the unique index cannot answer it, because `gallery_id` is its second column.
 *
 * Backfill: every `user_galleries` row with `wants_notifications = 1` becomes
 * exactly one subscription row (same `user_id`/`gallery_id`, migration timestamp).
 * No subscription is lost. The pivot rows themselves are NOT touched — neither
 * deleted nor re-flagged. A flagged pivot row is ambiguous: it is either an
 * opt-in-only row (the defect) or a real grant that was additionally subscribed
 * to. The pivot carries no timestamp and no provenance, so the flag alone cannot
 * separate the two, and a migration that deleted a row would risk revoking a real
 * grant by accident. The opt-in-only rows that keep acting as a grant are a
 * documented residual; they are listed by `notifications:prune-legacy-opt-in-grants`
 * (dry run first) and removed only deliberately by the owner.
 *
 * Rollback: `down()` drops this table and nothing else. Because `up()` leaves the
 * pivot rows untouched, the legacy flag still carries the subscription after the
 * rollback, so the pre-migration state is restored exactly. What the rollback
 * does lose are subscriptions written *after* the migration — the documented cost
 * of rolling this feature back, not a side effect.
 *
 * `user_galleries.wants_notifications` stays in place as a legacy column: the
 * application no longer reads or writes it, but the repair command needs it as
 * the marker of "this row carries an opt-in". A later migration may drop it once
 * the repair list has been processed.
 */
return new class extends Migration
{
    private const TABLE = 'gallery_notification_subscriptions';

    private const LEGACY_TABLE = 'user_galleries';

    private const CHUNK_SIZE = 500;

    public function up(): void
    {
        $this->assertSupportedDriver();

        if (! Schema::hasTable(self::LEGACY_TABLE)) {
            throw new RuntimeException('V047 requires table ['.self::LEGACY_TABLE.'].');
        }

        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignUuid('gallery_id')->constrained('galleries')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(
                    ['user_id', 'gallery_id'],
                    'gallery_notification_subscriptions_user_gallery_unique',
                );
                $table->index('gallery_id', 'gallery_notification_subscriptions_gallery_id_index');
            });
        }

        $this->backfill();
    }

    /**
     * The rollback of this feature: the new table goes, the legacy pivot stays
     * exactly as it was. `up()` never modifies the pivot, so the legacy flag
     * still carries every subscription that existed before the migration.
     */
    public function down(): void
    {
        $this->assertSupportedDriver();

        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::drop(self::TABLE);
    }

    /**
     * Move the subscription state of every flagged legacy row into the new table.
     *
     * The legacy rows are only read. They are never deleted and never re-flagged:
     * a flagged row may be a real grant, and a migration must not revoke it.
     */
    private function backfill(): void
    {
        DB::table(self::LEGACY_TABLE)
            ->where('wants_notifications', true)
            ->orderBy('user_id')
            ->orderBy('gallery_id')
            ->chunk(self::CHUNK_SIZE, function ($rows): void {
                $now = now();

                $subscriptions = [];
                foreach ($rows as $row) {
                    $subscriptions[] = [
                        'user_id' => $row->user_id,
                        'gallery_id' => $row->gallery_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($subscriptions === []) {
                    return;
                }

                // A replay of the migration must not fail on the unique index.
                DB::table(self::TABLE)->insertOrIgnore($subscriptions);
            });
    }

    private function assertSupportedDriver(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlite'], true)) {
            throw new RuntimeException('V047 does not support database driver ['.$driver.'].');
        }
    }
};
