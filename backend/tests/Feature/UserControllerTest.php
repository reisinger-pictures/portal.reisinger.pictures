<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MailpitAssertions;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use MailpitAssertions, RefreshDatabase;

    public function test_admin_can_create_user()
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));
        $token = auth('api')->login($admin);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/users', ['name' => 'Test User', 'email' => 'test@test.com']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['email' => 'test@test.com']);

        $this->assertMailpitSentTo('test@test.com');
    }

    public function test_partial_update_preserves_roles()
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));
        $token = auth('api')->login($admin);

        $clientRole = Role::firstOrCreate(['name' => UserRole::CLIENT->value]);
        $photogRole = Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]);
        $client = User::factory()->create(['flatrate_level' => 'none', 'brand' => 'rp']);
        $client->roles()->attach([$clientRole->id, $photogRole->id]);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/users/{$client->id}", [
                'flatrate_level' => 'print',
            ]);

        $response->assertOk();
        $this->assertEquals('print', $client->fresh()->flatrate_level);
        $this->assertTrue($client->fresh()->roles()->pluck('name')->contains('client'));
        $this->assertTrue($client->fresh()->roles()->pluck('name')->contains('photographer'));
    }

    public function test_partial_update_preserves_gallery_assignments()
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));
        $token = auth('api')->login($admin);

        $clientRole = Role::firstOrCreate(['name' => UserRole::CLIENT->value]);
        $client = User::factory()->create(['flatrate_level' => 'none', 'brand' => 'rp']);
        $client->roles()->attach([$clientRole->id]);

        $gallery = Gallery::factory()->create();
        $client->galleries()->attach($gallery->id);

        $this->assertCount(1, $client->fresh()->galleries);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/users/{$client->id}", [
                'flatrate_level' => 'web',
            ]);

        $response->assertOk();
        $this->assertEquals('web', $client->fresh()->flatrate_level);
        $this->assertCount(1, $client->fresh()->galleries, 'Gallery assignments should be preserved');
    }

    public function test_photographer_cannot_create_user()
    {
        $photog = User::factory()->create();
        $photog->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));
        $token = auth('api')->login($photog);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/users', ['name' => 'Test User', 'email' => 'test@test.com']);
        $response->assertStatus(403);
    }
}
