<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_login(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('secret'),
            'brand' => null,
        ]);

        $response = $this->postJson('/api/auth/login', $this->credentials($user));

        $response->assertStatus(200);
        $response->assertCookie('rp_jwt');
    }

    public function test_rp_user_can_login(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('secret'),
            'brand' => Brand::B2B,
        ]);

        $response = $this->postJson('/api/auth/login', $this->credentials($user));

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

        $response->assertStatus(401);
    }

    private function credentials(User $user): array
    {
        return ['email' => $user->email, 'password' => 'secret'];
    }
}
