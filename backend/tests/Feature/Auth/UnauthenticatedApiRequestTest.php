<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Access contract for anonymous requests to `auth:api` routes, independent of
 * the `Accept` header.
 *
 * This app is API-only and has no `login` named route, but Laravel's
 * `ApplicationBuilder::withMiddleware()` installs the framework default
 * `redirectGuestsTo(fn () => route('login'))` before the application callback
 * runs (vendor/laravel/framework .../Configuration/ApplicationBuilder.php:291).
 * For a request that does not ask for JSON, `Authenticate::redirectTo()`
 * therefore resolved `route('login')` while *building* the
 * `AuthenticationException` — a `RouteNotFoundException` raised inside the
 * middleware, i.e. before the `AuthenticationException` is ever thrown. The
 * kernel catches and reports that `RouteNotFoundException` and renders it as a
 * 500, so the configured render callback never sees it. Production answered
 * 500 for `curl https://…/api/auth/me` (curl sends the wildcard accept
 * `*`+`/`+`*` by default), while the same call with
 * `Accept: application/json` answered 401 (measured 2026-10-18).
 *
 * Notation: the wildcard accept value is written `*`+`/`+`*` throughout this
 * file — its raw three characters would terminate the enclosing docblock.
 *
 * These tests pin both halves: no JSON accept → 401, and the JSON client path
 * keeps the same status and the same body.
 */
class UnauthenticatedApiRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The production reproduction: `curl` without an explicit `Accept` header
     * sends the wildcard `*`+`/`+`*`, which is not a JSON accept. The
     * header is set explicitly here and asserted below, because a test client
     * that silently asked for JSON would measure the already-working path
     * instead of the broken one.
     */
    public function test_anonymous_non_json_request_to_auth_me_answers_401(): void
    {
        $response = $this->call('GET', '/api/auth/me', [], [], [], ['HTTP_ACCEPT' => '*/*']);

        $this->assertSame('*/*', app('request')->header('Accept'));
        $this->assertFalse(
            app('request')->expectsJson(),
            'Der Testclient sendet einen JSON-Accept und misst damit den falschen Pfad.'
        );

        $response->assertStatus(401);
    }

    /**
     * The second half of the production pair: the same measurement for the
     * `super_admin`-gated endpoint from D-27, which also answered 500 for a
     * non-JSON anonymous request.
     */
    public function test_anonymous_non_json_request_to_a_super_admin_route_answers_401(): void
    {
        $response = $this->call('GET', '/api/settings/license-terms', [], [], [], ['HTTP_ACCEPT' => '*/*']);

        $this->assertFalse(app('request')->expectsJson());

        $response->assertStatus(401);
    }

    /**
     * The non-JSON path must not degrade into an empty 401 while JSON clients
     * keep their body: both carry the identical status and JSON payload.
     */
    public function test_anonymous_non_json_request_carries_the_same_json_body_as_a_json_client(): void
    {
        $nonJson = $this->call('GET', '/api/auth/me', [], [], [], ['HTTP_ACCEPT' => '*/*']);
        $this->assertFalse(app('request')->expectsJson());

        $json = $this->getJson('/api/auth/me');
        $this->assertTrue(app('request')->expectsJson());

        $this->assertSame(401, $nonJson->getStatusCode());
        $this->assertSame(401, $json->getStatusCode());
        $this->assertSame('application/json', $nonJson->headers->get('Content-Type'));
        $this->assertSame(['message' => 'Unauthenticated.'], $nonJson->json());
        $this->assertSame($json->json(), $nonJson->json());
    }

    /**
     * The authenticated/authorization path must stay untouched by this fix:
     * a non-super-admin still gets the same JSON 403 without a JSON accept
     * header (`SuperAdminMiddleware` renders it itself, so this pins that the
     * change did not reroute it through the exception handler).
     */
    public function test_authenticated_non_super_admin_without_json_accept_still_answers_json_403(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));

        $response = $this->call('GET', '/api/settings/license-terms', [], [], [], [
            'HTTP_ACCEPT' => '*/*',
            'HTTP_AUTHORIZATION' => 'Bearer '.auth('api')->login($user),
        ]);

        $this->assertFalse(app('request')->expectsJson());

        $response->assertStatus(403);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertExactJson(['error' => 'Forbidden']);
    }
}
