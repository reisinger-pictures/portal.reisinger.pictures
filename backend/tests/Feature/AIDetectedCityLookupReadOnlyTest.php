<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * D-17, second provable claim: the `detected_city` the model returns is only
 * ever used as a **read key**.
 *
 * `detected_city` is the one field a model produces that the portal then feeds
 * into a second system — the UI resolves it against the `locations` table
 * (`GET /api/search/locations`). That is the only path by which model output
 * could turn into an action, so it is the path worth pinning. A value that
 * arrives from the provider is untrusted like any other caller text: it may be
 * used to find a row, never to change one.
 *
 * Two claims, both measured rather than asserted from reading the code:
 *
 * 1. Generating metadata **persists nothing**, whatever the model returns. The
 *    request runs through the real endpoint (auth, gate, validation, provider
 *    call, response) and the query log must contain no write at all.
 * 2. The location lookup that resolves that value **selects** and nothing else,
 *    including when the value is a SQL-shaped string.
 *
 * Deliberately not claimed here: that the model obeys anything (D-17). This
 * file says nothing about model behaviour, only about what the portal does
 * with the answer once it has one.
 */
class AIDetectedCityLookupReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    private User $photographer;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ai' => [
                'enabled' => true,
                'type' => 'openai',
                'base_url' => 'https://api.openai.com/v1',
                'api_key' => 'test-key',
                'model' => 'gpt-4o',
            ],
            // Scout's database engine keeps the lookup a plain SELECT; the
            // default meilisearch driver would leave the test reaching for a
            // service that is not part of what is being measured.
            'scout.driver' => 'database',
        ]);

        $this->photographer = User::factory()->create();
        $this->photographer->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));
    }

    protected function tearDown(): void
    {
        auth('api')->logout();
        parent::tearDown();
    }

    /**
     * A value that would be a serious bug if it were ever interpolated: it
     * closes a string, opens a comment and appends a statement. It is returned
     * verbatim by the model, so it reaches the lookup exactly as a hostile
     * string would.
     */
    private const HOSTILE_CITY = 'Linz\'; DROP TABLE locations; --';

    /**
     * @param  list<string>  $sql
     * @return list<string>
     */
    private function writesAmong(array $sql): array
    {
        return array_values(array_filter(
            $sql,
            static fn (string $query): bool => preg_match(
                '/^\s*(insert|update|delete|replace|drop|alter|truncate|create)\b/i',
                $query,
            ) === 1,
        ));
    }

    public function test_generating_metadata_persists_nothing_whatever_the_model_returns(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'title' => 'Model Title',
                            'description' => 'Model description',
                            'keywords' => 'model, metadata',
                            'location' => 'Alps',
                            'detected_city' => self::HOSTILE_CITY,
                        ], JSON_THROW_ON_ERROR),
                    ],
                ]],
            ]),
        ]);

        $sql = [];
        DB::listen(function (QueryExecuted $query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $response = $this->actingAs($this->photographer, 'api')
            ->postJson('/api/ai/generate-metadata-text', [
                'text_input' => 'A mountain ridge above the treeline',
                'global_context' => 'Alpine stock photography',
            ]);

        $response->assertOk()->assertJson([
            'title' => 'Model Title',
            // Untrusted means untrusted in both directions: the value is neither
            // executed, escaped into a query, nor rewritten on the way out.
            'detected_city' => self::HOSTILE_CITY,
        ]);

        // The model gets no second call and no tool — it is a pure text
        // completion, which is what makes a human click the only writer.
        Http::assertSentCount(1);

        $this->assertNotEmpty($sql, 'The request must have touched the database at all, otherwise "no writes" is empty.');
        $this->assertSame([], $this->writesAmong($sql));
    }

    public function test_the_location_lookup_for_a_model_supplied_city_only_selects(): void
    {
        $city = Location::create([
            'type' => 'city',
            'name' => 'Linz',
            'postal_code' => '4020',
            'state' => 'Oberösterreich',
            'country' => 'Österreich',
            'iso_country' => 'AT',
            'population' => 200000,
        ]);

        $sql = [];
        DB::listen(function (QueryExecuted $query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $response = $this->getJson('/api/search/locations?q='.urlencode(self::HOSTILE_CITY).'&type=city');

        $response->assertOk();

        $this->assertNotEmpty($sql, 'The lookup must have run a query, otherwise "no writes" is empty.');
        $this->assertSame([], $this->writesAmong($sql));

        $reloaded = $city->fresh();
        $this->assertNotNull($reloaded, 'The lookup must not have removed the location row.');
        $this->assertSame('4020', $reloaded->postal_code);
        $this->assertSame(200000, $reloaded->population);
        $this->assertSame(1, Location::where('name', 'Linz')->count());
        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('locations'));
    }
}
