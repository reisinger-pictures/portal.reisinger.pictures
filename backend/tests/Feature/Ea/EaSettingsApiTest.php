<?php

declare(strict_types=1);

namespace Tests\Feature\Ea;

use App\Enums\UserRole;

/**
 * EAR · GET/PUT /settings — Brand-scoped Einstellungen und der SV-Modus
 * (EUE-004 … EUE-009, EUE-108).
 */
class EaSettingsApiTest extends EaBookkeepingTestCase
{
    public function test_settings_require_authentication(): void
    {
        $this->getJson('/api/management/ea/settings')->assertUnauthorized();
    }

    public function test_jungunternehmer_mode_is_reachable_and_marks_the_kv_rate_as_hidden(): void
    {
        // EUE-108: die Konstante war toter Code, weil die Validierung den Modus
        // nicht akzeptierte. Jungunternehmer zahlen den Mindest-KV weiter.
        $token = $this->tokenFor(UserRole::SUPER_ADMIN->value);

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/management/ea/settings', [
                'default_business_share' => 100,
                'sv_mode' => 'jungunternehmer',
                'kleinunternehmer' => false,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/ea/settings')
            ->assertOk()
            ->assertJsonPath('sv_mode', 'jungunternehmer')
            ->assertJsonPath('kv_rate_hidden', true);
    }
}
