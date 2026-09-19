<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Mail\ModelProfileReminderMail;
use App\Models\Customer;
use App\Models\ModelAccessToken;
use App\Models\ModelProfile;
use App\Models\ModelRegistrationInvite;
use App\Models\User;
use App\Services\ModelQuestionnaire;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Lifecycle: Anker `last_confirmed_at`, Zustände, idempotente Reminder-Mails
 * (T+12/13/14) und Brand-Awareness.
 */
class ModelLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
        Storage::fake('local');
        Mail::fake();
    }

    private function profile(int $monthsOld, ?string $email = 'model@example.com', string $brand = 'rp'): ModelProfile
    {
        $customer = Customer::factory()->create([
            'brand' => $brand,
            'is_model' => true,
            'email' => $email,
        ]);

        return ModelProfile::create([
            'customer_id' => $customer->id,
            'catalog_version' => ModelQuestionnaire::CURRENT,
            'answers' => [],
            'gender' => null,
            'age_proof_required' => false,
            'submitted_at' => now()->subMonths($monthsOld)->subDay(),
            'last_confirmed_at' => now()->subMonths($monthsOld)->subDay(),
        ]);
    }

    public function test_lifecycle_status_thresholds(): void
    {
        $this->assertSame('active', $this->profile(0)->lifecycleStatus());
        $this->assertSame('active', $this->profile(12)->lifecycleStatus());
        $this->assertSame('inactive', $this->profile(13)->lifecycleStatus());
        $this->assertSame('expired', $this->profile(15)->lifecycleStatus());
    }

    public function test_due_reminder_stage_thresholds(): void
    {
        $this->assertNull($this->profile(11)->dueReminderStage());
        $this->assertSame('t12', $this->profile(12)->dueReminderStage());
        $this->assertSame('t13', $this->profile(13)->dueReminderStage());
        $this->assertSame('t14', $this->profile(14)->dueReminderStage());
    }

    public function test_submit_sets_last_confirmed_at(): void
    {
        $inviter = User::factory()->create(['brand' => Brand::B2B]);
        $invite = ModelRegistrationInvite::create([
            'token' => 'token-'.bin2hex(random_bytes(8)),
            'email' => 'manager@example.com',
            'brand' => Brand::B2B,
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
        ]);

        $answers = [
            'first_name' => 'Anna', 'last_name' => 'Beispiel', 'birthdate' => '1995-05-05',
            'email' => 'anna@example.com', 'street' => 'Teststraße 1', 'zip' => '4020',
            'city' => 'Linz', 'country' => 'Österreich',
            'consent_privacy' => true, 'consent_accuracy' => true, 'consent_contact' => true,
            'consent_photos' => true, 'consent_all_persons' => true, 'willingness_stock' => 'nein',
        ];
        foreach (array_keys(ModelQuestionnaire::SHOOTING_CATEGORIES) as $category) {
            $answers['willingness_'.$category] = 'nein';
        }

        $this->post("/api/model-registration/{$invite->token}", [
            'persons' => [[
                'answers' => $answers,
                'create_account' => false,
                'age_proof' => UploadedFile::fake()->image('ausweis.jpg'),
            ]],
            'manager_index' => 0,
        ], ['Accept' => 'application/json'])->assertCreated();

        $profile = ModelProfile::firstOrFail();
        $this->assertNotNull($profile->last_confirmed_at);
        $this->assertSame('active', $profile->lifecycleStatus());
    }

    public function test_command_sends_t12_reminder_once_and_marks_stage(): void
    {
        $profile = $this->profile(12);

        $this->artisan('app:process-model-lifecycle')->assertSuccessful();

        Mail::assertQueued(ModelProfileReminderMail::class, 1);
        Mail::assertQueued(ModelProfileReminderMail::class, fn (ModelProfileReminderMail $mail) => $mail->hasTo('model@example.com') && $mail->stage === 't12');

        $profile->refresh();
        $this->assertSame('t12', $profile->last_reminder_stage);
        $this->assertNotNull($profile->last_reminder_at);

        // Idempotent: a second run sends nothing new.
        $this->artisan('app:process-model-lifecycle')->assertSuccessful();
        Mail::assertQueued(ModelProfileReminderMail::class, 1);
    }

    public function test_command_escalates_through_t13_and_t14(): void
    {
        $profile = $this->profile(13);
        $this->artisan('app:process-model-lifecycle')->assertSuccessful();
        $this->assertSame('t13', $profile->fresh()->last_reminder_stage);

        $profile->forceFill(['last_confirmed_at' => now()->subMonths(14)->subDay()])->save();
        $this->artisan('app:process-model-lifecycle')->assertSuccessful();

        $this->assertSame('t14', $profile->fresh()->last_reminder_stage);
        Mail::assertQueued(ModelProfileReminderMail::class, 2);
    }

    public function test_profile_without_email_gets_no_reminder(): void
    {
        $profile = $this->profile(12, null);

        $this->artisan('app:process-model-lifecycle')->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertNull($profile->fresh()->last_reminder_stage);
    }

    public function test_profile_under_12_months_gets_no_reminder(): void
    {
        $this->profile(11);

        $this->artisan('app:process-model-lifecycle')->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_reminder_is_brand_aware_and_issues_24h_token(): void
    {
        $profile = $this->profile(12, 'model@example.com', 'rp');

        $this->artisan('app:process-model-lifecycle')->assertSuccessful();

        Mail::assertQueued(
            ModelProfileReminderMail::class,
            fn (ModelProfileReminderMail $mail) => $mail->brand === Brand::B2B
        );

        $token = ModelAccessToken::where('customer_id', $profile->customer_id)->firstOrFail();
        $this->assertTrue($token->expires_at->between(now()->addHours(23), now()->addHours(25)));
        $this->assertStringContainsString('/model-profil/'.$token->token, $token->url());
    }
}
