<?php

namespace Tests\Feature;

use Database\Seeders\E2ELocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class E2ELocationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixture_is_deterministic_idempotent_and_offline(): void
    {
        Http::fake();

        $this->artisan('db:seed', [
            '--class' => E2ELocationSeeder::class,
            '--force' => true,
        ])->assertExitCode(0);

        $firstImport = DB::table('locations')->orderBy('id')->get()
            ->map(fn ($location): array => (array) $location)
            ->all();

        $this->artisan('db:seed', [
            '--class' => E2ELocationSeeder::class,
            '--force' => true,
        ])->assertExitCode(0);

        $secondImport = DB::table('locations')->orderBy('id')->get()
            ->map(fn ($location): array => (array) $location)
            ->all();

        $this->assertCount(4, $secondImport);
        $this->assertSame($firstImport, $secondImport);
        $this->assertDatabaseHas('locations', [
            'id' => '11111111-1111-4111-8111-111111111111',
            'name' => 'Graz',
            'state' => 'Steiermark',
            'postal_code' => '8010',
        ]);
        $this->assertDatabaseHas('locations', [
            'id' => '22222222-2222-4222-8222-222222222222',
            'name' => 'Linz',
            'state' => 'Oberösterreich',
            'postal_code' => '4020',
        ]);
        $this->assertDatabaseHas('locations', [
            'id' => '33333333-3333-4333-8333-333333333333',
            'name' => 'Salzburg',
            'state' => 'Salzburg',
            'postal_code' => '5020',
        ]);
        Http::assertNothingSent();
    }

    public function test_fixture_provides_the_location_search_contract_used_by_e2e_specs(): void
    {
        config(['scout.driver' => 'database']);
        $this->seed(E2ELocationSeeder::class);

        $this->getJson('/api/search/locations?q=Salzburg&type=city')
            ->assertOk()
            ->assertJsonPath('0.name', 'Salzburg')
            ->assertJsonPath('0.state', 'Salzburg')
            ->assertJsonPath('0.postal_code', '5020');

        $this->getJson('/api/search/locations?q=Graz&type=city')
            ->assertOk()
            ->assertJsonPath('0.name', 'Graz')
            ->assertJsonPath('0.state', 'Steiermark')
            ->assertJsonPath('0.postal_code', '8010');

        $this->getJson('/api/search/locations?q=Linz&type=city')
            ->assertOk()
            ->assertJsonPath('0.name', 'Linz')
            ->assertJsonPath('0.state', 'Oberösterreich')
            ->assertJsonPath('0.postal_code', '4020');
    }
}
