<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Act;
use App\Models\Customer;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Personen-Fotos: Upload-Limits, Sichtbarkeit, Hauptbild, verschlüsselte
 * Ablage, auth-gated Delivery und Löschen.
 */
class ModelPhotoTest extends TestCase
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

    /**
     * @param  array<int, array<string, mixed>>  $photos
     * @return array<string, mixed>
     */
    private function person(array $photos = []): array
    {
        $person = [
            'answers' => $this->personAnswers(),
            'create_account' => false,
            'age_proof' => UploadedFile::fake()->image('ausweis.jpg'),
        ];

        if ($photos !== []) {
            $person['photos'] = $photos;
        }

        return $person;
    }

    private function submit(ModelRegistrationInvite $invite, array $persons)
    {
        return $this->post(
            "/api/model-registration/{$invite->token}",
            ['persons' => $persons, 'manager_index' => 0],
            ['Accept' => 'application/json']
        );
    }

    private function storePhoto(Customer $customer, ModelProfile $profile, string $visibility = 'internal', bool $primary = false): ModelPhoto
    {
        $stored = app(ModelFileStore::class)->storeUpload(
            "model-photos/{$customer->id}",
            UploadedFile::fake()->image('foto.jpg')
        );

        return ModelPhoto::create([
            'customer_id' => $customer->id,
            'model_profile_id' => $profile->id,
            'path' => $stored['path'],
            'original_name' => 'foto.jpg',
            'mime_type' => $stored['mime'],
            'size_bytes' => $stored['size'],
            'visibility' => $visibility,
            'is_primary' => $primary,
            'position' => 0,
        ]);
    }

    public function test_submit_stores_photos_with_visibility_and_requested_primary(): void
    {
        $response = $this->submit($this->invite(), [$this->person([
            ['file' => UploadedFile::fake()->image('a.jpg'), 'visibility' => 'internal'],
            ['file' => UploadedFile::fake()->image('b.jpg'), 'visibility' => 'public', 'is_primary' => '1'],
        ])]);

        $response->assertCreated();

        $profile = ModelProfile::firstOrFail();
        $this->assertSame(2, $profile->photos()->count());

        $primary = $profile->photos()->where('is_primary', true)->get();
        $this->assertCount(1, $primary);
        $this->assertSame('public', $primary->first()->visibility);

        foreach ($profile->photos as $photo) {
            Storage::disk('local')->assertExists($photo->path);
            $this->assertTrue(app(ModelFileStore::class)->isEncrypted($photo->path));
        }
    }

    public function test_submit_defaults_visibility_to_internal_without_primary(): void
    {
        $this->submit($this->invite(), [$this->person([
            ['file' => UploadedFile::fake()->image('a.jpg')],
            ['file' => UploadedFile::fake()->image('b.jpg')],
        ])])->assertCreated();

        $profile = ModelProfile::firstOrFail();
        $this->assertSame(2, $profile->photos()->where('visibility', 'internal')->count());

        // Default visibility is internal; a primary photo must be public, so an
        // all-internal batch has no primary.
        $this->assertSame(0, $profile->photos()->where('is_primary', true)->count());
    }

    public function test_submit_rejects_more_than_five_photos(): void
    {
        $photos = [];
        for ($i = 0; $i < 6; $i++) {
            $photos[] = ['file' => UploadedFile::fake()->image("p{$i}.jpg")];
        }

        $this->submit($this->invite(), [$this->person($photos)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['persons.0.photos']);
    }

    public function test_submit_rejects_sixth_photo_against_existing_photos(): void
    {
        $customer = Customer::factory()->create([
            'brand' => 'rp',
            'is_model' => true,
            'email' => 'anna@example.com',
        ]);
        $profile = ModelProfile::create([
            'customer_id' => $customer->id,
            'catalog_version' => 'v1',
            'answers' => [],
            'submitted_at' => now(),
        ]);
        for ($i = 0; $i < 5; $i++) {
            $this->storePhoto($customer, $profile, 'internal', $i === 0);
        }

        $this->submit($this->invite(), [$this->person([
            ['file' => UploadedFile::fake()->image('extra.jpg')],
        ])])->assertStatus(422)->assertJsonValidationErrors(['persons.0.photos']);

        $this->assertSame(5, $profile->photos()->count());
    }

    public function test_submit_rejects_invalid_photo_mime(): void
    {
        $this->submit($this->invite(), [$this->person([
            ['file' => UploadedFile::fake()->create('malware.txt', 10, 'text/plain')],
        ])])->assertStatus(422)->assertJsonValidationErrors(['persons.0.photos.0.file']);
    }

    public function test_admin_can_download_photo_and_it_is_audit_logged(): void
    {
        Log::spy();

        $admin = $this->admin('rp');
        $customer = Customer::factory()->create(['brand' => 'rp', 'is_model' => true]);
        $profile = ModelProfile::create([
            'customer_id' => $customer->id,
            'catalog_version' => 'v1',
            'answers' => [],
            'submitted_at' => now(),
        ]);
        $photo = $this->storePhoto($customer, $profile, 'internal', true);

        $expected = app(ModelFileStore::class)->getDecrypted($photo->path);

        $response = $this->actingAs($admin, 'api')
            ->get("/api/management/models/{$profile->id}/photos/{$photo->id}");

        $response->assertOk()->assertDownload('foto.jpg');
        $this->assertSame($expected, $response->streamedContent());

        Log::shouldHaveReceived('info')
            ->with('model.file.download', Mockery::on(
                fn (array $context) => $context['type'] === 'photo' && $context['photo_id'] === $photo->id
            ))
            ->once();
    }

    public function test_photo_download_is_brand_scoped(): void
    {
        $admin = $this->admin('rp');
        $customer = Customer::factory()->create(['brand' => 'srp', 'is_model' => true]);
        $profile = ModelProfile::create([
            'customer_id' => $customer->id,
            'catalog_version' => 'v1',
            'answers' => [],
            'submitted_at' => now(),
        ]);
        $photo = $this->storePhoto($customer, $profile, 'internal', true);

        $this->actingAs($admin, 'api')
            ->getJson("/api/management/models/{$profile->id}/photos/{$photo->id}")
            ->assertNotFound();
    }

    public function test_delete_photo_removes_file_and_resets_primary(): void
    {
        $admin = $this->admin('rp');
        $customer = Customer::factory()->create(['brand' => 'rp', 'is_model' => true]);
        $profile = ModelProfile::create([
            'customer_id' => $customer->id,
            'catalog_version' => 'v1',
            'answers' => [],
            'submitted_at' => now(),
        ]);
        $primary = $this->storePhoto($customer, $profile, 'public', true);
        $other = $this->storePhoto($customer, $profile, 'internal', false);

        $this->actingAs($admin, 'api')
            ->deleteJson("/api/management/models/{$profile->id}/photos/{$primary->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        Storage::disk('local')->assertMissing($primary->path);
        $this->assertDatabaseMissing('model_photos', ['id' => $primary->id]);
        $this->assertFalse($other->fresh()->is_primary);
    }

    public function test_admin_can_set_primary_photo(): void
    {
        $admin = $this->admin('rp');
        $customer = Customer::factory()->create(['brand' => 'rp', 'is_model' => true]);
        $profile = ModelProfile::create([
            'customer_id' => $customer->id,
            'catalog_version' => 'v1',
            'answers' => [],
            'submitted_at' => now(),
        ]);
        $first = $this->storePhoto($customer, $profile, 'internal', true);
        $second = $this->storePhoto($customer, $profile, 'public', false);

        $this->actingAs($admin, 'api')
            ->postJson("/api/management/models/{$profile->id}/photos/{$second->id}/primary")
            ->assertOk()
            ->assertJsonPath('primary_photo_id', $second->id);

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
    }

    public function test_resubmit_replaces_and_deletes_the_previous_age_proof(): void
    {
        $this->submit($this->invite(), [$this->person()])->assertCreated();
        $profile = ModelProfile::firstOrFail();
        $oldPath = $profile->age_proof_path;
        $this->assertNotNull($oldPath);
        Storage::disk('local')->assertExists($oldPath);

        // Second submit (same e-mail within the same brand → same customer/profile)
        // must replace the age proof and remove the superseded file.
        $this->submit($this->invite(), [$this->person()])->assertCreated();

        $profile->refresh();
        $newPath = $profile->age_proof_path;
        $this->assertNotNull($newPath);
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($newPath);
    }

    public function test_photo_visibility_matches_non_sequential_keys(): void
    {
        // Metadata must be matched by the stable photo key, not a re-indexed
        // offset: keys 2 and 5 would otherwise both fall back to the default.
        $this->submit($this->invite(), [$this->person([
            2 => ['file' => UploadedFile::fake()->image('two.jpg'), 'visibility' => 'public'],
            5 => ['file' => UploadedFile::fake()->image('five.jpg'), 'visibility' => 'internal'],
        ])])->assertCreated();

        $profile = ModelProfile::firstOrFail();
        $this->assertSame(2, $profile->photos()->count());
        $this->assertSame(1, $profile->photos()->where('visibility', 'public')->count());
        $this->assertSame(1, $profile->photos()->where('visibility', 'internal')->count());

        $positions = $profile->photos()->orderBy('position')->pluck('position')->all();
        $this->assertSame([0, 1], $positions);
    }

    public function test_failed_resubmit_keeps_previous_age_proof(): void
    {
        $this->submit($this->invite(), [$this->person()])->assertCreated();
        $profile = ModelProfile::firstOrFail();
        $oldPath = $profile->age_proof_path;
        $this->assertNotNull($oldPath);
        Storage::disk('local')->assertExists($oldPath);

        // Fail after the age-proof swap but before the transaction commits.
        $swappedPath = null;
        Act::creating(function () use (&$swappedPath): void {
            $swappedPath = ModelProfile::query()->value('age_proof_path');

            throw new \RuntimeException('Simulated failure after the age-proof swap');
        });

        $this->submit($this->invite(), [$this->person()])->assertStatus(500);

        $this->assertNotNull($swappedPath);
        $this->assertNotSame($oldPath, $swappedPath);

        // The new file is cleaned up (orphan), the old file survives and the
        // rolled-back DB row still references it.
        Storage::disk('local')->assertMissing($swappedPath);
        Storage::disk('local')->assertExists($oldPath);
        $this->assertSame($oldPath, $profile->fresh()->age_proof_path);
    }
}
