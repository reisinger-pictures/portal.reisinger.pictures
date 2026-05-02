<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GalleryGroupTest extends TestCase {
    use RefreshDatabase;

    public function test_photographer_can_create_gallery_group() {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::PHOTOGRAPHER->value]));
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->postJson('/api/management/gallery-groups', ['name' => 'Wedding 2024']);
        $response->assertStatus(200);
        $this->assertDatabaseHas('gallery_groups', ['name' => 'Wedding 2024']);
    }

    public function test_client_cannot_create_gallery_group() {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::CLIENT->value]));
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->postJson('/api/management/gallery-groups', ['name' => 'Hacked Group']);
        $response->assertStatus(403);
    }
}