<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

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

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/management/galleries', [
                'name' => 'Hochzeit Müller',
                'type' => 'delivery',
                'is_public' => false,
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

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/management/galleries', [
                'name' => 'Admin Gallery',
                'type' => 'delivery',
            ]);

        $response->assertStatus(403);
    }

    public function test_client_cannot_create_gallery(): void
    {
        $client = $this->createUserWithRole('client');
        $token = Auth::guard('api')->login($client);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/management/galleries', [
                'name' => 'Client Gallery',
                'type' => 'selection',
            ]);

        $response->assertStatus(403);
    }

    public function test_guest_cannot_create_gallery(): void
    {
        $response = $this->postJson('/api/management/galleries', [
            'name' => 'Hacker Gallery',
            'type' => 'delivery',
        ]);

        $response->assertStatus(401);
    }

    public function test_gallery_slug_collision_and_expires_at_conversion()
    {
        $photographer = $this->createUserWithRole('photographer');
        $token = Auth::guard('api')->login($photographer);

        // Erste Galerie
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/management/galleries', [
                'name' => 'Kollision',
                'type' => 'delivery',
                'slug' => 'kollision',
            ])->assertStatus(200);

        // Zweite Galerie mit selbem Slug
        $res2 = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/management/galleries', [
                'name' => 'Kollision',
                'type' => 'delivery',
                'slug' => 'kollision',
                'expires_at' => '2026-12-31',
            ]);

        $res2->assertStatus(200);

        $this->assertNotEquals('kollision', $res2->json('gallery.slug'));
        $this->assertStringStartsWith('kollision-', $res2->json('gallery.slug'));
        $this->assertStringContainsString('2026-12-31T23:59:59', $res2->json('gallery.expires_at'));
    }

    public function test_selection_create_normalizes_public_and_free_download_flags(): void
    {
        $photographer = $this->createUserWithRole('photographer');
        $token = Auth::guard('api')->login($photographer);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/management/galleries', [
                'name' => 'Auswahl',
                'type' => 'selection',
                'is_public' => true,
                'is_free_download' => true,
            ])
            ->assertOk();

        $galleryId = $response->json('gallery.id');
        $this->assertDatabaseHas('galleries', [
            'id' => $galleryId,
            'is_public' => false,
            'is_free_download' => false,
        ]);
    }

    public function test_selection_create_does_not_inherit_public_or_free_download_group_flags(): void
    {
        $photographer = $this->createUserWithRole('photographer');
        $group = GalleryGroup::factory()->create([
            'is_public' => true,
            'is_free_download' => true,
        ]);
        $token = Auth::guard('api')->login($photographer);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/management/galleries', [
                'name' => 'Auswahl in Ordner',
                'type' => 'selection',
                'gallery_group_id' => $group->id,
                'is_public' => true,
                'is_free_download' => true,
            ])
            ->assertOk();

        $galleryId = $response->json('gallery.id');
        $this->assertDatabaseHas('galleries', [
            'id' => $galleryId,
            'is_public' => false,
            'is_free_download' => false,
        ]);
    }

    public function test_selection_update_without_type_cannot_enable_public_or_free_download(): void
    {
        $photographer = $this->createUserWithRole('photographer');
        $gallery = Gallery::factory()->create([
            'type' => 'selection',
            'is_public' => false,
            'is_free_download' => false,
        ]);
        $photographer->galleries()->attach($gallery);
        $token = Auth::guard('api')->login($photographer);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->putJson('/api/management/galleries/'.$gallery->id, [
                'is_public' => true,
                'is_free_download' => true,
                'is_live' => true,
            ])
            ->assertOk();

        $this->assertDatabaseHas('galleries', [
            'id' => $gallery->id,
            'is_public' => false,
            'is_free_download' => false,
            'is_live' => false,
        ]);
    }

    public function test_invalid_date_throws_validation_error()
    {
        $photographer = $this->createUserWithRole('photographer');
        $token = Auth::guard('api')->login($photographer);

        $res = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/management/galleries', [
                'name' => 'Invalid Date',
                'type' => 'delivery',
                'expires_at' => 'Kein Datum',
            ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['expires_at']);
    }
}
