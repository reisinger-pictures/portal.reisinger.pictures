<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

class EurWarehouseApiTest extends EurBookkeepingTestCase
{
    public function test_warehouse_requires_authentication(): void
    {
        $this->getJson('/api/management/eur/warehouse')->assertUnauthorized();
        $this->postJson('/api/management/eur/warehouse', [])->assertUnauthorized();
    }

    public function test_warehouse_sums_integer_cents(): void
    {
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/warehouse', [
            'year' => 2026, 'month' => 5, 'day' => 5,
            'description' => 'Druckerpapier', 'amount_cents' => 1250,
        ])->assertCreated()->assertJsonPath('entry.amount_cents', 1250);

        $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/warehouse', [
            'year' => 2026, 'month' => 5, 'description' => 'Toner', 'amount_cents' => 3750,
        ])->assertCreated();

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/warehouse?year=2026&month=5');

        $response->assertOk();
        $response->assertJsonCount(2, 'entries');
        $response->assertJsonPath('total_cents', 5000);
    }

    public function test_warehouse_rejects_fractional_cents_and_foreign_year(): void
    {
        $token = $this->tokenFor();
        $base = ['year' => 2026, 'month' => 5, 'description' => 'Papier'];

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/warehouse', array_merge($base, ['amount_cents' => 12.5]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount_cents');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/warehouse', array_merge($base, ['amount_cents' => 100, 'year' => 2027]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year');

        $this->assertDatabaseCount('eur_warehouse_entries', 0);
    }
}
