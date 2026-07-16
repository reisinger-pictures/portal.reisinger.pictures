<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Role;
use App\Enums\Brand;
use App\Support\BrandRegistry;
use Illuminate\Support\Facades\Mail;
use App\Mail\TestMail;

class MailTestControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createSuperAdmin(): User
    {
        $user = User::factory()->create(['email' => 'superadmin@reisinger.pictures']);
        $role = Role::firstOrCreate(['name' => 'super_admin']);
        $user->roles()->attach($role);
        return $user;
    }

    private function createAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user->roles()->attach($role);
        return $user;
    }

    private function authHeaders(User $user): array
    {
        $token = auth('api')->login($user);
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
    }

    public function test_super_admin_can_send_test_email_to_own_address(): void
    {
        Mail::fake();
        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);

        $response = $this->postJson('/api/management/settings/test-email', [], $headers);

        $response->assertOk()
            ->assertJson(['success' => true, 'sent_to' => $user->email]);

        Mail::assertQueued(TestMail::class, function (TestMail $mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    public function test_non_super_admin_cannot_send_test_email(): void
    {
        Mail::fake();
        $user = $this->createAdmin();
        $headers = $this->authHeaders($user);

        $response = $this->postJson('/api/management/settings/test-email', [], $headers);

        $response->assertForbidden();
        Mail::assertNothingQueued();
    }

    public function test_unauthenticated_cannot_send_test_email(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/management/settings/test-email', []);

        $response->assertUnauthorized();
        Mail::assertNothingQueued();
    }
}
