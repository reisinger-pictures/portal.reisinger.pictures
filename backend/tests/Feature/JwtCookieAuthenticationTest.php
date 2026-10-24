<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Middleware\AuthenticateJwtCookie;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * The auth cookie is the browser's session, and it must win over a request
 * field named `token`.
 *
 * The JWT package reads the `Authorization` header first and a request field
 * named `token` second — both before the cookie parser. Without
 * {@see AuthenticateJwtCookie} a logged-in browser that
 * redeems an invite (the body carries the invite token) is treated as
 * anonymous and answered with the guest validation errors.
 */
class JwtCookieAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create(['brand' => 'rp']);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role->value]));

        return $user;
    }

    private function csrf(): array
    {
        return $this->csrfCredentials();
    }

    public function test_the_auth_cookie_identifies_a_request_whose_body_carries_a_token(): void
    {
        $member = $this->userWithRole(UserRole::CLIENT);
        $jwt = auth('api')->login($member);
        Auth::forgetGuards();
        $csrf = $this->csrf();

        $this->withUnencryptedCookie('rp_jwt', $jwt)
            ->withUnencryptedCookie('XSRF-TOKEN', $csrf['cookie'])
            ->withHeaders($csrf['headers'])
            ->withCredentials()
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $member->id);

        // The token field is data, not a credential: the same request stays
        // authenticated although it names a token that is not a JWT.
        $this->withUnencryptedCookie('rp_jwt', $jwt)
            ->withUnencryptedCookie('XSRF-TOKEN', $csrf['cookie'])
            ->withHeaders($csrf['headers'])
            ->withCredentials()
            ->postJson('/api/org-invites/redeem', ['token' => 'not-a-jwt'])
            ->assertStatus(422)
            ->assertJsonMissingPath('errors.name');
    }

    public function test_an_explicit_bearer_token_keeps_precedence_over_the_cookie(): void
    {
        $cookieUser = $this->userWithRole(UserRole::CLIENT);
        $bearerUser = $this->userWithRole(UserRole::CLIENT);
        $cookieJwt = auth('api')->login($cookieUser);
        $bearerJwt = auth('api')->login($bearerUser);
        Auth::forgetGuards();

        $this->withUnencryptedCookie('rp_jwt', $cookieJwt)
            ->withCredentials()
            ->withHeader('Authorization', 'Bearer '.$bearerJwt)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $bearerUser->id);
    }
}
