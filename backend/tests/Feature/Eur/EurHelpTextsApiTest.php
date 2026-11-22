<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

class EurHelpTextsApiTest extends EurBookkeepingTestCase
{
    public function test_help_texts_require_authentication(): void
    {
        $this->getJson('/api/management/eur/help-texts')->assertUnauthorized();
    }

    public function test_help_texts_list_has_no_cell_reference(): void
    {
        $token = $this->tokenFor();

        $response = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/help-texts');
        $response->assertOk();

        $first = $response->json('help.0');
        $this->assertArrayHasKey('kz', $first);
        $this->assertArrayHasKey('bezeichnung', $first);
        $this->assertArrayHasKey('hilfetext', $first);
        $this->assertArrayNotHasKey('zelle', $first);
        $this->assertArrayNotHasKey('cell', $first);
    }

    public function test_d39_d40_and_the_sv_double_counting_note(): void
    {
        $token = $this->tokenFor();

        $ergonomics = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/help-texts?kz=9216');
        $ergonomics->assertOk();
        $ergonomics->assertJsonPath('help.bezeichnung', 'Ergonomisches Mobiliar');
        $ergonomics->assertJsonPath('help.portal_note', null);

        $workplace = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/help-texts?kz=9215');
        $workplace->assertOk();
        $workplace->assertJsonPath('help.bezeichnung', 'Arbeitsplatzpauschale');

        $insurance = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/help-texts?kz=9225');
        $insurance->assertOk();
        $this->assertStringContainsString('EUE-023, EUE-115', (string) $insurance->json('help.portal_note'));
    }

    public function test_invalid_kz_is_rejected(): void
    {
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/help-texts?kz=abc')
            ->assertUnprocessable();
    }
}
