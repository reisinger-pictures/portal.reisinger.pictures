<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Gallery;
use Illuminate\Foundation\Testing\RefreshDatabase;

class NotificationOptInTest extends TestCase {
    use RefreshDatabase;

    public function test_client_can_toggle_opt_in_and_mail_controller_respects_it() {
        $client = User::factory()->create(['email' => 'optin@test.com']);
        $client->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::CLIENT->value]));
        $gallery = Gallery::factory()->create();
        $client->galleries()->attach($gallery, ['wants_notifications' => false]); // Startet opt-out

        // 1. API Toggle Test
        $token = auth('api')->login($client);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->postJson("/api/galleries/{$gallery->id}/opt-in", ['wants_notifications' => true]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('user_galleries', ['user_id' => $client->id, 'gallery_id' => $gallery->id, 'wants_notifications' => 1]);

        // 2. Mail Controller Test (Sollte den User nun inkludieren)
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::ADMIN->value]));
        $adminToken = auth('api')->login($admin);
        
        \Illuminate\Support\Facades\Http::delete('http://127.0.0.1:8026/api/v1/messages');

        $adminResponse = $this->withHeaders(['Authorization' => "Bearer $adminToken"])
             ->postJson("/api/management/galleries/{$gallery->id}/send-custom-email", [
                 'subject' => 'OptIn Update', 'body' => 'Hello'
             ]);
        
        $adminResponse->assertStatus(200);
        $this->assertEquals(1, $adminResponse->json('notified_count'));

        // WORKER STARTEN: Leert die asynchrone Warteschlange für den Test
        \Illuminate\Support\Facades\Artisan::call('queue:work', ['--stop-when-empty' => true]);

        $mailpitResponse = \Illuminate\Support\Facades\Http::get('http://127.0.0.1:8026/api/v1/messages');
        $messages = $mailpitResponse->json('messages');
        $this->assertGreaterThan(0, count($messages));
        $this->assertStringContainsString('optin@test.com', $messages[0]['To'][0]['Address']);
    }
}
