<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

class EurHourApiTest extends EurBookkeepingTestCase
{
    public function test_hours_require_authentication(): void
    {
        $this->getJson('/api/management/eur/hours')->assertUnauthorized();
        $this->postJson('/api/management/eur/hours', [])->assertUnauthorized();
    }

    public function test_hours_accept_positive_minutes_and_total_minutes(): void
    {
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/hours', [
            'year' => 2026, 'month' => 5, 'day' => 5, 'minutes' => 90, 'project' => 'Shooting',
        ])->assertCreated()->assertJsonPath('hour.minutes', 90);

        $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/hours', [
            'year' => 2026, 'month' => 5, 'day' => 6, 'minutes' => 30,
        ])->assertCreated();

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/hours?year=2026&month=5');

        $response->assertOk();
        $response->assertJsonCount(2, 'hours');
        $response->assertJsonPath('total_minutes', 120);
    }

    public function test_hours_reject_zero_minutes_and_foreign_year(): void
    {
        $token = $this->tokenFor();
        $base = ['year' => 2026, 'month' => 5, 'day' => 5];

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/hours', array_merge($base, ['minutes' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('minutes');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/hours', array_merge($base, ['minutes' => 30, 'year' => 2027]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/hours', array_merge($base, ['minutes' => 30, 'day' => 32]))
            ->assertUnprocessable();

        $this->assertDatabaseCount('eur_hours', 0);
    }
}
