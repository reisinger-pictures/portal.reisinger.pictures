<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Http\Middleware\BrandContextMiddleware;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * U-01: Login Brand-Mismatch Rejection.
 *
 * A user with a non-null brand (brand-bound) must be rejected when trying to log in at a
 * portal of a different brand. Only cross-brand users (brand=null, i.e. Super-Admin) may
 * log in at any portal.
 *
 * @see features/infrastructure/15-strict-user-brand-isolation.md
 */
class BrandLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_rp_user_cannot_login_at_srp_portal(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('secret'),
            'brand' => Brand::B2B,
        ]);
        BrandRegistry::set(Brand::SRP);

        $response = $this->withoutMiddleware(BrandContextMiddleware::class)
            ->postJson('/api/auth/login', $this->credentials($user));

        $response->assertStatus(403);
        $response->assertJsonPath('error', 'Dieser Account ist für ein anderes Portal registriert.');
    }

    public function test_srp_user_cannot_login_at_rp_portal(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('secret'),
            'brand' => Brand::SRP,
        ]);

        $response = $this->postJson('/api/auth/login', $this->credentials($user));

        $response->assertStatus(403);
        $response->assertJsonPath('error', 'Dieser Account ist für ein anderes Portal registriert.');
    }

    public function test_super_admin_can_login_at_rp_portal(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('secret'),
            'brand' => null,
        ]);

        $response = $this->postJson('/api/auth/login', $this->credentials($user));

        $response->assertStatus(200);
        $response->assertCookie('rp_jwt');
    }

    public function test_super_admin_can_login_at_srp_portal(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('secret'),
            'brand' => null,
        ]);
        BrandRegistry::set(Brand::SRP);

        $response = $this->withoutMiddleware(BrandContextMiddleware::class)
            ->postJson('/api/auth/login', $this->credentials($user));

        $response->assertStatus(200);
        $response->assertCookie('rp_jwt');
    }

    public function test_rp_user_can_login_at_rp_portal(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('secret'),
            'brand' => Brand::B2B,
        ]);

        $response = $this->postJson('/api/auth/login', $this->credentials($user));

        $response->assertStatus(200);
        $response->assertCookie('rp_jwt');
    }

    public function test_srp_user_can_login_at_srp_portal(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('secret'),
            'brand' => Brand::SRP,
        ]);
        BrandRegistry::set(Brand::SRP);

        $response = $this->withoutMiddleware(BrandContextMiddleware::class)
            ->postJson('/api/auth/login', $this->credentials($user));

        $response->assertStatus(200);
        $response->assertCookie('rp_jwt');
    }

    public function test_wrong_password_still_returns_401_not_403(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('secret'),
            'brand' => Brand::B2B,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        // Wrong password must return 401 before brand check is reached.
        $response->assertStatus(401);
    }

    private function credentials(User $user): array
    {
        return ['email' => $user->email, 'password' => 'secret'];
    }
}
