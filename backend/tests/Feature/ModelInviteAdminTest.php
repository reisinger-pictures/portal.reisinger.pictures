<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Mail\ModelInviteMail;
use App\Models\ModelRegistrationInvite;
use App\Models\Role;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ModelInviteAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
        Mail::fake();
    }

    private function userWithRole(UserRole $role, ?string $brand = 'rp'): User
    {
        $user = User::factory()->create(['brand' => $brand]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role->value]));

        return $user;
    }

    private function invite(string $brand = 'rp', array $attributes = []): ModelRegistrationInvite
    {
        return ModelRegistrationInvite::create(array_merge([
            'token' => 'token-'.bin2hex(random_bytes(8)),
            'email' => fake()->unique()->safeEmail(),
            'brand' => $brand,
            'invited_by' => User::factory()->create()->id,
            'expires_at' => now()->addDays(7),
        ], $attributes));
    }

    public function test_admin_can_create_model_invite(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/management/model-invites', ['email' => 'model@example.com']);

        $response->assertCreated()->assertJsonPath('success', true);

        $invite = ModelRegistrationInvite::where('email', 'model@example.com')->firstOrFail();
        $this->assertSame(64, strlen($invite->token));
        $this->assertSame('rp', $invite->brandValue());
        $this->assertSame($admin->id, $invite->invited_by);
        $this->assertTrue($invite->expires_at->between(now()->addDays(6), now()->addDays(8)));

        $expectedLink = BrandRegistry::frontendUrl(Brand::B2B).'/model-registrierung/'.$invite->token;
        $response->assertJsonPath('link', $expectedLink)
            ->assertJsonPath('invite.link', $expectedLink);

        Mail::assertQueued(ModelInviteMail::class, function (ModelInviteMail $mail) {
            return $mail->hasTo('model@example.com');
        });
    }

    public function test_admin_can_create_invite_without_email_and_with_label(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/management/model-invites', ['label' => 'Maria Muster / IG']);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('invite.label', 'Maria Muster / IG')
            ->assertJsonPath('invite.email', null);

        $invite = ModelRegistrationInvite::where('label', 'Maria Muster / IG')->firstOrFail();
        $this->assertNull($invite->email);
        $this->assertSame(64, strlen($invite->token));

        $expectedLink = BrandRegistry::frontendUrl(Brand::B2B).'/model-registrierung/'.$invite->token;
        $response->assertJsonPath('link', $expectedLink)
            ->assertJsonPath('invite.link', $expectedLink);

        Mail::assertNothingQueued();
    }

    public function test_create_validates_label_length(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);

        $this->actingAs($admin, 'api')
            ->postJson('/api/management/model-invites', [
                'email' => 'model@example.com',
                'label' => str_repeat('a', 256),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['label']);
    }

    public function test_super_admin_can_create_model_invite(): void
    {
        $admin = $this->userWithRole(UserRole::SUPER_ADMIN);

        $this->actingAs($admin, 'api')
            ->postJson('/api/management/model-invites', ['email' => 'model@example.com'])
            ->assertCreated();
    }

    public function test_create_validates_email(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);

        $this->actingAs($admin, 'api')
            ->postJson('/api/management/model-invites', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_photographer_cannot_create_model_invite(): void
    {
        $photographer = $this->userWithRole(UserRole::PHOTOGRAPHER);

        $this->actingAs($photographer, 'api')
            ->postJson('/api/management/model-invites', ['email' => 'model@example.com'])
            ->assertStatus(403);

        $this->assertDatabaseCount('model_registration_invites', 0);
    }

    public function test_client_cannot_create_model_invite(): void
    {
        $client = $this->userWithRole(UserRole::CLIENT);

        $this->actingAs($client, 'api')
            ->postJson('/api/management/model-invites', ['email' => 'model@example.com'])
            ->assertStatus(403);
    }

    public function test_guest_cannot_create_model_invite(): void
    {
        $this->postJson('/api/management/model-invites', ['email' => 'model@example.com'])
            ->assertStatus(401);
    }

    public function test_admin_can_list_invites_with_status(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);

        $this->invite('rp');
        $this->invite('rp', ['used_at' => now()]);
        $this->invite('rp', ['expires_at' => now()->subDay()]);

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/management/model-invites');

        $response->assertOk()->assertJsonCount(3);
        $statuses = collect($response->json())->pluck('status')->sort()->values()->all();
        $this->assertSame(['expired', 'open', 'redeemed'], $statuses);
    }

    public function test_invite_list_includes_link_and_label(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);
        $invite = $this->invite('rp', ['label' => 'Maria Muster / IG']);

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/management/model-invites');

        $response->assertOk()
            ->assertJsonPath('0.label', 'Maria Muster / IG')
            ->assertJsonPath(
                '0.link',
                BrandRegistry::frontendUrl(Brand::B2B).'/model-registrierung/'.$invite->token
            );
    }

    public function test_invite_list_is_brand_scoped(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');

        $this->invite('rp');
        $this->invite('srp');

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/management/model-invites');

        $response->assertOk()->assertJsonCount(1)->assertJsonPath('0.brand', 'rp');
    }

    public function test_admin_can_revoke_invite(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);
        $invite = $this->invite('rp');

        $this->actingAs($admin, 'api')
            ->deleteJson("/api/management/model-invites/{$invite->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('model_registration_invites', ['id' => $invite->id]);
    }

    public function test_admin_cannot_revoke_foreign_brand_invite(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $invite = $this->invite('srp');

        $this->actingAs($admin, 'api')
            ->deleteJson("/api/management/model-invites/{$invite->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('model_registration_invites', ['id' => $invite->id]);
    }

    public function test_photographer_cannot_list_invites(): void
    {
        $photographer = $this->userWithRole(UserRole::PHOTOGRAPHER);

        $this->actingAs($photographer, 'api')
            ->getJson('/api/management/model-invites')
            ->assertStatus(403);
    }

    public function test_client_cannot_list_invites(): void
    {
        $client = $this->userWithRole(UserRole::CLIENT);

        $this->actingAs($client, 'api')
            ->getJson('/api/management/model-invites')
            ->assertStatus(403);
    }
}
