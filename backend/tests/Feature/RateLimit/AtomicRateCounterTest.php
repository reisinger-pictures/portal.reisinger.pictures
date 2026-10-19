<?php

namespace Tests\Feature\RateLimit;

use App\Support\AtomicRateCounter;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Tests\TestCase;

/**
 * The count-first counting primitive (SEC-20261018-12, owner decision D-38).
 *
 * `RateLimiter::hit()` repairs exactly one state with an unconditional
 * `put($key, 1, $decay)`: a worker that lost the `add()` race and increments
 * the counter from 0 to 1. The write is unconditional and can land after other
 * workers already counted, which rolls the counter back and re-opens the
 * budget. The converted paths (checkout quota, Turnstile attempt counters)
 * share this primitive, so these tests pin the properties they rely on:
 *
 *  - a request is counted exactly once and the counter is never written
 *    blindly, in the ordinary state and in the lost-race state,
 *  - a counter that cannot be determined is reported as such instead of
 *    silently counting as zero,
 *  - the key shape stays the one the framework reads, so `RateLimiter::attempts()`
 *    and `RateLimiter::availableIn()` keep seeing the same values.
 */
class AtomicRateCounterTest extends TestCase
{
    /**
     * The behaviour guard of the primitive: a request is counted exactly once
     * and the framework read sees the same count.
     *
     * This test stays green in both variants — that is its purpose. The
     * atomicity proof is the lost-race test below.
     */
    public function test_a_request_is_counted_exactly_once_and_never_written_blindly(): void
    {
        $store = $this->recordingStore();
        $repository = new Repository($store);
        $limiter = new RateLimiter($repository);
        $counter = new AtomicRateCounter($repository, $limiter);

        $this->assertSame(1, $counter->count('probe-key', 60));
        $this->assertSame(2, $counter->count('probe-key', 60));
        $this->assertSame(3, $counter->count('probe-key', 60));

        $this->assertSame([], $store->puts, 'Counting must not overwrite the counter with a blind write.');
        $this->assertSame(3, (int) $limiter->attempts('probe-key'), 'The framework read must see the same count.');
    }

    /**
     * The atomicity proof of the primitive.
     *
     * Regression: the `assertSame([], $store->puts, …)` assertion turns red
     * when the counting is replaced by `RateLimiter::hit()`.
     */
    public function test_the_lost_add_race_counts_the_request_once_without_a_blind_write(): void
    {
        // `RateLimiter::increment()` repairs exactly one state with an
        // unconditional `put()`: a worker that lost the `add()` race and then
        // increments the counter from 0 to 1. The store below reports that
        // state — `add()` returns false and the first `increment()` returns 1 —
        // and records every `put()`.
        $store = $this->lostAddRaceStore();
        $repository = new Repository($store);
        $limiter = new RateLimiter($repository);
        $counter = new AtomicRateCounter($repository, $limiter);

        $this->assertSame(1, $counter->count('race-key', 60));
        $this->assertSame(1, (int) $limiter->attempts('race-key'), 'The lost-race request is counted exactly once.');
        $this->assertSame(
            [],
            $store->puts,
            'In the lost-race state the counter must not be repaired with the blind write RateLimiter::hit() performs.'
        );

        // Counter-example in the very same state: the framework's own counting
        // does perform that write, which is what makes the empty `puts` above a
        // property of the counter and not of the store.
        $frameworkStore = $this->lostAddRaceStore();
        $frameworkLimiter = new RateLimiter(new Repository($frameworkStore));

        $this->assertSame(1, $frameworkLimiter->hit('framework-key', 60));
        $this->assertSame(
            [['key' => 'framework-key', 'value' => 1]],
            $frameworkStore->puts,
            'RateLimiter::hit() writes the counter unconditionally in the lost-race state.'
        );
    }

