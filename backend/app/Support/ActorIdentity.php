<?php

namespace App\Support;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Canonical identity and ownership rules for authenticated actors.
 *
 * A transient guest is a real, signed actor even though it has no users row.
 * Its identity is the guest_id claim (or the guest_ subject fallback); it must
 * never be represented by the null users.id value. Ownerless rows are kept for
 * legacy/system orders, but deliberately do not match any actor.
 */
final class ActorIdentity
{
    public static function isRegistered(?User $actor): bool
    {
        return self::registeredId($actor) !== null;
    }

    public static function isGuest(?User $actor): bool
    {
        return $actor instanceof User && self::registeredId($actor) === null;
    }

    public static function registeredId(?User $actor): ?string
    {
        if (! $actor instanceof User) {
            return null;
        }

        return self::nonEmptyString($actor->getKey());
    }

    public static function guestId(?User $actor): ?string
    {
        if (! self::isGuest($actor)) {
            return null;
        }

        // TransientUserProvider derives this from the signed `guest_id` claim
        // and falls back to the `guest_<id>` JWT subject. The fallback here is
        // intentionally limited to the property populated by that provider;
        // an unidentifiable null-id actor is never allowed to own a row.
        return self::nonEmptyString($actor->guest_id);
    }

    /**
     * A typed identity suitable for comparing actors and scoping orders.
     */
    public static function ownerKey(?User $actor): ?string
    {
        $registeredId = self::registeredId($actor);
        if ($registeredId !== null) {
            return 'user:'.$registeredId;
        }

        $guestId = self::guestId($actor);
        if ($guestId !== null) {
            return 'guest:'.$guestId;
        }

        return null;
    }

    /**
     * Stable, non-secret actor portion for limiter/cache keys.
     *
     * Registered users retain their historical raw-id form so existing limiter
     * and purchase-cache entries remain valid. Guests receive a typed prefix,
     * preventing all transient actors with id=null from sharing one bucket.
     */
    public static function cacheIdentifier(?User $actor): string
    {
        $registeredId = self::registeredId($actor);
        if ($registeredId !== null) {
            return $registeredId;
        }

        $guestId = self::guestId($actor);
        if ($guestId !== null) {
            return 'guest:'.$guestId;
        }

        return 'invalid';
    }

    public static function lockIdentifier(?User $actor): ?string
    {
        $identifier = self::cacheIdentifier($actor);

        return $identifier === 'invalid' ? null : $identifier;
    }

    /**
     * Apply the single owner predicate used by every customer order query.
     *
     * Legacy both-null rows are intentionally excluded. A registered actor
     * may only match a row with its user_id and no guest_id; a guest may only
     * match a row with its guest_id and no user_id.
     */
    public static function scopeOrders(Builder $query, ?User $actor): Builder
    {
        $registeredId = self::registeredId($actor);
        if ($registeredId !== null) {
            return $query
                ->where('user_id', $registeredId)
                ->whereNull('guest_id');
        }

        $guestId = self::guestId($actor);
        if ($guestId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereNull('user_id')
            ->where('guest_id', $guestId);
    }

    public static function ownsOrder(Order $order, ?User $actor): bool
    {
        $registeredId = self::registeredId($actor);
        if ($registeredId !== null) {
            return self::nonEmptyString($order->user_id) === $registeredId
                && self::nonEmptyString($order->guest_id) === null;
        }

        $guestId = self::guestId($actor);
        if ($guestId === null) {
            return false;
        }

        return self::nonEmptyString($order->user_id) === null
            && self::nonEmptyString($order->guest_id) === $guestId;
    }

    /**
     * The database/model invariant is "at most one owner". Both-null rows are
     * retained for historical and system-created invoices; they are fail-closed
     * by ownsOrder()/scopeOrders() and are never used as a wildcard owner.
     */
    public static function assertOrderOwnerInvariant(mixed $userId, mixed $guestId): void
    {
        if (self::nonEmptyString($userId) !== null && self::nonEmptyString($guestId) !== null) {
            throw new \InvalidArgumentException('Eine Bestellung darf nicht gleichzeitig user_id und guest_id besitzen.');
        }

        $normalizedGuestId = self::nonEmptyString($guestId);
        if ($normalizedGuestId !== null && strlen($normalizedGuestId) > 36) {
            throw new \InvalidArgumentException('Die guest_id ist zu lang.');
        }
    }

    public static function purchaseCacheKeyForActor(?User $actor, string $photoId, string $requestedTier): ?string
    {
        $owner = self::ownerKey($actor);
        if ($owner === null) {
            return null;
        }

        return self::purchaseCacheKeyForOwner($owner, $photoId, $requestedTier);
    }

    public static function purchaseCacheKeyForOrder(Order $order, string $photoId, string $requestedTier): ?string
    {
        $userId = self::nonEmptyString($order->user_id);
        $guestId = self::nonEmptyString($order->guest_id);
        if (($userId === null) === ($guestId === null)) {
            return null;
        }

        $owner = $userId !== null ? 'user:'.$userId : 'guest:'.$guestId;

        return self::purchaseCacheKeyForOwner($owner, $photoId, $requestedTier);
    }

    private static function purchaseCacheKeyForOwner(string $owner, string $photoId, string $requestedTier): string
    {
        [$type, $value] = array_pad(explode(':', $owner, 2), 2, '');

        return $type.'.'.$value.'.purchased.'.$photoId.'.'.$requestedTier;
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
