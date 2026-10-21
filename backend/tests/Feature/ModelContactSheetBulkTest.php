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
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * HTTP-Contract + Privacy-Trennung des **Sammlungs**-Contact-Sheets (Phase 3).
 *
 * Die Text-Assertions prüfen den gerenderten PDF-Inhalt (der Service erzeugt
 * den Content-Stream bewusst unkomprimiert), damit ein PII-Leak in der
 * externen Variante zuverlässig fehlschlägt — genau wie im Einzelbogen
 * (`ModelContactSheetTest`).
 */
class ModelContactSheetBulkTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLIC_PHOTO = 'OeffentlichFoto.jpg';

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
    private function snapshot(string $stageName): array
    {
        return [
            ['scope' => 'person', 'key' => 'stage_name', 'label' => 'Künstlername / Pseudonym', 'type' => 'text', 'value' => $stageName],
            ['scope' => 'person', 'key' => 'phone', 'label' => 'Telefon', 'type' => 'tel', 'value' => '06601234567'],
            ['scope' => 'person', 'key' => 'willingness_portrait', 'label' => 'Bereitschaft: Portrait', 'type' => 'select', 'value' => 'sehr_gerne'],
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
            'city' => 'Linzstadt',
            'country' => 'Oesterreich',
            'birthdate' => '1995-01-15',
        ], $customerAttributes));

        return ModelProfile::create(array_merge([
            'customer_id' => $customer->id,
            'catalog_version' => 'v1',
            'answers' => $this->snapshot('StarletX'),
            'gender' => 'female',
            'submitted_at' => now(),
        ], $profileAttributes));
    }

    private function addPhoto(
        ModelProfile $profile,
        string $name,
        string $visibility,
        bool $primary = false,
        int $size = 24,
        string $mimeType = 'image/png',
    ): ModelPhoto {
        $path = 'model-photos/'.$profile->id.'/'.$name;
        $bytes = $this->imageBytes($size);
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

    private function imageBytes(int $size = 24): string
    {
        $image = imagecreatetruecolor($size, $size);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 40));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    public function test_photographer_cannot_export_a_bulk_contact_sheet(): void
    {
        $photographer = $this->userWithRole(UserRole::PHOTOGRAPHER);
        $profile = $this->profile();

        $this->actingAs($photographer, 'api')
            ->postJson('/api/management/models/contact-sheet', [
                'ids' => [$profile->id],
                'variant' => 'internal',
            ])
            ->assertForbidden();
    }

    public function test_bulk_export_requires_at_least_one_id(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);

        $this->actingAs($admin, 'api')
            ->postJson('/api/management/models/contact-sheet', ['ids' => [], 'variant' => 'internal'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');

        // Kein Array (z. B. ein einzelner String) ist ebenfalls ungültig.
        $this->actingAs($admin, 'api')
            ->postJson('/api/management/models/contact-sheet', ['ids' => 'not-an-array', 'variant' => 'internal'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');

        // IDs müssen Strings sein.
        $this->actingAs($admin, 'api')
            ->postJson('/api/management/models/contact-sheet', ['ids' => [1, 2], 'variant' => 'internal'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');
    }

    public function test_bulk_export_rejects_more_profiles_than_the_limit(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);

        // 26 verschiedene IDs — das Limit gilt für die eindeutige Auswahl.
        $ids = [];
        for ($index = 0; $index < ModelContactSheetService::MAX_BULK_PROFILES + 1; $index++) {
            $ids[] = sprintf('00000000-0000-4000-8000-%012d', $index);
        }

        $this->actingAs($admin, 'api')
            ->postJson('/api/management/models/contact-sheet', ['ids' => $ids, 'variant' => 'internal'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');
    }

    public function test_bulk_export_counts_duplicated_ids_once(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);
        $profile = $this->profile();

        // 26mal dieselbe ID: eindeutig ist das **eine** Profil, also unter
        // dem Limit — und im Bogen steht genau eine Kachel.
        $ids = array_fill(0, ModelContactSheetService::MAX_BULK_PROFILES + 1, $profile->id);

        $response = $this->actingAs($admin, 'api')
            ->post('/api/management/models/contact-sheet', ['ids' => $ids, 'variant' => 'internal'])
            ->assertOk();

        $text = $this->pdfText($response->streamedContent());
        $this->assertStringContainsString('Profil 1 von 1', $text);
        $this->assertSame(1, substr_count($text, 'Profil '));
    }

    public function test_bulk_export_rejects_an_unknown_variant(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);
        $profile = $this->profile();

        $this->actingAs($admin, 'api')
            ->postJson('/api/management/models/contact-sheet', ['ids' => [$profile->id], 'variant' => 'bogus'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant');
    }

    public function test_bulk_export_rejects_a_profile_of_another_brand(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $foreign = $this->profile(['brand' => 'srp']);

        $this->actingAs($admin, 'api')
            ->postJson('/api/management/models/contact-sheet', ['ids' => [$foreign->id], 'variant' => 'internal'])
            ->assertNotFound();
    }

    public function test_bulk_export_fails_closed_when_one_id_does_not_resolve(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);
        $profile = $this->profile();

        // Die fremde/unbekannte ID bricht den **ganzen** Export ab — ein
        // stillschweigendes Weglassen würde eine Auswahl verändern.
        $this->actingAs($admin, 'api')
            ->postJson('/api/management/models/contact-sheet', [
                'ids' => [$profile->id, '00000000-0000-0000-0000-000000000000'],
                'variant' => 'internal',
            ])
            ->assertNotFound();
    }

    public function test_bulk_export_refuses_an_inactive_profile_for_a_regular_admin(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);

        // 14 Monate seit der letzten Bestätigung → `inactive` (13–15 Monate).
        $profile = $this->profile([], ['last_confirmed_at' => now()->subMonths(14)]);
        $this->assertSame(ModelProfile::LIFECYCLE_INACTIVE, $profile->lifecycleStatus());

        // Dasselbe Gate wie beim Einzelbogen: ein Regular Admin darf ein
        // inaktives Profil nicht exportieren.
        $this->actingAs($admin, 'api')
            ->postJson('/api/management/models/contact-sheet', ['ids' => [$profile->id], 'variant' => 'internal'])
            ->assertForbidden();
    }

    public function test_bulk_export_allows_an_inactive_profile_for_a_super_admin(): void
    {
        $superAdmin = $this->userWithRole(UserRole::SUPER_ADMIN, null);

        $profile = $this->profile([], ['last_confirmed_at' => now()->subMonths(14)]);
        $this->assertSame(ModelProfile::LIFECYCLE_INACTIVE, $profile->lifecycleStatus());

        $this->actingAs($superAdmin, 'api')
            ->post('/api/management/models/contact-sheet', ['ids' => [$profile->id], 'variant' => 'internal'])
            ->assertOk();
    }

    public function test_bulk_export_renders_one_tile_per_selected_profile(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);

        $first = $this->profile(
            ['name' => 'RealnameA', 'email' => 'a@example.com', 'city' => 'Linzstadt'],
            ['answers' => $this->snapshot('StarletA')],
        );
        $second = $this->profile(
            ['name' => 'RealnameB', 'email' => 'b@example.com', 'city' => 'Innsbruck'],
            ['answers' => $this->snapshot('StarletB')],
        );

        $this->addPhoto($first, self::PUBLIC_PHOTO, ModelPhoto::VISIBILITY_PUBLIC, true);
        $this->addPhoto($second, self::PUBLIC_PHOTO, ModelPhoto::VISIBILITY_PUBLIC, true);

        $response = $this->actingAs($admin, 'api')
            ->post('/api/management/models/contact-sheet', [
                'ids' => [$first->id, $second->id],
                'variant' => 'internal',
            ]);

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('models-contact-sheet-internal-'.now()->format('Ymd').'.pdf');

        $text = $this->pdfText($response->streamedContent());

        // Genau eine Kachel je ausgewähltem Profil.
        $this->assertStringContainsString('Profil 1 von 2', $text);
        $this->assertStringContainsString('Profil 2 von 2', $text);
        $this->assertSame(2, substr_count($text, 'Profil '));

        // Beide Profile sind im Bogen.
        $this->assertStringContainsString('RealnameA', $text);
        $this->assertStringContainsString('RealnameB', $text);
        $this->assertStringContainsString('Linzstadt', $text);
        $this->assertStringContainsString('Innsbruck', $text);
    }

    public function test_internal_bulk_export_contains_the_contact_data(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);

        $first = $this->profile(
            ['name' => 'RealnameA', 'email' => 'a@example.com', 'city' => 'Linzstadt'],
            ['answers' => $this->snapshot('StarletA')],
        );
        $second = $this->profile(
            ['name' => 'RealnameB', 'email' => 'b@example.com', 'city' => 'Innsbruck'],
            ['answers' => $this->snapshot('StarletB')],
        );

        $this->addPhoto($first, self::PUBLIC_PHOTO, ModelPhoto::VISIBILITY_PUBLIC, true);
        $this->addPhoto($second, self::PUBLIC_PHOTO, ModelPhoto::VISIBILITY_PUBLIC, true);

        $pdf = $this->actingAs($admin, 'api')
            ->post('/api/management/models/contact-sheet', [
                'ids' => [$first->id, $second->id],
                'variant' => 'internal',
            ])
            ->assertOk()
            ->streamedContent();

        $text = $this->pdfText($pdf);

        // Interne Variante: Kontaktdaten und Foto-Namen sind sichtbar.
        $this->assertStringContainsString('RealnameA', $text);
        $this->assertStringContainsString('a@example.com', $text);
        $this->assertStringContainsString('RealnameB', $text);
        $this->assertStringContainsString('b@example.com', $text);
        $this->assertStringContainsString(self::PUBLIC_PHOTO, $text);

        // Intern ohne Wasserzeichen.
        $this->assertStringNotContainsString('Nur zur Ansicht', $text);
    }

    public function test_external_bulk_export_omits_pii_and_internal_photos(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);

        $first = $this->profile(
            ['name' => 'RealnameA', 'email' => 'a@example.com', 'city' => 'Linzstadt'],
            ['answers' => $this->snapshot('StarletA')],
        );
        $second = $this->profile(
            ['name' => 'RealnameB', 'email' => 'b@example.com', 'city' => 'Innsbruck'],
            ['answers' => $this->snapshot('StarletB')],
        );

        // Öffentliches Foto (24×24) + internes Foto (40×40). Die Variante
        // darf nur das öffentliche einbetten — die Bildmaße im PDF belegen das.
        $this->addPhoto($first, self::PUBLIC_PHOTO, ModelPhoto::VISIBILITY_PUBLIC, true, 24);
        $this->addPhoto($first, self::INTERNAL_PHOTO, ModelPhoto::VISIBILITY_INTERNAL, false, 40);
        $this->addPhoto($second, self::PUBLIC_PHOTO, ModelPhoto::VISIBILITY_PUBLIC, true, 24);

        $response = $this->actingAs($admin, 'api')
            ->post('/api/management/models/contact-sheet', [
                'ids' => [$first->id, $second->id],
                'variant' => 'external',
            ]);

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('models-contact-sheet-external-'.now()->format('Ymd').'.pdf');

        $pdf = $response->streamedContent();
        $text = $this->pdfText($pdf);

        // Öffentliche Identität + Ort.
        $this->assertStringContainsString('StarletA', $text);
        $this->assertStringContainsString('StarletB', $text);
        $this->assertStringContainsString('Linzstadt', $text);

        // Wasserzeichen (extern, wiederholt).
        $this->assertGreaterThanOrEqual(2, substr_count($pdf, 'Nur zur Ansicht'));

        // Keine PII: kein Realname, keine Kontaktdaten.
        $this->assertStringNotContainsString('RealnameA', $text);
        $this->assertStringNotContainsString('RealnameB', $text);
        $this->assertStringNotContainsString('a@example.com', $text);
        $this->assertStringNotContainsString('b@example.com', $text);
        $this->assertStringNotContainsString('06601234567', $text);

        // Keine Original-Dateinamen und kein internes Foto: nur das öffentliche
        // Bild (24×24) ist eingebettet, das interne (40×40) nicht.
        $this->assertStringNotContainsString(self::PUBLIC_PHOTO, $text);
        $this->assertStringNotContainsString(self::INTERNAL_PHOTO, $text);
        $this->assertStringContainsString('/Subtype /Image', $pdf);
        $this->assertStringContainsString('/Width 24', $pdf);
        $this->assertStringNotContainsString('/Width 40', $pdf);

        // D-41 (extern): das interne Foto war nie Kandidat — es wird weder
        // gezählt noch erwähnt. Die Kachel meldet deshalb keine Auslassung.
        $this->assertStringNotContainsString('Nicht enthaltene Fotos', $text);
        $this->assertStringNotContainsString('ausgelassen', $text);
    }

    public function test_bulk_export_names_the_photo_limit_of_the_tile(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);
        $profile = $this->profile(
            ['name' => 'RealnameA', 'email' => 'a@example.com'],
            ['answers' => $this->snapshot('StarletA')],
        );

        // Zwei Fotos: die Kachel zeigt **eines** (das Hauptbild), das zweite
        // war nie Kandidat — es wird als Limit-Auslassung benannt (D-41).
        $this->addPhoto($profile, 'Erstes.png', ModelPhoto::VISIBILITY_PUBLIC, true);
        $this->addPhoto($profile, 'Zweites.png', ModelPhoto::VISIBILITY_PUBLIC, false);

        $text = $this->pdfText($this->actingAs($admin, 'api')
            ->post('/api/management/models/contact-sheet', [
                'ids' => [$profile->id],
                'variant' => 'internal',
            ])
            ->assertOk()
            ->streamedContent());

        $this->assertStringContainsString('Nicht enthaltene Fotos: 1', $text);
        $this->assertStringContainsString('1 Foto ausgelassen: Foto-Limit (max. 1)', $text);

        // Nur das eine Foto ist im Bogen.
        $this->assertStringContainsString('Erstes.png', $text);
        $this->assertStringNotContainsString('Zweites.png', $text);
    }

    public function test_bulk_export_names_the_omitted_photo_per_tile(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);
        $profile = $this->profile(
            ['name' => 'RealnameA', 'email' => 'a@example.com'],
            ['answers' => $this->snapshot('StarletA')],
        );

        // Gespeichert sind JPEG-Bytes, die DB-Spalte behauptet `image/png`:
        // die MIME-Prüfung schlägt fehl (fail-closed, vor dem Decoder).
        $path = 'model-photos/'.$profile->id.'/mismatch.jpg';
        app(ModelFileStore::class)->putEncrypted($path, $this->jpegBytes());
        ModelPhoto::create([
            'customer_id' => $profile->customer_id,
            'model_profile_id' => $profile->id,
            'path' => $path,
            'original_name' => 'mismatch.jpg',
            'mime_type' => 'image/png',
            'size_bytes' => 100,
            'visibility' => ModelPhoto::VISIBILITY_PUBLIC,
            'is_primary' => true,
            'position' => 0,
        ]);

        $text = $this->pdfText($this->actingAs($admin, 'api')
            ->post('/api/management/models/contact-sheet', [
                'ids' => [$profile->id],
                'variant' => 'internal',
            ])
            ->assertOk()
            ->streamedContent());

        // D-41: Die Kachel nennt Anzahl und Grund der Auslassung — PII-frei.
        $this->assertStringContainsString('Nicht enthaltene Fotos: 1', $text);
        $this->assertStringContainsString('1 Foto ausgelassen: MIME-Prüfung', $text);
        $this->assertStringNotContainsString('mismatch.jpg', $text);
    }

    /**
     * Das Byte-Budget der Sammlung begrenzt die eingebetteten Bytes.
     *
     * Eingebettet werden die **Originalbytes** (JPEG/PNG unverändert) — ohne
     * Budget trägt eine Sammlung bis zu 25 Fotos mit je bis zu 20 MiB. Das
     * Budget ist deshalb eine **zusätzliche** Grenze: der Foto-Prüfpfad
     * (Byte-Cap → Decode → MIME → Dimensions → GD) bleibt unverändert, nur
     * die Einbettung wird zusätzlich budgetiert.
     *
     * Der Test verkleinert das Budget über die `protected`-Naht
     * (`collectionMaxTotalPhotoBytes()`), damit kein 32-MiB-Fixture nötig ist:
     * das Budget reicht exakt für das erste Foto, das zweite wird nicht
     * eingebettet und auf **Dokumentebene** benannt (Anzahl + Grund, PII-frei).
     */
    public function test_collection_budget_omits_a_photo_and_names_it_on_document_level(): void
    {
        $first = $this->profile(
            ['name' => 'RealnameA', 'email' => 'a@example.com'],
            ['answers' => $this->snapshot('StarletA')],
        );
        $second = $this->profile(
            ['name' => 'RealnameB', 'email' => 'b@example.com'],
            ['answers' => $this->snapshot('StarletB')],
        );

        $this->addPhoto($first, 'Erstes.png', ModelPhoto::VISIBILITY_PUBLIC, true);
        $this->addPhoto($second, 'Zweites.png', ModelPhoto::VISIBILITY_PUBLIC, true);

        // Das Budget reicht exakt für ein Foto (die Rohbytes des PNG).
        $budget = strlen($this->imageBytes(24));

        $text = $this->pdfText($this->collectionService($budget)->renderCollection(
            [$first, $second],
            ModelContactSheetService::VARIANT_INTERNAL,
        ));

        // Das zweite Foto ist nicht eingebettet — und der Bogen **benennt** die
        // Auslassung (Anzahl + Grund), statt die Auswahl stillschweigend zu
        // verkürzen.
        $this->assertStringContainsString(
            '1 Foto ausgelassen: '.ModelContactSheetService::COLLECTION_BUDGET_OMISSION_LABEL,
            $text,
        );

        // Die Auslassung ist **Dokument**-Ebene, nicht Kachel-Ebene: die Kachel
        // meldet keine gescheiterte Foto-Prüfung.
        $this->assertStringNotContainsString('Nicht enthaltene Fotos', $text);

        // Beide Kacheln stehen im Bogen; nur das Foto der zweiten fehlt.
        $this->assertStringContainsString('Profil 1 von 2', $text);
        $this->assertStringContainsString('Profil 2 von 2', $text);
        $this->assertStringContainsString('Erstes.png', $text);
        $this->assertStringNotContainsString('Zweites.png', $text);
    }

    /**
     * Auch **ein einzelnes** Foto kann das Gesamtbudget überschreiten.
     *
     * Der Budget-Test oberhalb deckt den Fall „Budget nach dem ersten Foto
     * erschöpft" ab. Dieser Test deckt den Randfall ab: das **erste** (und
     * einzige) Foto übersteigt das Budget bereits. Es wird nicht eingebettet
     * und auf **Dokumentebene** benannt (Anzahl + Grund, PII-frei) — die Kachel
     * meldet **keine** gescheiterte Prüfung.
     */
    public function test_collection_budget_omits_a_single_photo_that_alone_exceeds_the_budget(): void
    {
        $profile = $this->profile(
            ['name' => 'RealnameA', 'email' => 'a@example.com'],
            ['answers' => $this->snapshot('StarletA')],
        );

        $this->addPhoto($profile, 'Erstes.png', ModelPhoto::VISIBILITY_PUBLIC, true);

        // Ein Budget von einem Byte: das Foto passt nicht hinein.
        $text = $this->pdfText($this->collectionService(1)->renderCollection(
            [$profile],
            ModelContactSheetService::VARIANT_INTERNAL,
        ));

        // Die Auslassung wird auf Dokumentebene benannt (Anzahl + Grund).
        $this->assertStringContainsString(
            '1 Foto ausgelassen: '.ModelContactSheetService::COLLECTION_BUDGET_OMISSION_LABEL,
            $text,
        );

        // Die Kachel meldet **keine** gescheiterte Prüfung — das Budget ist
        // keine Prüfung, sondern eine zusätzliche Grenze.
        $this->assertStringNotContainsString('Nicht enthaltene Fotos', $text);

        // Die Kachel steht im Bogen, das Foto ist nicht eingebettet.
        $this->assertStringContainsString('Profil 1 von 1', $text);
        $this->assertStringNotContainsString('Erstes.png', $text);
    }

    /**
     * Der Sammlungs-Service mit verkleinertem Byte-Budget.
     *
     * Die `protected`-Naht `collectionMaxTotalPhotoBytes()` ist der einzige
     * Eingriff — der Foto-Prüfpfad bleibt der des Produktivcodes.
     */
    private function collectionService(int $budget): ModelContactSheetService
    {
        return new class($budget, app(ModelFileStore::class), app(ModelQuestionnaire::class)) extends ModelContactSheetService
        {
            public function __construct(
                private readonly int $budget,
                ModelFileStore $fileStore,
                ModelQuestionnaire $questionnaire,
            ) {
                parent::__construct($fileStore, $questionnaire);
            }

            protected function collectionMaxTotalPhotoBytes(): int
            {
                return $this->budget;
            }
        };
    }

    public function test_bulk_export_writes_one_audit_line_per_profile_and_a_bulk_line(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);

        $first = $this->profile(['name' => 'RealnameA'], ['answers' => $this->snapshot('StarletA')]);
        $second = $this->profile(['name' => 'RealnameB'], ['answers' => $this->snapshot('StarletB')]);

        Log::spy();

        $this->actingAs($admin, 'api')
            ->post('/api/management/models/contact-sheet', [
                'ids' => [$first->id, $second->id],
                'variant' => 'external',
            ])
            ->assertOk();

        // Eine Zeile je Profil — PII-frei.
        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context): bool => $message === 'model.contact_sheet.export'
                && $context['variant'] === 'external'
                && in_array($context['model_profile_id'], [$first->id, $second->id], true)
                && array_key_exists('customer_id', $context)
                && $context['user_id'] === $admin->id
                && ! array_key_exists('email', $context)
        )->twice();

        // Eine Sammelzeile mit Anzahl und Variante.
        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context): bool => $message === 'model.contact_sheet.export_bulk'
                && $context['count'] === 2
                && $context['variant'] === 'external'
                && $context['user_id'] === $admin->id
        )->once();
    }

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

    /**
     * Sichtbarer Text des unkomprimierten Content-Streams.
     *
     * DomPDF schreibt mit Core-Fonts in Windows-1252 (Umlaute sind dort
     * Einzelbytes) und escaped Klammern als `\(`. Deshalb wird das Dokument
     * einmal nach UTF-8 dekodiert und der Text aus den Text-Operatoren
     * (`[(…)] TJ`) extrahiert.
     */
    private function pdfText(string $pdf): string
    {
        $decoded = (string) iconv('CP1252', 'UTF-8//IGNORE', $pdf);

        $parts = [];
        if (preg_match_all('/\[([^\]]*)\]\s*TJ/', $decoded, $arrays) === 0) {
            return $decoded;
        }

        foreach ($arrays[1] as $array) {
            if (preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\)/', $array, $strings) === 0) {
                continue;
            }

            foreach ($strings[1] as $string) {
                $parts[] = $this->unescapePdfString($string);
            }
        }

        return implode(' ', $parts);
    }

    private function unescapePdfString(string $value): string
    {
        return preg_replace_callback(
            '/\\\\([nrtbf()\\\\]|[0-7]{1,3})/',
            static function (array $match): string {
                $escape = $match[1];

                if (ctype_digit($escape)) {
                    return chr((int) octdec($escape));
                }

                return match ($escape) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'b' => "\x08",
                    'f' => "\x0C",
                    '(' => '(',
                    ')' => ')',
                    '\\' => '\\',
                    default => $escape,
                };
            },
            $value
        ) ?? $value;
    }
}
