<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsAndStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_stats()
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));
        $token = auth('api')->login($admin);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/management/stats');
        $response->assertStatus(200);
    }

    public function test_client_cannot_access_stats()
    {
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/management/stats');
        $response->assertStatus(403);
    }
}
