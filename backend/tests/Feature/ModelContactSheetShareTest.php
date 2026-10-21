<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\ModelPhoto;
use App\Models\ModelProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\ModelContactSheetLinkService;
use App\Services\ModelContactSheetService;
use App\Services\ModelFileStore;
use App\Support\BrandRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * HTTP-Contract des signierten **Extern-Links** (Phase 4).
 *
 * Geprüft werden die drei Grenzen des Links:
 *  - **Ausstellung** ist eine Autorisierungsgrenze: nur Admin, nur die eigene
 *    Brand, nur die externe Variante.
 *  - **Auslieferung** ist fail-closed: ungültig, abgelaufen, fremde Brand oder
 *    kein Profil mehr → immer 404 mit derselben generischen Meldung (kein
 *    Token-Orakel).
 *  - **Datenschutz**: der Empfänger bekommt ausschließlich das externe Blatt
 *    (keine PII, mit Wasserzeichen) und die Auslassung wird **benannt** statt
 *    stillschweigend verschwiegen.
 */
class ModelContactSheetShareTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLIC_PHOTO = 'OeffentlichFoto.jpg';

    private const INVALID_LINK_MESSAGE = 'Dieser Link ist ungültig oder abgelaufen.';

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
            'answers' => $this->snapshot('StarletZ'),
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
    ): ModelPhoto {
        $path = 'model-photos/'.$profile->id.'/'.$name;
        $bytes = $this->imageBytes($size);
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

    /**
     * Legt einen Share-Link an und liefert dessen Token.
     *
     * @param  array<int, string>  $profileIds
     */
    private function issueToken(array $profileIds, string $brand = 'rp', ?Carbon $expiresAt = null): string
    {
        return app(ModelContactSheetLinkService::class)->issue($profileIds, $brand, $expiresAt);
    }

    private function tokenFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $token = basename($path);

        $this->assertNotSame('', $token);

        return $token;
    }

    public function test_photographer_cannot_create_a_share_link(): void
    {
        $photographer = $this->userWithRole(UserRole::PHOTOGRAPHER);
        $profile = $this->profile();

        $this->actingAs($photographer, 'api')
            ->postJson('/api/management/models/contact-sheet/share', ['ids' => [$profile->id]])
            ->assertForbidden();
    }

    public function test_share_link_requires_at_least_one_id(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);

        $this->actingAs($admin, 'api')
            ->postJson('/api/management/models/contact-sheet/share', ['ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');

        $this->actingAs($admin, 'api')
            ->postJson('/api/management/models/contact-sheet/share', ['ids' => 'not-an-array'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');
    }

    public function test_share_link_rejects_more_profiles_than_the_limit(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);

        $ids = [];
        for ($index = 0; $index < ModelContactSheetService::MAX_BULK_PROFILES + 1; $index++) {
            $ids[] = sprintf('00000000-0000-4000-8000-%012d', $index);
        }

        $this->actingAs($admin, 'api')
            ->postJson('/api/management/models/contact-sheet/share', ['ids' => $ids])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');
    }

    public function test_share_link_refuses_the_internal_variant(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);
        $profile = $this->profile();

        // Die interne Variante enthält PII — sie darf nie teilbar sein.
        $this->actingAs($admin, 'api')
            ->postJson('/api/management/models/contact-sheet/share', [
                'ids' => [$profile->id],
                'variant' => 'internal',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant');
    }

    public function test_share_link_rejects_a_profile_of_another_brand(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $foreign = $this->profile(['brand' => 'srp']);

        $this->actingAs($admin, 'api')
            ->postJson('/api/management/models/contact-sheet/share', ['ids' => [$foreign->id]])
            ->assertNotFound();
    }

    public function test_share_link_fails_closed_when_one_id_does_not_resolve(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);
        $profile = $this->profile();

        $this->actingAs($admin, 'api')
            ->postJson('/api/management/models/contact-sheet/share', [
                'ids' => [$profile->id, '00000000-0000-0000-0000-000000000000'],
            ])
            ->assertNotFound();
    }

    public function test_share_link_refuses_an_inactive_profile_for_a_regular_admin(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);

        // 14 Monate seit der letzten Bestätigung → `inactive` (13–15 Monate).
        $profile = $this->profile([], ['last_confirmed_at' => now()->subMonths(14)]);
        $this->assertSame(ModelProfile::LIFECYCLE_INACTIVE, $profile->lifecycleStatus());

        // Dasselbe Gate wie beim Einzel- und beim Bulk-Bogen: ein Regular Admin
        // darf ein inaktives Profil nicht teilen.
        $this->actingAs($admin, 'api')
            ->postJson('/api/management/models/contact-sheet/share', ['ids' => [$profile->id]])
            ->assertForbidden();
    }

    public function test_share_link_allows_an_inactive_profile_for_a_super_admin(): void
    {
        $superAdmin = $this->userWithRole(UserRole::SUPER_ADMIN, null);

        $profile = $this->profile([], ['last_confirmed_at' => now()->subMonths(14)]);
        $this->assertSame(ModelProfile::LIFECYCLE_INACTIVE, $profile->lifecycleStatus());

        $this->actingAs($superAdmin, 'api')
            ->postJson('/api/management/models/contact-sheet/share', ['ids' => [$profile->id]])
            ->assertCreated();
    }

    public function test_admin_creates_a_share_link_for_the_selection(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);
        $profile = $this->profile();

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/management/models/contact-sheet/share', ['ids' => [$profile->id]])
            ->assertCreated()
            ->assertJsonPath('variant', 'external')
            ->assertJsonPath('count', 1);

        $url = (string) $response->json('url');
        $this->assertStringContainsString('/kontaktbogen/', $url);
        $this->assertNotSame('', $this->tokenFromUrl($url));

        $expiresAt = (string) $response->json('expires_at');
        $this->assertNotSame('', $expiresAt);
        $this->assertGreaterThan(
            now()->addDays(ModelContactSheetLinkService::TTL_DAYS - 1)->timestamp,
            (int) strtotime($expiresAt),
        );
    }

    public function test_a_new_link_does_not_invalidate_an_older_one(): void
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

        $olderToken = $this->tokenFromUrl((string) $this->actingAs($admin, 'api')
            ->postJson('/api/management/models/contact-sheet/share', ['ids' => [$first->id]])
            ->assertCreated()
            ->json('url'));

        // Ein zweiter Link für eine andere Auswahl.
        $this->actingAs($admin, 'api')
            ->postJson('/api/management/models/contact-sheet/share', ['ids' => [$second->id]])
            ->assertCreated();

        // Akzeptierte Grenze: bis zum Ablauf gibt es **keine** Widerrufung —
        // der ältere Link bleibt gültig.
        $response = $this->get('/api/public/model-contact-sheet/'.$olderToken);
        $response->assertOk();

        $text = $this->pdfText($response->streamedContent());
        $this->assertStringContainsString('StarletA', $text);
    }

    public function test_public_endpoint_delivers_the_external_sheet_without_a_session(): void
    {
        $profile = $this->profile(
            ['name' => 'RealnameA', 'email' => 'a@example.com', 'city' => 'Linzstadt'],
            ['answers' => $this->snapshot('StarletA')],
        );
        $this->addPhoto($profile, self::PUBLIC_PHOTO, ModelPhoto::VISIBILITY_PUBLIC, true);

        $token = $this->issueToken([$profile->id]);

        // Kein actingAs: der Endpunkt ist öffentlich, der Token ist das Credential.
        $response = $this->get('/api/public/model-contact-sheet/'.$token);

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('models-contact-sheet-external-'.now()->format('Ymd').'.pdf');

        $pdf = $response->streamedContent();
        $text = $this->pdfText($pdf);

        // Der Künstlername ist sichtbar …
        $this->assertStringContainsString('StarletA', $text);
        $this->assertStringContainsString('Linzstadt', $text);

        // … aber keine PII.
        $this->assertStringNotContainsString('RealnameA', $text);
        $this->assertStringNotContainsString('a@example.com', $text);
        $this->assertStringNotContainsString('06601234567', $text);
        $this->assertStringNotContainsString(self::PUBLIC_PHOTO, $text);

        // Wasserzeichen (extern, wiederholt).
        $this->assertGreaterThanOrEqual(2, substr_count($pdf, 'Nur zur Ansicht'));
    }

    public function test_public_endpoint_rejects_a_tampered_token(): void
    {
        $profile = $this->profile();
        $token = $this->issueToken([$profile->id]);

        $tampered = substr($token, 0, -1).(str_ends_with($token, 'A') ? 'B' : 'A');

        $this->get('/api/public/model-contact-sheet/'.$tampered)
            ->assertNotFound()
            ->assertJsonPath('error', self::INVALID_LINK_MESSAGE);
    }

    /**
     * Der Payload wird verändert, die **Signatur bleibt unverändert**.
     *
     * Der Test oberhalb verändert das letzte Zeichen der Signatur — das ist
     * je nach Originalzeichen entweder ein nicht-kanonisches Base64 (der
     * Decoder lehnt ab) oder ein anderer HMAC (die Signaturprüfung lehnt ab).
     * Dieser Test isoliert die Signaturprüfung: der Token bleibt wohlgeformt,
     * die Signatur passt aber nicht mehr zum Payload.
     */
    public function test_public_endpoint_rejects_a_token_with_a_mutated_payload(): void
    {
        $profile = $this->profile();
        $token = $this->issueToken([$profile->id]);

        [$header, $payload, $signature] = explode('.', $token);

        $claims = json_decode($this->base64UrlDecode($payload), true);
        $this->assertIsArray($claims);
        $this->assertArrayHasKey('offer', $claims);

        // Die Brand im Payload weicht von der des Tokens ab — der Token bleibt
        // syntaktisch gültig, nur die Signatur deckt ihn nicht mehr ab.
        $claims['offer']['brand'] = 'srp';

        $mutated = $header.'.'.$this->base64UrlEncode((string) json_encode($claims)).'.'.$signature;

        // Der Token ist wohlgeformt (drei Segmente) — der Fehlerfall ist die
        // Signatur, nicht die Form.
        $this->assertCount(3, explode('.', $mutated));

        $this->get('/api/public/model-contact-sheet/'.$mutated)
            ->assertNotFound()
            ->assertJsonPath('error', self::INVALID_LINK_MESSAGE);
    }

    /**
     * Die Link-URL zeigt auf die Frontend der **eigenen** Brand — nicht auf
     * den allgemeinen Default (`app.url`).
     *
     * Der Test schaltet die Umgebungs-URL (`FRONTEND_URL`) bewusst ab: ohne
     * sie hätte sie Vorrang, weil sie per Default gesetzt ist. Erst dann
     * läuft die Auflösung über die Brand-Konfiguration (`configForBrand()`)
     * — und dort ist für `rp` eine eigene `frontend_url` hinterlegt.
     */
    public function test_share_link_url_points_at_the_own_brand_frontend(): void
    {
        // Die env-Variante (`FRONTEND_URL`) überschreibt jede Brand-Auflösung;
        // der Test prüft deshalb den Brand-Pfad selbst.
        config(['app.frontend_url' => null, 'app.url' => 'https://portal.test']);

        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $profile = $this->profile(['brand' => 'rp']);

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/management/models/contact-sheet/share', ['ids' => [$profile->id]])
            ->assertCreated();

        $url = (string) $response->json('url');

        // Die eigene Brand-Frontend (aus `config/brands.php`) — nicht der
        // Default (`app.url`).
        $this->assertStringStartsWith('https://portal.reisinger.pictures/kontaktbogen/', $url);
        $this->assertStringNotContainsString('portal.test', $url);
    }

    /**
     * Eine Brand **ohne** `Brand`-Enum-Fall kann keinen Extern-Link bekommen.
     *
     * Die Auslieferung prüft die Token-Brand gegen die Host-Brand
     * (`BrandRegistry::currentIdOrNull()`), und die ist im Request-Pfad immer
     * ein Enum-Fall (`BrandContextMiddleware` → `BrandRegistry::fromHost()` →
     * `Brand::tryFrom($config->id) ?? Brand::B2B`). Eine Brand-ID außerhalb
     * des Enums (Legacy, z. B. `srp`) kann deshalb **nie** die Host-Brand
     * eines Links sein — der Token bekäme immer 404.
     *
     * Fail closed: der Vorgang wird abgelehnt (422) und **kein** Token
     * ausgestellt — besser ein abgelehnter Vorgang als ein Link, der nie
     * funktioniert.
     */
    public function test_share_link_is_refused_for_a_brand_outside_the_enum(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'srp');
        $profile = $this->profile(['brand' => 'srp']);

        // Kein Token wird ausgestellt — der Link-Service darf gar nicht
        // angesprochen werden.
        $links = Mockery::mock(ModelContactSheetLinkService::class);
        $links->shouldNotReceive('issue');
        $this->app->instance(ModelContactSheetLinkService::class, $links);

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/management/models/contact-sheet/share', ['ids' => [$profile->id]])
            ->assertStatus(422);

        // Keine URL in der Antwort — es wurde kein Link erzeugt.
        $this->assertArrayNotHasKey('url', $response->json());
        // Die Meldung nennt das **durchgesetzte** Kriterium: die Brand muss
        // eine sein, die das Portal unterstützt (ein `Brand`-Enum-Fall) — und
        // nicht etwa eine mit eigener Portalinstanz (das war die alte, falsche
        // Aussage: eine Legacy-Brand wie `srp` hatte eine eigene Instanz,
        // aber keinen Enum-Fall).
        $this->assertSame(
            'Für diese Brand kann kein Extern-Link erstellt werden. Der Link ist nur für Brands verfügbar, die das Portal unterstützt.',
            $response->json('error'),
        );
    }

    private function base64UrlDecode(string $value): string
    {
        $normalized = strtr($value, '-_', '+/');
        $padded = str_pad($normalized, (int) (ceil(strlen($normalized) / 4) * 4), '=');

        return (string) base64_decode($padded, true);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public function test_public_endpoint_rejects_an_expired_token(): void
    {
        $profile = $this->profile();
        $token = $this->issueToken([$profile->id], 'rp', now()->subDay());

        $this->get('/api/public/model-contact-sheet/'.$token)
            ->assertNotFound()
            ->assertJsonPath('error', self::INVALID_LINK_MESSAGE);
    }

    public function test_public_endpoint_rejects_a_token_of_another_brand(): void
    {
        $profile = $this->profile();
        $token = $this->issueToken([$profile->id], 'srp');

        $this->get('/api/public/model-contact-sheet/'.$token)
            ->assertNotFound()
            ->assertJsonPath('error', self::INVALID_LINK_MESSAGE);
    }

    public function test_public_endpoint_names_the_profiles_that_are_no_longer_available(): void
    {
        $first = $this->profile(
            ['name' => 'RealnameA', 'email' => 'a@example.com', 'city' => 'Linzstadt'],
            ['answers' => $this->snapshot('StarletA')],
        );
        $second = $this->profile(
            ['name' => 'RealnameB', 'email' => 'b@example.com', 'city' => 'Innsbruck'],
            ['answers' => $this->snapshot('StarletB')],
        );

        $this->addPhoto($first, self::PUBLIC_PHOTO, ModelPhoto::VISIBILITY_PUBLIC, true);

        $token = $this->issueToken([$first->id, $second->id]);

        // Das zweite Profil ist nicht mehr vorhanden (gelöscht).
        ModelProfile::where('id', $second->id)->delete();

        $response = $this->get('/api/public/model-contact-sheet/'.$token);
        $response->assertOk();

        $text = $this->pdfText($response->streamedContent());

        // Ein stilles Verengen ist nicht erlaubt: der Bogen nennt die Anzahl.
        $this->assertStringContainsString('1 Profil nicht mehr verfügbar', $text);

        // Nur das verbliebene Profil ist im Bogen.
        $this->assertStringContainsString('StarletA', $text);
        $this->assertStringNotContainsString('StarletB', $text);
        $this->assertStringContainsString('Profil 1 von 1', $text);
    }

    public function test_public_endpoint_rejects_a_link_without_any_remaining_profile(): void
    {
        $profile = $this->profile();
        $token = $this->issueToken([$profile->id]);

        ModelProfile::where('id', $profile->id)->delete();

        $this->get('/api/public/model-contact-sheet/'.$token)
            ->assertNotFound()
            ->assertJsonPath('error', self::INVALID_LINK_MESSAGE);
    }

    public function test_share_link_writes_audit_lines_without_the_token(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN);
        $profile = $this->profile();

        // Ein Recorder statt `Log::spy()`: nur so lässt sich **negativ**
        // belegen, dass der Token in keiner Log-Zeile steht.
        $recorder = new class
        {
            /** @var array<int, array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function __call(string $method, array $arguments): void
            {
                $this->records[] = [
                    'message' => (string) ($arguments[0] ?? ''),
                    'context' => (array) ($arguments[1] ?? []),
                ];
            }
        };

        Log::swap($recorder);

        $created = $this->actingAs($admin, 'api')
            ->postJson('/api/management/models/contact-sheet/share', ['ids' => [$profile->id]])
            ->assertCreated();

        $token = $this->tokenFromUrl((string) $created->json('url'));

        $this->get('/api/public/model-contact-sheet/'.$token)->assertOk();

        $messages = array_map(fn (array $record): string => $record['message'], $recorder->records);

        // Ausstellung: PII-freie Zeile mit der link_id (nicht dem Token).
        $this->assertContains('model.contact_sheet.share_link.created', $messages);
        $creationRecords = array_values(array_filter(
            $recorder->records,
            fn (array $record): bool => $record['message'] === 'model.contact_sheet.share_link.created',
        ));
        $this->assertCount(1, $creationRecords);
        $creationContext = $creationRecords[0]['context'];
        $this->assertIsString($creationContext['link_id']);
        $this->assertSame(1, $creationContext['count']);
        $this->assertSame('rp', $creationContext['brand']);
        $this->assertSame($admin->id, $creationContext['user_id']);
        $this->assertArrayNotHasKey('email', $creationContext);

        // Zugriff: PII-freie Zeile mit der Anzahl der gelieferten Profile.
        $this->assertContains('model.contact_sheet.share_link.accessed', $messages);
        $accessRecords = array_values(array_filter(
            $recorder->records,
            fn (array $record): bool => $record['message'] === 'model.contact_sheet.share_link.accessed',
        ));
        $this->assertCount(1, $accessRecords);
        $accessContext = $accessRecords[0]['context'];
        $this->assertIsString($accessContext['link_id']);
        $this->assertSame(1, $accessContext['count']);
        $this->assertSame('rp', $accessContext['brand']);
        $this->assertArrayNotHasKey('user_id', $accessContext);

        // Der Token ist ein Bearer-Credential: er darf in keiner Log-Zeile stehen.
        foreach ($recorder->records as $record) {
            $this->assertStringNotContainsString($token, $record['message']);
            $this->assertStringNotContainsString($token, (string) json_encode($record['context']));
        }
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
