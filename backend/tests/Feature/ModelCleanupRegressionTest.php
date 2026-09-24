<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Jobs\DeleteModelFilesJob;
use App\Jobs\SyncCustomerSearchJob;
use App\Models\Act;
use App\Models\ActMember;
use App\Models\Customer;
use App\Models\ModelAccessToken;
use App\Models\ModelPhoto;
use App\Models\ModelProfile;
use App\Models\ModelRegistrationInvite;
use App\Models\Role;
use App\Models\User;
use App\Services\ModelFileStore;
use App\Services\ModelProfileEraser;
use App\Services\ModelQuestionnaire;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Focused regressions for the CRM hard-delete/update cleanup contract.
 */
class ModelCleanupRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'scout.driver' => 'null',
            'queue.default' => 'sync',
        ]);
        BrandRegistry::set(Brand::B2B);
        Mail::fake();
        Storage::fake('local');
    }

    public function test_admin_customer_email_update_keeps_encrypted_profile_snapshot_in_sync(): void
    {
        $admin = $this->superAdmin();
        $model = $this->modelWithProfile();
        $profile = $model['profile'];
        $newEmail = 'canonical-updated@example.com';

        Queue::fake();

        $this->actingAs($admin, 'api')
            ->putJson("/api/management/customers/{$model['customer']->id}", [
                'email' => $newEmail,
            ])
            ->assertOk()
            ->assertJsonPath('customer.email', $newEmail);

        $this->assertSame($newEmail, $model['customer']->fresh()->email);
        $this->assertSame($newEmail, $profile->fresh()->answersMap()['email']);

        $rawAnswers = DB::table('model_profiles')->where('id', $profile->id)->value('answers');
        $this->assertIsString($rawAnswers);
        $this->assertStringNotContainsString($newEmail, $rawAnswers);

        Queue::assertPushed(SyncCustomerSearchJob::class, function (SyncCustomerSearchJob $job) use ($model): bool {
            return $job->customerId() === (string) $model['customer']->id
                && $job->operation() === SyncCustomerSearchJob::INDEX;
        });
    }

    public function test_owner_email_update_keeps_the_canonical_customer_in_sync(): void
    {
        $model = $this->modelWithProfile();
        $token = ModelAccessToken::issueFor($model['customer']);
        $updatedEmail = 'owner-updated@example.com';
        Queue::fake();

        $this->postJson("/api/model-profil/{$token->token}", [
            'answers' => $this->answers($updatedEmail),
        ])->assertOk();

        $this->assertSame($updatedEmail, $model['customer']->fresh()->email);
        $this->assertSame($updatedEmail, $model['profile']->fresh()->answersMap()['email']);
        Queue::assertPushed(SyncCustomerSearchJob::class);
    }

    public function test_scoped_model_customer_update_rolls_back_before_scout_job_is_queued(): void
    {
        $model = $this->modelWithProfile();
        $token = ModelAccessToken::issueFor($model['customer']);
        $oldEmail = $model['customer']->email;
        $oldAnswers = $model['profile']->answers;
        $failTouch = true;

        // The customer write happens before touch(). If touch() fails, the
        // profile/customer transaction must roll back and no search job may be
        // registered for the uncommitted address.
        ModelAccessToken::saving(function () use (&$failTouch): void {
            if ($failTouch) {
                throw new RuntimeException('simulated token write failure');
            }
        });
        Queue::fake();

        $answers = $this->answers('rolled-back@example.com');
        $this->postJson("/api/model-profil/{$token->token}", ['answers' => $answers])
            ->assertStatus(500);

        $failTouch = false;
        $this->assertSame($oldEmail, $model['customer']->fresh()->email);
        $this->assertSame($oldAnswers, $model['profile']->fresh()->answers);
        Queue::assertNotPushed(SyncCustomerSearchJob::class);
    }

    public function test_erasure_uses_the_authoritative_path_seen_at_the_delete_boundary(): void
    {
        $model = $this->modelWithProfile();
        $photo = ModelPhoto::create([
            'customer_id' => $model['customer']->id,
            'model_profile_id' => $model['profile']->id,
            'path' => 'model-photos/old.jpg',
            'original_name' => 'old.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1,
            'visibility' => ModelPhoto::VISIBILITY_INTERNAL,
            'is_primary' => false,
            'position' => 0,
        ]);
        $newPath = 'model-photos/new.jpg';
        app(ModelFileStore::class)->putEncrypted($photo->path, 'old');
        app(ModelFileStore::class)->putEncrypted($newPath, 'new');

        $changed = false;
        DB::listen(function ($query) use (&$changed, $photo, $newPath): void {
            if ($changed || ! str_contains(strtolower($query->sql), 'model_photos')) {
                return;
            }

            $changed = true;
            ModelPhoto::whereKey($photo->id)->update(['path' => $newPath]);
        });

        app(ModelProfileEraser::class)->erase($model['customer'], 'test');

        $this->assertTrue($changed);
        Storage::disk('local')->assertMissing($newPath);
        Storage::disk('local')->assertExists($photo->path);
    }

    public function test_customerless_memberless_act_invite_is_removed_with_the_act(): void
    {
        $model = $this->modelWithProfile();
        $customer = $model['customer'];
        $inviter = User::factory()->create(['brand' => Brand::B2B]);
        $act = Act::create([
            'brand' => 'rp',
            'manager_customer_id' => $customer->id,
            'act_type' => 'single',
            'catalog_version' => 'v1',
            'answers' => [],
            'person_count' => 1,
            'submitted_at' => now(),
        ]);
        ActMember::create([
            'act_id' => $act->id,
            'customer_id' => $customer->id,
            'role' => 'manager',
            'position' => 0,
        ]);

        $customerlessInvite = ModelRegistrationInvite::create([
            'token' => 'customerless-'.bin2hex(random_bytes(16)),
            'email' => 'customerless-private@example.com',
            'label' => 'private label',
            'brand' => 'rp',
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
            'used_at' => now(),
            'act_id' => $act->id,
            'customer_id' => null,
        ]);
        $managerOnlyAct = Act::create([
            'brand' => 'rp',
            'manager_customer_id' => $customer->id,
            'act_type' => 'single',
            'catalog_version' => 'v1',
            'answers' => [],
            'person_count' => 1,
            'submitted_at' => now(),
        ]);
        $managerOnlyInvite = ModelRegistrationInvite::create([
            'token' => 'manager-only-'.bin2hex(random_bytes(16)),
            'email' => 'manager-only-private@example.com',
            'brand' => 'rp',
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
            'act_id' => $managerOnlyAct->id,
            'customer_id' => null,
        ]);
        $unrelatedInvite = ModelRegistrationInvite::create([
            'token' => 'unrelated-'.bin2hex(random_bytes(16)),
            'email' => 'unrelated@example.com',
            'brand' => 'rp',
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
        ]);

        Queue::fake();
        app(ModelProfileEraser::class)->erase($customer, 'test');

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        $this->assertDatabaseMissing('acts', ['id' => $act->id]);
        $this->assertDatabaseMissing('acts', ['id' => $managerOnlyAct->id]);
        $this->assertDatabaseMissing('model_registration_invites', ['id' => $customerlessInvite->id]);
        $this->assertDatabaseMissing('model_registration_invites', ['id' => $managerOnlyInvite->id]);
        $this->assertDatabaseHas('model_registration_invites', ['id' => $unrelatedInvite->id]);
    }

    public function test_cancelled_customer_delete_does_not_queue_search_or_leak_observer_state(): void
    {
        $customer = Customer::withoutSyncingToSearch(fn (): Customer => Customer::factory()->create([
            'brand' => 'rp',
        ]));
        $cancel = true;
        Customer::deleting(function () use (&$cancel) {
            if ($cancel) {
                return false;
            }

            return null;
        });
        Queue::fake();

        $this->assertFalse($customer->delete());
        $cancel = false;
        Queue::assertNotPushed(SyncCustomerSearchJob::class);
    }

    public function test_direct_customer_delete_queues_scout_removal_instead_of_calling_it_inline(): void
    {
        $customer = Customer::withoutSyncingToSearch(fn (): Customer => Customer::factory()->create([
            'brand' => 'rp',
        ]));

        $engine = Mockery::mock(Engine::class);
        $engine->shouldNotReceive('delete');
        $manager = Mockery::mock(EngineManager::class);
        $manager->shouldReceive('engine')->andReturn($engine);
        $this->app->instance(EngineManager::class, $manager);
        Queue::fake();

        $this->assertTrue($customer->delete());
        Queue::assertPushed(SyncCustomerSearchJob::class, function (SyncCustomerSearchJob $job) use ($customer): bool {
            return $job->customerId() === (string) $customer->id
                && $job->operation() === SyncCustomerSearchJob::REMOVE;
        });
    }

    public function test_scout_failure_after_customer_delete_is_logged_without_failing_the_delete(): void
    {
        $customer = Customer::withoutSyncingToSearch(fn (): Customer => Customer::factory()->create([
            'brand' => 'rp',
        ]));
        $exception = new RuntimeException('simulated post-delete scout failure');
        $engine = Mockery::mock(Engine::class);
        $engine->shouldReceive('delete')->once()->andThrow($exception);
        $manager = Mockery::mock(EngineManager::class);
        $manager->shouldReceive('engine')->andReturn($engine);
        $this->app->instance(EngineManager::class, $manager);
        Log::spy();

        $this->assertTrue($customer->delete());
        Log::shouldHaveReceived('error')
            ->with('customer.search_sync.dispatch_failed', Mockery::on(
                static fn (array $context): bool => $context['customer_id'] === (string) $customer->id
            ))
            ->once();
    }

    public function test_admin_customer_delete_cannot_bypass_model_eraser(): void
    {
        $admin = $this->superAdmin();
        $model = $this->modelWithProfile();
        $inviter = User::factory()->create(['brand' => 'rp']);
        $invite = ModelRegistrationInvite::create([
            'token' => 'admin-delete-'.bin2hex(random_bytes(16)),
            'email' => 'admin-delete-private@example.com',
            'brand' => 'rp',
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
            'customer_id' => $model['customer']->id,
        ]);
        Queue::fake();

        $this->actingAs($admin, 'api')
            ->deleteJson("/api/management/customers/{$model['customer']->id}")
            ->assertOk();

        $this->assertDatabaseMissing('customers', ['id' => $model['customer']->id]);
        $this->assertDatabaseMissing('model_registration_invites', ['id' => $invite->id]);
    }

    public function test_post_commit_file_and_search_cleanup_use_durable_jobs(): void
    {
        $model = $this->modelWithProfile();
        $customer = $model['customer'];
        Queue::fake();

        app(ModelProfileEraser::class)->erase($customer, 'test');

        Queue::assertPushed(DeleteModelFilesJob::class);
        Queue::assertPushed(SyncCustomerSearchJob::class, function (SyncCustomerSearchJob $job) use ($customer): bool {
            return $job->customerId() === (string) $customer->id
                && $job->operation() === SyncCustomerSearchJob::REMOVE;
        });
    }

    public function test_model_file_store_reports_a_file_that_remains_after_delete(): void
    {
        $disk = Mockery::mock();
        $disk->shouldReceive('delete')->once()->andReturn(false);
        $disk->shouldReceive('exists')->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('local')->once()->andReturn($disk);

        $this->expectException(RuntimeException::class);
        (new ModelFileStore)->delete('model-age-proofs/stuck.jpg');
    }

    public function test_failed_scout_unindex_job_is_audited_for_retry_handling(): void
    {
        $exception = new RuntimeException('simulated scout unindex failure');
        $engine = Mockery::mock(Engine::class);
        $engine->shouldReceive('delete')->once()->andThrow($exception);
        $manager = Mockery::mock(EngineManager::class);
        $manager->shouldReceive('engine')->andReturn($engine);
        $this->app->instance(EngineManager::class, $manager);
        Log::spy();

        $job = new SyncCustomerSearchJob('customer-1', SyncCustomerSearchJob::REMOVE);
        try {
            $job->handle();
            $this->fail('Expected the simulated Scout failure.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
            $job->failed($caught);
        }

        $this->assertSame(5, $job->tries);
        Log::shouldHaveReceived('error')
            ->with('customer.search_sync.failed', Mockery::on(
                static fn (array $context): bool => $context['customer_id'] === 'customer-1'
                    && $context['operation'] === SyncCustomerSearchJob::REMOVE
            ))
            ->once();
    }

    public function test_failed_model_file_job_is_audited_for_retry_handling(): void
    {
        Log::spy();
        $store = Mockery::mock(ModelFileStore::class);
        $store->shouldReceive('delete')
            ->once()
            ->andThrow(new RuntimeException('simulated private-disk failure'));
        $job = new DeleteModelFilesJob(['model-age-proofs/a/proof.jpg'], 'test', 'customer-1');

        try {
            $job->handle($store);
            $this->fail('Expected the simulated model-file failure.');
        } catch (RuntimeException $exception) {
            $job->failed($exception);
        }

        $this->assertSame(5, $job->tries);
        Log::shouldHaveReceived('error')
            ->with('model.file_cleanup.failed', Mockery::on(
                static fn (array $context): bool => $context['customer_id'] === 'customer-1'
                    && $context['reason'] === 'test'
                    && $context['path_count'] === 1
            ))
            ->once();
    }

    /**
     * @return array{customer: Customer, profile: ModelProfile, proof: string}
     */
    private function modelWithProfile(): array
    {
        $customer = Customer::withoutSyncingToSearch(fn (): Customer => Customer::factory()->create([
            'brand' => 'rp',
            'is_model' => true,
            'email' => 'canonical@example.com',
        ]));
        $proof = "model-age-proofs/{$customer->id}/proof.jpg";
        app(ModelFileStore::class)->putEncrypted($proof, 'proof');

        $profile = ModelProfile::create([
            'customer_id' => $customer->id,
            'catalog_version' => ModelQuestionnaire::CURRENT,
            'answers' => $this->questionnaire()->buildSnapshot('person', $this->answers(), [
                'is_manager' => true,
                'person_count' => 1,
            ]),
            'age_proof_required' => true,
            'age_proof_path' => $proof,
            'age_proof_uploaded_at' => now(),
            'submitted_at' => now(),
            'last_confirmed_at' => now(),
        ]);

        return ['customer' => $customer, 'profile' => $profile, 'proof' => $proof];
    }

    /**
     * @return array<string, mixed>
     */
    private function answers(string $email = 'canonical@example.com'): array
    {
        $answers = [
            'first_name' => 'Canonical',
            'last_name' => 'Model',
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
        ];
        foreach (array_keys(ModelQuestionnaire::SHOOTING_CATEGORIES) as $category) {
            $answers['willingness_'.$category] = 'nein';
        }
        $answers['willingness_stock'] = 'nein';

        return $answers;
    }

    private function questionnaire(): ModelQuestionnaire
    {
        return app(ModelQuestionnaire::class);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['brand' => 'rp']);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));

        return $user;
    }
}
