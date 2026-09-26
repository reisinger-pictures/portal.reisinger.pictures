<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Makes rating ownership explicit in the database.
 *
 * MySQL/MariaDB do not support partial unique indexes and SQLite does not
 * share MySQL's partial-index syntax. A nullable typed actor key gives both
 * drivers the same portable invariant: identifiable actors have
 * `user:<id>` or `guest:<id>` and are unique per photo. UUID identities are
 * canonicalized to lowercase so MySQL's case-insensitive collations cannot
 * bypass the index. Ownerless legacy rows keep NULL (both drivers allow
 * multiple NULL values in a unique index).
 *
 * The historical (photo_id, user_id, guest_id) unique index is deliberately
 * retained. It remains the registered-user compatibility invariant while the
 * actor-key index supplies an unambiguous key for both registered and guest
 * mutations.
 *
 * Legacy rows with no owner are never deleted. If an old installation contains
 * duplicate rows for an identifiable actor, this migration is fail-closed: it
 * reports the duplicate groups and aborts without deleting or merging a single
 * row. An operator resolves the source rows and retries the migration. Only a
 * clean preflight is followed by the canonical actor-key backfill and the
 * unique index that is the durable race guard for future writers.
 */
return new class extends Migration
{
    private const ACTOR_KEY_COLUMN = 'actor_key';

    private const ACTOR_KEY_INDEX = 'ratings_photo_actor_key_unique';

    private const MAX_REPORT_GROUPS = 20;

    private const MAX_REPORT_IDS = 20;

    public function up(): void
    {
        if (! Schema::hasColumn('ratings', self::ACTOR_KEY_COLUMN)) {
            Schema::table('ratings', function (Blueprint $table): void {
                $table->string(self::ACTOR_KEY_COLUMN, 64)->nullable()->after('guest_id');
            });
        }

        $this->preflightAndNormalizeRatings();

        if (! Schema::hasIndex('ratings', self::ACTOR_KEY_INDEX, 'unique')) {
            Schema::table('ratings', function (Blueprint $table): void {
                $table->unique(
                    ['photo_id', self::ACTOR_KEY_COLUMN],
                    self::ACTOR_KEY_INDEX,
                );
            });
        }
    }

    public function down(): void
    {
        // down() wird nie ausgeführt (Policy).
    }

    private function preflightAndNormalizeRatings(): void
    {
        $actorKeyUpdates = [];
        $actorIdentities = [];

        $ratingsQuery = DB::table('ratings')
            ->select(['id', 'photo_id', 'user_id', 'guest_id', self::ACTOR_KEY_COLUMN]);

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            // MariaDB's default collation can treat differently-cased UUID
            // strings as equal. BINARY ordering makes the report deterministic
            // across drivers.
            $ratingsQuery->orderByRaw('BINARY `id`');
        } else {
            $ratingsQuery->orderBy('id');
        }

        $ratingsQuery->chunk(500, function ($ratings) use (&$actorKeyUpdates, &$actorIdentities): void {
            foreach ($ratings as $rating) {
                $actorKey = $this->actorKey($rating->user_id, $rating->guest_id);

                // Both-null rows are retained as inaccessible legacy rows. A
                // stale actor key is never allowed to turn one into a new
                // owner bucket on a retry.
                if ($actorKey === null) {
                    if ($rating->actor_key !== null) {
                        $actorKeyUpdates[$rating->id] = null;
                    }

                    continue;
                }

                $actorIdentities[$rating->photo_id."\0".$actorKey][] = (string) $rating->id;

                if ($rating->actor_key !== $actorKey) {
                    $actorKeyUpdates[$rating->id] = $actorKey;
                }
            }
        });

        $duplicateGroups = array_filter(
            $actorIdentities,
            static fn (array $ids): bool => count($ids) > 1,
        );

        if ($duplicateGroups !== []) {
            $report = $this->duplicateReport($duplicateGroups);
            Log::error('V038 rating actor-key preflight failed', [
                'report' => $report,
            ]);

            throw new RuntimeException($report);
        }

        // No duplicates: it is now safe to backfill the canonical keys. This
        // runs in one transaction so a failure never leaves half-normalized
        // actor keys behind.
        DB::transaction(function () use ($actorKeyUpdates): void {
            // Clear stale values before writing canonical keys. This matters on
            // SQLite (case-sensitive text comparison) when a legacy row has a
            // differently-cased key that would otherwise collide mid-update.
            foreach (array_chunk($actorKeyUpdates, 500, true) as $updates) {
                foreach ($updates as $id => $actorKey) {
                    DB::table('ratings')
                        ->where('id', $id)
                        ->update([self::ACTOR_KEY_COLUMN => null]);
                }
            }

            foreach (array_chunk($actorKeyUpdates, 500, true) as $updates) {
                foreach ($updates as $id => $actorKey) {
                    DB::table('ratings')
                        ->where('id', $id)
                        ->update([self::ACTOR_KEY_COLUMN => $actorKey]);
                }
            }
        });
    }

    /**
     * @param  array<string, list<string>>  $groups
     */
    private function duplicateReport(array $groups): string
    {
        $lines = [
            'V038 rating actor-key preflight failed: duplicate ratings for the same (photo_id, actor).',
            'No ratings were deleted or merged. Resolve the reported groups before retrying V038.',
        ];

        $totalGroups = count($groups);
        $totalRows = array_sum(array_map(
            static fn (array $ids): int => count($ids),
            $groups,
        ));
        $lines[] = sprintf('ratings duplicate_groups=%d duplicate_rows=%d', $totalGroups, $totalRows);

        $groupIndex = 0;
        foreach ($groups as $identity => $ids) {
            if ($groupIndex >= self::MAX_REPORT_GROUPS) {
                break;
            }

            $ids = array_values(array_unique(array_map('strval', $ids)));
            sort($ids, SORT_STRING);
            $lines[] = sprintf(
                'ratings identity=%s count=%d ids=%s',
                str_replace("\0", ' actor=', $identity),
                count($ids),
                implode(',', array_slice($ids, 0, self::MAX_REPORT_IDS)),
            );
            $groupIndex++;
        }

        if ($totalGroups > self::MAX_REPORT_GROUPS) {
            $lines[] = sprintf(
                'ratings ... %d additional duplicate groups omitted.',
                $totalGroups - self::MAX_REPORT_GROUPS,
            );
        }

        return implode("\n", $lines);
    }

    private function actorKey(mixed $userId, mixed $guestId): ?string
    {
        $userId = $this->normalizeId($userId);
        $guestId = $this->normalizeId($guestId);

        if ($userId !== null && $guestId === null) {
            return 'user:'.$userId;
        }

        if ($guestId !== null && $userId === null) {
            return 'guest:'.$guestId;
        }

        // Both-null rows are legacy. Both non-null rows are malformed legacy
        // data; retaining them without a key is safer than guessing ownership.
        return null;
    }

    private function normalizeId(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = strtolower(trim((string) $value));

        return $value !== '' ? $value : null;
    }
};
