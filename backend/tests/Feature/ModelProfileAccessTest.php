<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\ModelAccessToken;
use App\Models\ModelProfile;
use App\Models\ModelRegistrationInvite;
use App\Models\Role;
use App\Models\User;
use App\Services\ModelFileStore;
use App\Services\ModelQuestionnaire;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Profil-Zugang: Admin-Zugangslink (24h, Revoke), öffentlicher Magic-Link
 * (lesen/bestätigen/aktualisieren) und „Meine Profile" für Portal-Konten.
 */
class ModelProfileAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
        Storage::fake('local');
    }

    private function admin(?string $brand = 'rp'): User
    {
        $user = User::factory()->create(['brand' => $brand]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function personAnswers(string $email = 'anna@example.com'): array
    {
        $answers = [
            'first_name' => 'Anna',
            'last_name' => 'Beispiel',
            'stage_name' => 'Nova',
            'birthdate' => '1995-05-05',
            'email' => $email,
            'phone' => '+43 660 1234567',
            'street' => 'Teststraße 1',
            'zip' => '4020',
            'city' => 'Linz',
            'country' => 'Österreich',
            'consent_privacy' => true,
            'consent_accuracy' => true,
            'consent_contact' => true,
            'consent_photos' => true,
            'consent_all_persons' => true,
            'willingness_stock' => 'nein',
        ];
        foreach (array_keys(ModelQuestionnaire::SHOOTING_CATEGORIES) as $category) {
            $answers['willingness_'.$category] = 'nein';
        }

        return $answers;
    }

    private function createModel(string $brand = 'rp', string $email = 'anna@example.com'): ModelProfile
    {
        $inviter = User::factory()->create(['brand' => Brand::tryFrom($brand) ?? Brand::B2B]);
        $invite = ModelRegistrationInvite::create([
            'token' => 'token-'.bin2hex(random_bytes(8)),
            'email' => $email,
            'brand' => $brand,
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
        ]);

        $this->post(
            "/api/model-registration/{$invite->token}",
            [
                'persons' => [[
                    'answers' => $this->personAnswers($email),
                    'create_account' => false,
                    'age_proof' => UploadedFile::fake()->image('ausweis.jpg'),
                ]],
                'manager_index' => 0,
            ],
            ['Accept' => 'application/json']
        )->assertCreated();

        return ModelProfile::whereHas('customer', fn ($q) => $q->where('email', $email))->firstOrFail();
    }

    private function issueToken(Customer $customer): ModelAccessToken
    {
        return ModelAccessToken::issueFor($customer);
    }

    public function test_admin_can_create_access_link_valid_for_24h(): void
    {
        $admin = $this->admin('rp');
        $profile = $this->createModel();
        $customer = $profile->customer;

        $response = $this->actingAs($admin, 'api')
            ->postJson("/api/management/models/{$customer->id}/access-link");

        $response->assertCreated()->assertJsonPath('success', true);

        $token = ModelAccessToken::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame(64, strlen($token->token));
        $this->assertTrue($token->expires_at->between(now()->addHours(23), now()->addHours(25)));
        $this->assertStringContainsString('/model-profil/'.$token->token, $response->json('link'));
    }

    public function test_creating_a_second_link_revokes_the_first(): void
    {
        $admin = $this->admin('rp');
        $profile = $this->createModel();
        $customer = $profile->customer;

        $first = $this->issueToken($customer);
        $second = $this->issueToken($customer);

        $this->assertNotNull($first->fresh()->revoked_at);
        $this->assertNull($second->fresh()->revoked_at);
        $this->assertSame(1, ModelAccessToken::where('customer_id', $customer->id)->whereNull('revoked_at')->count());
    }

    public function test_access_link_is_exposed_in_admin_model_list(): void
    {
        $admin = $this->admin('rp');
        $profile = $this->createModel();
        $token = $this->issueToken($profile->customer);

        $response = $this->actingAs($admin, 'api')->getJson('/api/management/models');

        $response->assertOk();
        $link = $response->json('0.access_link.url');
        $this->assertNotNull($link);
        $this->assertSame($token->token, basename(parse_url($link, PHP_URL_PATH)));
    }

    public function test_admin_can_revoke_access_link(): void
    {
        $admin = $this->admin('rp');
        $profile = $this->createModel();
        $customer = $profile->customer;
        $token = $this->issueToken($customer);

        $this->actingAs($admin, 'api')
            ->deleteJson("/api/management/models/{$customer->id}/access-link")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotNull($token->fresh()->revoked_at);
        $this->getJson("/api/model-profil/{$token->token}")->assertStatus(410);
    }

    public function test_access_link_creation_is_brand_scoped(): void
    {
        $admin = $this->admin('rp');
        $profile = $this->createModel('srp', 'foreign@example.com');

        $this->actingAs($admin, 'api')
            ->postJson("/api/management/models/{$profile->customer_id}/access-link")
            ->assertNotFound();
    }

    public function test_public_show_returns_snapshot_and_catalog(): void
    {
        $profile = $this->createModel();
        $token = $this->issueToken($profile->customer);

        $response = $this->getJson("/api/model-profil/{$token->token}");

        $response->assertOk()
            ->assertJsonPath('brand', 'rp')
            ->assertJsonPath('catalog_version', ModelQuestionnaire::CURRENT)
            ->assertJsonPath('current_catalog_version', ModelQuestionnaire::CURRENT)
            ->assertJsonPath('is_catalog_outdated', false)
            ->assertJsonStructure(['answers', 'categories', 'sections', 'last_confirmed_at', 'expires_at']);

        $this->assertNotNull($token->fresh()->last_used_at);
    }

    public function test_public_show_returns_404_for_unknown_token(): void
    {
        $this->getJson('/api/model-profil/does-not-exist')->assertNotFound();
    }

    public function test_public_show_returns_410_for_expired_token(): void
    {
        $profile = $this->createModel();
        $token = $this->issueToken($profile->customer);
        $token->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->getJson("/api/model-profil/{$token->token}")->assertStatus(410);
    }

    public function test_public_show_returns_410_for_revoked_token(): void
    {
        $profile = $this->createModel();
        $token = $this->issueToken($profile->customer);
        $token->forceFill(['revoked_at' => now()])->save();

        $this->getJson("/api/model-profil/{$token->token}")->assertStatus(410);
    }

    public function test_public_update_writes_new_snapshot_and_confirms(): void
    {
        $profile = $this->createModel();
        $token = $this->issueToken($profile->customer);

        $answers = $this->personAnswers();
        $answers['stage_name'] = 'Nova Updated';
        $answers['city'] = 'Wien';

        $response = $this->postJson("/api/model-profil/{$token->token}", ['answers' => $answers]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('catalog_version', ModelQuestionnaire::CURRENT);

        $profile->refresh();
        $this->assertSame(ModelQuestionnaire::CURRENT, $profile->catalog_version);
        $this->assertSame('Nova Updated', $profile->answersMap()['stage_name']);
        $this->assertNotNull($profile->last_confirmed_at);
        $this->assertSame('Wien', $profile->customer->fresh()->city);
    }

    public function test_public_update_rejects_invalid_answers(): void
    {
        $profile = $this->createModel();
        $token = $this->issueToken($profile->customer);

        $answers = $this->personAnswers();
        unset($answers['last_name']);

        $this->postJson("/api/model-profil/{$token->token}", ['answers' => $answers])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['answers.last_name']);
    }

    public function test_public_confirm_resets_timer_without_snapshot_change(): void
    {
        $profile = $this->createModel();
        $profile->forceFill(['last_confirmed_at' => now()->subMonths(13)])->save();
        $token = $this->issueToken($profile->customer);

        $before = $profile->answers;

        $this->postJson("/api/model-profil/{$token->token}/confirm")
            ->assertOk()
            ->assertJsonPath('success', true);

        $profile->refresh();
        $this->assertTrue($profile->last_confirmed_at->greaterThan(now()->subMinute()));
        $this->assertSame($before, $profile->answers);
    }

    public function test_me_models_is_owner_and_brand_scoped(): void
    {
        $profile = $this->createModel();
        $user = User::factory()->create(['brand' => 'rp']);
        $profile->customer->forceFill(['user_id' => $user->id])->save();

        // Different owner, same brand.
        $other = $this->createModel('rp', 'other@example.com');

        // Same owner, different brand.
        $foreign = $this->createModel('srp', 'foreign@example.com');
        $foreign->customer->forceFill(['user_id' => $user->id])->save();

        $response = $this->actingAs($user, 'api')->getJson('/api/me/models');

        $response->assertOk()->assertJsonCount(1);
        $this->assertSame($profile->id, $response->json('0.id'));
    }

    public function test_me_models_requires_authentication(): void
    {
        $this->getJson('/api/me/models')->assertStatus(401);
    }

    public function test_update_requires_age_proof_when_profile_has_none(): void
    {
        $profile = $this->createModel();
        $profile->forceFill(['age_proof_path' => null, 'age_proof_uploaded_at' => null])->save();
        $token = $this->issueToken($profile->customer);

        // No file → the invariant "profile has a proof afterwards" is enforced.
        $this->postJson("/api/model-profil/{$token->token}", ['answers' => $this->personAnswers()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['age_proof']);

        // With an upload the update succeeds and the profile keeps a proof.
        $this->post(
            "/api/model-profil/{$token->token}",
            ['answers' => $this->personAnswers(), 'age_proof' => UploadedFile::fake()->image('ausweis.jpg')],
            ['Accept' => 'application/json']
        )->assertOk();

        $profile->refresh();
        $this->assertTrue($profile->age_proof_required);
        $this->assertNotNull($profile->age_proof_path);
        $this->assertTrue(app(ModelFileStore::class)->exists($profile->age_proof_path));
    }

    public function test_update_replaces_age_proof_and_removes_the_old_file(): void
    {
        $profile = $this->createModel();
        $token = $this->issueToken($profile->customer);
        $oldPath = $profile->age_proof_path;
        $this->assertNotNull($oldPath);

        $this->post(
            "/api/model-profil/{$token->token}",
            ['answers' => $this->personAnswers(), 'age_proof' => UploadedFile::fake()->image('neu.jpg')],
            ['Accept' => 'application/json']
        )->assertOk();

        $profile->refresh();
        $this->assertNotSame($oldPath, $profile->age_proof_path);
        Storage::disk('local')->assertMissing($oldPath);
        $this->assertTrue(app(ModelFileStore::class)->exists($profile->age_proof_path));
    }

    public function test_update_is_atomic_when_a_metadata_write_fails(): void
    {
        $profile = $this->createModel();
        $token = $this->issueToken($profile->customer);
        $oldPath = $profile->age_proof_path;
        $oldCatalogVersion = $profile->catalog_version;
        $oldAnswers = $profile->answers;
        $this->assertNotNull($oldPath);

        // Fail during the customer write, i.e. after the profile save + photo
        // updates inside the update transaction.
        Customer::updating(function (): void {
            throw new \RuntimeException('Simulated failure during customer write');
        });

        $answers = $this->personAnswers();
        $answers['city'] = 'Graz';

        $this->post(
            "/api/model-profil/{$token->token}",
            ['answers' => $answers, 'age_proof' => UploadedFile::fake()->image('neu.jpg')],
            ['Accept' => 'application/json']
        )->assertStatus(500);

        $profile->refresh();
        $this->assertSame($oldCatalogVersion, $profile->catalog_version);
        $this->assertSame($oldAnswers, $profile->answers);
        $this->assertSame($oldPath, $profile->age_proof_path);

        // Old file untouched, freshly stored proof cleaned up (exactly one file).
        Storage::disk('local')->assertExists($oldPath);
        $this->assertCount(1, Storage::disk('local')->allFiles('model-age-proofs'));
    }
}
