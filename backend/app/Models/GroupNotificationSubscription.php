<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A group notification subscription.
 *
 * A subscription is not a grant: it says "this user wants to be informed about
 * new galleries in this group", nothing more. It therefore lives in its own table
 * (`group_notification_subscriptions`, V048) and not in `user_gallery_groups`, the
 * pivot that carries the client group grant — see the owner decision of 2026-10-21
 * (D-46 / AD-10) and features/infrastructure/02-email-system.md §2.2.
 *
 * The row *is* the subscription: there is no flag column. A row exists exactly
 * while the subscription exists.
 *
 * @property int $id
 * @property string $user_id
 * @property string $gallery_group_id
 */
class GroupNotificationSubscription extends Model
{
    public const TABLE = 'group_notification_subscriptions';

    protected $table = self::TABLE;

    protected $fillable = [
        'user_id',
        'gallery_group_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(GalleryGroup::class, 'gallery_group_id');
    }

    /**
     * Subscribe a user to a gallery group.
     *
     * The write is a single atomic upsert against the unique index over
     * `(user_id, gallery_group_id)`. A check-then-insert (`firstOrNew()->save()`)
     * would raise a unique constraint violation — an HTTP 500 — when two
     * requests of the same user race each other between the SELECT and the
     * INSERT. The upsert cannot lose that race: the database resolves the
     * conflict itself and updates `updated_at`.
     *
     * `created_at` is not part of the update list, so a re-subscription keeps
     * the moment of the first subscription.
     */
    public static function subscribe(string $userId, string $galleryGroupId): void
    {
        $now = now();

        DB::table(self::TABLE)->upsert(
            [[
                'user_id' => $userId,
                'gallery_group_id' => $galleryGroupId,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['user_id', 'gallery_group_id'],
            ['updated_at'],
        );
    }

    /**
     * Revoke the subscription of a user for a gallery group.
     *
     * @return int the number of removed rows (0 or 1)
     */
    public static function unsubscribe(string $userId, string $galleryGroupId): int
    {
        return DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->where('gallery_group_id', $galleryGroupId)
            ->delete();
    }
}
