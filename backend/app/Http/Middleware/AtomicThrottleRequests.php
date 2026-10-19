<?php

namespace App\Http\Middleware;

use App\Support\AtomicRateCounter;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limiting that counts before it decides (SEC-20261018-12).
 *
 * `Illuminate\Routing\Middleware\ThrottleRequests::handleRequest()` asks
 * `tooManyAttempts()` and increments only afterwards. That read-then-write
 * window is not atomic across workers: every worker can observe a counter
 * below the limit and pass, so the budget overshoots by the number of
 * simultaneously in-flight requests (measured: 32-37 of 30 with 40 concurrent
 * clients against MariaDB 11.4). This middleware inverts the order and rejects
 * on the counter returned by its own increment.
 *
 * It does **not** call `RateLimiter::hit()`. That method's `increment()` ends
 * with a repair write — `put($key, $amount, $decay)` — whenever a worker that
 * lost the `add()` race increments the counter from 0 to 1. The write is
 * unconditional and can land after other workers already counted, which rolls
 * the counter back and re-opens the budget (one run of the same series admitted
 * 32 at a limit of 30; the counts match a rollback from 3 to 1). The counter
 * here is built from two primitives without such a write:
 *
 *  - `add($key, 1, $decay)` creates the window counter for exactly one request
 *    (atomic insert-if-absent) and doubles as the expiry reset, because a store
 *    evicts the expired row it reads before inserting,
 *  - `increment($key)` is the atomic read-modify-write and returns the new
 *    value; on the `database` store (the production default) it runs inside a
 *    transaction with a row lock.
 *
 * In the single-limit case wired here the counter therefore only ever grows
 * inside a window, so at most `maxAttempts` requests per window reach the
 * controller; the multi-limit and `after()` refunds below are deliberate
 * decrements. `Retry-After` still comes from `RateLimiter::availableIn()` via
 * the `:timer` key the window creator writes.
 *
 * Deliberately *not* the framework default and *not* global: every other
 * `throttle:` route keeps the framework behaviour (including both races above)
 * until it is migrated on purpose. No controller work is wrapped in a lock, so
 * queue `withoutOverlapping` semantics are untouched.
 *
 * Owner decision D-38 keeps that conversion bounded: the counting primitive
 * lives in `App\Support\AtomicRateCounter` and is shared with the checkout
 * quota and the Turnstile attempt counters, while the remaining `throttle:`
 * routes stay on the framework middleware as a documented limit.
 */
class AtomicThrottleRequests extends ThrottleRequests
{
    private ?AtomicRateCounter $counter = null;

    public function __construct(RateLimiter $limiter, protected Repository $cache)
    {
        parent::__construct($limiter);
    }

    /**
     * @param  Request  $request
     * @param  array<int, object>  $limits
     * @return Response
     *
     * @throws ThrottleRequestsException
     * @throws HttpResponseException
     */
    protected function handleRequest($request, Closure $next, array $limits)
    {
        $consumed = [];

        foreach ($limits as $limit) {
            $hits = $this->countAttempt($limit);

            if ($hits > $limit->maxAttempts) {
                // A rejection must not consume a *different* bucket: multi-limit
                // limiters (e.g. the checkout bucket) would otherwise be charged
                // for requests another limit refused. The rejecting hit itself
                // stays counted — refunding it would cost a second write exactly
                // when the limiter's job is to shed load, and the threshold
                // decision does not depend on the counter staying at the limit.
                foreach ($consumed as $consumedLimit) {
                    $this->limiter->decrement($consumedLimit->key, $consumedLimit->decaySeconds);
                }

                throw $this->buildException($request, $limit->key, $limit->maxAttempts, $limit->responseCallback);
            }

            $consumed[] = $limit;
        }

        $response = $next($request);

        foreach ($limits as $limit) {
            if ($limit->afterCallback && ! ($limit->afterCallback)($response)) {
                // `Limit::...->after()` counts only matching responses. This
                // middleware already counted the request, so refund the ones the
                // callback declines to count.
                $this->limiter->decrement($limit->key, $limit->decaySeconds);
            }

            $response = $this->addHeaders(
                $response,
                $limit->maxAttempts,
                $this->calculateRemainingAttempts($limit->key, $limit->maxAttempts)
            );
        }

        return $response;
    }

    /**
     * Count this request and return the number of counted requests in the window.
     *
     * @param  object{key: string, maxAttempts: int, decaySeconds: int}  $limit
     */
    protected function countAttempt(object $limit): int
    {
        $hits = $this->counter()->count($limit->key, $limit->decaySeconds);

        if ($hits === AtomicRateCounter::UNCOUNTABLE) {
            // The counter row vanished between the two calls. Admitting the
            // request uncounted would widen the window, so refuse it instead.
            return $limit->maxAttempts + 1;
        }

        return $hits;
    }

    /**
     * The shared count-first primitive. The same unit backs the checkout
     * quota and the Turnstile attempt counters (owner decision D-38).
     */
    private function counter(): AtomicRateCounter
    {
        return $this->counter ??= new AtomicRateCounter($this->cache, $this->limiter);
    }
}
