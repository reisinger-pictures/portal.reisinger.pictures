<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

/**
 * D-83b: WEB-Pflichtfelder, gültige Ausgaben-Art und der aus dem Katalog
 * abgeleitete Betriebsanteil. Der Client kann den Anteil nicht setzen.
 */
class EurWarehouseApiTest extends EurBookkeepingTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'year' => 2026,
            'month' => 5,
            'day' => 5,
            'description' => 'Druckerpapier',
            'supplier' => 'Bürobedarf GmbH',
            'amount_cents' => 1250,
            'receipt_no' => 'WE-2026-0001',
            'expense_kind' => 'Wareneinkäufe',
        ];
    }

    public function test_warehouse_requires_authentication(): void
    {
        $this->getJson('/api/management/eur/warehouse')->assertUnauthorized();
        $this->postJson('/api/management/eur/warehouse', [])->assertUnauthorized();
    }

    public function test_warehouse_sums_integer_cents_and_derives_the_business_share(): void
    {
        $token = $this->tokenFor();

        $created = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/warehouse', $this->payload())
            ->assertCreated()
            ->json('entry');

        // "Wareneinkäufe" hat keinen hinterlegten Anteil -> voller Betrag.
        $this->assertSame(100, $created['business_share']);
        $this->assertSame('Bürobedarf GmbH', $created['supplier']);
        $this->assertSame('Wareneinkäufe', $created['expense_kind']);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/warehouse', array_merge($this->payload(), [
                'amount_cents' => 3750,
                'receipt_no' => 'WE-2026-0002',
                'expense_kind' => 'Klimaticket',
            ]))
            ->assertCreated()
            ->assertJsonPath('entry.business_share', 50);

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/warehouse?year=2026&month=5');

        $response->assertOk();
        $response->assertJsonCount(2, 'entries');
        $response->assertJsonPath('total_cents', 5000);
    }

    public function test_web_required_fields_are_enforced(): void
    {
        $token = $this->tokenFor();

        foreach (['day', 'supplier', 'description', 'receipt_no'] as $field) {
            $this->withHeaders($this->bearer($token))
                ->postJson('/api/management/eur/warehouse', array_diff_key($this->payload(), [$field => true]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseCount('eur_warehouse_entries', 0);
    }

    public function test_we_receipt_requires_a_defined_expense_kind(): void
    {
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/warehouse', array_diff_key($this->payload(), ['expense_kind' => true]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expense_kind');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/warehouse', array_merge($this->payload(), ['expense_kind' => 'Erfindungen']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expense_kind');

        $this->assertDatabaseCount('eur_warehouse_entries', 0);
    }

    public function test_non_we_receipt_needs_no_expense_kind_and_uses_full_share(): void
    {
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/warehouse', array_merge($this->payload(), [
                'receipt_no' => 'R-2026-0077',
                'expense_kind' => null,
            ]))
            ->assertCreated()
            ->assertJsonPath('entry.business_share', 100)
            ->assertJsonPath('entry.expense_kind', null);
    }

    public function test_client_cannot_set_the_business_share(): void
    {
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/warehouse', array_merge($this->payload(), ['business_share' => 5]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('business_share');
    }

    public function test_warehouse_rejects_fractional_cents_and_foreign_year(): void
    {
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/warehouse', array_merge($this->payload(), ['amount_cents' => 12.5]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount_cents');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/warehouse', array_merge($this->payload(), ['year' => 2025]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year');

        $this->assertDatabaseCount('eur_warehouse_entries', 0);
    }
}
