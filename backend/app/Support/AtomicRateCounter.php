<?php

namespace App\Support;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;

/**
 * Counts one request in a rate-limit window before the caller decides.
 *
 * The framework's `RateLimiter::hit()` counts *after* the decision and repairs
 * a lost `add()` race with an unconditional `put($key, 1, $decay)`. That write
 * can land after other workers already counted and rolls the counter back,
 * which re-opens the budget (SEC-20261018-12, owner decision D-38).
 *
 * This counter is built from two primitives without such a write:
 *
 *  - `add($key, 1, $decay)` creates the window counter for exactly one request
 *    (atomic insert-if-absent) and doubles as the expiry reset, because a store
 *    evicts the expired row it reads before inserting,
 *  - `increment($key)` is the atomic read-modify-write and returns the new
 *    value; on the `database` store (the production default) it runs inside a
 *    transaction with a row lock.
 *
 * The key is cleaned exactly like `RateLimiter::cleanRateLimiterKey()` does and
 * the `:timer` companion key is written by the window creator, so
 * `RateLimiter::attempts()` and `RateLimiter::availableIn()` keep reading the
 * same values they read for a `RateLimiter::hit()` counter. The `:timer` value
 * is the same `Carbon::now()->addSeconds($decaySeconds)` timestamp the
 * framework limiter writes.
 */
final class AtomicRateCounter
{
    /**
     * Returned instead of a count when the counter could neither be created
     * nor incremented. A caller must treat it as "over every limit": a
     * request whose count cannot be determined must not widen a budget.
     */
    public const UNCOUNTABLE = -1;

    public function __construct(
        private readonly Repository $cache,
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * Count one request and return the number of counted requests in the window.
     *
     * @return int the number of counted requests, or self::UNCOUNTABLE when
     *             the counter could not be determined
     */
    public function count(string $key, int $decaySeconds): int
    {
        $cleanKey = $this->limiter->cleanRateLimiterKey($key);

        if ($this->cache->add($cleanKey, 1, $decaySeconds)) {
            // This request opened the window. The timer only feeds
            // `Retry-After`; the counter's own expiry is the window.
            $this->cache->add($cleanKey.':timer', $this->availableAt($decaySeconds), $decaySeconds);

            return 1;
        }

        $hits = $this->cache->increment($cleanKey);

        if ($hits === false) {
            // The counter row vanished between the two calls. Counting the
            // request later would widen the window, so report the count as
            // undeterminable instead of admitting the request uncounted.
            return self::UNCOUNTABLE;
        }

        return (int) $hits;
    }

    /**
     * The UNIX timestamp the window ends at. Mirrors
     * `Illuminate\Support\InteractsWithTime::availableAt()`.
     */
    public function availableAt(int $decaySeconds): int
    {
        return Carbon::now()->addSeconds($decaySeconds)->getTimestamp();
    }
}
