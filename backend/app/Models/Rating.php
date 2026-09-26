<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Rating extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'photo_id',
        'user_id',
        'guest_id',
        'guest_name',
        'rating',
        'comment',
        'actor_key',
    ];

    protected $casts = [
        'rating' => 'integer',
        'actor_key' => 'string',
    ];

    /**
     * Return the portable ownership key used by V038.
     *
     * Prefixes keep a registered user and a transient guest with the same
     * underlying identifier in separate uniqueness buckets. UUID identities
     * are canonicalized to lowercase so MySQL's case-insensitive collations
     * cannot bypass the portable index. NULL is reserved
     * for ownerless legacy rows (and malformed both-owner rows), which must not
     * be guessed into an actor identity.
     */
    public static function actorKeyFor(mixed $userId, mixed $guestId): ?string
    {
        $userId = static::normalizeActorId($userId);
        $guestId = static::normalizeActorId($guestId);

        if ($userId !== null && $guestId === null) {
            return 'user:'.$userId;
        }

        if ($guestId !== null && $userId === null) {
            return 'guest:'.$guestId;
        }

        return null;
    }

    protected static function booted(): void
    {
        static::saving(function (self $rating): void {
            $rating->actor_key = static::actorKeyFor($rating->user_id, $rating->guest_id);
        });
    }

    private static function normalizeActorId(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = strtolower(trim((string) $value));

        return $value !== '' ? $value : null;
    }
}
