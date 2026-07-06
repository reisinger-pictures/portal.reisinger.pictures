<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MailpitAssertions;

class AuthControllerTest extends TestCase {
    use RefreshDatabase, MailpitAssertions;

    public function test_user_can_login_with_correct_credentials() {
        $user = User::factory()->create(['password' => bcrypt('password123')]);
        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password123']);
        $response->assertStatus(200)->assertCookie('rp_jwt');
    }

    public function test_user_cannot_login_with_incorrect_credentials() {
        $user = User::factory()->create(['password' => bcrypt('password123')]);
        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrongpass']);
        $response->assertStatus(401);
    }


    public function test_user_can_register_and_mail_is_sent_to_mailpit_and_can_reset_password() {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'New User',
            'email' => 'new@user.com'
        ]);
        
        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['email' => 'new@user.com']);

        $this->assertMailpitSentTo('new@user.com');
        $message = $this->getMailpitMessageByEmail('new@user.com');
        $this->assertNotNull($message, 'E-Mail an new@user.com nicht in Mailpit gefunden');
        $htmlBody = $message['HTML'] ?? '';

        // Regex für die Token-Extraktion (berücksichtigt evtl. &amp;)
        preg_match('/reset-password\\?token=([a-zA-Z0-9]+)&(?:amp;)?email=/', $htmlBody, $matches);
        $this->assertNotEmpty($matches, "Reset-Link bzw. Token wurde nicht in der E-Mail gefunden.");
        $token = $matches[1];

        // Passwort-Reset mit extrahiertem Token ausführen
        $resetResponse = $this->postJson('/api/auth/reset-password', [
            'email' => 'new@user.com',
            'token' => $token,
            'password' => 'newSecurePassword123'
        ]);

        $resetResponse->assertStatus(200)->assertCookie('rp_jwt');

        // Finale DB-Prüfung: Hat der User jetzt ein gehashtes Passwort?
        $user = \App\Models\User::where('email', 'new@user.com')->first();
        $this->assertNotNull($user->password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('newSecurePassword123', $user->password));
    }

    public function test_me_endpoint_returns_user_data() {
        $user = User::factory()->create();
        $token = auth('api')->login($user);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/auth/me');
        $response->assertStatus(200)->assertJsonPath('email', $user->email);
    }
}