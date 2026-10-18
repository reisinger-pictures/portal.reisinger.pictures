<?php

namespace Tests\Feature\Contract;

use App\Enums\Brand;
use App\Http\Middleware\AtomicThrottleRequests;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Deterministic half of SEC-20261018-12.
 *
 * The measured defect was a non-atomic check-then-increment in
 * `ThrottleRequests::handleRequest()`; the real concurrency evidence lives in
 * tests/scripts/contract-content-mariadb-probe.sh. This file pins the part that
 * can be proven without parallelism:
 *
 *  - the decision uses the counter after this request was counted, so a counter
 *    that already sits at the limit rejects before the wrapped handler runs —
 *    for the contract content route that means no `heartbeat` audit row,
 *  - the counter is never written blindly. `RateLimiter::hit()` performs such a
 *    write (`put($key, 1, ...)`) whenever a worker that lost the `add()` race
 *    increments from 0 to 1; landing late, it rolls the counter back and
 *    re-opens the budget. The last test demonstrates both the write and its
 *    absence in this middleware,
 *  - a request whose count cannot be determined is refused instead of admitted.
 */
class AtomicThrottleRequestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_counter_at_the_limit_is_rejected_before_the_handler_runs(): void
    {
        $repository = new Repository(new ArrayStore);
        $limiter = $this->namedLimiter($repository, 'probe-token', maxAttempts: 30);
        $key = $this->counterKey('probe', 'probe-token');

        // A live window that already used its whole budget.
        $repository->put($key, 30, 60);
        $repository->put($key.':timer', time() + 60, 60);

        $handlerCalls = 0;

        try {
            (new AtomicThrottleRequests($limiter, $repository))->handle(
                Request::create('/api/contracts/sign/probe-token', 'GET'),
                function () use (&$handlerCalls) {
                    $handlerCalls++;

                    return response('handler ran');
                },
                'probe'
            );

            $this->fail('The middleware allowed a request while the counter was at the limit.');
        } catch (ThrottleRequestsException $exception) {
            $this->assertSame(429, $exception->getStatusCode());
            $this->assertSame(0, $handlerCalls, 'A throttled request must not reach the wrapped handler.');
            $this->assertGreaterThan(0, (int) $exception->getHeaders()['Retry-After']);
            $this->assertSame(30, $exception->getHeaders()['X-RateLimit-Limit']);
            $this->assertSame(0, $exception->getHeaders()['X-RateLimit-Remaining']);
        }
    }

    public function test_the_request_that_reaches_the_limit_exactly_still_runs(): void
    {
        $repository = new Repository(new ArrayStore);
        $limiter = $this->namedLimiter($repository, 'probe-token', maxAttempts: 30);
        $key = $this->counterKey('probe', 'probe-token');

        $repository->put($key, 29, 60);
        $repository->put($key.':timer', time() + 60, 60);

        $response = (new AtomicThrottleRequests($limiter, $repository))->handle(
            Request::create('/api/contracts/sign/probe-token', 'GET'),
            fn () => response('handler ran'),
            'probe'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(30, (int) $limiter->attempts($key));
        $this->assertSame('30', (string) $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('0', (string) $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_a_request_whose_count_cannot_be_determined_is_refused(): void
    {
        $repository = new class extends Repository
        {
            public function __construct()
            {
                parent::__construct(new ArrayStore);
            }

            public function increment($key, $value = 1)
            {
                // Simulates the counter row vanishing between `add()` and
                // `increment()`; the request must not be admitted uncounted.
                return false;
            }
        };
        $limiter = $this->namedLimiter($repository, 'probe-token', maxAttempts: 30);
        $repository->put($this->counterKey('probe', 'probe-token'), 5, 60);

        $handlerCalls = 0;

        try {
            (new AtomicThrottleRequests($limiter, $repository))->handle(
                Request::create('/api/contracts/sign/probe-token', 'GET'),
                function () use (&$handlerCalls) {
                    $handlerCalls++;

                    return response('handler ran');
                },
                'probe'
            );

            $this->fail('An uncountable request was admitted.');
        } catch (ThrottleRequestsException $exception) {
            $this->assertSame(429, $exception->getStatusCode());
            $this->assertSame(0, $handlerCalls);
        }
    }

    public function test_the_counter_is_never_written_blindly(): void
    {
        $store = new class extends ArrayStore
        {
            /** @var list<array{key: string, value: mixed}> */
            public array $puts = [];

            public function add($key, $value, $seconds)
            {
                // The production store (`database`) has a native atomic add; the
                // array store only has the non-atomic get+put fallback inside
                // Repository::add(), which would blur the recording below.
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
        $repository = new Repository($store);

        $limiter = new RateLimiter($repository);
        $limiter->for('probe', fn () => Limit::perMinute(2)->by('probe-token'));
        $limiter->for('framework', fn () => Limit::perMinute(2)->by('framework-token'));
        $middleware = new AtomicThrottleRequests($limiter, $repository);
        $request = Request::create('/api/contracts/sign/probe-token', 'GET');
        $allowed = 0;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $middleware->handle($request, function () use (&$allowed) {
                    $allowed++;

                    return response('handler ran');
                }, 'probe');
            } catch (ThrottleRequestsException) {
                // The third request is over the limit of two.
            }
        }

        $this->assertSame(2, $allowed);
        $this->assertSame([], $store->puts, 'Counting must not overwrite the counter with a blind write.');
        $this->assertSame(3, (int) $limiter->attempts($this->counterKey('probe', 'probe-token')));

        // Counter-example: the framework's own counting writes the counter
        // unconditionally as soon as a worker that lost the `add()` race
        // increments from 0 to 1. Landing after another worker's increment,
        // that write rolls the counter back.
        $frameworkKey = $this->counterKey('framework', 'framework-token');
        $repository->add($frameworkKey, 0, 60);

        $this->assertSame(1, $limiter->hit($frameworkKey, 60));
        $this->assertSame(
            [['key' => $frameworkKey, 'value' => 1]],
            $store->puts,
            'RateLimiter::hit() performs the blind counter write this middleware avoids.'
        );
    }

    public function test_the_lost_add_race_does_not_produce_a_blind_counter_write(): void
    {
        // `RateLimiter::increment()` repairs exactly one state with an
        // unconditional `put()`: a worker that lost the `add()` race and then
        // increments the counter from 0 to 1. The store below reports that state
        // — `add()` returns false and the first `increment()` returns 1 — and
        // records every `put()`. The preceding test only reaches the write on
        // the framework side; this one runs the middleware *in* the race state.
        $store = $this->lostAddRaceStore();
        $repository = new Repository($store);
        $limiter = new RateLimiter($repository);
        $limiter->for('race', fn () => Limit::perMinute(30)->by('race-token'));
        $key = $this->counterKey('race', 'race-token');

        $response = (new AtomicThrottleRequests($limiter, $repository))->handle(
            Request::create('/api/contracts/sign/race-token', 'GET'),
            fn () => response('handler ran'),
            'race'
        );

        $this->assertSame(200, $response->getStatusCode(), 'A single counted request stays under the limit.');
        $this->assertSame(1, (int) $limiter->attempts($key), 'The lost-race request is counted exactly once.');
        $this->assertSame(
            [],
            $store->puts,
            'In the lost-race state the middleware must not repair the counter with the blind write RateLimiter::hit() performs.'
        );

        // Counter-example in the very same state: the framework's own counting
        // does perform that write, which is what makes the empty `puts` above a
        // property of the middleware and not of the store.
        $frameworkStore = $this->lostAddRaceStore();
        $frameworkLimiter = new RateLimiter(new Repository($frameworkStore));
        $frameworkKey = $this->counterKey('framework', 'framework-token');

        $this->assertSame(1, $frameworkLimiter->hit($frameworkKey, 60));
        $this->assertSame(
            [['key' => $frameworkKey, 'value' => 1]],
            $frameworkStore->puts,
            'RateLimiter::hit() writes the counter unconditionally in the lost-race state.'
        );
    }

    public function test_the_positional_throttle_form_counts_before_it_decides(): void
    {
        $repository = new Repository(new ArrayStore);
        $limiter = new RateLimiter($repository);
        $middleware = new AtomicThrottleRequests($limiter, $repository);
        $request = Request::create('/api/probe', 'GET');
        $request->setRouteResolver(fn () => new Route(['GET'], '/api/probe', []));
        $allowed = 0;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $middleware->handle($request, function () use (&$allowed) {
                $allowed++;

                return response('handler ran');
            }, '2', '1');
        }

        try {
            $middleware->handle($request, function () use (&$allowed) {
                $allowed++;

                return response('handler ran');
            }, '2', '1');

            $this->fail('The third request passed a limit of two.');
        } catch (ThrottleRequestsException $exception) {
            $this->assertSame(429, $exception->getStatusCode());
            $this->assertSame(2, $allowed, 'Only the two admitted requests may run the handler.');
            $this->assertSame(3, (int) $limiter->attempts($this->positionalKey($request)));
            $this->assertGreaterThan(0, (int) $exception->getHeaders()['Retry-After']);
        }
    }

    public function test_the_contract_content_route_uses_the_count_first_middleware(): void
    {
        $route = app('router')->getRoutes()->getByName('api.contracts.sign.content');

        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();

        $this->assertContains(AtomicThrottleRequests::class.':contract-content', $middleware);
        $this->assertNotContains('throttle:contract-content', $middleware, 'The racy framework middleware must not remain on this route.');
    }

    public function test_a_throttled_contract_content_request_writes_no_heartbeat(): void
    {
        BrandRegistry::set(Brand::B2B);
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $template = Contract::factory()->template()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
            'items' => [],
            'discounts' => [],
            'terms_html' => '<p>Template terms</p>',
            'available_roles' => ['Model'],
            'expires_at' => now()->addDays(30),
        ]);
        $contract = Contract::factory()->active()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
            'template_id' => $template->id,
            'items' => [[
                'type' => 'item',
                'description' => 'Contract content',
                'qty' => 1,
                'price' => 10000,
            ]],
            'discounts' => [],
            'terms_html' => '<p>Active contract terms</p>',
            'available_roles' => ['Model'],
            'expires_at' => now()->addDays(20),
            'closes_at' => now()->addDays(10),
        ]);
        $token = Str::random(64);
        $signer = ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'personal_token' => $token,
            'status' => 'joined',
            'roles' => ['Model'],
        ]);

        try {
            for ($poll = 0; $poll < 30; $poll++) {
                $this->getJson('/api/contracts/sign/'.$token)
                    ->assertOk()
                    ->assertJsonPath('contract.terms_html', '<p>Active contract terms</p>');
            }

            $throttled = $this->getJson('/api/contracts/sign/'.$token)
                ->assertStatus(429);

            $heartbeats = DB::table('contract_audit_logs')
                ->where('contract_signer_id', $signer->id)
                ->where('action', 'heartbeat')
                ->count();

            $this->assertSame(30, $heartbeats, 'The throttled request must not write a heartbeat.');
            $this->assertGreaterThan(0, (int) $throttled->headers->get('Retry-After'));
        } finally {
            DB::table('contract_audit_logs')->whereIn('contract_id', [$contract->id, $template->id])->delete();
            DB::table('contract_signers')->where('contract_id', $contract->id)->delete();
            Contract::query()->whereKey($contract->id)->delete();
            Contract::query()->whereKey($template->id)->delete();
            $user->delete();
        }
    }

    private function namedLimiter(Repository $repository, string $by, int $maxAttempts): RateLimiter
    {
        $limiter = new RateLimiter($repository);
        $limiter->for('probe', fn () => Limit::perMinute($maxAttempts)->by($by));

        return $limiter;
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

    private function counterKey(string $limiterName, string $limitKey): string
    {
        // Mirrors ThrottleRequests::handleRequestUsingNamedLimiter().
        return md5($limiterName.$limitKey);
    }

    private function positionalKey(Request $request): string
    {
        // Mirrors ThrottleRequests::resolveRequestSignature() for an anonymous
        // request: sha1(domain|ip).
        return sha1($request->route()->getDomain().'|'.$request->ip());
    }
}
