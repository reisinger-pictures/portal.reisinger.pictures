<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Mail\ModelProfileUpdatedMail;
use App\Models\Act;
use App\Models\ActMember;
use App\Models\ModelAccessToken;
use App\Models\ModelProfile;
use App\Models\ModelRegistrationInvite;
use App\Models\Role;
use App\Models\User;
use App\Services\ModelQuestionnaire;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Update-Benachrichtigung an den einladenden Admin, wenn ein Model sein Profil
 * über den öffentlichen Magic-Link aktualisiert.
 */
class ModelProfileUpdatedMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
        Mail::fake();
        Storage::fake('local');
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

    /**
     * Register a model through the public invite flow (creates Act + Invite
     * binding) and return the profile plus the inviting admin.
     *
     * @return array{0: ModelProfile, 1: User}
     */
    private function createModel(string $email = 'anna@example.com'): array
    {
        $inviter = User::factory()->create([
            'brand' => Brand::B2B,
            'email' => 'inviter@example.com',
            'name' => 'Ivy Inviter',
        ]);
        $inviter->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        $invite = ModelRegistrationInvite::create([
            'token' => 'token-'.bin2hex(random_bytes(8)),
            'email' => $email,
            'brand' => Brand::B2B,
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

        $profile = ModelProfile::whereHas('customer', fn ($q) => $q->where('email', $email))->firstOrFail();

        return [$profile, $inviter];
    }

    public function test_update_sends_notification_mail_to_the_inviter(): void
    {
        [$profile, $inviter] = $this->createModel();
        $customer = $profile->customer;
        $token = ModelAccessToken::issueFor($customer);

        Carbon::setTestNow(Carbon::parse('2026-05-01 10:30:00'));

        $answers = $this->personAnswers();
        $answers['city'] = 'Wien';

        $this->postJson("/api/model-profil/{$token->token}", ['answers' => $answers])
            ->assertOk()
            ->assertJsonPath('success', true);

        Carbon::setTestNow();

        $frontend = BrandRegistry::frontendUrl(Brand::B2B);

        Mail::assertQueued(ModelProfileUpdatedMail::class, function (ModelProfileUpdatedMail $mail) use ($inviter, $customer, $frontend): bool {
            $this->assertTrue($mail->hasTo($inviter->email));
            $this->assertSame('Ivy Inviter', $mail->recipientName);
            $this->assertSame($customer->name, $mail->modelName);
            $this->assertSame('single', $mail->actType);
            $this->assertSame('Einzelperson', $mail->actTypeLabel());
            $this->assertSame(1, $mail->personCount);
            $this->assertSame($customer->id, $mail->modelId);
            $this->assertSame('01.05.2026 10:30', $mail->updatedAt);

            $html = $mail->render();
            $this->assertStringContainsString($customer->name, $html);
            $this->assertStringContainsString('Einzelperson', $html);
            $this->assertStringContainsString('01.05.2026 10:30', $html);
            $this->assertStringContainsString($frontend.'/admin-models?model='.$customer->id, $html);

            return true;
        });

        Mail::assertQueued(ModelProfileUpdatedMail::class, 1);
    }

    public function test_update_notifies_the_inviter_of_the_matching_later_act(): void
    {
        [$profile, $firstInviter] = $this->createModel();
        $customer = $profile->customer;

        // Die erste Einladung in die Vergangenheit schieben, damit die zweite
        // Einladung eindeutig die jüngste ist.
        ModelRegistrationInvite::query()->update(['created_at' => now()->subMinutes(5)]);

        $secondInviter = User::factory()->create([
            'brand' => Brand::B2B,
            'email' => 'second@example.com',
            'name' => 'Second Inviter',
        ]);
        $secondInviter->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        $secondAct = Act::factory()->create([
            'brand' => Brand::B2B,
            'act_type' => 'couple',
            'person_count' => 2,
            'submitted_at' => now(),
        ]);
        // Pivot über das Modell anlegen: HasUuids generiert die Pivot-`id` nur
        // beim Eloquent-Insert, nicht bei `attach()`.
        ActMember::create([
            'act_id' => $secondAct->id,
            'customer_id' => $customer->id,
            'role' => 'member',
            'position' => 1,
        ]);

        ModelRegistrationInvite::create([
            'token' => 'token-'.bin2hex(random_bytes(8)),
            'email' => $customer->email,
            'brand' => Brand::B2B,
            'invited_by' => $secondInviter->id,
            'expires_at' => now()->addDays(7),
            'act_id' => $secondAct->id,
            'customer_id' => $customer->id,
        ]);

        $token = ModelAccessToken::issueFor($customer);

        $this->postJson("/api/model-profil/{$token->token}", ['answers' => $this->personAnswers()])
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertQueued(ModelProfileUpdatedMail::class, function (ModelProfileUpdatedMail $mail) use ($secondInviter): bool {
            $this->assertTrue($mail->hasTo($secondInviter->email));
            $this->assertSame('Second Inviter', $mail->recipientName);
            $this->assertSame('couple', $mail->actType);
            $this->assertSame(2, $mail->personCount);

            return true;
        });

        // Der Inviter des ersten Acts darf nicht (mehr) benachrichtigt werden.
        Mail::assertNotQueued(ModelProfileUpdatedMail::class, function (ModelProfileUpdatedMail $mail) use ($firstInviter): bool {
            return $mail->hasTo($firstInviter->email);
        });
    }

    public function test_update_without_invite_sends_no_mail_and_still_succeeds(): void
    {
        [$profile] = $this->createModel();

        // No invite (and therefore no inviter) is bound to the profile's act.
        ModelRegistrationInvite::query()->delete();

        $token = ModelAccessToken::issueFor($profile->customer);

        $this->postJson("/api/model-profil/{$token->token}", ['answers' => $this->personAnswers()])
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertNotQueued(ModelProfileUpdatedMail::class);
    }
}
