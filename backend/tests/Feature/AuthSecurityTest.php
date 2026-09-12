<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regression: P1 finding — AuthController hardening.
 *  - login validates required input (422 instead of 500)
 *  - password reset tokens expire after config('auth.passwords.users.expire')
 *  - reset endpoint does not leak whether an email is the system admin
 */
class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_without_email_returns_422_instead_of_500(): void
    {
        $response = $this->postJson('/api/auth/login', ['password' => 'secret']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_login_without_password_returns_422_instead_of_500(): void
    {
        $response = $this->postJson('/api/auth/login', ['email' => 'someone@example.com']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
    }

    public function test_expired_password_reset_token_is_rejected(): void
    {
        $user = User::factory()->create(['password' => null, 'brand' => 'rp']);

        $tokenValue = 'expired-reset-token';
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($tokenValue),
            'created_at' => now()->subMinutes((int) config('auth.passwords.users.expire', 60) + 1),
        ]);

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $tokenValue,
            'password' => 'newPassword123',
        ]);

        $response->assertStatus(400);
        $this->assertNull($user->fresh()->password, 'Expired token must not set a password');
    }

    public function test_fresh_password_reset_token_is_accepted(): void
    {
        $user = User::factory()->create(['password' => null, 'brand' => 'rp']);

        $tokenValue = 'fresh-reset-token';
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($tokenValue),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $tokenValue,
            'password' => 'newPassword123',
        ]);

        $response->assertStatus(200);
        $this->assertTrue(Hash::check('newPassword123', $user->fresh()->password));
    }

    public function test_reset_password_does_not_leak_system_admin_identity(): void
    {
        Config::set('admin.email', 'admin@example.com');

        $adminResponse = $this->postJson('/api/auth/reset-password', [
            'email' => 'admin@example.com',
            'token' => 'arbitrary-token',
            'password' => 'newPassword123',
        ]);

        $unknownResponse = $this->postJson('/api/auth/reset-password', [
            'email' => 'nobody@example.com',
            'token' => 'arbitrary-token',
            'password' => 'newPassword123',
        ]);

        // Same status and same message — no oracle distinguishing the system admin.
        $adminResponse->assertStatus(400);
        $unknownResponse->assertStatus(400);
        $this->assertSame(
            $unknownResponse->json('error'),
            $adminResponse->json('error')
        );
    }
}
