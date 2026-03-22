<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UserControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_admin_can_create_user() {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'admin']));
        $token = auth('api')->login($admin);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->postJson('/api/management/users', ['name' => 'Test User', 'email' => 'test@test.com']);
        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['email' => 'test@test.com']);
    }

    public function test_photographer_cannot_create_user() {
        $photog = User::factory()->create();
        $photog->roles()->attach(Role::firstOrCreate(['name' => 'photographer']));
        $token = auth('api')->login($photog);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->postJson('/api/management/users', ['name' => 'Test User', 'email' => 'test@test.com']);
        $response->assertStatus(403);
    }
}