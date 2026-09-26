<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\ModelPhoto;
use App\Models\ModelProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\ModelFileStore;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * HTTP-Contract + Privacy-Trennung des PDF-Contact-Sheets.
 *
 * Die positiven/negativen Text-Assertions prüfen den tatsächlich gerenderten
 * PDF-Inhalt (der Service erzeugt den Content-Stream bewusst unkomprimiert),
 * damit ein PII-Leak in der externen Variante zuverlässig fehlschlägt.
 */
class ModelContactSheetTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLIC_PHOTO = 'PublicFoto.jpg';

    private const INTERNAL_PHOTO = 'InternFoto.jpg';

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
        Storage::fake('local');
    }

    private function userWithRole(UserRole $role, ?string $brand = 'rp'): User
    {
        $user = User::factory()->create(['brand' => $brand]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role->value]));

        return $user;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function snapshot(): array
    {
        return [
            ['scope' => 'person', 'key' => 'stage_name', 'label' => 'Künstlername / Pseudonym', 'type' => 'text', 'value' => 'StarletX'],
            ['scope' => 'person', 'key' => 'phone', 'label' => 'Telefon', 'type' => 'tel', 'value' => '06601234567'],
            ['scope' => 'person', 'key' => 'experience_portrait', 'label' => 'Erfahrung: Portrait', 'type' => 'select', 'value' => '+'],
            ['scope' => 'person', 'key' => 'willingness_portrait', 'label' => 'Bereitschaft: Portrait', 'type' => 'select', 'value' => 'sehr_gerne'],
            ['scope' => 'person', 'key' => 'agency_name', 'label' => 'Agentur (Name)', 'type' => 'text', 'value' => 'AgenturX'],
            ['scope' => 'person', 'key' => 'person_notes', 'label' => 'Anmerkungen zur Person', 'type' => 'textarea', 'value' => 'NotizY'],
        ];
    }

    /**
     * @param  array<string, mixed>  $customerAttributes
     * @param  array<string, mixed>  $profileAttributes
     */
    private function profile(array $customerAttributes = [], array $profileAttributes = []): ModelProfile
    {
        $customer = Customer::factory()->create(array_merge([
            'brand' => 'rp',
            'is_model' => true,
            'name' => 'RealnameZ',
            'email' => 'anna@example.com',
            'street' => 'Geheimstrasse',
            'zip' => 'ZIPCODE42',
            'city' => 'Linzstadt',
            'country' => 'Oesterreich',
            'birthdate' => '1995-01-15',
        ], $customerAttributes));

        return ModelProfile::create(array_merge([
            'customer_id' => $customer->id,
            'catalog_version' => 'v1',
            'answers' => $this->snapshot(),
            'gender' => 'female',
            'age_proof_required' => true,
            'age_proof_uploaded_at' => now(),
            'submitted_at' => now(),
        ], $profileAttributes));
    }

    private function addPhoto(ModelProfile $profile, string $name, string $visibility, bool $primary = false): ModelPhoto
    {
        $path = 'model-photos/'.$profile->id.'/'.$name;
        $bytes = $this->imageBytes();
        app(ModelFileStore::class)->putEncrypted($path, $bytes);

        return ModelPhoto::create([
            'customer_id' => $profile->customer_id,
            'model_profile_id' => $profile->id,
            'path' => $path,
            'original_name' => $name,
            'mime_type' => 'image/png',
            'size_bytes' => strlen($bytes),
            'visibility' => $visibility,
            'is_primary' => $primary,
            'position' => $primary ? 0 : 1,
        ]);
    }

    private function imageBytes(): string
    {
        $image = imagecreatetruecolor(24, 24);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 40));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    public function test_photographer_cannot_export_contact_sheet(): void
    {
        $photographer = $this->userWithRole(UserRole::PHOTOGRAPHER);
        $profile = $this->profile();

        $this->actingAs($photographer, 'api')
            ->getJson("/api/management/models/{$profile->id}/contact-sheet?variant=internal")
            ->assertForbidden();
    }

    public function test_variant_is_required_and_must_be_known(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);
        $profile = $this->profile();

        $this->actingAs($admin, 'api')
            ->getJson("/api/management/models/{$profile->id}/contact-sheet")
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant');

        $this->actingAs($admin, 'api')
            ->getJson("/api/management/models/{$profile->id}/contact-sheet?variant=bogus")
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant');
    }

    public function test_internal_export_contains_pii_and_all_photos(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);
        $profile = $this->profile();
        $this->addPhoto($profile, self::PUBLIC_PHOTO, ModelPhoto::VISIBILITY_PUBLIC, true);
        $this->addPhoto($profile, self::INTERNAL_PHOTO, ModelPhoto::VISIBILITY_INTERNAL);

        Log::spy();

        $response = $this->actingAs($admin, 'api')
            ->get("/api/management/models/{$profile->id}/contact-sheet?variant=internal");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('model-'.$profile->id.'-internal-'.now()->format('Ymd').'.pdf');

        $pdf = $response->streamedContent();

        $this->assertStringContainsString('RealnameZ', $pdf);
        $this->assertStringContainsString('anna@example.com', $pdf);
        $this->assertStringContainsString('06601234567', $pdf);
        $this->assertStringContainsString('Geheimstrasse', $pdf);
        $this->assertStringContainsString('ZIPCODE42', $pdf);
        $this->assertStringContainsString('15.01.1995', $pdf);
        $this->assertStringContainsString(self::PUBLIC_PHOTO, $pdf);
        $this->assertStringContainsString(self::INTERNAL_PHOTO, $pdf);
        $this->assertStringNotContainsString('Nur zur Ansicht', $pdf);

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context): bool => $message === 'model.contact_sheet.export'
                && $context['variant'] === 'internal'
                && $context['model_profile_id'] === $profile->id
                && $context['user_id'] === $admin->id
                && ! array_key_exists('email', $context)
        )->once();
    }

    public function test_external_export_omits_pii_and_internal_photos(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);
        $profile = $this->profile();
        $this->addPhoto($profile, self::PUBLIC_PHOTO, ModelPhoto::VISIBILITY_PUBLIC, true);
        $this->addPhoto($profile, self::INTERNAL_PHOTO, ModelPhoto::VISIBILITY_INTERNAL);

        $response = $this->actingAs($admin, 'api')
            ->get("/api/management/models/{$profile->id}/contact-sheet?variant=external");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('model-'.$profile->id.'-external-'.now()->format('Ymd').'.pdf');

        $pdf = $response->streamedContent();

        // Öffentliche Identität + Wasserzeichen (wiederholt über die Seite).
        $this->assertStringContainsString('StarletX', $pdf);
        $this->assertStringContainsString('Linzstadt', $pdf);
        $this->assertGreaterThanOrEqual(2, substr_count($pdf, 'Nur zur Ansicht'));

        // Extern nur generische Foto-Captions, keine Original-Dateinamen (PII).
        $this->assertStringContainsString('Foto 1', $pdf);
        $this->assertStringNotContainsString(self::PUBLIC_PHOTO, $pdf);

        // Keine internen Fotos.
        $this->assertStringNotContainsString(self::INTERNAL_PHOTO, $pdf);
        $this->assertStringNotContainsString('Intern (nicht', $pdf);

        // Keine PII / internen Daten.
        $this->assertStringNotContainsString('RealnameZ', $pdf);
        $this->assertStringNotContainsString('anna@example.com', $pdf);
        $this->assertStringNotContainsString('06601234567', $pdf);
        $this->assertStringNotContainsString('Geheimstrasse', $pdf);
        $this->assertStringNotContainsString('ZIPCODE42', $pdf);
        $this->assertStringNotContainsString('15.01.1995', $pdf);
        $this->assertStringNotContainsString('AgenturX', $pdf);
        $this->assertStringNotContainsString('NotizY', $pdf);
    }

    public function test_external_export_never_renders_original_photo_filenames(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);
        $profile = $this->profile();
        $this->addPhoto($profile, 'Anna_Mustermann_akte.jpg', ModelPhoto::VISIBILITY_PUBLIC, true);

        $external = $this->actingAs($admin, 'api')
            ->get("/api/management/models/{$profile->id}/contact-sheet?variant=external")
            ->assertOk()
            ->streamedContent();

        // Der reale Dateiname (PII) darf nicht im externen PDF landen, sondern
        // nur die generische Caption.
        $this->assertStringNotContainsString('Anna_Mustermann', $external);
        $this->assertStringContainsString('Foto 1', $external);

        // Intern bleibt der Originalname sichtbar (unverändertes Verhalten).
        $internal = $this->actingAs($admin, 'api')
            ->get("/api/management/models/{$profile->id}/contact-sheet?variant=internal")
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Anna_Mustermann_akte.jpg', $internal);
    }

    public function test_external_export_without_stage_name_never_falls_back_to_real_name(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);
        $snapshot = array_values(array_filter(
            $this->snapshot(),
            static fn (array $item): bool => $item['key'] !== 'stage_name'
        ));
        $profile = $this->profile([], ['answers' => $snapshot]);
        $this->addPhoto($profile, self::PUBLIC_PHOTO, ModelPhoto::VISIBILITY_PUBLIC, true);

        $external = $this->actingAs($admin, 'api')
            ->get("/api/management/models/{$profile->id}/contact-sheet?variant=external")
            ->assertOk()
            ->streamedContent();

        $this->assertStringNotContainsString('RealnameZ', $external);

        // Beleg, dass der Realname im Profil liegt und intern weiterhin erscheint.
        $internal = $this->actingAs($admin, 'api')
            ->get("/api/management/models/{$profile->id}/contact-sheet?variant=internal")
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('RealnameZ', $internal);
    }

    public function test_contact_sheet_is_brand_scoped(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $profile = $this->profile(['brand' => 'srp']);

        $this->actingAs($admin, 'api')
            ->getJson("/api/management/models/{$profile->id}/contact-sheet?variant=external")
            ->assertNotFound();
    }
}
