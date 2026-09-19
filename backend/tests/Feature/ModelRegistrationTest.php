<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Mail\ActivateAccountMail;
use App\Mail\ModelRegistrationSuccessMail;
use App\Models\Act;
use App\Models\Customer;
use App\Models\ModelProfile;
use App\Models\ModelRegistrationInvite;
use App\Models\Role;
use App\Models\User;
use App\Services\ModelQuestionnaire;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ModelRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
        Mail::fake();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function makeInvite(array $attributes = []): ModelRegistrationInvite
    {
        $inviter = User::factory()->create(['brand' => Brand::B2B, 'email' => 'inviter@example.com']);
        $inviter->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        return ModelRegistrationInvite::create(array_merge([
            'token' => 'test-token-'.bin2hex(random_bytes(8)),
            'email' => 'manager@example.com',
            'brand' => Brand::B2B,
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
        ], $attributes));
    }

    /**
     * @return array<string, string>
     */
    private function willingnessDefaults(string $level = 'nein'): array
    {
        $defaults = ['willingness_stock' => $level];
        foreach (array_keys(ModelQuestionnaire::SHOOTING_CATEGORIES) as $category) {
            $defaults['willingness_'.$category] = $level;
        }

        return $defaults;
    }

    /**
     * @return array<string, mixed>
     */
    private function personAnswers(array $overrides = []): array
    {
        return array_merge([
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
        ], $this->willingnessDefaults(), $overrides);
    }

    /**
     * Build one person's payload. The age proof is mandatory in v2, so a fake
     * upload is attached by default; pass `false` to simulate a missing proof.
     *
     * @return array<string, mixed>
     */
    private function person(array $overrides = [], bool $manager = false, UploadedFile|false|null $ageProof = null): array
    {
        $answers = $this->personAnswers($overrides);
        if ($manager) {
            $answers['consent_all_persons'] = true;
        }

        $person = [
            'answers' => $answers,
            'create_account' => false,
        ];

        if ($ageProof !== false) {
            $person['age_proof'] = $ageProof ?? UploadedFile::fake()->image('ausweis.jpg');
        }

        return $person;
    }

    /**
     * @param  array<int, array<string, mixed>>  $persons
     * @param  array<string, mixed>  $act
     */
    private function submit(ModelRegistrationInvite $invite, array $persons, array $act = [], int $managerIndex = 0)
    {
        return $this->post(
            "/api/model-registration/{$invite->token}",
            [
                'persons' => $persons,
                'act' => ['answers' => $act],
                'manager_index' => $managerIndex,
            ],
            ['Accept' => 'application/json']
        );
    }

    public function test_check_returns_public_questionnaire_for_open_invite(): void
    {
        $invite = $this->makeInvite();

        $response = $this->getJson("/api/model-registration/{$invite->token}");

        $response->assertOk()
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('brand', 'rp')
            ->assertJsonPath('person_count', 0)
            ->assertJsonPath('catalog_version', ModelQuestionnaire::CURRENT)
            ->assertJsonStructure([
                'email',
                'expires_at',
                'categories' => [['key', 'label', 'description', 'requires_age_proof']],
                'sections',
            ]);
    }

    public function test_check_returns_404_for_unknown_token(): void
    {
        $this->getJson('/api/model-registration/unknown-token')->assertNotFound();
    }

    public function test_check_returns_410_for_used_invite(): void
    {
        $invite = $this->makeInvite(['used_at' => now()]);

        $this->getJson("/api/model-registration/{$invite->token}")->assertStatus(410);
    }

    public function test_check_returns_410_for_expired_invite(): void
    {
        $invite = $this->makeInvite(['expires_at' => now()->subDay()]);

        $this->getJson("/api/model-registration/{$invite->token}")->assertStatus(410);
    }

    public function test_submit_creates_customer_profile_act_and_member(): void
    {
        $invite = $this->makeInvite();

        $response = $this->submit($invite, [$this->person(['gender' => 'weiblich'], true)]);

        $response->assertCreated()->assertJsonPath('success', true)->assertJsonPath('person_count', 1);

        $this->assertDatabaseHas('customers', [
            'email' => 'anna@example.com',
            'brand' => 'rp',
            'is_model' => true,
            'name' => 'Anna Beispiel',
            'city' => 'Linz',
        ]);

        $customer = Customer::where('email', 'anna@example.com')->firstOrFail();

        $this->assertDatabaseHas('model_profiles', [
            'customer_id' => $customer->id,
            'catalog_version' => 'v1',
            'age_proof_required' => true,
            'gender' => 'female',
        ]);

        $this->assertDatabaseHas('acts', [
            'brand' => 'rp',
            'act_type' => 'single',
            'person_count' => 1,
            'catalog_version' => 'v1',
        ]);

        $this->assertDatabaseHas('act_members', [
            'customer_id' => $customer->id,
            'role' => 'manager',
            'position' => 0,
        ]);

        $invite->refresh();
        $this->assertNotNull($invite->used_at);
        $this->assertNotNull($invite->act_id);
        $this->assertSame($customer->id, $invite->customer_id);
    }

    public function test_submit_creates_one_customer_per_person_and_couple_act(): void
    {
        $invite = $this->makeInvite();

        $response = $this->submit($invite, [
            $this->person(['email' => 'anna@example.com', 'first_name' => 'Anna'], true),
            $this->person(['email' => 'bea@example.com', 'first_name' => 'Bea']),
        ]);

        $response->assertCreated();

        $this->assertSame(2, Customer::where('brand', 'rp')->count());
        $act = Act::firstOrFail();
        $this->assertSame('couple', $act->act_type);
        $this->assertSame(2, $act->person_count);
        $this->assertSame(2, $act->members()->count());
    }

    public function test_submit_derives_group_act_for_three_persons(): void
    {
        $invite = $this->makeInvite();

        $response = $this->submit($invite, [
            $this->person(['email' => 'a@example.com'], true),
            $this->person(['email' => 'b@example.com']),
            $this->person(['email' => 'c@example.com']),
        ]);

        $response->assertCreated();
        $this->assertSame('group', Act::firstOrFail()->act_type);
    }

    public function test_submit_assigns_manager_role_to_manager_index(): void
    {
        $invite = $this->makeInvite();

        $response = $this->submit($invite, [
            $this->person(['email' => 'anna@example.com']),
            $this->person(['email' => 'bea@example.com', 'first_name' => 'Bea'], true),
        ], [], 1);

        $response->assertCreated();

        $manager = Customer::where('email', 'bea@example.com')->firstOrFail();
        $this->assertSame($manager->id, Act::firstOrFail()->manager_customer_id);
        $this->assertDatabaseHas('act_members', ['customer_id' => $manager->id, 'role' => 'manager', 'position' => 1]);
        $this->assertDatabaseHas('act_members', [
            'customer_id' => Customer::where('email', 'anna@example.com')->firstOrFail()->id,
            'role' => 'member',
        ]);
    }

    public function test_submit_stores_catalog_version_and_answers_snapshot(): void
    {
        $invite = $this->makeInvite();

        $this->submit($invite, [$this->person(['stage_name' => 'Nova'], true)])->assertCreated();

        $profile = ModelProfile::firstOrFail();
        $this->assertSame('v1', $profile->catalog_version);

        $firstName = collect($profile->answers)->firstWhere('key', 'first_name');
        $this->assertSame([
            'scope' => 'person',
            'key' => 'first_name',
            'label' => 'Vorname',
            'type' => 'text',
            'value' => 'Anna',
        ], $firstName);

        $this->assertSame('Nova', collect($profile->answers)->firstWhere('key', 'stage_name')['value']);
    }

    public function test_submit_stores_checkbox_answers_as_booleans(): void
    {
        $invite = $this->makeInvite();

        // Multipart-style string booleans, as sent by the frontend.
        $this->submit($invite, [$this->person([
            'consent_privacy' => '1',
            'consent_accuracy' => '1',
            'consent_contact' => 'on',
            'consent_photos' => '1',
        ], true)])->assertCreated();

        $profile = ModelProfile::firstOrFail();
        foreach (['consent_privacy', 'consent_accuracy', 'consent_contact', 'consent_photos'] as $key) {
            $this->assertSame(
                true,
                collect($profile->answers)->firstWhere('key', $key)['value'],
                "Checkbox {$key} wurde nicht auf bool coerct."
            );
        }
    }

    public function test_submit_requires_age_proof_for_every_person(): void
    {
        $invite = $this->makeInvite();

        $response = $this->submit($invite, [$this->person([], true, false)]);

        $response->assertStatus(422)->assertJsonValidationErrors(['persons.0.age_proof']);
    }

    public function test_submit_stores_age_proof_on_private_disk_for_any_category(): void
    {
        $invite = $this->makeInvite();

        // Even a portrait-only profile now requires (and stores) the proof.
        $response = $this->submit($invite, [
            $this->person(['experience_portrait' => '+'], true),
        ]);

        $response->assertCreated();

        $profile = ModelProfile::firstOrFail();
        $this->assertTrue($profile->age_proof_required);
        $this->assertNotNull($profile->age_proof_path);
        $this->assertNotNull($profile->age_proof_uploaded_at);
        Storage::disk('local')->assertExists($profile->age_proof_path);
        Storage::disk('public')->assertMissing($profile->age_proof_path);
    }

    public function test_submitted_pdf_age_proof_roundtrips_through_encrypted_download(): void
    {
        $invite = $this->makeInvite();

        $pdfBytes = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
        $pdf = UploadedFile::fake()->createWithContent('ausweis.pdf', $pdfBytes);

        $this->submit($invite, [$this->person([], true, $pdf)])->assertCreated();

        $profile = ModelProfile::firstOrFail();
        Storage::disk('local')->assertExists($profile->age_proof_path);
        $this->assertStringEndsWith('.pdf', $profile->age_proof_path);

        // Encrypted at rest: the stored bytes are not the plaintext PDF.
        $this->assertNotSame($pdfBytes, Storage::disk('local')->get($profile->age_proof_path));

        $admin = User::where('email', 'inviter@example.com')->firstOrFail();

        $response = $this->actingAs($admin, 'api')
            ->get("/api/management/models/{$profile->id}/age-proof");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame($pdfBytes, $response->streamedContent());
    }

    public function test_submitted_jpg_age_proof_roundtrips_with_image_content_type(): void
    {
        $invite = $this->makeInvite();
        $jpg = UploadedFile::fake()->image('ausweis.jpg', 16, 16);

        $this->submit($invite, [$this->person([], true, $jpg)])->assertCreated();

        $profile = ModelProfile::firstOrFail();
        Storage::disk('local')->assertExists($profile->age_proof_path);
        $this->assertStringEndsWith('.jpg', $profile->age_proof_path);

        $admin = User::where('email', 'inviter@example.com')->firstOrFail();

        $response = $this->actingAs($admin, 'api')
            ->get("/api/management/models/{$profile->id}/age-proof");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/jpeg');

        $content = $response->streamedContent();
        $this->assertNotSame('', $content);
        $this->assertSame("\xFF\xD8", substr($content, 0, 2), 'Der Download ist kein gültiges JPEG.');
    }

    public function test_transaction_failure_removes_tracked_age_proof(): void
    {
        $invite = $this->makeInvite();
        $capturedPath = null;

        // Fail late in the transaction (after the age proof was stored) to
        // exercise the outer catch's orphan cleanup.
        Act::creating(function () use (&$capturedPath): void {
            $capturedPath = ModelProfile::query()->value('age_proof_path');

            throw new \RuntimeException('Simulated failure after age-proof upload');
        });

        $response = $this->submit($invite, [
            $this->person(['experience_bikini' => '+'], true, UploadedFile::fake()->image('ausweis.jpg')),
        ]);

        $response->assertStatus(500);

        $this->assertNotNull($capturedPath, 'Altersnachweis wurde vor dem Fehler nicht gespeichert.');
        Storage::disk('local')->assertMissing($capturedPath);

        // The rollback left nothing persisted.
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('model_profiles', 0);
        $this->assertDatabaseCount('acts', 0);
    }

    public function test_failed_age_proof_metadata_write_removes_uploaded_file(): void
    {
        $invite = $this->makeInvite();

        // updateOrCreate creates the profile first; this listener only fires on
        // the subsequent metadata update inside storeAgeProof(), i.e. after the
        // file has been written to disk but before its path is returned.
        ModelProfile::updating(function (): void {
            throw new \RuntimeException('Simulated age-proof metadata write failure');
        });

        $response = $this->submit($invite, [
            $this->person(['experience_bikini' => '+'], true, UploadedFile::fake()->image('ausweis.jpg')),
        ]);

        $response->assertStatus(500);

        $this->assertSame([], Storage::disk('local')->allFiles('model-age-proofs'));
        $this->assertDatabaseCount('model_profiles', 0);
    }

    public function test_submit_requires_age_proof_per_person(): void
    {
        $invite = $this->makeInvite();

        $response = $this->submit($invite, [
            $this->person(['email' => 'anna@example.com'], true),
            $this->person(['email' => 'bea@example.com'], false, false),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['persons.1.age_proof']);
        $response->assertJsonMissingValidationErrors(['persons.0.age_proof']);
    }

    public function test_submit_rejects_invalid_age_proof_mime(): void
    {
        $invite = $this->makeInvite();

        $response = $this->submit($invite, [
            $this->person(
                ['experience_akt' => '+'],
                true,
                UploadedFile::fake()->create('ausweis.txt', 10, 'text/plain')
            ),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['persons.0.age_proof']);
    }

    public function test_submit_rejects_whatsapp_channel_without_phone(): void
    {
        $invite = $this->makeInvite();
        $person = $this->person(['preferred_contact' => ['WhatsApp'], 'phone' => ''], true);

        $response = $this->submit($invite, [$person]);

        $response->assertStatus(422)->assertJsonValidationErrors(['persons.0.answers.phone']);
    }

    public function test_submit_rejects_instagram_channel_without_handle(): void
    {
        $invite = $this->makeInvite();
        $person = $this->person(['preferred_contact' => ['Instagram'], 'instagram' => ''], true);

        $response = $this->submit($invite, [$person]);

        $response->assertStatus(422)->assertJsonValidationErrors(['persons.0.answers.instagram']);
    }

    public function test_submit_accepts_contact_channel_when_backing_field_is_filled(): void
    {
        $invite = $this->makeInvite();
        $person = $this->person([
            'preferred_contact' => ['Telefon', 'WhatsApp', 'Instagram'],
            'phone' => '+43 660 1234567',
            'instagram' => '@anna',
        ], true);

        $this->submit($invite, [$person])->assertCreated();

        $profile = ModelProfile::firstOrFail();
        $preferred = collect($profile->answers)->firstWhere('key', 'preferred_contact');
        $this->assertSame(['Telefon', 'WhatsApp', 'Instagram'], $preferred['value']);
    }

    public function test_submit_rejects_missing_required_answer(): void
    {
        $invite = $this->makeInvite();
        $person = $this->person([], true);
        unset($person['answers']['last_name']);

        $response = $this->submit($invite, [$person]);

        $response->assertStatus(422)->assertJsonValidationErrors(['persons.0.answers.last_name']);
    }

    public function test_submit_rejects_invalid_select_option(): void
    {
        $invite = $this->makeInvite();
        $person = $this->person(['gender' => 'alien'], true);

        $response = $this->submit($invite, [$person]);

        $response->assertStatus(422)->assertJsonValidationErrors(['persons.0.answers.gender']);
    }

    public function test_single_person_submit_does_not_require_all_persons_consent(): void
    {
        $invite = $this->makeInvite();

        // A single-person act is implicitly managed: no foreign-data consent.
        $this->submit($invite, [$this->person()])->assertCreated();

        $profile = ModelProfile::firstOrFail();
        $this->assertNull(collect($profile->answers)->firstWhere('key', 'consent_all_persons'));
    }

    public function test_submit_rejects_missing_manager_consent_for_multiple_persons(): void
    {
        $invite = $this->makeInvite();

        // Person 0 is the manager but omits consent_all_persons; person 1 makes
        // the act multi-person, which activates the requirement.
        $manager = $this->person(['email' => 'anna@example.com']);
        $member = $this->person(['email' => 'bea@example.com', 'first_name' => 'Bea']);

        $response = $this->submit($invite, [$manager, $member]);

        $response->assertStatus(422)->assertJsonValidationErrors(['persons.0.answers.consent_all_persons']);
        $response->assertJsonMissingValidationErrors(['persons.1.answers.consent_all_persons']);
    }

    public function test_submit_does_not_require_manager_consent_from_members(): void
    {
        $invite = $this->makeInvite();

        $response = $this->submit($invite, [
            $this->person(['email' => 'anna@example.com'], true),
            $this->person(['email' => 'bea@example.com', 'first_name' => 'Bea']),
        ]);

        $response->assertCreated();
        $response->assertJsonMissingValidationErrors(['persons.1.answers.consent_all_persons']);
    }

    public function test_submit_rejects_duplicate_email_within_submission(): void
    {
        $invite = $this->makeInvite();

        $response = $this->submit($invite, [
            $this->person(['email' => 'anna@example.com'], true),
            $this->person(['email' => 'anna@example.com', 'first_name' => 'Bea']),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['persons.1.answers.email']);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_submit_consumes_token_once(): void
    {
        $invite = $this->makeInvite();

        $this->submit($invite, [$this->person([], true)])->assertCreated();

        $this->assertNotNull($invite->fresh()->used_at);
        $this->assertSame(1, ModelProfile::count());

        // Second submit with the same token is rejected and creates nothing new.
        $this->submit($invite, [$this->person([], true)])->assertStatus(410);

        $this->assertSame(1, ModelProfile::count());
        $this->assertSame(1, Act::count());
    }

    public function test_token_claim_is_atomic(): void
    {
        $invite = $this->makeInvite(['used_at' => now()]);

        $affected = ModelRegistrationInvite::where('id', $invite->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $this->assertSame(0, $affected);
    }

    public function test_submit_returns_409_when_token_is_claimed_between_check_and_claim(): void
    {
        $invite = $this->makeInvite();

        // Simulate the concurrent race: the invite is still unused at the
        // controller's pre-check, but another request redeems it right after
        // the SELECT and before the atomic UPDATE claim.
        $fired = false;
        DB::listen(function ($query) use ($invite, &$fired): void {
            if ($fired || str_contains($query->sql, 'insert') || str_contains($query->sql, 'update')) {
                return;
            }
            if (str_contains($query->sql, 'model_registration_invites')) {
                $fired = true;
                DB::table('model_registration_invites')
                    ->where('id', $invite->id)
                    ->update(['used_at' => now()]);
            }
        });

        $this->submit($invite, [$this->person([], true)])->assertStatus(409);

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('acts', 0);
    }

    public function test_submit_rejects_expired_token(): void
    {
        $invite = $this->makeInvite(['expires_at' => now()->subDay()]);

        $this->submit($invite, [$this->person([], true)])->assertStatus(410);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_submit_rejects_unknown_token(): void
    {
        $this->postJson('/api/model-registration/unknown-token', [
            'persons' => [$this->person([], true)],
        ])->assertNotFound();
    }

    public function test_submit_sends_success_mail_to_inviting_user(): void
    {
        $invite = $this->makeInvite();

        $this->submit($invite, [$this->person([], true)])->assertCreated();

        Mail::assertQueued(ModelRegistrationSuccessMail::class, function (ModelRegistrationSuccessMail $mail) {
            return $mail->hasTo('inviter@example.com');
        });
    }

    public function test_submit_dedupes_customer_by_email_within_brand(): void
    {
        $invite = $this->makeInvite();
        $existing = Customer::factory()->create([
            'email' => 'anna@example.com',
            'brand' => Brand::B2B,
            'name' => 'Alter Name',
            'is_model' => false,
        ]);

        $this->submit($invite, [$this->person([], true)])->assertCreated();

        $this->assertSame(1, Customer::where('email', 'anna@example.com')->count());
        $this->assertTrue($existing->fresh()->is_model);
        $this->assertDatabaseHas('model_profiles', ['customer_id' => $existing->id]);
    }

    public function test_submit_does_not_dedupe_customer_across_brands(): void
    {
        $invite = $this->makeInvite();
        Customer::factory()->create(['email' => 'anna@example.com', 'brand' => 'srp']);

        $this->submit($invite, [$this->person([], true)])->assertCreated();

        $this->assertSame(2, Customer::where('email', 'anna@example.com')->count());
        $rpCustomer = Customer::where('email', 'anna@example.com')->where('brand', 'rp')->firstOrFail();
        $this->assertDatabaseHas('model_profiles', ['customer_id' => $rpCustomer->id]);
    }

    public function test_submit_creates_optional_portal_account(): void
    {
        $invite = $this->makeInvite();
        $person = $this->person([], true);
        $person['create_account'] = true;

        $this->submit($invite, [$person])->assertCreated();

        $user = User::where('email', 'anna@example.com')->firstOrFail();
        $this->assertNull($user->password);
        $this->assertTrue($user->roles()->where('name', UserRole::CLIENT->value)->exists());

        $customer = Customer::where('email', 'anna@example.com')->firstOrFail();
        $this->assertSame($user->id, $customer->user_id);

        Mail::assertQueued(ActivateAccountMail::class, function (ActivateAccountMail $mail) {
            return $mail->hasTo('anna@example.com');
        });
    }

    public function test_submit_without_account_creates_no_user(): void
    {
        $invite = $this->makeInvite();

        $this->submit($invite, [$this->person([], true)])->assertCreated();

        $this->assertDatabaseMissing('users', ['email' => 'anna@example.com']);
        Mail::assertNotQueued(ActivateAccountMail::class);
    }

    public function test_submit_accepts_string_create_account_flag(): void
    {
        $invite = $this->makeInvite();
        $person = $this->person([], true);
        $person['create_account'] = 'true';

        $this->submit($invite, [$person])->assertCreated();

        $this->assertNotNull(User::where('email', 'anna@example.com')->first());
    }

    public function test_submit_treats_string_false_create_account_as_disabled(): void
    {
        $invite = $this->makeInvite();
        $person = $this->person([], true);
        $person['create_account'] = 'false';

        $this->submit($invite, [$person])->assertCreated();

        $this->assertDatabaseMissing('users', ['email' => 'anna@example.com']);
        Mail::assertNotQueued(ActivateAccountMail::class);
    }

    public function test_submit_does_not_link_foreign_brand_portal_account(): void
    {
        $invite = $this->makeInvite();
        $foreignUser = User::factory()->create(['email' => 'anna@example.com', 'brand' => 'srp']);
        $person = $this->person([], true);
        $person['create_account'] = true;

        $this->submit($invite, [$person])->assertCreated();

        $this->assertSame(1, User::where('email', 'anna@example.com')->count());

        $customer = Customer::where('email', 'anna@example.com')->firstOrFail();
        $this->assertNull($customer->user_id);
        $this->assertFalse($foreignUser->fresh()->roles()->where('name', UserRole::CLIENT->value)->exists());
        Mail::assertNotQueued(ActivateAccountMail::class);
    }

    public function test_submit_links_existing_same_brand_portal_account(): void
    {
        $invite = $this->makeInvite();
        $existing = User::factory()->create(['email' => 'anna@example.com', 'brand' => 'rp']);
        $person = $this->person([], true);
        $person['create_account'] = true;

        $this->submit($invite, [$person])->assertCreated();

        $this->assertSame(1, User::where('email', 'anna@example.com')->count());

        $customer = Customer::where('email', 'anna@example.com')->firstOrFail();
        $this->assertSame($existing->id, $customer->user_id);
        $this->assertTrue($existing->fresh()->roles()->where('name', UserRole::CLIENT->value)->exists());
    }
}
