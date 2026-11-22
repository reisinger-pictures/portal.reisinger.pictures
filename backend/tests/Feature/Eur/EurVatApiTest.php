<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

/**
 * EAR · POST /vat/calc — acht Kennzahlen, Zahllast, Quartale und die beiden
 * Erklärungsblöcke (EUE-082 … EUE-088).
 */
class EurVatApiTest extends EurBookkeepingTestCase
{
    public function test_calc_requires_authentication(): void
    {
        $this->postJson('/api/management/eur/vat/calc', ['year' => 2026, 'month' => 5])->assertUnauthorized();
    }

    public function test_calc_returns_two_explanation_blocks_alongside_the_kz(): void
    {
        $token = $this->tokenFor();

        $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/vat/calc', [
            'year' => 2026,
            'month' => 5,
            'small_business_income_cents' => 10000,
            'ig_bases_cents' => ['20' => 5000],
        ]);

        $response->assertOk();

        foreach (['000', '016', '057', '070', '072', '073', '008', '125'] as $code) {
            $this->assertArrayHasKey($code, $response->json('kz'));
        }

        $response->assertJsonPath('kz.016', 10000)
            ->assertJsonPath('zahllast_cents', 1000)
            ->assertJsonPath('quarters.2.016', 10000)
            ->assertJsonPath('year_totals.016', 10000)
            ->assertJsonPath('erklaerung.voranmeldung.title', '1. Umsatzsteuervoranmeldung')
            ->assertJsonPath('erklaerung.erklaerung.title', '2. Umsatzsteuererklärung');

        $voranmeldung = $response->json('erklaerung.voranmeldung');
        $erklaerung = $response->json('erklaerung.erklaerung');

        $this->assertStringContainsString('EUE-088', (string) $voranmeldung['text']);
        $this->assertSame($voranmeldung['text'], $erklaerung['text']);
        $this->assertSame($response->json('kz'), $voranmeldung['kz']);
        $this->assertSame($response->json('zahllast_cents'), $erklaerung['zahllast_cents']);
    }

    public function test_unknown_year_is_rejected(): void
    {
        $this->withHeaders($this->bearer($this->tokenFor()))
            ->postJson('/api/management/eur/vat/calc', ['year' => 2027, 'month' => 5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year');
    }
}
