<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\ModelAccessToken;
use App\Models\ModelPhoto;
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
 * Regel: Ein Hauptbild (`is_primary`) muss öffentlich sein. Serverseitig
 * erzwungen bei Submit, Profil-Update (Magic Link) und Primary-Wechsel im
 * Management (422 mit Feld-Fehler).
 */
class ModelPhotoPrimaryVisibilityTest extends TestCase
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

    private function invite(): ModelRegistrationInvite
    {
        $inviter = User::factory()->create(['brand' => Brand::B2B]);

        return ModelRegistrationInvite::create([
            'token' => 'token-'.bin2hex(random_bytes(8)),
            'email' => 'manager@example.com',
            'brand' => Brand::B2B,
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function personAnswers(): array
    {
        $answers = [
            'first_name' => 'Anna',
            'last_name' => 'Beispiel',
            'birthdate' => '1995-05-05',
            'email' => 'anna@example.com',
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

    /**
     * @param  array<int, array<string, mixed>>  $photos
     * @return array<string, mixed>
     */
    private function person(array $photos): array
    {
        return [
            'answers' => $this->personAnswers(),
            'create_account' => false,
            'age_proof' => UploadedFile::fake()->image('ausweis.jpg'),
            'photos' => $photos,
        ];
    }

    private function submit(ModelRegistrationInvite $invite, array $persons)
    {
        return $this->post(
            "/api/model-registration/{$invite->token}",
            ['persons' => $persons, 'manager_index' => 0],
            ['Accept' => 'application/json']
        );
    }

    /**
     * @return array{profile: ModelProfile, public: ModelPhoto, internal: ModelPhoto, token: string}
     */
    private function profileWithPhotos(): array
    {
        $customer = Customer::factory()->create(['brand' => 'rp', 'is_model' => true]);
        $store = app(ModelFileStore::class);

        $proof = "model-age-proofs/{$customer->id}/ausweis.jpg";
        $store->putEncrypted($proof, 'secret');

        $profile = ModelProfile::create([
            'customer_id' => $customer->id,
            'catalog_version' => ModelQuestionnaire::CURRENT,
            'answers' => [],
            'age_proof_required' => true,
            'age_proof_path' => $proof,
            'age_proof_uploaded_at' => now(),
            'submitted_at' => now(),
        ]);

        $public = $this->photo($profile, 'public.jpg', 'public', true, 0);
        $internal = $this->photo($profile, 'internal.jpg', 'internal', false, 1);

        return [
            'profile' => $profile,
            'public' => $public,
            'internal' => $internal,
            'token' => ModelAccessToken::issueFor($customer)->token,
        ];
    }

    private function photo(ModelProfile $profile, string $name, string $visibility, bool $primary, int $position): ModelPhoto
    {
        $path = "model-photos/{$profile->customer_id}/{$name}";
        app(ModelFileStore::class)->putEncrypted($path, $name);

        return ModelPhoto::create([
            'customer_id' => $profile->customer_id,
            'model_profile_id' => $profile->id,
            'path' => $path,
            'original_name' => $name,
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'visibility' => $visibility,
            'is_primary' => $primary,
            'position' => $position,
        ]);
    }

    public function test_submit_rejects_internal_requested_primary(): void
    {
        $this->submit($this->invite(), [$this->person([
            ['file' => UploadedFile::fake()->image('a.jpg'), 'visibility' => 'internal', 'is_primary' => '1'],
        ])])->assertStatus(422)
            ->assertJsonValidationErrors(['persons.0.photos.0.is_primary']);

        $this->assertDatabaseCount('model_photos', 0);
    }

    public function test_submit_accepts_public_requested_primary(): void
    {
        $this->submit($this->invite(), [$this->person([
            ['file' => UploadedFile::fake()->image('a.jpg'), 'visibility' => 'public', 'is_primary' => '1'],
            ['file' => UploadedFile::fake()->image('b.jpg'), 'visibility' => 'internal'],
        ])])->assertCreated();

        $profile = ModelProfile::firstOrFail();
        $primary = $profile->photos()->where('is_primary', true)->get();
        $this->assertCount(1, $primary);
        $this->assertSame('public', $primary->first()->visibility);
    }

    public function test_submit_elects_a_public_primary_without_explicit_request(): void
    {
        $this->submit($this->invite(), [$this->person([
            ['file' => UploadedFile::fake()->image('a.jpg'), 'visibility' => 'internal'],
            ['file' => UploadedFile::fake()->image('b.jpg'), 'visibility' => 'public'],
        ])])->assertCreated();

        $profile = ModelProfile::firstOrFail();
        $primary = $profile->photos()->where('is_primary', true)->get();
        $this->assertCount(1, $primary);
        $this->assertSame('public', $primary->first()->visibility);
    }

    public function test_submit_leaves_no_primary_when_all_photos_are_internal(): void
    {
        $this->submit($this->invite(), [$this->person([
            ['file' => UploadedFile::fake()->image('a.jpg'), 'visibility' => 'internal'],
            ['file' => UploadedFile::fake()->image('b.jpg'), 'visibility' => 'internal'],
        ])])->assertCreated();

        $profile = ModelProfile::firstOrFail();
        $this->assertSame(0, $profile->photos()->where('is_primary', true)->count());
    }

    public function test_profile_update_rejects_internal_primary(): void
    {
        $data = $this->profileWithPhotos();

        $this->postJson("/api/model-profil/{$data['token']}", [
            'answers' => $this->personAnswers(),
            'photos' => [['id' => $data['internal']->id, 'is_primary' => true]],
        ])->assertStatus(422)->assertJsonValidationErrors(['photos.0.is_primary']);

        $this->assertTrue($data['public']->fresh()->is_primary);
        $this->assertFalse($data['internal']->fresh()->is_primary);
    }

    public function test_profile_update_sets_public_primary(): void
    {
        $data = $this->profileWithPhotos();
        // Start with no primary, then promote the public one.
        ModelPhoto::where('model_profile_id', $data['profile']->id)->update(['is_primary' => false]);

        $this->postJson("/api/model-profil/{$data['token']}", [
            'answers' => $this->personAnswers(),
            'photos' => [['id' => $data['public']->id, 'is_primary' => true]],
        ])->assertOk();

        $this->assertTrue($data['public']->fresh()->is_primary);
    }

    public function test_profile_update_rejects_demoting_primary_to_internal(): void
    {
        $data = $this->profileWithPhotos();

        $this->postJson("/api/model-profil/{$data['token']}", [
            'answers' => $this->personAnswers(),
            'photos' => [['id' => $data['public']->id, 'visibility' => 'internal']],
        ])->assertStatus(422)->assertJsonValidationErrors(['photos']);

        $primary = $data['public']->fresh();
        $this->assertSame('public', $primary->visibility);
        $this->assertTrue($primary->is_primary);
    }

    public function test_profile_update_clears_primary_on_explicit_false_when_demoting(): void
    {
        $data = $this->profileWithPhotos();
        // Only the (public) primary photo remains relevant.
        $data['internal']->delete();

        $this->postJson("/api/model-profil/{$data['token']}", [
            'answers' => $this->personAnswers(),
            'photos' => [['id' => $data['public']->id, 'visibility' => 'internal', 'is_primary' => false]],
        ])->assertOk();

        $demoted = $data['public']->fresh();
        $this->assertSame('internal', $demoted->visibility);
        $this->assertFalse($demoted->is_primary);
        $this->assertSame(0, $data['profile']->photos()->where('is_primary', true)->count());
    }

    public function test_profile_update_can_clear_primary_without_changing_visibility(): void
    {
        $data = $this->profileWithPhotos();

        $this->postJson("/api/model-profil/{$data['token']}", [
            'answers' => $this->personAnswers(),
            'photos' => [['id' => $data['public']->id, 'is_primary' => false]],
        ])->assertOk();

        $this->assertFalse($data['public']->fresh()->is_primary);
        $this->assertSame('public', $data['public']->fresh()->visibility);
        $this->assertSame(0, $data['profile']->photos()->where('is_primary', true)->count());
    }

    public function test_management_rejects_internal_primary(): void
    {
        $data = $this->profileWithPhotos();

        $this->actingAs($this->admin('rp'), 'api')
            ->postJson("/api/management/models/{$data['profile']->id}/photos/{$data['internal']->id}/primary")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_primary']);

        $this->assertTrue($data['public']->fresh()->is_primary);
    }

    public function test_management_sets_public_primary(): void
    {
        $data = $this->profileWithPhotos();
        ModelPhoto::where('model_profile_id', $data['profile']->id)->update(['is_primary' => false]);

        $this->actingAs($this->admin('rp'), 'api')
            ->postJson("/api/management/models/{$data['profile']->id}/photos/{$data['public']->id}/primary")
            ->assertOk()
            ->assertJsonPath('primary_photo_id', $data['public']->id);

        $this->assertTrue($data['public']->fresh()->is_primary);
    }
}
