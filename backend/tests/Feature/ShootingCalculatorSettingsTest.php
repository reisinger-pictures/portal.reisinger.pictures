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
        // Frische DB, keine Settings vorhanden → hardcoded Defaults
        $response = $this->getJson('/api/settings/license-terms');

        $response->assertStatus(200)
            ->assertJsonPath('base_price', '35.00')
            ->assertJsonPath('calc_base_price', '50')
            ->assertJsonPath('calc_hourly_rate', '100')
            ->assertJsonPath('calc_images_per_hour', '6');
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

        // Folge-GET liefert die gespeicherten Werte
        $this->getJson('/api/settings/license-terms')
            ->assertStatus(200)
            ->assertJsonPath('calc_base_price', '75')
            ->assertJsonPath('calc_hourly_rate', '150')
            ->assertJsonPath('calc_images_per_hour', '10');
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
