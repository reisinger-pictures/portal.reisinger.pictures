<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\Org;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;
use Tests\Support\MailpitAssertions;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use MailpitAssertions, RefreshDatabase;

    public function test_user_can_login_with_correct_credentials()
    {
        $user = User::factory()->create(['brand' => Brand::B2B, 'password' => bcrypt('password123')]);
        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password123']);
        $response->assertStatus(200)->assertCookie('rp_jwt');
    }

    public function test_user_cannot_login_with_incorrect_credentials()
    {
        $user = User::factory()->create(['brand' => Brand::B2B, 'password' => bcrypt('password123')]);
        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrongpass']);
        $response->assertStatus(401);
    }

    public function test_user_can_register_and_mail_is_sent_to_mailpit_and_can_reset_password()
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'New User',
            'email' => 'new@user.com',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['email' => 'new@user.com']);

        $this->assertMailpitSentTo('new@user.com');
        $message = $this->getMailpitMessageByEmail('new@user.com');
        $this->assertNotNull($message, 'E-Mail an new@user.com nicht in Mailpit gefunden');
        $htmlBody = $message['HTML'] ?? '';

        // Regex für die Token-Extraktion (berücksichtigt evtl. &amp;)
        preg_match('/reset-password\\?token=([a-zA-Z0-9]+)&(?:amp;)?email=/', $htmlBody, $matches);
        $this->assertNotEmpty($matches, 'Reset-Link bzw. Token wurde nicht in der E-Mail gefunden.');
        $token = $matches[1];

        // Passwort-Reset mit extrahiertem Token ausführen
        $resetResponse = $this->postJson('/api/auth/reset-password', [
            'email' => 'new@user.com',
            'token' => $token,
            'password' => 'newSecurePassword123',
        ]);

        $resetResponse->assertStatus(200)->assertCookie('rp_jwt');

        // Finale DB-Prüfung: Hat der User jetzt ein gehashtes Passwort?
        $user = User::where('email', 'new@user.com')->first();
        $this->assertNotNull($user->password);
        $this->assertTrue(Hash::check('newSecurePassword123', $user->password));
    }

    public function test_foreign_brand_registration_rolls_back_user_and_reset_state()
    {
        Mail::fake();
        $email = 'foreign-registration@foreign.example.com';
        Org::factory()->create([
            'domain' => 'foreign.example.com',
            'brand' => 'srp',
        ]);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Foreign User',
            'email' => $email,
        ]);

        $response->assertStatus(403)->assertJson([
            'error' => 'Registrierung für diese Domain ist auf diesem Portal nicht möglich.',
        ]);

        $this->assertDatabaseMissing('users', ['email' => $email]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $email]);
        Mail::assertNothingSent();
    }

    public function test_me_endpoint_returns_billing_and_metadata_permission_contract()
    {
        $user = User::factory()->create([
            'brand' => Brand::B2B,
            'billing_name' => 'Billing Name',
            'billing_company' => 'Billing Company',
            'billing_street' => 'Billing Street 1',
            'billing_zip' => '1010',
            'billing_city' => 'Vienna',
            'can_edit_metadata' => true,
            'can_purchase_upgrades' => true,
        ]);
        $token = Auth::guard('api')->login($user);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/auth/me');

        $response->assertStatus(200)->assertJsonPaths([
            'billing_name' => 'Billing Name',
            'billing_company' => 'Billing Company',
            'billing_street' => 'Billing Street 1',
            'billing_zip' => '1010',
            'billing_city' => 'Vienna',
            'can_edit_metadata' => true,
            'can_purchase_upgrades' => true,
        ]);
    }

    public function test_auth_issues_separate_http_only_refresh_cookie_with_refresh_ttl()
    {
        $user = User::factory()->create(['brand' => Brand::B2B, 'password' => bcrypt('password123')]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertCookie('rp_jwt')
            ->assertCookie('rp_jwt_refresh');

        $accessCookie = $response->getCookie('rp_jwt', false);
        $refreshCookie = $response->getCookie('rp_jwt_refresh', false);

        $this->assertTrue($accessCookie->isHttpOnly());
        $this->assertTrue($refreshCookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $refreshCookie->getSameSite()));
        $this->assertGreaterThan(
            $accessCookie->getExpiresTime(),
            $refreshCookie->getExpiresTime()
        );
    }

    public function test_expired_access_token_can_be_refreshed_by_dedicated_cookie()
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $token = $this->issueExpiredAccessToken($user);

        $this->assertFalse(app(JWTAuth::class)->setToken($token)->check());

        // The expired access token is sent as a bearer header to prove that
        // auth:api is not involved. A browser would have removed rp_jwt by now;
        // only the longer-lived refresh cookie is sent below.
        $response = $this->withHeader('Authorization', "Bearer $token")
            ->withUnencryptedCookie('rp_jwt_refresh', $token)
            ->withCredentials()
            ->postJson('/api/auth/refresh');

        $response->assertStatus(200)
            ->assertCookie('rp_jwt')
            ->assertCookie('rp_jwt_refresh');

        $newAccessToken = $response->getCookie('rp_jwt', false)->getValue();
        $newRefreshToken = $response->getCookie('rp_jwt_refresh', false)->getValue();

        $this->assertNotSame($token, $newAccessToken);
        $this->assertNotSame($token, $newRefreshToken);
        $this->assertTrue(app(JWTAuth::class)->setToken($newAccessToken)->check());
    }

    public function test_refresh_rotates_and_blacklists_previous_refresh_cookie()
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $token = Auth::guard('api')->login($user);

        $first = $this->withUnencryptedCookie('rp_jwt_refresh', $token)
            ->withCredentials()
            ->postJson('/api/auth/refresh');
        $first->assertStatus(200)->assertCookie('rp_jwt')->assertCookie('rp_jwt_refresh');
        $rotatedRefreshToken = $first->getCookie('rp_jwt_refresh', false)->getValue();

        $this->assertNotSame($token, $rotatedRefreshToken);

        $second = $this->withUnencryptedCookie('rp_jwt_refresh', $token)
            ->withCredentials()
            ->postJson('/api/auth/refresh');
        $second->assertStatus(401)
            ->assertJson(['error' => 'Token konnte nicht aktualisiert werden.']);
    }

    public function test_logout_expires_access_and_refresh_cookies()
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $token = Auth::guard('api')->login($user);

        $response = $this->withUnencryptedCookie('rp_jwt', $token)
            ->withCredentials()
            ->postJson('/api/auth/logout');

        $response->assertStatus(200)
            ->assertCookieExpired('rp_jwt')
            ->assertCookieExpired('rp_jwt_refresh');
    }

    public function test_me_endpoint_returns_user_data()
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $token = auth('api')->login($user);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/auth/me');
        $response->assertStatus(200)->assertJsonPath('email', $user->email);
    }

    public function test_password_reset_on_wrong_brand_returns_403_and_does_not_change_password()
    {
        // B-09: Brand-Check before password mutation.
        $user = User::factory()->create([
            'brand' => 'test-brand',
            'password' => null,
        ]);

        $tokenValue = 'valid-reset-token-123';
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($tokenValue),
            'created_at' => now(),
        ]);

        // BrandRegistry is set to B2B (default from TestCase setUp),
        // but the user has brand=test-brand → mismatch.
        $response = $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $tokenValue,
            'password' => 'newPassword123',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'Dieser Account ist für ein anderes Portal registriert.']);

        $user->refresh();
        $this->assertNull($user->password, 'Password must remain unchanged on brand mismatch');
    }

    public function test_password_reset_for_super_admin_is_rejected_without_enumeration()
    {
        // T5/S5b: The system admin identity comes from config('admin.email')
        // (env ADMIN_EMAIL, generic fallback). Reset is disabled, but the response
        // must be indistinguishable from any other invalid token (no oracle).
        Config::set('admin.email', 'admin@example.com');

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => 'admin@example.com',
            'token' => 'any-token-value',
            'password' => 'newPassword123',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'Der Setup-Link ist ungültig oder abgelaufen.']);
    }

    private function issueExpiredAccessToken(User $user): string
    {
        $accessTtl = max((int) config('jwt.ttl', 240), 1);
        Carbon::setTestNow(Carbon::now()->subMinutes($accessTtl + 1));

        try {
            return Auth::guard('api')->login($user);
        } finally {
            Carbon::setTestNow();
        }
    }
}
