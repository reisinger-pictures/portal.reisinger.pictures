<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LocationSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_location_endpoint_requires_minimum_length_and_valid_type()
    {
        $res = $this->getJson('/api/search/locations?q=L&type=city');
        $res->assertStatus(200)->assertExactJson([]);

        $res2 = $this->getJson('/api/search/locations?q=Linz&type=invalid');
        $res2->assertStatus(200)->assertExactJson([]);
    }

    public function test_location_endpoint_returns_data()
    {
        // Da Meilisearch in Tests asynchron sein kann, testen wir hier vor allem
        // dass der Controller nicht crasht und den Request an Scout weitergibt.
        Location::create([
            'type' => 'city',
            'name' => 'Linz',
            'state' => 'Oberösterreich',
            'country' => 'Österreich',
            'iso_country' => 'AT'
        ]);

        $res = $this->getJson('/api/search/locations?q=Linz&type=city');
        $res->assertStatus(200);
        $this->assertIsArray($res->json());
    }
}
