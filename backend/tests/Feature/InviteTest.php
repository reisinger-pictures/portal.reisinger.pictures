<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Models\Gallery;
use App\Models\GalleryInvite;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

class InviteTest extends TestCase {
    use RefreshDatabase;

    public function test_redeem_invite_creates_guest_user() {
        $gallery = Gallery::factory()->create(['password_hash' => null]);
        $invite = GalleryInvite::create(['gallery_id' => $gallery->id, 'token' => 'randomtoken123']);

        $response = $this->postJson('/api/invites/redeem', [
            'token' => 'randomtoken123',
            'name' => 'Guest User',
            'email' => 'guest@example.com'
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['success', 'full_path'])
                 ->assertCookie('rp_jwt');
        $this->assertDatabaseHas('users', ['email' => 'guest@example.com']);
    }
    public function test_send_invite_email_and_redeem_magic_link() {
        // 1. Admin & Galerie vorbereiten
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'admin']));
        $token = auth('api')->login($admin);
        
        $gallery = Gallery::factory()->create(['name' => 'Magic Test Galerie']);

        // 2. Mailpit leeren
        try {
            \Illuminate\Support\Facades\Http::delete('http://127.0.0.1:8026/api/v1/messages');
        } catch (\Exception $e) {
            $this->markTestSkipped('Mailpit läuft nicht auf Port 8026.');
        }

        // 3. E-Mail über API versenden
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->postJson("/api/management/galleries/{$gallery->id}/invites/send", [
                             'email' => 'magic-guest@example.com',
                             'name' => null // Wichtig für anonymen Test-Link
                         ]);
        $response->assertStatus(200);

        // 4. E-Mail aus Mailpit abrufen & Token extrahieren
        $mailpitResponse = \Illuminate\Support\Facades\Http::get('http://127.0.0.1:8026/api/v1/messages');
        $this->assertTrue($mailpitResponse->successful());
        
        $messages = $mailpitResponse->json('messages');
        $this->assertCount(1, $messages, 'E-Mail wurde nicht in Mailpit gefunden.');

        $messageId = $messages[0]['ID'];
        $mailDetails = \Illuminate\Support\Facades\Http::get("http://127.0.0.1:8026/api/v1/message/{$messageId}");
        $htmlBody = $mailDetails->json('HTML');

        preg_match('/invite\/([a-zA-Z0-9]+)/', $htmlBody, $matches);
        $this->assertNotEmpty($matches, "Einladungs-Link bzw. Token wurde nicht in der E-Mail gefunden.");
        $inviteToken = $matches[1];

        // 5. Token als Gast einlösen
        auth('api')->logout(); // Sicherstellen, dass wir als Gast agieren

        $redeemResponse = $this->postJson('/api/invites/redeem', [
            'token' => $inviteToken,
            'name' => 'Magic Guest',
            'email' => 'magic-guest@example.com'
        ]);

        $redeemResponse->assertStatus(200)
                       ->assertCookie('rp_jwt');

        // 6. Prüfen, ob der Gast korrekt in der DB angelegt und verknüpft wurde
        $this->assertDatabaseHas('users', ['email' => 'magic-guest@example.com']);
        $guestUser = User::where('email', 'magic-guest@example.com')->first();
        $this->assertTrue($guestUser->galleries->contains($gallery->id));
    }
}
