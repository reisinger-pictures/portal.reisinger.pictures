<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Models\Gallery;
use App\Models\Role;
use App\Enums\Brand;
use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

class FtpImportTest extends TestCase {
    use RefreshDatabase;

    public function test_photographer_can_get_ftp_status() {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::PHOTOGRAPHER->value]));
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/management/ftp/status');
        $response->assertStatus(200)->assertJsonStructure(['ftp_folder', 'file_count']);
    }

    public function test_client_cannot_access_ftp() {
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/management/ftp/status');
        $response->assertStatus(403);
    }

    public function test_photographer_cannot_set_target_to_other_brand_gallery() {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        $otherGallery = Gallery::factory()->create(['brand' => 'test-brand']);

        $token = auth('api')->login($user);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/target', ['gallery_id' => $otherGallery->id]);

        $response->assertStatus(403);
    }

    public function test_photographer_can_set_target_to_own_brand_gallery() {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        $gallery = Gallery::factory()->create(['brand' => Brand::B2B]);
        $user->galleries()->attach($gallery->id);

        $token = auth('api')->login($user);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/target', ['gallery_id' => $gallery->id]);

        $response->assertStatus(200);
    }

    public function test_photographer_cannot_process_other_brand_gallery() {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        $otherGallery = Gallery::factory()->create(['brand' => 'test-brand']);
        $user->update(['current_ftp_gallery_id' => $otherGallery->id]);

        $token = auth('api')->login($user);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/process');

        $response->assertStatus(403);
    }

    public function test_set_target_rejects_nonexistent_gallery() {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        $token = auth('api')->login($user);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/target', ['gallery_id' => 'non-existent']);

        $response->assertStatus(422);
    }
}
