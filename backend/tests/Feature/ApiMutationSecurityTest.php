<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Mail\OrgInviteMail;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ApiMutationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_login_rejects_a_cross_origin_json_request(): void
    {
        $user = User::factory()->create([
            'brand' => Brand::B2B,
            'password' => bcrypt('correct-password'),
        ]);

        $this->withHeader('Origin', 'https://attacker.example')
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'correct-password',
            ])
            ->assertForbidden();
    }

    public function test_login_rejects_a_cross_origin_form_submission(): void
    {
        $user = User::factory()->create([
            'brand' => Brand::B2B,
            'password' => bcrypt('correct-password'),
        ]);

        $this->call(
            'POST',
            '/api/auth/login',
            ['email' => $user->email, 'password' => 'correct-password'],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                'HTTP_ORIGIN' => 'https://attacker.example',
                'HTTP_REFERER' => 'https://attacker.example/login',
            ],
            http_build_query(['email' => $user->email, 'password' => 'correct-password']),
        )->assertStatus(415);
    }

    public function test_cookie_authenticated_mutations_fail_closed_without_a_csrf_token(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $jwt = auth('api')->login($user);
        Auth::forgetGuards();

        $this->withUnencryptedCookie('rp_jwt_refresh', $jwt)
            ->withCredentials()
            ->withHeader('Origin', rtrim((string) config('app.url'), '/'))
            ->postJson('/api/auth/refresh')
            ->assertForbidden();
    }

    public function test_same_origin_cookie_authenticated_mutation_accepts_the_signed_csrf_token(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $jwt = auth('api')->login($user);
        $csrf = $this->csrfCredentials();
        Auth::forgetGuards();

        $this->withUnencryptedCookie('rp_jwt', $jwt)
            ->withUnencryptedCookie('XSRF-TOKEN', $csrf['cookie'])
            ->withHeaders($csrf['headers'])
            ->withCredentials()
            ->postJson('/api/auth/logout')
            ->assertOk();
    }

    public function test_cross_origin_form_submission_cannot_create_or_send_an_org_invite(): void
    {
        $org = Org::factory()->create();
        $admin = User::factory()->create(['brand' => Brand::B2B]);
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));
        $jwt = auth('api')->login($admin);

        $this->withUnencryptedCookie('rp_jwt', $jwt)
            ->call(
                'POST',
                "/api/management/orgs/{$org->id}/invites",
                ['email' => 'attacker-controlled@example.com'],
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                    'HTTP_ORIGIN' => 'https://attacker.example',
                    'HTTP_REFERER' => 'https://attacker.example/invite',
                ],
                http_build_query(['email' => 'attacker-controlled@example.com']),
            )
            ->assertStatus(415);

        $this->assertDatabaseCount('org_invites', 0);
        Mail::assertNothingQueued();
        Mail::assertNotQueued(OrgInviteMail::class);
    }
}
