<?php

namespace Tests\Feature\RateLimit;

use App\Http\Middleware\AtomicThrottleRequests;
use App\Models\Order;
use App\Models\User;
use App\Services\CheckoutRiskService;
use App\Support\CheckoutKey;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The prioritized rate-limit paths of owner decision D-38.
 *
 * D-38 converts the security- and billing-relevant paths to the count-first
 * pattern and leaves the remaining `throttle:` routes on the framework
 * middleware as a documented limit. Every test below names the assertion that
 * turns red when the atomic counting is replaced by `RateLimiter::hit()`.
 */
class AtomicRateLimitConversionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The behaviour guard of the checkout quota: the attempt is counted
     * before the decision is taken, and a counter at the limit rejects with
     * the unchanged 429 body and `Retry-After`.
     *
     * This test stays green in both variants — that is its purpose: the
     * conversion must not change the observable behaviour. The atomicity proof
     * is the lost-race test below.
     */
    public function test_the_checkout_quota_is_counted_before_it_is_decided(): void
    {
        $store = $this->recordingStore();
        $this->useStore($store);

        config([
            'app.checkout_throttle_user_per_hour' => 2,
            'app.checkout_throttle_ip_per_hour' => 100,
            'app.checkout_throttle_ip_per_day' => 100,
        ]);

        $user = User::factory()->create();
        $request = $this->requestFromIp('198.51.100.70');
        $service = app(CheckoutRiskService::class);
        $key = CheckoutKey::user($user, 'checkout-quota');

        $service->assertCheckoutQuotaAllowed($request, $user);
        $service->assertCheckoutQuotaAllowed($request, $user);

        try {
            $service->assertCheckoutQuotaAllowed($request, $user);
            $this->fail('The checkout quota did not reject the third attempt.');
        } catch (HttpResponseException $exception) {
            $response = $exception->getResponse();

            $this->assertSame(429, $response->getStatusCode());
            $this->assertSame(
                ['error' => 'Checkout ist vorübergehend ausgelastet. Bitte versuche es später erneut.'],
                $response->getData(true),
                'The rejection body must stay byte-for-byte the same.'
            );
            $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        }

        $this->assertSame(
            3,
            (int) RateLimiter::attempts($key),
            'The rejected attempt is counted before the decision is taken.'
        );
        $this->assertSame(
            [],
            $store->puts,
            'The quota counter is never written blindly. The blind repair write only appears in the '
            .'lost-race state, which the next test reproduces.'
        );
    }

    /**
     * The atomicity proof of the checkout quota: in the lost-race state the
     * counter is counted exactly once per attempt and never repaired with the
     * blind write.
     *
     * Regression: the `assertSame([], $store->puts, …)` assertion turns red
     * when the counting is replaced by `RateLimiter::hit()`.
     */
    public function test_the_checkout_quota_counter_is_not_written_blindly_in_the_lost_add_race_state(): void
    {
        // The state SEC-20261018-12 measured: the worker lost the `add()` race.
        // `RateLimiter::hit()` repairs that state with an unconditional
        // `put($key, 1, $decay)`, which can land after other workers counted
        // and roll the counter back.
        $store = $this->lostAddRaceStore();
        $this->useStore($store);

        config([
            'app.checkout_throttle_user_per_hour' => 2,
            'app.checkout_throttle_ip_per_hour' => 100,
            'app.checkout_throttle_ip_per_day' => 100,
        ]);

        $user = User::factory()->create();
        $request = $this->requestFromIp('198.51.100.71');
        $service = app(CheckoutRiskService::class);
        $key = CheckoutKey::user($user, 'checkout-quota');

        $service->assertCheckoutQuotaAllowed($request, $user);
        $service->assertCheckoutQuotaAllowed($request, $user);

        $this->assertDenied(
            fn () => $service->assertCheckoutQuotaAllowed($request, $user),
            429,
            withRetryAfter: true
        );

        $this->assertSame(
            3,
            (int) RateLimiter::attempts($key),
            'The lost-race attempt is counted exactly once per attempt.'
        );
        $this->assertSame(
            [],
            $store->puts,
            'Regression: in the lost-race state the quota counter must not be repaired with the blind '
            .'write RateLimiter::hit() performs. This assertion turns red when the counting is replaced '
            .'by RateLimiter::hit().'
        );
    }

    /**
     * The behaviour guard of the Turnstile attempt counters: the threshold
     * semantics (`< userThreshold` / `< ipThreshold`) and the "no challenge
     * needed" fast path are unchanged.
     *
     * This test stays green in both variants — that is its purpose. The
     * atomicity proof is the lost-race test below.
     */
    public function test_the_turnstile_attempt_counters_keep_their_threshold_semantics(): void
    {
        $this->enableTurnstile();
        config([
            'app.turnstile_user_threshold_per_hour' => 3,
            'app.turnstile_ip_threshold_per_hour' => 5,
        ]);

        $user = User::factory()->create();
        $request = $this->requestFromIp('198.51.100.72');
        $service = app(CheckoutRiskService::class);

        // Below the threshold: the fast path stays open, no challenge needed.
        $service->assertImmediateStripeAllowed($request, $user);
        $service->assertImmediateStripeAllowed($request, $user);

        $this->assertSame(
            2,
            (int) RateLimiter::attempts(CheckoutKey::user($user, 'checkout-risk-attempt'))
        );
        $this->assertSame(
            2,
            (int) RateLimiter::attempts(CheckoutKey::ip('198.51.100.72', 'checkout-risk-attempt'))
        );

        // The third attempt reaches the user threshold and needs the challenge.
        $this->assertDenied(
            fn () => $service->assertImmediateStripeAllowed($request, $user),
            403,
            ['turnstile_required' => true]
        );

        $this->assertSame(
            3,
            (int) RateLimiter::attempts(CheckoutKey::user($user, 'checkout-risk-attempt')),
            'The current attempt is counted, so the threshold counts it as well.'
        );
        $this->assertSame(
            3,
            (int) RateLimiter::attempts(CheckoutKey::ip('198.51.100.72', 'checkout-risk-attempt'))
        );
    }

    /**
     * The atomicity proof of the Turnstile attempt counters.
     *
     * Regression: the `assertSame([], $store->puts, …)` assertion turns red
     * when the counting is replaced by `RateLimiter::hit()`.
     */
    public function test_the_turnstile_attempt_counters_are_not_written_blindly_in_the_lost_add_race_state(): void
    {
        $store = $this->lostAddRaceStore();
        $this->useStore($store);
        $this->enableTurnstile();
        config([
            'app.turnstile_user_threshold_per_hour' => 3,
            'app.turnstile_ip_threshold_per_hour' => 5,
        ]);

        $user = User::factory()->create();
        $request = $this->requestFromIp('198.51.100.73');
        $service = app(CheckoutRiskService::class);

        $service->assertImmediateStripeAllowed($request, $user);

        $this->assertSame(
            1,
            (int) RateLimiter::attempts(CheckoutKey::user($user, 'checkout-risk-attempt')),
            'The lost-race attempt is counted exactly once.'
        );
        $this->assertSame(
            [],
            $store->puts,
            'Regression: in the lost-race state the attempt counters must not be repaired with the blind '
            .'write RateLimiter::hit() performs. This assertion turns red when the counting is replaced '
            .'by RateLimiter::hit().'
        );
    }

    /**
     * The key-shape guard of the failure-velocity counters: the gate reads them
     * with `RateLimiter::attempts()`, so the converted counting must keep the
     * counters readable under the same key shape.
     *
     * This test stays green in both variants — that is its purpose.
     */
    public function test_the_failure_velocity_counter_stays_readable_for_the_turnstile_gate(): void
    {
        $this->enableTurnstile();
        config([
            'app.turnstile_user_threshold_per_hour' => 100,
            'app.turnstile_ip_threshold_per_hour' => 100,
            'app.turnstile_failure_user_threshold_per_hour' => 2,
            'app.turnstile_failure_ip_threshold_per_hour' => 100,
        ]);

        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'ip_address' => '198.51.100.74',
        ]);
        $service = app(CheckoutRiskService::class);

        $service->recordVerifiedPaymentFailure($order);
        $service->recordVerifiedPaymentFailure($order);

        // The gate reads these counters with `RateLimiter::attempts()`, so the
        // converted counting must keep the key shape the framework reads.
        $this->assertSame(
            2,
            (int) RateLimiter::attempts(CheckoutKey::user($user, 'checkout-failure'))
        );
        $this->assertSame(
            2,
            (int) RateLimiter::attempts(CheckoutKey::ip('198.51.100.74', 'checkout-failure'))
        );

        $this->assertDenied(
            fn () => $service->assertImmediateStripeAllowed($this->requestFromIp('198.51.100.74'), $user),
            403,
            ['turnstile_required' => true]
        );
    }

    /**
     * The atomicity proof of the failure-velocity counters: in the lost-race
     * state both buckets are counted exactly once and never repaired with the
     * blind write.
     *
     * A rollback on this counter is the security-relevant one: it lowers the
     * verified payment failure count and re-opens the Turnstile challenge gate
     * for a user or IP with many failed payments.
     *
     * Regression: the `assertSame([], $store->puts, …)` assertion turns red
     * when `countBestEffort()` is replaced by `RateLimiter::hit()`.
     */
    public function test_the_failure_velocity_counters_are_not_written_blindly_in_the_lost_add_race_state(): void
    {
        $store = $this->lostAddRaceStore();
        $this->useStore($store);

        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'ip_address' => '198.51.100.75',
        ]);
        $service = app(CheckoutRiskService::class);

        $service->recordVerifiedPaymentFailure($order);

        $this->assertSame(
            1,
            (int) RateLimiter::attempts(CheckoutKey::user($user, 'checkout-failure')),
            'The lost-race failure is counted exactly once in the user bucket.'
        );
        $this->assertSame(
            1,
            (int) RateLimiter::attempts(CheckoutKey::ip('198.51.100.75', 'checkout-failure')),
            'The lost-race failure is counted exactly once in the IP bucket.'
        );
        $this->assertSame(
            [],
            $store->puts,
            'Regression: in the lost-race state the failure counters must not be repaired with the blind '
            .'write RateLimiter::hit() performs — a rollback here re-opens the Turnstile challenge gate. '
            .'This assertion turns red when countBestEffort() is replaced by RateLimiter::hit().'
        );
    }

    /**
     * The route guard of the coupon validation path: the route carries the
     * count-first middleware and no longer the racy framework one.
     *
     * Regression: both assertions turn red when the route is reverted to
     * `throttle:coupon-validate`.
     */
    public function test_the_coupon_validation_route_uses_the_count_first_middleware(): void
    {
        $route = app('router')->getRoutes()->getByName('api.coupons.validate');

        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();

        $this->assertContains(AtomicThrottleRequests::class.':coupon-validate', $middleware);
        $this->assertNotContains(
            'throttle:coupon-validate',
            $middleware,
            'The racy framework middleware must not remain on this route.'
        );
    }

    /**
     * The atomicity proof of the coupon validation path: the route's own
     * limiter counts the request exactly once and never repairs the counter
     * blindly.
     *
     * Regression: the `assertSame([], $store->puts, …)` assertion turns red
     * when the counting is replaced by `RateLimiter::hit()`.
     */
    public function test_the_coupon_validation_route_counter_is_counted_once_and_never_written_blindly(): void
    {
        $store = $this->lostAddRaceStore();
        $repository = new Repository($store);
        $limiter = $this->limiterFor($repository, 'coupon-validate');
        $middleware = new AtomicThrottleRequests($limiter, $repository);

        $response = $middleware->handle(
            Request::create('/api/coupons/validate', 'POST'),
            fn () => response('handler ran'),
            'coupon-validate'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('10', (string) $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame(
            '9',
            (string) $response->headers->get('X-RateLimit-Remaining'),
            'The request is counted exactly once.'
        );
        $this->assertSame(
            [],
            $store->puts,
            'Regression: in the lost-race state the route counter must not be repaired with the blind '
            .'write RateLimiter::hit() performs. This assertion turns red when the counting is replaced '
            .'by RateLimiter::hit().'
        );
    }

    /**
     * The route guard of the Stripe webhook path: the route carries the
     * count-first middleware and no longer the racy framework one.
     *
     * Regression: both assertions turn red when the route is reverted to
     * `throttle:10,1`.
     */
    public function test_the_stripe_webhook_route_uses_the_count_first_middleware(): void
    {
        $route = app('router')->getRoutes()->getByName('api.webhooks.stripe');

        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();

        $this->assertContains(AtomicThrottleRequests::class.':10,1', $middleware);
        $this->assertNotContains(
            'throttle:10,1',
            $middleware,
            'The racy framework middleware must not remain on this route.'
        );
    }

    /**
     * The atomicity proof of the Stripe webhook path: the positional budget
     * counts the request exactly once and never repairs the counter blindly.
     *
     * Regression: the `assertSame([], $store->puts, …)` assertion turns red
     * when the counting is replaced by `RateLimiter::hit()`.
     */
    public function test_the_stripe_webhook_route_counter_is_counted_once_and_never_written_blindly(): void
    {
        $store = $this->lostAddRaceStore();
        $repository = new Repository($store);
        $limiter = new CacheRateLimiter($repository);
        $middleware = new AtomicThrottleRequests($limiter, $repository);
        $request = Request::create('/api/webhooks/stripe', 'POST');
        $request->setRouteResolver(fn () => new Route(['POST'], '/api/webhooks/stripe', []));

        $response = $middleware->handle(
            $request,
            fn () => response('handler ran'),
            '10',
            '1'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('10', (string) $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame(
            '9',
            (string) $response->headers->get('X-RateLimit-Remaining'),
            'The request is counted exactly once.'
        );
        $this->assertSame(
            [],
            $store->puts,
            'Regression: in the lost-race state the route counter must not be repaired with the blind '
            .'write RateLimiter::hit() performs. This assertion turns red when the counting is replaced '
            .'by RateLimiter::hit().'
        );
    }

    private function requestFromIp(string $ip): Request
    {
        return Request::create('/api/orders/checkout', 'POST', [], [], [], ['REMOTE_ADDR' => $ip]);
    }

    /**
     * A limiter that uses the given store and keeps the application's named
     * limiter definition, so the route's real budget is exercised.
     */
    private function limiterFor(Repository $repository, string $name): CacheRateLimiter
    {
        $definition = app(CacheRateLimiter::class)->limiter($name);

        $this->assertNotNull($definition, "The named limiter {$name} must be registered.");

        $limiter = new CacheRateLimiter($repository);
        $limiter->for($name, $definition);

        return $limiter;
    }

    private function enableTurnstile(): void
    {
        config([
            'services.turnstile.site_key' => 'site-key',
            'services.turnstile.secret' => 'server-secret',
            'services.turnstile.allowed_hostnames' => 'checkout.example.test',
        ]);
    }

    /**
     * Make the application read and write the rate limiters in the given
     * store, so the service counters, the framework reads and the recorded
     * writes all address the same rows.
     */
    private function useStore(ArrayStore $store): Repository
    {
        $repository = new Repository($store);

        $this->app->instance('cache.store', $repository);
        $this->app->instance(CacheRateLimiter::class, new CacheRateLimiter($repository));
        RateLimiter::clearResolvedInstance(CacheRateLimiter::class);

        return $repository;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function assertDenied(
        callable $callback,
        int $status,
        array $json = [],
        bool $withRetryAfter = false,
    ): void {
        try {
            $callback();
            $this->fail('Expected rejection was not thrown.');
        } catch (HttpResponseException $exception) {
            $response = $exception->getResponse();

            $this->assertSame($status, $response->getStatusCode());

            if ($withRetryAfter) {
                $this->assertNotEmpty($response->headers->get('Retry-After'));
            }

            foreach ($json as $key => $value) {
                $this->assertSame($value, $response->getData(true)[$key] ?? null);
            }
        }
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
     * returns 1. Every blind `put()` is recorded in `$puts`.
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
