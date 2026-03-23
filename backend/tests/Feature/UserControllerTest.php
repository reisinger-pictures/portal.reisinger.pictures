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

        // Vor dem Test die Mailpit API bereinigen
        try {
            \Illuminate\Support\Facades\Http::delete('http://127.0.0.1:8026/api/v1/messages');
        } catch (\Exception $e) {
            $this->markTestSkipped('Mailpit läuft nicht auf Port 8026.');
        }

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->postJson('/api/management/users', ['name' => 'Test User', 'email' => 'test@test.com']);
        
        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['email' => 'test@test.com']);

        // Harter Check gegen die Mailpit API (statt Mocking)
        $mailpitResponse = \Illuminate\Support\Facades\Http::get('http://127.0.0.1:8026/api/v1/messages');
        $this->assertTrue($mailpitResponse->successful(), 'Mailpit API nicht erreichbar');
        
        $messages = $mailpitResponse->json('messages');
        $this->assertCount(1, $messages, 'Es sollte exakt eine E-Mail versendet worden sein.');
        $this->assertStringContainsString('test@test.com', $messages[0]['To'][0]['Address']);
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