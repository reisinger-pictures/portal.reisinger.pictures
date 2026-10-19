<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\ModelPhoto;
use App\Models\ModelProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\ModelContactSheetService;
use App\Services\ModelFileStore;
use App\Services\ModelQuestionnaire;
use App\Services\PhotoProcessingService;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    private function addPhoto(
        ModelProfile $profile,
        string $name,
        string $visibility,
        bool $primary = false,
        ?string $mimeType = 'image/png',
    ): ModelPhoto {
        $path = 'model-photos/'.$profile->id.'/'.$name;
        $bytes = $this->imageBytes();
        app(ModelFileStore::class)->putEncrypted($path, $bytes);

        return ModelPhoto::create([
            'customer_id' => $profile->customer_id,
            'model_profile_id' => $profile->id,
            'path' => $path,
            'original_name' => $name,
            'mime_type' => $mimeType,
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

    public function test_internal_export_accepts_a_valid_legacy_photo_without_stored_mime(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);
        $profile = $this->profile();
        $this->addPhoto($profile, 'LegacyOhneMime.png', ModelPhoto::VISIBILITY_PUBLIC, true, null);

        $pdf = $this->actingAs($admin, 'api')
            ->get("/api/management/models/{$profile->id}/contact-sheet?variant=internal")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->streamedContent();

        // Der MIME kommt aus den Bytes, nicht aus der (fehlenden) DB-Spalte:
        // das Foto wird weiterhin eingebettet und nicht stillschweigend verworfen.
        // DomPDF löst die Data-URI in ein Image-XObject auf, deshalb prüfen wir
        // Caption und Bildobjekt statt der Base64-Zeichenkette.
        $this->assertStringContainsString('LegacyOhneMime.png', $pdf);
        $this->assertStringContainsString('/Subtype /Image', $pdf);
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

    public function test_oversized_stored_webp_is_rejected_before_contact_sheet_decode(): void
    {
        $bytes = $this->webpHeader(PhotoProcessingService::MAX_IMAGE_DIMENSION + 1, 1);
        $this->assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
        $this->assertSame(PhotoProcessingService::MAX_IMAGE_DIMENSION + 1, getimagesizefromstring($bytes)[0]);

        $this->assertStoredPhotoRejectedBeforeDecoder($bytes, 'legacy-oversized.webp', 'image/webp');
    }

    public function test_malformed_stored_webp_is_rejected_before_contact_sheet_decode(): void
    {
        $this->assertStoredPhotoRejectedBeforeDecoder(
            'RIFF'.pack('V', 12).'WEBPbroken',
            'legacy-malformed.webp',
            'image/webp',
        );
    }

    public function test_stored_photo_above_the_persistable_budget_is_omitted_before_contact_sheet_decode(): void
    {
        // Legacy file: written before ModelFileStore enforced its stored-byte
        // budget, so it may exceed what today's store can persist. Fail-closed
        // — omitted, never embedded. `str_pad` needs a single 20 MiB
        // allocation (no duplicate while concatenating); the cap itself cannot
        // be exercised with fewer bytes.
        $bytes = str_pad($this->imageBytes(), PhotoProcessingService::MAX_IMAGE_BYTES + 1, "\0");
        $this->assertGreaterThan(PhotoProcessingService::MAX_IMAGE_BYTES, strlen($bytes));
        $this->assertSame('image/png', (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes));

        $this->assertStoredPhotoRejectedBeforeDecoder($bytes, 'legacy-above-budget.png', 'image/png');
    }

    public function test_contact_sheet_byte_cap_is_the_persistable_image_budget_not_the_upload_gate(): void
    {
        // A stored photo may legally be larger than the 10 MiB *source* gate:
        // storeUpload() re-encodes at quality 90, which inflates a highly
        // compressed upload several-fold. The sheet therefore reads the
        // persisted bound (PhotoProcessingService::MAX_IMAGE_BYTES) — a file
        // between the two numbers must still reach the decoder.
        //
        // The spy returns false, so the assertion stops at the cap: reaching
        // the decoder proves the bytes passed it. Embedding a >10 MiB data URI
        // would measure DomPDF's memory instead of this invariant.
        $bytes = str_pad($this->imageBytes(), 10 * 1024 * 1024 + 1, "\0");
        $this->assertGreaterThan(10 * 1024 * 1024, strlen($bytes));
        $this->assertLessThan(PhotoProcessingService::MAX_IMAGE_BYTES, strlen($bytes));
        $this->assertSame('image/png', (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
        $this->assertSame(24, getimagesizefromstring($bytes)[0]);

        $profile = $this->profile();
        $this->addRawPhoto($profile, 'reencoded-above-source-gate.png', $bytes, 'image/png');

        $service = $this->sheetWithDecodeSpy();
        $service->render($profile, ModelContactSheetService::VARIANT_INTERNAL);

        $this->assertSame(1, $service->decodeCalls);
    }

    public function test_photo_stored_through_the_upload_gate_is_embedded_in_the_contact_sheet(): void
    {
        $profile = $this->profile();
        $stored = app(ModelFileStore::class)->storeUpload(
            'model-photos/'.$profile->id,
            UploadedFile::fake()->createWithContent('portrait.jpg', $this->jpegBytes()),
        );

        $this->assertSame('image/jpeg', $stored['mime']);
        $this->assertLessThanOrEqual(PhotoProcessingService::MAX_IMAGE_BYTES, $stored['size']);

        ModelPhoto::create([
            'customer_id' => $profile->customer_id,
            'model_profile_id' => $profile->id,
            'path' => $stored['path'],
            'original_name' => 'portrait.jpg',
            'mime_type' => $stored['mime'],
            'size_bytes' => $stored['size'],
            'visibility' => ModelPhoto::VISIBILITY_PUBLIC,
            'is_primary' => true,
            'position' => 0,
        ]);

        $pdf = (new ModelContactSheetService(app(ModelFileStore::class), app(ModelQuestionnaire::class)))
            ->render($profile, ModelContactSheetService::VARIANT_INTERNAL);

        // Stored by the real upload path (decode → re-encode → budget check)
        // and embedded: the store's bound and the sheet's bound are the same.
        $this->assertStringContainsString('portrait.jpg', $pdf);
        $this->assertStringContainsString('/Subtype /Image', $pdf);
    }

    public function test_undecodable_stored_raster_is_omitted_instead_of_embedded_as_unverified_bytes(): void
    {
        // Truncated PNG / header-only WebP: metadata (mime + dimensions) is
        // parseable, so only the actual decoder can notice the damage.
        $truncatedPng = substr($this->imageBytes(), 0, 40);
        $this->assertSame('image/png', (new \finfo(FILEINFO_MIME_TYPE))->buffer($truncatedPng));
        $this->assertSame(24, getimagesizefromstring($truncatedPng)[0]);
        $this->assertFalse(@imagecreatefromstring($truncatedPng));

        $headerOnlyWebp = $this->webpHeader(24, 24);
        $this->assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->buffer($headerOnlyWebp));
        $this->assertSame(24, getimagesizefromstring($headerOnlyWebp)[0]);
        $this->assertFalse(@imagecreatefromstring($headerOnlyWebp));

        $service = new class(app(ModelFileStore::class), app(ModelQuestionnaire::class)) extends ModelContactSheetService
        {
            public function dataUriFor(string $contents, ?string $mime): ?string
            {
                return $this->imageDataUri($contents, $mime);
            }
        };

        $this->assertNull($service->dataUriFor($truncatedPng, 'image/png'));
        // The guarantee is "no unverified bytes", not "no raw bytes": JPEG/PNG
        // bytes are still passed through verbatim after a successful decode.
        // Pins the removed WebP fallback, which returned a raw `data:image/webp`
        // URI for an image GD could not decode.
        $this->assertNull($service->dataUriFor($headerOnlyWebp, 'image/webp'));

        $profile = $this->profile();
        $this->addRawPhoto($profile, 'legacy-truncated.png', $truncatedPng, 'image/png');

        $pdf = $service->render($profile, ModelContactSheetService::VARIANT_INTERNAL);

        // Explicit outcome: the unusable photo is omitted like any other
        // unembeddable photo; the sheet itself still renders.
        $this->assertStringContainsString('Model Contact Sheet', $pdf);
        $this->assertStringNotContainsString('legacy-truncated.png', $pdf);
        $this->assertStringNotContainsString('/Subtype /Image', $pdf);
    }

    public function test_valid_stored_webp_is_embedded_as_normalized_jpeg(): void
    {
        $webp = $this->webpBytes();
        $profile = $this->profile();
        $this->addRawPhoto($profile, 'valid.webp', $webp, 'image/webp');

        $pdf = (new ModelContactSheetService(app(ModelFileStore::class), app(ModelQuestionnaire::class)))
            ->render($profile, ModelContactSheetService::VARIANT_INTERNAL);

        $this->assertStringContainsString('valid.webp', $pdf);
        $this->assertStringContainsString('/Subtype /Image', $pdf);
        // Normalized to JPEG for DomPDF; the WebP container is not embedded.
        $this->assertStringContainsString('/Filter /DCTDecode', $pdf);
        $this->assertStringNotContainsString('WEBPVP8', $pdf);
    }

    private function assertStoredPhotoRejectedBeforeDecoder(string $bytes, string $name, string $mime): void
    {
        $profile = $this->profile();
        $this->addRawPhoto($profile, $name, $bytes, $mime);

        $service = $this->sheetWithDecodeSpy();
        $pdf = $service->render($profile, ModelContactSheetService::VARIANT_INTERNAL);

        $this->assertSame(0, $service->decodeCalls);
        $this->assertStringNotContainsString($name, $pdf);
    }

    /**
     * Sheet service whose raster decoder never succeeds. The counter proves
     * whether the byte/MIME/dimension precheck let the bytes through: reaching
     * the decoder bumps it, being rejected before it leaves it at 0.
     */
    private function sheetWithDecodeSpy(): object
    {
        return new class(app(ModelFileStore::class), app(ModelQuestionnaire::class)) extends ModelContactSheetService
        {
            public int $decodeCalls = 0;

            protected function decodeRaster(string $contents): \GdImage|false
            {
                $this->decodeCalls++;

                return false;
            }
        };
    }

    private function addRawPhoto(ModelProfile $profile, string $name, string $bytes, string $mime): ModelPhoto
    {
        $path = 'model-photos/'.$profile->id.'/'.$name;
        app(ModelFileStore::class)->putEncrypted($path, $bytes);

        return ModelPhoto::create([
            'customer_id' => $profile->customer_id,
            'model_profile_id' => $profile->id,
            'path' => $path,
            'original_name' => $name,
            'mime_type' => $mime,
            'size_bytes' => strlen($bytes),
            'visibility' => ModelPhoto::VISIBILITY_PUBLIC,
            'is_primary' => true,
            'position' => 0,
        ]);
    }

    private function webpBytes(): string
    {
        $image = imagecreatetruecolor(24, 24);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 120, 200));
        ob_start();
        imagewebp($image);
        $bytes = (string) ob_get_clean();

        return $bytes;
    }

    /**
     * Deterministic, poorly compressible JPEG: storeUpload() re-encodes it at
     * quality 90, so the stored size differs from the source size.
     */
    private function jpegBytes(): string
    {
        $image = imagecreatetruecolor(120, 120);
        for ($y = 0; $y < 120; $y++) {
            for ($x = 0; $x < 120; $x++) {
                imagesetpixel($image, $x, $y, imagecolorallocate(
                    $image,
                    ($x * 37 + $y * 11) % 256,
                    ($x * 17 + $y * 53) % 256,
                    ($x * 97 + $y * 7) % 256,
                ));
            }
        }

        ob_start();
        imagejpeg($image, null, 40);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    public function test_contact_sheet_is_brand_scoped(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $profile = $this->profile(['brand' => 'srp']);

        $this->actingAs($admin, 'api')
            ->getJson("/api/management/models/{$profile->id}/contact-sheet?variant=external")
            ->assertNotFound();
    }

    private function webpHeader(int $width, int $height): string
    {
        $dimensions = static fn (int $value): string => substr(pack('V', $value - 1), 0, 3);

        return 'RIFF'.pack('V', 22).'WEBPVP8X'.pack('V', 10)."\0\0\0\0"
            .$dimensions($width)
            .$dimensions($height);
    }
}
