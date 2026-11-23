<?php

namespace Tests\Unit\Auth;

use App\Enums\Brand;
use App\Models\User;
use App\Services\Auth\AuthTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_defaults_to_sanctum_without_the_env_flag(): void
    {
        // Assert the declared default in config/auth.php, independent of any
        // AUTH_DRIVER override used to run a particular suite.
        $config = (string) file_get_contents(config_path('auth.php'));
        $this->assertStringContainsString("env('AUTH_DRIVER', 'sanctum')", $config);

        config()->set('auth.migration.driver', 'sanctum');
        $service = app(AuthTokenService::class);

        $this->assertSame(AuthTokenService::DRIVER_SANCTUM, $service->driver());
        $this->assertTrue($service->isSanctum());
    }

    public function test_a_stray_driver_value_cannot_restore_the_removed_legacy_mode(): void
    {
        config()->set('auth.migration.driver', 'typo');

        $service = app(AuthTokenService::class);

        $this->assertSame(AuthTokenService::DRIVER_SANCTUM, $service->driver());
        $this->assertTrue($service->isSanctum());
    }

    public function test_issue_and_verify_use_a_persisted_sanctum_access_token(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $service = app(AuthTokenService::class);

        $token = $service->issue($user);
        $record = PersonalAccessToken::findToken($token);

        $this->assertInstanceOf(PersonalAccessToken::class, $record);
        $this->assertSame('api', $record->name);
        $this->assertEqualsWithDelta(
            now()->addMinutes($service->accessTtlMinutes())->timestamp,
            $record->expires_at?->timestamp,
            5,
        );

        $resolved = $service->verify($token);
        $this->assertInstanceOf(User::class, $resolved);
        $this->assertSame($user->getKey(), $resolved->getKey());
    }

    public function test_refresh_rotates_the_credential_and_invalidates_the_old_one(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $service = app(AuthTokenService::class);

        $access = $service->issue($user);
        $rotated = $service->refresh($access);

        $this->assertIsString($rotated);
        $this->assertNotSame($access, $rotated);
        $this->assertNull($service->verify($access));
        $this->assertInstanceOf(User::class, $service->verify($rotated));
    }
}
