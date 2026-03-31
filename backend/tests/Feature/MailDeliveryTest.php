<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Gallery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

class MailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mailpit API löschen vor dem Test, damit wir einen sauberen State haben
        try {
            Http::delete('http://127.0.0.1:8026/api/v1/messages');
        } catch (\Exception $e) {
            $this->markTestSkipped('Mailpit läuft nicht auf Port 8026.');
        }
    }

    public function test_invite_email_is_sent_to_mailpit()
    {
        $gallery = Gallery::factory()->create(['name' => 'Sommerfest']);
        $admin = User::factory()->create();
        $admin->roles()->attach(\App\Models\Role::firstOrCreate(['name' => 'admin']));
        $admin->galleries()->attach($gallery);
        
        $token = auth('api')->login($admin);

        // Wir rufen den echten Endpoint auf, der die E-Mail versendet
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/management/galleries/' . $gallery->id . '/invites/send', [
                'email' => 'kunde@example.com',
                'name' => 'Max Mustermann'
            ]);

        $response->assertStatus(200);

        // Mailpit API abfragen
        $mailpitResponse = Http::get('http://127.0.0.1:8026/api/v1/messages');
        $this->assertTrue($mailpitResponse->successful());
        
        $messages = $mailpitResponse->json('messages');
        $this->assertCount(1, $messages, 'Es sollte exakt eine E-Mail in Mailpit liegen.');
        
        $firstMessage = $messages[0];
        $this->assertStringContainsString('kunde@example.com', $firstMessage['To'][0]['Address']);
        $this->assertStringContainsString('Sommerfest', $firstMessage['Subject']);
    }
}
