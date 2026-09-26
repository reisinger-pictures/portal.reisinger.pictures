<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Act;
use App\Models\Customer;
use App\Models\ModelAccessToken;
use App\Models\ModelPhoto;
use App\Models\ModelProfile;
use App\Models\ModelRegistrationInvite;
use App\Models\Role;
use App\Models\User;
use App\Services\ModelFileStore;
use App\Services\ModelPhotoPrimaryService;
use App\Services\ModelQuestionnaire;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * CR-DATA-006: admin and owner primary-photo promotions share one profile
 * mutex. The test database is SQLite :memory:, so the two request orderings
 * below are the deterministic winner/loser stand-ins for parallel requests;
 * the cache-lock regression still exercises a real shared lock handle.
 */
class ModelPhotoPrimaryPromotionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        BrandRegistry::set(Brand::B2B);
        Storage::fake('local');
    }

    public function test_owner_then_admin_promotions_leave_exactly_one_primary(): void
    {
        $data = $this->profileWithPhotos();

        // This is the owner-winner/admin-loser ordering of two requests that
        // arrive concurrently. The second request must observe and preserve
        // the first request's committed primary.
        $this->postJson("/api/model-profil/{$data['token']}", [
            'answers' => $this->answers(),
            'photos' => [['id' => $data['second']->id, 'is_primary' => true]],
        ])->assertOk();

        $this->actingAs($this->admin(), 'api')
            ->postJson("/api/management/models/{$data['profile']->id}/photos/{$data['third']->id}/primary")
            ->assertOk()
            ->assertJsonPath('primary_photo_id', $data['third']->id);

        $this->assertOnePrimary($data['profile'], $data['third']);
    }

    public function test_admin_then_owner_promotions_leave_exactly_one_primary(): void
    {
        $data = $this->profileWithPhotos();

        // Reverse the deterministic request order as well. This guards the
        // owner path against restoring a stale primary after the admin wins.
        $this->actingAs($this->admin(), 'api')
            ->postJson("/api/management/models/{$data['profile']->id}/photos/{$data['second']->id}/primary")
            ->assertOk();

        $this->postJson("/api/model-profil/{$data['token']}", [
            'answers' => $this->answers(),
            'photos' => [['id' => $data['third']->id, 'is_primary' => true]],
        ])->assertOk();

        $this->assertOnePrimary($data['profile'], $data['third']);
    }

    public function test_repromoting_the_current_primary_forces_a_database_write(): void
    {
        $data = $this->profileWithPhotos();
        $primaryWrites = 0;

        ModelPhoto::saving(function (ModelPhoto $photo) use ($data, &$primaryWrites): void {
            if ($photo->id === $data['first']->id && $photo->is_primary) {
                $primaryWrites++;
            }
        });

        $primary = app(ModelPhotoPrimaryService::class)
            ->promote($data['profile'], $data['first']->id);

        $this->assertSame($data['first']->id, $primary->id);
        $this->assertSame(1, $primaryWrites);
        $this->assertTrue($primary->wasChanged('is_primary'));
        $this->assertTrue($data['first']->fresh()->is_primary);
    }

    public function test_registration_resubmission_repromotes_existing_primary_through_shared_service(): void
    {
        $customer = Customer::withoutSyncingToSearch(fn (): Customer => Customer::factory()->create([
            'brand' => 'rp',
            'email' => 'anna@example.com',
            'is_model' => true,
        ]));
        $store = app(ModelFileStore::class);
        $proofPath = "model-age-proofs/{$customer->id}/old-proof.jpg";
        $store->putEncrypted($proofPath, 'old-proof');
        $profile = ModelProfile::create([
            'customer_id' => $customer->id,
            'catalog_version' => ModelQuestionnaire::CURRENT,
            'answers' => [],
            'age_proof_required' => true,
            'age_proof_path' => $proofPath,
            'age_proof_uploaded_at' => now(),
            'submitted_at' => now(),
        ]);
        $existingPrimary = $this->photo($profile, 'existing-primary.jpg', true, 0);
        $inviter = User::factory()->create(['brand' => 'rp']);
        $invite = ModelRegistrationInvite::create([
            'token' => bin2hex(random_bytes(32)),
            'email' => 'anna@example.com',
            'brand' => 'rp',
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
        ]);

        $lockName = 'model-photo-primary:'.$profile->getKey();
        $lockObservedDuringActCreate = false;
        $primaryUpdates = 0;
        Act::creating(function () use ($lockName, &$lockObservedDuringActCreate): void {
            $lockObservedDuringActCreate = Cache::lock($lockName)->isLocked();
        });
        ModelPhoto::updated(function (ModelPhoto $photo) use ($existingPrimary, &$primaryUpdates): void {
            if ($photo->id === $existingPrimary->id && $photo->wasChanged('is_primary')) {
                $primaryUpdates++;
            }
        });

        Mail::fake();

        $this->post("/api/model-registration/{$invite->token}", [
            'persons' => [[
                'answers' => $this->answers(),
                'create_account' => false,
                'age_proof' => UploadedFile::fake()->create('new-proof.jpg', 10, 'image/jpeg'),
                'photos' => [[
                    'file' => UploadedFile::fake()->create('new-internal.jpg', 10, 'image/jpeg'),
                    'visibility' => ModelPhoto::VISIBILITY_INTERNAL,
                ]],
            ]],
            'manager_index' => 0,
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertTrue($lockObservedDuringActCreate);
        $this->assertFalse(Cache::lock($lockName)->isLocked());
        $this->assertSame(1, $primaryUpdates);
        $this->assertTrue($existingPrimary->fresh()->is_primary);
        $this->assertSame(1, $profile->photos()->where('is_primary', true)->count());
    }

    public function test_owner_visibility_only_update_keeps_the_lock_until_metadata_commit(): void
    {
        $data = $this->profileWithPhotos();
        $lockName = 'model-photo-primary:'.$data['profile']->getKey();
        $baseTransactionLevel = DB::connection()->transactionLevel();
        $lockObservedDuringCustomerWrite = false;
        $lockObservedAfterCommit = false;
        $transactionLevelDuringCustomerWrite = 0;
        $primaryWrites = 0;

        Customer::saving(function (Customer $customer) use ($data, $lockName, &$lockObservedDuringCustomerWrite, &$lockObservedAfterCommit, &$transactionLevelDuringCustomerWrite): void {
            if ((string) $customer->getKey() === (string) $data['profile']->customer_id) {
                $lockObservedDuringCustomerWrite = Cache::lock($lockName)->isLocked();
                $transactionLevelDuringCustomerWrite = DB::connection()->transactionLevel();
                DB::afterCommit(function () use ($lockName, &$lockObservedAfterCommit): void {
                    $lockObservedAfterCommit = Cache::lock($lockName)->isLocked();
                });
            }
        });
        ModelPhoto::saving(function (ModelPhoto $photo) use ($data, &$primaryWrites): void {
            if ($photo->id === $data['first']->id && $photo->is_primary) {
                $primaryWrites++;
            }
        });

        $this->postJson("/api/model-profil/{$data['token']}", [
            'answers' => $this->answers(),
            'photos' => [[
                'id' => $data['second']->id,
                'visibility' => ModelPhoto::VISIBILITY_INTERNAL,
            ]],
        ])->assertOk();

        $this->assertTrue($lockObservedDuringCustomerWrite);
        $this->assertTrue($lockObservedAfterCommit);
        $this->assertSame($baseTransactionLevel + 1, $transactionLevelDuringCustomerWrite);
        $this->assertSame(0, $primaryWrites);
        $this->assertFalse(Cache::lock($lockName)->isLocked());
        $this->assertTrue($data['first']->fresh()->is_primary);
        $this->assertSame(ModelPhoto::VISIBILITY_INTERNAL, $data['second']->fresh()->visibility);
        $this->assertSame(1, $data['profile']->photos()->where('is_primary', true)->count());
    }

    public function test_owner_visibility_only_update_repairs_a_legacy_duplicate(): void
    {
        $data = $this->profileWithPhotos();

        $data['first']->forceFill(['is_primary' => true])->save();
        $data['second']->forceFill(['is_primary' => true])->save();
        $this->assertSame(2, $data['profile']->photos()->where('is_primary', true)->count());

        $this->postJson("/api/model-profil/{$data['token']}", [
            'answers' => $this->answers(),
            'photos' => [[
                'id' => $data['third']->id,
                'visibility' => ModelPhoto::VISIBILITY_INTERNAL,
            ]],
        ])->assertOk();

        $this->assertOnePrimary($data['profile'], $data['first']);
        $this->assertFalse($data['second']->fresh()->is_primary);
        $this->assertSame(ModelPhoto::VISIBILITY_INTERNAL, $data['third']->fresh()->visibility);
    }

    public function test_cancelled_photo_delete_keeps_the_primary_row_and_file(): void
    {
        $data = $this->profileWithPhotos();
        $photoId = $data['first']->id;

        ModelPhoto::deleting(function (ModelPhoto $photo) use ($photoId): bool {
            return $photo->id !== $photoId;
        });

        $this->actingAs($this->admin(), 'api')
            ->deleteJson("/api/management/models/{$data['profile']->id}/photos/{$photoId}")
            ->assertStatus(500);

        $this->assertDatabaseHas('model_photos', [
            'id' => $photoId,
            'is_primary' => true,
        ]);
        $this->assertTrue($data['first']->fresh()->is_primary);
        Storage::disk('local')->assertExists($data['first']->path);
    }

    public function test_failed_photo_delete_rolls_back_the_primary_and_file(): void
    {
        $data = $this->profileWithPhotos();
        $photoId = $data['first']->id;

        ModelPhoto::deleting(function (): void {
            throw new \RuntimeException('simulated photo deletion failure');
        });

        $this->actingAs($this->admin(), 'api')
            ->deleteJson("/api/management/models/{$data['profile']->id}/photos/{$photoId}")
            ->assertStatus(500);

        $this->assertDatabaseHas('model_photos', [
            'id' => $photoId,
            'is_primary' => true,
        ]);
        Storage::disk('local')->assertExists($data['first']->path);
    }

    public function test_promotion_waits_for_a_shared_profile_cache_lock(): void
    {
        $data = $this->profileWithPhotos();
        // A second lock handle on the same shared cache key forces the real
        // block/wait path; SQLite :memory: cannot provide a second DB writer.
        $lock = Cache::lock('model-photo-primary:'.$data['profile']->getKey(), 1);

        $this->assertTrue($lock->get());
        $startedAt = microtime(true);

        try {
            $primary = app(ModelPhotoPrimaryService::class)
                ->promote($data['profile'], $data['second']->id);
        } finally {
            $lock->release();
        }

        $this->assertGreaterThan(0.5, microtime(true) - $startedAt);
        $this->assertSame($data['second']->id, $primary->id);
        $this->assertTrue($data['second']->fresh()->is_primary);
    }

    public function test_a_legacy_duplicate_is_normalized_by_the_shared_promotion_path(): void
    {
        $data = $this->profileWithPhotos();

        // This is the durable state a pre-fix interleaving could leave behind.
        // A subsequent admin/owner request must repair it, not add a third
        // primary or preserve the duplicate.
        $data['first']->forceFill(['is_primary' => true])->save();
        $data['second']->forceFill(['is_primary' => true])->save();
        $this->assertSame(2, $data['profile']->photos()->where('is_primary', true)->count());

        $this->postJson("/api/model-profil/{$data['token']}", [
            'answers' => $this->answers(),
            'photos' => [['id' => $data['third']->id, 'is_primary' => true]],
        ])->assertOk();

        $this->assertOnePrimary($data['profile'], $data['third']);
    }

    public function test_a_failed_promotion_rolls_back_the_clear_before_set(): void
    {
        $data = $this->profileWithPhotos();

        ModelPhoto::saving(function (ModelPhoto $photo) use ($data): void {
            if ($photo->id === $data['second']->id && $photo->is_primary) {
                throw new \RuntimeException('simulated primary write failure');
            }
        });

        try {
            app(ModelPhotoPrimaryService::class)->promote($data['profile'], $data['second']->id);
            $this->fail('Expected the simulated primary write failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated primary write failure', $exception->getMessage());
        }

        $this->assertTrue($data['first']->fresh()->is_primary);
        $this->assertFalse($data['second']->fresh()->is_primary);
        $this->assertSame(1, $data['profile']->photos()->where('is_primary', true)->count());
    }

    /**
     * @return array{profile: ModelProfile, first: ModelPhoto, second: ModelPhoto, third: ModelPhoto, token: string}
     */
    private function profileWithPhotos(): array
    {
        $customer = Customer::withoutSyncingToSearch(fn (): Customer => Customer::factory()->create([
            'brand' => 'rp',
            'is_model' => true,
        ]));
        $store = app(ModelFileStore::class);
        $proof = "model-age-proofs/{$customer->id}/proof.jpg";
        $store->putEncrypted($proof, 'proof');

        $profile = ModelProfile::create([
            'customer_id' => $customer->id,
            'catalog_version' => ModelQuestionnaire::CURRENT,
            'answers' => [],
            'age_proof_required' => true,
            'age_proof_path' => $proof,
            'age_proof_uploaded_at' => now(),
            'submitted_at' => now(),
        ]);

        $first = $this->photo($profile, 'first.jpg', true, 0);
        $second = $this->photo($profile, 'second.jpg', false, 1);
        $third = $this->photo($profile, 'third.jpg', false, 2);

        return [
            'profile' => $profile,
            'first' => $first,
            'second' => $second,
            'third' => $third,
            'token' => ModelAccessToken::issueFor($customer)->token,
        ];
    }

    private function photo(ModelProfile $profile, string $name, bool $primary, int $position): ModelPhoto
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
            'visibility' => ModelPhoto::VISIBILITY_PUBLIC,
            'is_primary' => $primary,
            'position' => $position,
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['brand' => 'rp']);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function answers(): array
    {
        $answers = [
            'first_name' => 'Anna',
            'last_name' => 'Beispiel',
            'stage_name' => 'Nova',
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

    private function assertOnePrimary(ModelProfile $profile, ModelPhoto $expected): void
    {
        $primaries = $profile->photos()->where('is_primary', true)->get();

        $this->assertCount(1, $primaries);
        $this->assertTrue($expected->fresh()->is_primary);
        $this->assertSame($expected->id, $primaries->first()->id);
    }
}
