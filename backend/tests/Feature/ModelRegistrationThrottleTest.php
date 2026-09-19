<?php

namespace Tests\Feature;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Regression guard for the public model-registration rate limiting.
 *
 * Laravel's positional `throttle:<max>,<decay>` uses the shared key
 * `sha1(domain|ip)` for unauthenticated requests, so the model-registration
 * endpoints must use their own named limiter instead — otherwise auth/invite
 * traffic (E2E, NAT) would consume the model budget.
 */
class ModelRegistrationThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_routes_use_the_dedicated_named_limiter(): void
    {
        foreach (['api.model-registration.check', 'api.model-registration.submit'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Route {$name} ist nicht registriert.");

            $middleware = $route->gatherMiddleware();
            $this->assertContains(
                'throttle:model-registration',
                $middleware,
                "Route {$name} nutzt nicht den dedizierten Limiter."
            );
            $this->assertEmpty(
                preg_grep('/^throttle:\d/', $middleware),
                "Route {$name} hat noch einen positionalen Throttle."
            );
        }
    }

    public function test_limiter_is_registered_with_its_own_ip_key(): void
    {
        $limiter = RateLimiter::limiter('model-registration');
        $this->assertNotNull($limiter, 'Limiter "model-registration" ist nicht registriert.');

        $request = Request::create('/api/model-registration/abc', 'GET', server: ['REMOTE_ADDR' => '203.0.113.7']);
        $limit = $limiter($request);

        $this->assertInstanceOf(Limit::class, $limit);
        $this->assertSame('203.0.113.7', $limit->key);
        $this->assertSame((int) config('app.throttle_model_registration', 10), $limit->maxAttempts);
        $this->assertGreaterThan(0, $limit->maxAttempts, 'Der Limiter darf nicht unbegrenzt sein.');
    }

    public function test_model_registration_ignores_the_shared_positional_throttle_bucket(): void
    {
        // Exhaust the positional bucket that auth/invite routes share
        // (`sha1(domain|ip)`) far beyond the model limit.
        for ($i = 0; $i < 50; $i++) {
            RateLimiter::hit(sha1('|127.0.0.1'), 60);
        }

        // The public endpoint still has its own budget → 404 (unknown token), not 429.
        $this->getJson('/api/model-registration/unknown-token')->assertNotFound();
    }

    public function test_model_registration_limiter_enforces_its_configured_limit(): void
    {
        config(['app.throttle_model_registration' => 2]);

        $this->getJson('/api/model-registration/unknown-token')->assertNotFound();
        $this->getJson('/api/model-registration/unknown-token')->assertNotFound();
        $this->getJson('/api/model-registration/unknown-token')->assertStatus(429);
    }
}
