<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Mail\ModelRegistrationSuccessMail;
use App\Models\Act;
use App\Models\Customer;
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
 * Fertigstellungs-Mail an den Einladenden: harte Fakten + Deeplink pro Person.
 */
class ModelRegistrationSuccessMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
        Mail::fake();
        Storage::fake('local');
    }

    private function invite(User $inviter): ModelRegistrationInvite
    {
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
    private function personAnswers(string $email, string $firstName, string $city): array
    {
        $answers = [
            'first_name' => $firstName,
            'last_name' => 'Beispiel',
            'birthdate' => '1995-05-05',
            'email' => $email,
            'street' => 'Teststraße 1',
            'zip' => '4020',
            'city' => $city,
            'country' => 'Österreich',
            'consent_privacy' => true,
            'consent_accuracy' => true,
            'consent_contact' => true,
            'consent_photos' => true,
            'willingness_stock' => 'sehr_gerne',
        ];
        foreach (array_keys(ModelQuestionnaire::SHOOTING_CATEGORIES) as $category) {
            $answers['willingness_'.$category] = 'nein';
        }

        return $answers;
    }

    public function test_success_mail_contains_facts_and_profile_deeplinks(): void
    {
        $inviter = User::factory()->create(['brand' => Brand::B2B, 'email' => 'inviter@example.com', 'name' => 'Ivy Inviter']);
        $inviter->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));
        $invite = $this->invite($inviter);

        $annaAnswers = $this->personAnswers('anna@example.com', 'Anna', 'Linz');
        $annaAnswers['experience_portrait'] = '+';
        $annaAnswers['willingness_portrait'] = 'gerne';
        $annaAnswers['consent_all_persons'] = true;

        $response = $this->post(
            "/api/model-registration/{$invite->token}",
            [
                'persons' => [
                    [
                        'answers' => $annaAnswers,
                        'create_account' => true,
                        'age_proof' => UploadedFile::fake()->image('ausweis-a.jpg'),
                    ],
                    [
                        'answers' => $this->personAnswers('bea@example.com', 'Bea', 'Wien'),
                        'create_account' => false,
                        'age_proof' => UploadedFile::fake()->image('ausweis-b.jpg'),
                    ],
                ],
                'manager_index' => 0,
            ],
            ['Accept' => 'application/json']
        );

        $response->assertCreated();

        $annaId = Customer::where('email', 'anna@example.com')->firstOrFail()->id;
        $beaId = Customer::where('email', 'bea@example.com')->firstOrFail()->id;
        $frontend = BrandRegistry::frontendUrl(Brand::B2B);
        $expectedAge = Carbon::parse('1995-05-05')->age;

        Mail::assertQueued(ModelRegistrationSuccessMail::class, function (ModelRegistrationSuccessMail $mail) use ($annaId, $beaId, $frontend, $expectedAge) {
            $this->assertTrue($mail->hasTo('inviter@example.com'));
            $this->assertSame('Ivy Inviter', $mail->recipientName);
            $this->assertSame(2, $mail->personCount);
            $this->assertSame('couple', $mail->actType);
            $this->assertSame('Paar', $mail->actTypeLabel());
            $this->assertCount(2, $mail->persons);

            $anna = $mail->persons[0];
            $this->assertSame($annaId, $anna['id']);
            $this->assertSame('Anna Beispiel', $anna['name']);
            $this->assertSame($expectedAge, $anna['age']);
            $this->assertSame('Linz', $anna['city']);
            $this->assertTrue($anna['age_proof_uploaded']);
            $this->assertTrue($anna['portal_account']);
            $this->assertContains(['label' => 'Portrait', 'willingness' => 'Gerne'], $anna['categories']);

            $bea = $mail->persons[1];
            $this->assertSame($beaId, $bea['id']);
            $this->assertSame('Bea Beispiel', $bea['name']);
            $this->assertSame('Wien', $bea['city']);
            $this->assertFalse($bea['portal_account']);

            $html = $mail->render();
            $this->assertStringContainsString('Paar', $html);
            $this->assertStringContainsString('Anna Beispiel', $html);
            $this->assertStringContainsString('Linz', $html);
            $this->assertStringContainsString('Portrait', $html);
            $this->assertStringContainsString('Gerne', $html);
            $this->assertStringContainsString('hochgeladen', $html);
            $this->assertStringContainsString($frontend.'/admin-models?model='.$annaId, $html);
            $this->assertStringContainsString($frontend.'/admin-models?model='.$beaId, $html);

            return true;
        });
    }

    public function test_no_mail_is_sent_when_the_transaction_rolls_back(): void
    {
        $inviter = User::factory()->create(['brand' => Brand::B2B, 'email' => 'inviter@example.com', 'name' => 'Ivy Inviter']);
        $inviter->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));
        $invite = $this->invite($inviter);

        $answers = $this->personAnswers('anna@example.com', 'Anna', 'Linz');
        $answers['consent_all_persons'] = true;

        // Fail after the mails would previously have been dispatched (inside the
        // transaction), to prove they are only sent after a successful commit.
        Act::creating(function (): void {
            throw new \RuntimeException('Simulated failure after mail dispatch point');
        });

        $this->post(
            "/api/model-registration/{$invite->token}",
            [
                'persons' => [[
                    'answers' => $answers,
                    'create_account' => true,
                    'age_proof' => UploadedFile::fake()->image('ausweis.jpg'),
                ]],
                'manager_index' => 0,
            ],
            ['Accept' => 'application/json']
        )->assertStatus(500);

        Mail::assertNothingQueued();
    }
}
