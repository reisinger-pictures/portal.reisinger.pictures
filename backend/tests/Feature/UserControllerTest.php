<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MailpitAssertions;

class UserControllerTest extends TestCase {
    use RefreshDatabase, MailpitAssertions;

    public function test_admin_can_create_user() {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::ADMIN->value]));
        $token = auth('api')->login($admin);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->postJson('/api/management/users', ['name' => 'Test User', 'email' => 'test@test.com']);
        
        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['email' => 'test@test.com']);

        $this->assertMailpitSentTo('test@test.com');
    }

    public function test_partial_update_preserves_roles() {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::ADMIN->value]));
        $token = auth('api')->login($admin);

        $clientRole = Role::firstOrCreate(['name' => \App\Enums\UserRole::CLIENT->value]);
        $photogRole = Role::firstOrCreate(['name' => \App\Enums\UserRole::PHOTOGRAPHER->value]);
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

    public function test_partial_update_preserves_gallery_assignments() {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::ADMIN->value]));
        $token = auth('api')->login($admin);

        $clientRole = Role::firstOrCreate(['name' => \App\Enums\UserRole::CLIENT->value]);
        $client = User::factory()->create(['flatrate_level' => 'none', 'brand' => 'rp']);
        $client->roles()->attach([$clientRole->id]);

        $gallery = \App\Models\Gallery::factory()->create();
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

    public function test_photographer_cannot_create_user() {
        $photog = User::factory()->create();
        $photog->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::PHOTOGRAPHER->value]));
        $token = auth('api')->login($photog);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->postJson('/api/management/users', ['name' => 'Test User', 'email' => 'test@test.com']);
        $response->assertStatus(403);
    }
}