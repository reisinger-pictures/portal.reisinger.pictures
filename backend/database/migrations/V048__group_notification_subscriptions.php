<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The group notification subscription gets its own table.
 *
 * Owner decision 2026-10-21 (D-46 / AD-10, features/tech/07-architectural-decisions.md
 * and features/infrastructure/02-email-system.md §2.2).
 *
 * The defect this migration exists for: `NotificationController::toggleGroupOptIn()`
 * wrote the subscription into `user_gallery_groups` — the pivot that carries the
 * client group grant (`AuthorizationService::getAllowedGalleryIds()`, step 2 "group
 * assignments (recursive)"). A subscription therefore produced a grant, and a group
 * grant covers the whole subtree of the group. Measured on the unmodified tree
 * (backend/tests/Feature/Notification/GroupNotificationSubscriptionTest.php): the
 * opt-in set `user_gallery_groups.wants_notifications = 1` on the grant row, the
 * reader handed out 2 of 2 galleries of the group's subtree, and it still handed out
 * 2 of 2 after `users.org_id` had been cleared. The mirror image: revoking the group
 * membership deleted the pivot row and took the subscription with it — the
 * subscription and the grant were one and the same row.
 *
 * The alternative — an opt-in marker that stays in the grant pivot — is rejected:
 * the pivot would keep the rows and every future reader of `user_gallery_groups`
 * would have to know which rows are grants and which are subscriptions.
 *
 * Schema decision, documented up front as required by backend/AGENTS.md:
 * - `id`             surrogate key (bigint), matching the other association tables
 *                    (`volume_preset_tiers`, V029) and the gallery subscription
 *                    table (V047). The legacy pivot `user_gallery_groups` has a
 *                    composite primary key instead; the subscription table is a
 *                    first-class relation and gets a surrogate key like V047.
 * - `user_id`        FK to `users` with ON DELETE CASCADE, like the pivot. A
 *                    subscription without an account would be unattributable, and a
 *                    row that outlives a deleted account would be a dangling reference.
 * - `gallery_group_id` FK to `gallery_groups` with ON DELETE CASCADE, like the
 *                    pivot. With the group, the subscription is gone.
 * - timestamps       `created_at`/`updated_at`, so the moment of the subscription is
 *                    answerable. The legacy pivot carried no timestamp at all.
 *
 * Indexes:
 * - `group_notification_subscriptions_user_group_unique` — UNIQUE over
 *   `(user_id, gallery_group_id)`. This is the natural key: one subscription per
 *   user and group. It also serves the read path "which groups has this user
 *   subscribed to" from its leftmost column.
 * - `group_notification_subscriptions_gallery_group_id_index` — over
 *   `(gallery_group_id)`. This is the query the mail path asks ("who is subscribed
 *   to this group?"); the unique index cannot answer it, because `gallery_group_id`
 *   is its second column.
 *
 * Backfill: every `user_gallery_groups` row with `wants_notifications = 1` becomes
 * exactly one subscription row (same `user_id`/`gallery_group_id`, migration
 * timestamp). No subscription is lost. The pivot rows themselves are NOT touched —
 * neither deleted nor re-flagged. A flagged pivot row is ambiguous: it is either an
 * opt-in-only row (the defect) or a real grant that was additionally subscribed to.
 * The pivot carries no timestamp and no provenance, so the flag alone cannot
 * separate the two, and a migration that deleted a row would risk revoking a real
 * grant by accident. The opt-in-only rows that keep acting as a grant are a
 * documented residual; they are listed by `notifications:prune-legacy-group-opt-in-grants`
 * (dry run first) and removed only deliberately by the owner.
 *
 * Rollback: `down()` drops this table and nothing else. Because `up()` leaves the
 * pivot rows untouched, the legacy flag still carries the subscription after the
 * rollback, so the pre-migration state is restored exactly. What the rollback
 * does lose are subscriptions written *after* the migration — the documented cost
 * of rolling this feature back, not a side effect.
 *
 * `user_gallery_groups.wants_notifications` stays in place as a legacy column: the
 * application no longer reads or writes it, but the repair command needs it as
 * the marker of "this row carries an opt-in". A later migration may drop it once
 * the repair list has been processed.
 */
return new class extends Migration
{
    private const TABLE = 'group_notification_subscriptions';

    private const LEGACY_TABLE = 'user_gallery_groups';

    private const CHUNK_SIZE = 500;

    public function up(): void
    {
        $this->assertSupportedDriver();

        if (! Schema::hasTable(self::LEGACY_TABLE)) {
            throw new RuntimeException('V048 requires table ['.self::LEGACY_TABLE.'].');
        }

        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignUuid('gallery_group_id')->constrained('gallery_groups')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(
                    ['user_id', 'gallery_group_id'],
                    'group_notification_subscriptions_user_group_unique',
                );
                $table->index('gallery_group_id', 'group_notification_subscriptions_gallery_group_id_index');
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
            ->orderBy('gallery_group_id')
            ->chunk(self::CHUNK_SIZE, function ($rows): void {
                $now = now();

                $subscriptions = [];
                foreach ($rows as $row) {
                    $subscriptions[] = [
                        'user_id' => $row->user_id,
                        'gallery_group_id' => $row->gallery_group_id,
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
            throw new RuntimeException('V048 does not support database driver ['.$driver.'].');
        }
    }
};
