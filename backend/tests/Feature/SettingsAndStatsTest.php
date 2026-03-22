<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SettingsAndStatsTest extends TestCase {
    use RefreshDatabase;

    public function test_admin_can_access_stats() {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'admin']));
        $token = auth('api')->login($admin);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/management/stats');
        $response->assertStatus(200);
    }

    public function test_client_cannot_access_stats() {
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/management/stats');
        $response->assertStatus(403);
    }
}