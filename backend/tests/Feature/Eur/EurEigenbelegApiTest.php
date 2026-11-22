<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Models\EurEntry;
use App\Support\BrandRegistry;

/** Portal #31 — Eigenbeleg als Ausgabe mit Journal-Fallback 9230. */
class EurEigenbelegApiTest extends EurBookkeepingTestCase
{
    public function test_store_creates_a_brand_scoped_expense_with_fallback_code(): void
    {
        $token = $this->tokenFor();

        $response = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/eigenbelege', [
                'year' => 2026,
                'month' => 5,
                'day' => 17,
                'description' => 'Porto für Belegnachreichung',
                'amount_cents' => 350,
                'business_share' => 100,
            ]);

        $response->assertCreated()
            ->assertJsonPath('entry.year', 2026)
            ->assertJsonPath('entry.month', 5)
            ->assertJsonPath('entry.day', 17)
            ->assertJsonPath('entry.direction', 'expense')
            ->assertJsonPath('entry.description', 'Porto für Belegnachreichung')
            ->assertJsonPath('entry.e1a_code', '9230')
            ->assertJsonPath('entry.business_share', 100)
            ->assertJsonPath('entry.amount_cents', 350)
            ->assertJsonPath('entry.business_amount_cents', 350);

        $this->assertDatabaseHas('eur_entries', [
            'id' => $response->json('entry.id'),
            'brand' => BrandRegistry::currentId(),
            'direction' => 'expense',
            'e1a_code' => '9230',
            'business_amount_cents' => 350,
        ]);
        $this->assertDatabaseHas('eur_audit_logs', [
            'action' => 'created', 'subject_type' => 'eur_entry',
            'subject_id' => $response->json('entry.id'),
        ]);
        $this->assertSame(1, EurEntry::forCurrentBrand()->count());
    }

    public function test_store_applies_the_business_share_and_keeps_a_mapped_code(): void
    {
        $token = $this->tokenFor();

        $response = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/eigenbelege', [
                'year' => 2025,
                'month' => 11,
                'day' => 3,
                'description' => 'Internet 50 % betrieblich',
                'amount_cents' => 4990,
                'business_share' => 50,
                'e1a_code' => '9160',
            ]);

        $response->assertCreated()
            ->assertJsonPath('entry.e1a_code', '9160')
            ->assertJsonPath('entry.business_amount_cents', 2495);
    }

    public function test_store_rejects_unknown_year_zero_amount_and_invalid_share(): void
    {
        $token = $this->tokenFor();
        $payload = ['year' => 2026, 'month' => 5, 'day' => 5, 'description' => 'Porto', 'amount_cents' => 100];

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/eigenbelege', array_merge($payload, ['year' => 2027]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year');
        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/eigenbelege', array_merge($payload, ['amount_cents' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount_cents');
        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/eigenbelege', array_merge($payload, ['business_share' => 33]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('business_share');

        $this->assertDatabaseCount('eur_entries', 0);
    }

    public function test_eigenbeleg_requires_authentication(): void
    {
        $this->postJson('/api/management/eur/eigenbelege', [
            'year' => 2026, 'month' => 5, 'day' => 5,
            'description' => 'Porto', 'amount_cents' => 100,
        ])->assertUnauthorized();
    }
}
