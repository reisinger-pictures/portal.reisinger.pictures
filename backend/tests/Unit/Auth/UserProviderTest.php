<?php

namespace Tests\Unit\Auth;

use App\Enums\Brand;
use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class UserProviderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * D-77: with the JWT-only transient provider removed, the `web` guard's
     * provider must still resolve a registered user by its id.
     */
    public function test_web_guard_provider_resolves_a_registered_user_by_id(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);

        $provider = Auth::createUserProvider((string) config('auth.guards.web.provider'));

        $this->assertInstanceOf(EloquentUserProvider::class, $provider);
        $resolved = $provider->retrieveById($user->getKey());

        $this->assertInstanceOf(User::class, $resolved);
        $this->assertSame($user->getKey(), $resolved->getKey());
    }
}
