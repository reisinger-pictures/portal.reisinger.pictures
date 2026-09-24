<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
 * duplicate rows for an identifiable actor, the lexicographically smallest
 * rating UUID is retained and only redundant duplicates are removed. UUID order
 * is stable across MySQL/MariaDB and SQLite, so a retry produces the same result.
 */
return new class extends Migration
{
    private const ACTOR_KEY_COLUMN = 'actor_key';

    private const ACTOR_KEY_INDEX = 'ratings_photo_actor_key_unique';

    public function up(): void
    {
        if (! Schema::hasColumn('ratings', self::ACTOR_KEY_COLUMN)) {
            Schema::table('ratings', function (Blueprint $table): void {
                $table->string(self::ACTOR_KEY_COLUMN, 64)->nullable()->after('guest_id');
            });
        }

        $this->normalizeAndDeduplicateRatings();

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

    private function normalizeAndDeduplicateRatings(): void
    {
        DB::transaction(function (): void {
            $seenActors = [];
            $duplicateIds = [];
            $actorKeyUpdates = [];

            $ratingsQuery = DB::table('ratings')
                ->select(['id', 'photo_id', 'user_id', 'guest_id', self::ACTOR_KEY_COLUMN]);

            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                // MariaDB's default collation can treat differently-cased UUID
                // strings as equal. BINARY ordering makes the winner rule
                // bytewise and therefore repeatable across drivers.
                $ratingsQuery->orderByRaw('BINARY `id`');
            } else {
                $ratingsQuery->orderBy('id');
            }

            $ratingsQuery->chunk(500, function ($ratings) use (&$seenActors, &$duplicateIds, &$actorKeyUpdates): void {
                foreach ($ratings as $rating) {
                    $actorKey = $this->actorKey($rating->user_id, $rating->guest_id);

                    // Both-null rows are retained as inaccessible legacy
                    // rows. A stale actor key is never allowed to turn one
                    // into a new owner bucket on a retry.
                    if ($actorKey === null) {
                        if ($rating->actor_key !== null) {
                            $actorKeyUpdates[$rating->id] = null;
                        }

                        continue;
                    }

                    $identity = $rating->photo_id."\0".$actorKey;
                    if (array_key_exists($identity, $seenActors)) {
                        $duplicateIds[] = $rating->id;

                        continue;
                    }

                    $seenActors[$identity] = true;
                    if ($rating->actor_key !== $actorKey) {
                        $actorKeyUpdates[$rating->id] = $actorKey;
                    }
                }
            });

            foreach (array_chunk($duplicateIds, 500) as $ids) {
                DB::table('ratings')->whereIn('id', $ids)->delete();
            }

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
