<?php

namespace Tests\Feature\Authorization;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RoleAbortTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user->roles()->attach($role);
        return $user;
    }

    public function test_super_admin_can_access_protected_routes(): void
    {
        $superAdmin = $this->createUserWithRole(UserRole::SUPER_ADMIN->value);
        $token = auth('api')->login($superAdmin);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/customers')
            ->assertStatus(200);
    }

    public function test_admin_gets_403_on_super_admin_routes(): void
    {
        $admin = $this->createUserWithRole(UserRole::ADMIN->value);
        $token = auth('api')->login($admin);

        // Customers CRUD
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/customers')
            ->assertStatus(403);

        // License catalog use cases
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/settings/license-use-cases', [
                'name' => 'Test Use Case',
            ])
            ->assertStatus(403);

        // Products CRUD
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/products')
            ->assertStatus(403);
    }

    public function test_photographer_gets_403_on_super_admin_routes(): void
    {
        $photographer = $this->createUserWithRole(UserRole::PHOTOGRAPHER->value);
        $token = auth('api')->login($photographer);

        // Customers CRUD
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/customers')
            ->assertStatus(403);

        // License catalog modifiers
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/settings/license-modifiers', [
                'name' => 'Test Modifier',
            ])
            ->assertStatus(403);

        // Text Snippets CRUD
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/text-snippets')
            ->assertStatus(403);
    }

    public function test_client_gets_403_on_super_admin_routes(): void
    {
        $client = $this->createUserWithRole(UserRole::CLIENT->value);
        $token = auth('api')->login($client);

        // Customers CRUD
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/customers')
            ->assertStatus(403);

        // Products CRUD
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/products', [
                'name' => 'Hacked Product',
            ])
            ->assertStatus(403);

        // Payout management
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/payouts')
            ->assertStatus(403);
    }
}
