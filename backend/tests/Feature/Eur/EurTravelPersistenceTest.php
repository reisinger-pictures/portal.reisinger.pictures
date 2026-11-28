<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Models\EurTravelTrip;
use App\Support\BrandRegistry;

/**
 * D-83a: Reisezeilen werden brand-scoped persistiert; die Beträge stammen aus
 * derselben serverseitigen Berechnung wie `travel/calc`.
 */
class EurTravelPersistenceTest extends EurBookkeepingTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'year' => 2026,
            'month' => 5,
            'receipt_no' => 'R-REISE-E2E',
            'vehicle' => 'Privatauto',
            'kilometres' => 120,
            'passengers' => 1,
            'country' => 'Österreich',
            'days' => 2,
            'meals' => 1,
            'with_receipts' => true,
            'business_share' => 80,
            'note' => 'Kundentermin Wien',
        ];
    }

    public function test_travel_persistence_requires_authentication(): void
    {
        $this->getJson('/api/management/eur/travel')->assertUnauthorized();
        $this->postJson('/api/management/eur/travel', [])->assertUnauthorized();
        $this->deleteJson('/api/management/eur/travel/1')->assertUnauthorized();
    }

    public function test_store_persists_the_server_calculated_amounts(): void
    {
        $token = $this->tokenFor();
        $payload = $this->payload();

        $calc = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/travel/calc', $payload)
            ->assertOk()
            ->json();

        $created = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/travel', $payload)
            ->assertCreated()
            ->json('trip');

        $this->assertSame($calc['kilometre_cents'], $created['kilometre_cents']);
        $this->assertSame($calc['day_cents'], $created['day_cents']);
        $this->assertSame($calc['night_cents'], $created['night_cents']);
        $this->assertSame($calc['total_cents'], $created['total_cents']);
        $this->assertSame($calc['business_cents'], $created['business_cents']);
        $this->assertSame(80, $created['business_share']);
        $this->assertSame('Kundentermin Wien', $created['note']);
        // EUE-077/081: Belegnummer und Kennzahl werden mitgespeichert.
        $this->assertSame('R-REISE-E2E', $created['receipt_no']);
        $this->assertSame('9160', $created['e1a_code']);
        $this->assertTrue($created['deductible']);

        $this->assertDatabaseHas('eur_travel_trips', [
            'id' => $created['id'],
            'brand' => BrandRegistry::currentId(),
            'total_cents' => $calc['total_cents'],
        ]);
        $this->assertDatabaseHas('eur_audit_logs', [
            'subject_type' => 'eur_travel_trip',
            'subject_id' => $created['id'],
            'action' => 'created',
        ]);
    }

    public function test_index_is_brand_scoped_and_sums_integer_cents(): void
    {
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/travel', $this->payload())
            ->assertCreated();
        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/travel', array_merge($this->payload(), [
                'receipt_no' => 'R-REISE-E2E-2',
                'kilometres' => 30,
            ]))
            ->assertCreated();

        $foreign = EurTravelTrip::create(array_merge($this->payload(), [
            'brand' => self::OTHER_BRAND,
            'kilometre_cents' => 1,
            'day_cents' => 2,
            'night_cents' => 3,
            'total_cents' => 6,
            'business_cents' => 5,
        ]));

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/travel?year=2026&month=5')
            ->assertOk();

        $response->assertJsonCount(2, 'trips');
        $this->assertSame(
            (int) $response->json('total_cents'),
            (int) $response->json('trips.0.total_cents') + (int) $response->json('trips.1.total_cents'),
        );
        $this->assertSame(
            (int) $response->json('business_cents'),
            (int) $response->json('trips.0.business_cents') + (int) $response->json('trips.1.business_cents'),
        );

        $this->withHeaders($this->bearer($token))
            ->deleteJson("/api/management/eur/travel/{$foreign->id}")
            ->assertNotFound();
    }

    public function test_store_rejects_invalid_travel_input_without_persisting(): void
    {
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/travel', array_merge($this->payload(), ['business_share' => 33]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('business_share');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/travel', array_merge($this->payload(), ['country' => 'Nirgendwo']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('country');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/travel', array_merge($this->payload(), ['month' => 13]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('month');

        $this->assertDatabaseCount('eur_travel_trips', 0);
    }

    public function test_store_refuses_rule_only_years(): void
    {
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/travel', array_merge($this->payload(), ['year' => 2024]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year');

        $this->assertDatabaseCount('eur_travel_trips', 0);
    }

    public function test_store_accepts_a_year_without_a_pinned_country_table(): void
    {
        $token = $this->tokenFor();

        // 2027 ist buchbar; die Ländertabelle von 2026 gilt als Fallback.
        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/travel', array_merge($this->payload(), ['year' => 2027]))
            ->assertCreated();

        $this->assertDatabaseCount('eur_travel_trips', 1);
    }

    public function test_store_requires_a_receipt_number_and_rejects_duplicates(): void
    {
        $token = $this->tokenFor();

        $without = $this->payload();
        unset($without['receipt_no']);
        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/travel', $without)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('receipt_no');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/travel', $this->payload())
            ->assertCreated();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/travel', $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Diese Belegnummer ist bereits für eine andere Reise vergeben.');

        $this->assertDatabaseCount('eur_travel_trips', 1);
    }

    public function test_store_derives_distance_from_km_readings_and_rejects_mixing(): void
    {
        $token = $this->tokenFor();
        $payload = $this->payload();
        unset($payload['kilometres']);
        $payload['start_km'] = 1000;
        $payload['end_km'] = 1120;

        $created = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/travel', $payload)
            ->assertCreated()
            ->json('trip');

        $this->assertSame(120, $created['kilometres']);
        $this->assertSame(1000, $created['start_km']);
        $this->assertSame(1120, $created['end_km']);

        // EUE-067: nie Distanz und Kilometerstand zusammen.
        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/travel', array_merge($this->payload(), [
                'start_km' => 1000,
                'end_km' => 1120,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['kilometres', 'start_km', 'end_km']);

        // Und der Endstand muss größer sein als der Startstand.
        $reversed = $payload;
        $reversed['start_km'] = 1120;
        $reversed['end_km'] = 1000;
        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/travel', $reversed)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_km');

        $this->assertDatabaseCount('eur_travel_trips', 1);
    }

    public function test_private_purpose_zeroes_the_deductible_amount(): void
    {
        $token = $this->tokenFor();
        $payload = array_merge($this->payload(), [
            'receipt_no' => 'R-REISE-PRIVAT',
            'purpose' => 'Privat',
        ]);

        $created = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/travel', $payload)
            ->assertCreated()
            ->json('trip');

        $this->assertSame('Privat', $created['purpose']);
        $this->assertSame(0, $created['business_cents']);
        $this->assertFalse($created['deductible']);
        $this->assertGreaterThan(0, $created['total_cents']);
        $this->assertSame('9160', $created['e1a_code']);

        $index = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/travel?year=2026')
            ->assertOk()
            ->json('trips.0');
        $this->assertFalse($index['deductible']);
        $this->assertSame(0, $index['business_cents']);
    }
}
