<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LocationSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void {
        parent::setUp();
        config(['scout.driver' => 'database']);
    }

    public function test_location_endpoint_requires_minimum_length_and_valid_type()
    {
        $res = $this->getJson('/api/search/locations?q=&type=city');
        $res->assertStatus(200)->assertExactJson([]);

        $res2 = $this->getJson('/api/search/locations?q=Linz&type=invalid');
        $res2->assertStatus(200)->assertExactJson([]);
    }

    public function test_location_endpoint_returns_data_by_city_and_postal_code()
    {
        Location::create([
            'type' => 'city',
            'name' => 'Linz',
            'postal_code' => '4020',
            'state' => 'Oberösterreich',
            'country' => 'Österreich',
            'iso_country' => 'AT'
        ]);

        $resCity = $this->getJson('/api/search/locations?q=Linz&type=city');
        $resCity->assertStatus(200);
        $this->assertCount(1, $resCity->json());

        $resZip = $this->getJson('/api/search/locations?q=4020&type=city');
        $resZip->assertStatus(200);
        $this->assertCount(1, $resZip->json());
    }

    public function test_city_search_deduplicates_by_name_and_sorts_by_population()
    {
        Location::create(['type' => 'city', 'name' => 'Wien', 'postal_code' => '1010', 'state' => 'Wien', 'country' => 'Österreich', 'iso_country' => 'AT', 'population' => 1000000]);
        Location::create(['type' => 'city', 'name' => 'Wien', 'postal_code' => '1020', 'state' => 'Wien', 'country' => 'Österreich', 'iso_country' => 'AT', 'population' => 900000]);

        $res = $this->getJson('/api/search/locations?q=Wien&type=city');
        $res->assertStatus(200);
        
        // Sollte nur 1 Ergebnis zurückgeben wegen unique('name') im Controller
        $this->assertCount(1, $res->json());
        // Das Ergebnis mit der höchsten Population (1010) muss das verbleibende sein
        $this->assertEquals('1010', $res->json()[0]['postal_code']);
    }
}
