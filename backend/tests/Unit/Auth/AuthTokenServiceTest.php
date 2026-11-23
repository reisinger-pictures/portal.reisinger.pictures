<?php

namespace Tests\Unit\Auth;

use App\Models\User;
use App\Services\Auth\AuthTokenService;
use Illuminate\Support\Facades\Auth;
use Mockery;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Tests\TestCase;

class AuthTokenServiceTest extends TestCase
{
    public function test_driver_defaults_to_legacy_without_the_env_flag(): void
    {
        $service = app(AuthTokenService::class);

        $this->assertSame(AuthTokenService::DRIVER_LEGACY, $service->driver());
        $this->assertFalse($service->isSanctum());
    }

    public function test_unknown_driver_value_falls_back_to_legacy(): void
    {
        config()->set('auth.migration.driver', 'typo');

        $this->assertSame(AuthTokenService::DRIVER_LEGACY, app(AuthTokenService::class)->driver());
    }

    public function test_legacy_issue_delegates_to_the_jwt_guard_without_creating_a_sanctum_token(): void
    {
        $guard = Mockery::mock(JWTGuard::class);
        $guard->shouldReceive('login')->once()->andReturn('jwt-token');
        Auth::shouldReceive('guard')->once()->with('api')->andReturn($guard);

        $user = Mockery::mock(User::class);
        $user->shouldNotReceive('createToken');

        $this->assertSame('jwt-token', app(AuthTokenService::class)->issue($user));
    }

    public function test_legacy_verify_delegates_to_the_jwt_guard_user(): void
    {
        $user = new User;
        $guard = Mockery::mock(JWTGuard::class);
        $guard->shouldReceive('setToken')->once()->with('jwt-token')->andReturnSelf();
        $guard->shouldReceive('user')->once()->andReturn($user);
        Auth::shouldReceive('guard')->once()->with('api')->andReturn($guard);

        $this->assertSame($user, app(AuthTokenService::class)->verify('jwt-token'));
    }

    public function test_legacy_refresh_delegates_to_the_jwt_guard_refresh(): void
    {
        $guard = Mockery::mock(JWTGuard::class);
        $guard->shouldReceive('setToken')->once()->with('old-token')->andReturnSelf();
        $guard->shouldReceive('refresh')->once()->andReturn('new-token');
        Auth::shouldReceive('guard')->once()->with('api')->andReturn($guard);

        $this->assertSame('new-token', app(AuthTokenService::class)->refresh('old-token'));
    }
}