    /**
     * The fail-closed guard of the primitive: a counter that cannot be
     * determined is reported as such, never as zero.
     *
     * Regression: this test turns red when the counting is replaced by
     * `RateLimiter::hit()`, which cannot report an undeterminable counter.
     */
    public function test_a_counter_that_cannot_be_determined_is_reported_as_uncountable(): void
    {
        $store = new class extends ArrayStore
        {
            public function increment($key, $value = 1)
            {
                // Simulates the counter row vanishing between `add()` and
                // `increment()`; the request must not be admitted uncounted.
                return false;
            }
        };
        $repository = new Repository($store);
        $counter = new AtomicRateCounter($repository, new RateLimiter($repository));

        // The row exists, so the window creator loses the `add()` race and the
        // counter is only reachable through the increment.
        $repository->put('vanished-key', 5, 60);

        $this->assertSame(AtomicRateCounter::UNCOUNTABLE, $counter->count('vanished-key', 60));
    }

    /**
     * The key-shape guard of the primitive: the counter is stored under the
     * cleaned key and the `:timer` companion, so `RateLimiter::attempts()` and
     * `RateLimiter::availableIn()` keep reading the same values.
     *
     * This test stays green in both variants — that is its purpose.
     */
    public function test_the_counter_keeps_the_key_shape_the_framework_reads(): void
    {
        $store = $this->recordingStore();
        $repository = new Repository($store);
        $limiter = new RateLimiter($repository);
        $counter = new AtomicRateCounter($repository, $limiter);

        $key = 'checkout-quota:user:0123456789abcdef';
        $cleanKey = $limiter->cleanRateLimiterKey($key);

        $this->assertSame(1, $counter->count($key, 3600));
        $this->assertSame(1, $repository->get($cleanKey), 'The counter is stored under the cleaned key.');
        $this->assertNotNull($repository->get($cleanKey.':timer'), 'The timer companion key feeds Retry-After.');
        $this->assertSame(1, (int) $limiter->attempts($key));
        $this->assertGreaterThan(0, $limiter->availableIn($key));
    }

    /**
     * A store that records every blind `put()`. The read-modify-write of
     * `increment()` is deliberately kept out of that record: the increment is
     * the atomic primitive, a `put()` is the blind repair write.
     *
     * @return ArrayStore&object{puts: list<array{key: string, value: mixed}>}
     */
    private function recordingStore(): ArrayStore
    {
        return new class extends ArrayStore
        {
            /** @var list<array{key: string, value: mixed}> */
            public array $puts = [];

            public function add($key, $value, $seconds)
            {
                // The production store (`database`) has a native atomic add;
                // the array store only has the non-atomic get+put fallback
                // inside Repository::add(), which would blur the recording.
                if (! is_null($this->get($key))) {
                    return false;
                }

                parent::put($key, $value, $seconds);

                return true;
            }

            public function put($key, $value, $seconds)
            {
                $this->puts[] = ['key' => $key, 'value' => $value];

                return parent::put($key, $value, $seconds);
            }
        };
    }

    /**
     * A store in the one state `RateLimiter::increment()` repairs with an
     * unconditional `put()`: `add()` loses the race and the first `increment()`
     * returns 1. Every blind `put()` is recorded in `$puts`, kept separate from
     * the read-modify-write `increment()` performs through `parent::put()`.
     *
     * @return ArrayStore&object{puts: list<array{key: string, value: mixed}>}
     */
    private function lostAddRaceStore(): ArrayStore
    {
        return new class extends ArrayStore
        {
            /** @var list<array{key: string, value: mixed}> */
            public array $puts = [];

            public function add($key, $value, $seconds)
            {
                // Losing the add() race in both calls: the counter and the :timer.
                return false;
            }

            public function increment($key, $value = 1)
            {
                // The new value after this worker's increment: 0 -> 1.
                $incremented = (int) ($this->get($key) ?? 0) + $value;

                parent::put($key, $incremented, 60);

                return $incremented;
            }

            public function put($key, $value, $seconds)
            {
                $this->puts[] = ['key' => $key, 'value' => $value];

                return parent::put($key, $value, $seconds);
            }
        };
    }
}
