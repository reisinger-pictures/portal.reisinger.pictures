<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GalleryGroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_photographer_can_create_gallery_group()
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/gallery-groups', ['name' => 'Wedding 2024']);
        $response->assertStatus(200);
        $this->assertDatabaseHas('gallery_groups', ['name' => 'Wedding 2024']);
    }

    public function test_client_cannot_create_gallery_group()
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/gallery-groups', ['name' => 'Hacked Group']);
        $response->assertStatus(403);
    }
}
