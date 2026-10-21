<?php

namespace Tests\Unit\Services;

use App\Services\ModelContactSheetLinkService;
use App\Services\ModelContactSheetService;
use App\Services\OfferTokenService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Strikte Prüfung der Nutzlast eines Contact-Sheet-Links (Phase 4).
 *
 * Die HTTP-Ebene (`ModelContactSheetShareTest`) deckt nur die Fehlerfälle ab,
 * die **vor** der Nutzlastprüfung greifen (Signatur, Ablauf, Host-Brand,
 * leere Auslieferung). Die Prüfungen der Nutzlast selbst — Typ, Variante,
 * ID-Liste, Brand, `link_id` — bekommen hier einen eigenen Test.
 *
 * Für die Negativfälle wird ein Token mit **gültiger Signatur** und
 * absichtlich verbogenem Payload gebaut (über denselben
 * {@see OfferTokenService}). Sonst würde der Test nur die Signaturprüfung
 * beweisen, nicht die Formprüfung.
 */
class ModelContactSheetLinkServiceTest extends TestCase
{
    private ModelContactSheetLinkService $service;

    private OfferTokenService $offerTokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ModelContactSheetLinkService::class);
        $this->offerTokens = app(OfferTokenService::class);
    }

    public function test_a_freshly_issued_link_verifies_with_its_complete_payload(): void
    {
        $token = $this->service->issue(['profile-1', 'profile-2'], 'rp');

        $payload = $this->service->verify($token, 'rp');

        $this->assertNotNull($payload);
        $this->assertSame(['profile-1', 'profile-2'], $payload['model_profile_ids']);
        $this->assertSame(ModelContactSheetService::VARIANT_EXTERNAL, $payload['variant']);
        $this->assertSame('rp', $payload['brand']);
        $this->assertTrue(Str::isUuid($payload['link_id']));
    }

    public function test_a_link_of_another_brand_is_rejected(): void
    {
        $token = $this->service->issue(['profile-1'], 'rp');

        $this->assertNull($this->service->verify($token, 'srp'));
    }

    public function test_an_expired_link_is_rejected(): void
    {
        $token = $this->service->issue(['profile-1'], 'rp', now()->subMinute());

        $this->assertNull($this->service->verify($token, 'rp'));
    }

    /**
     * Ein Quote-/Offer-Token ist kein Contact-Sheet-Token.
     *
     * Der Typ fehlt in der Nutzlast — `verify()` liefert `null`, statt die
     * fremde Nutzlast (z. B. Foto-IDs eines Angebots) zu akzeptieren.
     */
    public function test_a_quote_token_is_not_accepted_as_a_contact_sheet_token(): void
    {
        $token = $this->offerTokens->issueQuote(['photo-1', 'photo-2'], 12345, null, 'rp');

        // Die Signatur ist gültig — abgelehnt wird der **Typ** der Nutzlast.
        $this->assertNotNull($this->offerTokens->verify($token));

        $this->assertNull($this->service->verify($token, 'rp'));
    }

    public function test_a_token_of_another_type_is_rejected(): void
    {
        $token = $this->signedToken([
            'type' => 'quote',
            'model_profile_ids' => ['profile-1'],
            'variant' => ModelContactSheetService::VARIANT_EXTERNAL,
            'brand' => 'rp',
            'link_id' => (string) Str::uuid(),
        ]);

        $this->assertNull($this->service->verify($token, 'rp'));
    }

    /**
     * Die interne Variante (mit PII) ist nie teilbar.
     */
    public function test_a_token_with_the_internal_variant_is_rejected(): void
    {
        $token = $this->signedToken([
            'type' => ModelContactSheetLinkService::TOKEN_TYPE,
            'model_profile_ids' => ['profile-1'],
            'variant' => ModelContactSheetService::VARIANT_INTERNAL,
            'brand' => 'rp',
            'link_id' => (string) Str::uuid(),
        ]);

        $this->assertNull($this->service->verify($token, 'rp'));
    }

    public function test_a_token_without_profile_ids_is_rejected(): void
    {
        $token = $this->signedToken([
            'type' => ModelContactSheetLinkService::TOKEN_TYPE,
            'model_profile_ids' => [],
            'variant' => ModelContactSheetService::VARIANT_EXTERNAL,
            'brand' => 'rp',
            'link_id' => (string) Str::uuid(),
        ]);

        $this->assertNull($this->service->verify($token, 'rp'));
    }

    public function test_a_token_with_more_profiles_than_the_limit_is_rejected(): void
    {
        $ids = [];
        for ($index = 0; $index <= ModelContactSheetService::MAX_BULK_PROFILES; $index++) {
            $ids[] = sprintf('00000000-0000-4000-8000-%012d', $index);
        }

        $token = $this->signedToken([
            'type' => ModelContactSheetLinkService::TOKEN_TYPE,
            'model_profile_ids' => $ids,
            'variant' => ModelContactSheetService::VARIANT_EXTERNAL,
            'brand' => 'rp',
            'link_id' => (string) Str::uuid(),
        ]);

        $this->assertNull($this->service->verify($token, 'rp'));
    }

    public function test_a_token_with_duplicate_profile_ids_is_rejected(): void
    {
        $token = $this->signedToken([
            'type' => ModelContactSheetLinkService::TOKEN_TYPE,
            'model_profile_ids' => ['profile-1', 'profile-1'],
            'variant' => ModelContactSheetService::VARIANT_EXTERNAL,
            'brand' => 'rp',
            'link_id' => (string) Str::uuid(),
        ]);

        $this->assertNull($this->service->verify($token, 'rp'));
    }

    public function test_a_token_with_an_invalid_profile_id_is_rejected(): void
    {
        $token = $this->signedToken([
            'type' => ModelContactSheetLinkService::TOKEN_TYPE,
            'model_profile_ids' => ['profile-1', ''],
            'variant' => ModelContactSheetService::VARIANT_EXTERNAL,
            'brand' => 'rp',
            'link_id' => (string) Str::uuid(),
        ]);

        $this->assertNull($this->service->verify($token, 'rp'));
    }

    public function test_a_token_without_a_brand_is_rejected(): void
    {
        $token = $this->signedToken([
            'type' => ModelContactSheetLinkService::TOKEN_TYPE,
            'model_profile_ids' => ['profile-1'],
            'variant' => ModelContactSheetService::VARIANT_EXTERNAL,
            'brand' => '',
            'link_id' => (string) Str::uuid(),
        ]);

        $this->assertNull($this->service->verify($token, 'rp'));
    }

    /**
     * Die `link_id` ist der PII-freie Audit-Griff — ohne gültige UUID ist der
     * Token nicht verwertbar.
     */
    public function test_a_token_with_a_link_id_that_is_no_uuid_is_rejected(): void
    {
        $token = $this->signedToken([
            'type' => ModelContactSheetLinkService::TOKEN_TYPE,
            'model_profile_ids' => ['profile-1'],
            'variant' => ModelContactSheetService::VARIANT_EXTERNAL,
            'brand' => 'rp',
            'link_id' => 'not-a-uuid',
        ]);

        $this->assertNull($this->service->verify($token, 'rp'));
    }

    /**
     * Ein Token mit **gültiger Signatur** und absichtlich verbogenem Payload.
     *
     * @param  array<string, mixed>  $payload
     */
    private function signedToken(array $payload): string
    {
        $token = $this->offerTokens->issue($payload, now()->addDays(ModelContactSheetLinkService::TTL_DAYS));

        // Die Signatur ist gültig: der Negativfall muss an der **Form** der
        // Nutzlast liegen, nicht an der Signaturprüfung.
        $this->assertSame($payload, $this->offerTokens->verify($token));

        return $token;
    }
}
