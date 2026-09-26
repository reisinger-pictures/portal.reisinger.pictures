<?php

namespace Tests\Feature\Middleware;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagementMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_management_route_without_token_returns_401_not_500(): void
    {
        // H4 regression: without a JWT, auth()->user() is null. Before the fix
        // the middleware dereferenced null and returned a 500. It must return 401.
        $response = $this->getJson('/api/management/galleries');

        $response->assertStatus(401);
    }

    public function test_client_gets_403_on_management_route(): void
    {
        // A client has no admin/photographer/org-admin role → management middleware must 403.
        $client = User::factory()->create();
        $client->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));

        $token = auth('api')->login($client);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/galleries');

        $response->assertStatus(403);
    }

    public function test_admin_can_access_management_route(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        $token = auth('api')->login($admin);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/galleries');

        $response->assertStatus(200);
    }
}
