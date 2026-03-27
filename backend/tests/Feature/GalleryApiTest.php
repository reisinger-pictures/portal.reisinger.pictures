<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

class GalleryApiTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithRole(string $roleName)
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user->roles()->attach($role);
        return $user;
    }

    public function test_photographer_can_create_gallery(): void
    {
        $photographer = $this->createUserWithRole('photographer');
        $token = Auth::guard('api')->login($photographer);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/management/galleries', [
                'name' => 'Hochzeit Müller',
                'type' => 'delivery',
                'is_public' => false
            ]);

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('gallery.name', 'Hochzeit Müller')
                 ->assertJsonMissing(['password_hash']); // Leak Prevention Test
                 
        $this->assertDatabaseHas('galleries', ['name' => 'Hochzeit Müller']);
    }

    public function test_pure_admin_cannot_create_gallery(): void
    {
        $admin = $this->createUserWithRole('admin');
        $token = Auth::guard('api')->login($admin);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/management/galleries', [
                'name' => 'Admin Gallery',
                'type' => 'delivery'
            ]);

        $response->assertStatus(403);
    }

    public function test_client_cannot_create_gallery(): void
    {
        $client = $this->createUserWithRole('client');
        $token = Auth::guard('api')->login($client);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/management/galleries', [
                'name' => 'Client Gallery',
                'type' => 'selection'
            ]);

        $response->assertStatus(403);
    }

    public function test_guest_cannot_create_gallery(): void
    {
        $response = $this->postJson('/api/management/galleries', [
            'name' => 'Hacker Gallery',
            'type' => 'delivery'
        ]);

        $response->assertStatus(401);
    }
}
