<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
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
}