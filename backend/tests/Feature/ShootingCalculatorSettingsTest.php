<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShootingCalculatorSettingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Erzeugt einen Admin-User mit Rolle und gibt einen gültigen JWT zurück.
     */
    private function adminToken(): string
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(
            Role::firstOrCreate(['name' => \App\Enums\UserRole::ADMIN->value])
        );
        return auth('api')->login($admin);
    }

    /**
     * Erzeugt einen Super-Admin-User mit Rolle und gibt einen gültigen JWT zurück.
     * Benötigt für billing-details-WRITE (R-01: super_admin-gesichert).
     */
    private function superAdminToken(): string
    {
        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(
            Role::firstOrCreate(['name' => \App\Enums\UserRole::SUPER_ADMIN->value])
        );
        return auth('api')->login($superAdmin);
    }

    /**
     * Gültiges calc_*-Payload. Setzt immer die required mult_*-Felder,
     * damit calc_*-spezifische Validierungsregeln nicht durch fehlende
     * required-Felder überlagert werden.
     */
    private function validCalcPayload(array $overrides = []): array
    {
        return array_merge([
            'mult_commercial' => '2.0',
            'mult_unlimited' => '1.5',
            'mult_international' => '1.5',
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // GET /api/settings/license-terms — Defaults & gespeicherte Werte
    // ------------------------------------------------------------------

    public function test_get_license_terms_returns_defaults_when_settings_missing(): void
    {
        // R-01: GET /settings/license-terms ist öffentlich (nur Lizenztexte + Preisfaktoren,
        // KEINE Bankdaten). Frische DB → hardcoded Defaults.
        $response = $this->getJson('/api/settings/license-terms');

        $response->assertStatus(200)
            ->assertJsonPath('base_price', '35.00')
            ->assertJsonPath('calc_base_price', '50')
            ->assertJsonPath('calc_hourly_rate', '80')
            ->assertJsonPath('calc_images_per_hour', '6')
            ->assertJsonPath('calc_outdoor_images_per_hour', null);
    }

    public function test_get_license_terms_returns_persisted_values_after_put(): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validCalcPayload([
                'calc_base_price' => '75',
                'calc_hourly_rate' => '150',
                'calc_images_per_hour' => '10',
            ]))
            ->assertStatus(200);

        // Folge-GET liefert die gespeicherten Werte (license-terms ist öffentlich, s. R-01)
        $this->getJson('/api/settings/license-terms')
            ->assertStatus(200)
            ->assertJsonPath('calc_base_price', '75')
            ->assertJsonPath('calc_hourly_rate', '150')
            ->assertJsonPath('calc_images_per_hour', '10');
    }

    // ------------------------------------------------------------------
    // R-01 (security/naming): Lizenztexte sind public-safe, Bank-/Firmendaten liegen
    // im separaten, auth-geschützten Endpunkt /settings/billing-details.
    // ------------------------------------------------------------------

    public function test_license_terms_is_public_and_omits_billing_data(): void
    {
        // Sensible Werte persistieren, damit ein Leak erkannt würde (nicht nur leere Defaults).
        // Settings werden brand-scoped gelesen (resolver scope nach brand='rp' im Default-B2B-Kontext),
        // daher wird hier explizit die B2B-Brand mitgegeben.
        Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'AT99 9999 9999 9999 9999']);
        Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'Max Mustermann']);
        Setting::updateOrCreate(['key' => 'company_email', 'brand' => 'rp'], ['value' => 'finance@reisinger.pictures']);
        Setting::updateOrCreate(['key' => 'company_city', 'brand' => 'rp'], ['value' => 'Wien']);
        Setting::updateOrCreate(['key' => 'mult_commercial', 'brand' => 'rp'], ['value' => '2.0']);
        Setting::updateOrCreate(['key' => 'calc_images_per_hour', 'brand' => 'rp'], ['value' => '6']);

        // Vollständig anonymer Aufruf (kein Authorization-Header) — license-terms ist öffentlich.
        $this->getJson('/api/settings/license-terms')
            ->assertStatus(200)
            ->assertJsonMissingPath('bank_iban')
            ->assertJsonMissingPath('bank_bic')
            ->assertJsonMissingPath('bank_holder')
            ->assertJsonMissingPath('company_street')
            ->assertJsonMissingPath('company_zip')
            ->assertJsonMissingPath('company_city')
            ->assertJsonMissingPath('company_country')
            ->assertJsonMissingPath('company_email')
            // Regression-Guard: legitime öffentliche Felder bleiben verfügbar (Gallery-Flow).
            ->assertJsonStructure([
                'editorial', 'commercial', '1_year', 'unlimited',
                'mult_commercial', 'mult_unlimited', 'mult_international',
                'base_price', 'calc_base_price', 'calc_hourly_rate', 'calc_images_per_hour',
            ])
            ->assertJsonPath('mult_commercial', '2.0')
            ->assertJsonPath('calc_images_per_hour', '6');
    }

    public function test_billing_details_rejects_anonymous_request(): void
    {
        // Billing-/Impressum-Daten sind sensibel → anonym = 401 (kein Leak).
        $this->getJson('/api/settings/billing-details')->assertStatus(401);
    }

    public function test_billing_details_returns_data_when_authenticated(): void
    {
        // GET bleibt auth:api (Klienten brauchen Bankdaten für "Kauf auf Rechnung").
        Setting::updateOrCreate(['key' => 'bank_iban'], ['value' => 'AT99 9999 9999 9999 9999']);
        Setting::updateOrCreate(['key' => 'bank_holder'], ['value' => 'Max Mustermann']);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/settings/billing-details')
            ->assertStatus(200)
            ->assertJsonPath('bank_iban', 'AT99 9999 9999 9999 9999')
            ->assertJsonPath('bank_holder', 'Max Mustermann');
    }

    public function test_billing_details_update_requires_super_admin(): void
    {
        // R-01: WRITE ist super_admin-gesichert. Ein regulärer Admin -> 403.
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/billing-details', [
                'bank_iban' => 'AT11 2222 3333 4444 5555',
            ])
            ->assertStatus(403);
    }

    public function test_billing_details_update_persists_for_super_admin(): void
    {
        $token = $this->superAdminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/billing-details', [
                'bank_iban' => 'AT11 2222 3333 4444 5555',
            ])
            ->assertStatus(200);

        $this->assertSame('AT11 2222 3333 4444 5555', Setting::where('key', 'bank_iban')->value('value'));
    }

    // ------------------------------------------------------------------
    // PUT /api/management/settings/license-terms — valide Updates
    // ------------------------------------------------------------------

    public function test_update_accepts_valid_calc_values_and_persists(): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validCalcPayload([
                'calc_base_price' => '60.5',
                'calc_hourly_rate' => '120',
                'calc_images_per_hour' => '8',
            ]))
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        // Persistenz in DB prüfen (Werte werden als String gespeichert)
        $this->assertSame('60.5', Setting::where('key', 'calc_base_price')->value('value'));
        $this->assertSame('120', Setting::where('key', 'calc_hourly_rate')->value('value'));
        $this->assertSame('8', Setting::where('key', 'calc_images_per_hour')->value('value'));
    }

    public function test_update_accepts_decimal_for_numeric_calc_fields(): void
    {
        // calc_base_price/calc_hourly_rate sind numeric → Dezimal OK
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validCalcPayload([
                'calc_base_price' => '49.99',
                'calc_hourly_rate' => '99.5',
            ]))
            ->assertStatus(200);
    }

    public function test_update_persists_outdoor_images_per_hour(): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validCalcPayload([
                'calc_outdoor_images_per_hour' => '10',
            ]))
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertSame('10', Setting::where('key', 'calc_outdoor_images_per_hour')->value('value'));
    }

    public function test_migration_converts_outdoor_multiplier_preserving_price(): void
    {
        $migration = require database_path('migrations/V028__calc_outdoor_images_per_hour.php');

        Setting::updateOrCreate(['key' => 'calc_outdoor_multiplier', 'brand' => 'rp'], ['value' => '0.5']);
        Setting::updateOrCreate(['key' => 'calc_images_per_hour', 'brand' => 'rp'], ['value' => '6']);

        $migration->up();

        $this->assertSame('12', Setting::where('key', 'calc_outdoor_images_per_hour')->where('brand', 'rp')->value('value'));
        $this->assertNull(Setting::where('key', 'calc_outdoor_multiplier')->where('brand', 'rp')->value('value'));

        // custom value: preiserhaltend
        Setting::updateOrCreate(['key' => 'calc_outdoor_multiplier', 'brand' => 'rp'], ['value' => '0.25']);

        $migration->up();

        $this->assertSame('24', Setting::where('key', 'calc_outdoor_images_per_hour')->where('brand', 'rp')->value('value'));
    }

    // ------------------------------------------------------------------
    // PUT — Validierungsfehler (422)
    // ------------------------------------------------------------------

    #[DataProvider('invalidCalcPayloadProvider')]
    public function test_update_rejects_invalid_calc_payload(array $payload, string $expectedErrorKey): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validCalcPayload($payload))
            ->assertStatus(422)
            ->assertJsonValidationErrors([$expectedErrorKey]);
    }

    public static function invalidCalcPayloadProvider(): array
    {
        return [
            'calc_base_price negativ' => [['calc_base_price' => '-1'], 'calc_base_price'],
            'calc_hourly_rate negativ' => [['calc_hourly_rate' => '-0.01'], 'calc_hourly_rate'],
            'calc_images_per_hour null (min:1)' => [['calc_images_per_hour' => 0], 'calc_images_per_hour'],
            'calc_base_price nicht-numerisch' => [['calc_base_price' => 'abc'], 'calc_base_price'],
            'calc_hourly_rate nicht-numerisch' => [['calc_hourly_rate' => 'free'], 'calc_hourly_rate'],
            'calc_images_per_hour dezimal (integer-rule)' => [['calc_images_per_hour' => '1.5'], 'calc_images_per_hour'],
            'calc_images_per_hour negativ' => [['calc_images_per_hour' => -3], 'calc_images_per_hour'],
            'calc_images_per_hour nicht-numerisch' => [['calc_images_per_hour' => 'many'], 'calc_images_per_hour'],
            'calc_outdoor_images_per_hour null (min:1)' => [['calc_outdoor_images_per_hour' => 0], 'calc_outdoor_images_per_hour'],
            'calc_outdoor_images_per_hour dezimal (integer-rule)' => [['calc_outdoor_images_per_hour' => '4.5'], 'calc_outdoor_images_per_hour'],
            'calc_outdoor_images_per_hour negativ' => [['calc_outdoor_images_per_hour' => -2], 'calc_outdoor_images_per_hour'],
            'calc_outdoor_images_per_hour nicht-numerisch' => [['calc_outdoor_images_per_hour' => 'many'], 'calc_outdoor_images_per_hour'],
        ];
    }

    public function test_update_rejects_when_required_multipliers_missing(): void
    {
        // mult_* sind required — ohne sie 422 (Verhalten eingefroren)
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', [
                'calc_base_price' => '50',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'mult_commercial',
                'mult_unlimited',
                'mult_international',
            ]);
    }

    // ------------------------------------------------------------------
    // PUT — Authorisierung (403 / 401)
    // ------------------------------------------------------------------

    public function test_update_rejects_non_admin_user_with_403(): void
    {
        // Plain User ohne Admin-Rolle → ManagementMiddleware 403
        $user = User::factory()->create(); // keine Rollen
        $token = auth('api')->login($user);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validCalcPayload())
            ->assertStatus(403);
    }

    public function test_update_rejects_unauthenticated_request_with_401(): void
    {
        // Kein Token → auth:api Middleware 401
        $this->putJson('/api/management/settings/license-terms', $this->validCalcPayload())
            ->assertStatus(401);
    }
}
