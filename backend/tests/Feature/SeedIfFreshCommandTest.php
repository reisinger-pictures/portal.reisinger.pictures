<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingResolver;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Contract of `php artisan app:seed-if-fresh`, the command the backend
 * entrypoint runs instead of an unconditional `db:seed --force`.
 *
 * Why the command exists (owner decision 2026-09-28): `DatabaseSeeder::now`
 * writes its 28 declared `settings` rows with `upsert` on (key, brand), so it
 * is authoritative for exactly those keys. `deployment/docker-compose.yml` ran
 * `db:seed --force` on *every* container start, and `AGENTS.md` §13 makes
 * `docker restart portal_backend` mandatory after any sync with PHP changes.
 * Every mandated restart therefore overwrote 28 production keys, including the
 * pricing terms, without a human ever running the seed — the warning in
 * `backend/AGENTS.md` (Database Setup Policy) only covers a manual `db:seed`.
 *
 * The seed itself stays: a first install has to be automated. Only the *when*
 * changes. A running production is never re-seeded by a restart.
 */
class SeedIfFreshCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The 28 keys `DatabaseSeeder::seedCatalogForBrand()` declares, as key
     * names only. `DatabaseSeederSettingsAuthorityTest` pins their *values* with
     * a literal map; repeating the map here for a third time would only add a
     * place to forget an update, so this test asserts presence and a handful of
     * value spot-checks instead — the keys whose value is the whole point
     * (`base_price` in cents, not the 35.00 euros V004 wrote).
     *
     * @var list<string>
     */
    private const DECLARED_KEYS = [
        'price_web', 'price_print', 'price_original',
        'mult_commercial', 'mult_unlimited', 'mult_international',
        'term_web', 'term_print', 'term_original',
        'term_territory_national', 'term_territory_international',
        'calc_base_price', 'calc_hourly_rate', 'calc_images_per_hour',
        'calc_outdoor_images_per_hour', 'calc_flatrate_multiplier',
        'base_price', 'setup_fee', 'privacy_fee', 'extra_image_fee',
        'bank_holder', 'bank_iban', 'bank_bic',
        'company_street', 'company_zip', 'company_city', 'company_country',
        'company_email',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Config::set('admin.email', 'seed-if-fresh-admin@example.test');
        Config::set('admin.password', 'seed-if-fresh-password-for-tests');
    }

    /**
     * A database that was migrated but never seeded: `migrate` created the
     * schema, nobody ran the seeder. `users` is empty, so the first install
     * still gets its admin account — otherwise login is dead on a fresh
     * install and the automation that is *supposed* to exist would be gone.
     */
    public function test_a_fresh_database_is_seeded(): void
    {
        $this->assertDatabaseCount('users', 0);

        $this->artisan('app:seed-if-fresh')->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('users', ['email' => config('admin.email')]);

        $missing = [];
        foreach (self::DECLARED_KEYS as $key) {
            if ($this->storedValue($key) === null) {
                $missing[] = $key;
            }
        }
        $this->assertSame([], $missing, 'The 28 declared settings keys have to land on a fresh database.');

        $this->assertSame('8000', $this->storedValue('base_price'));
        $this->assertSame('5000', $this->storedValue('calc_base_price'));
        $this->assertSame('7500', $this->storedValue('price_web'));
    }

    /**
     * The negative test, and the one that decides whether this change worked.
     *
     * A production database is seeded, the operator sets `price_web` through
     * the same resolver the UI uses, and a container start happens. A command
     * that unconditionally seeds passes `test_a_fresh_database_is_seeded` and
     * fails here.
     */
    public function test_a_seeded_database_is_left_alone(): void
    {
        $this->seed(DatabaseSeeder::class);

        $resolver = app(SettingResolver::class);
        $resolver->set('price_web', '9999');
        $resolver->set('base_price', '12345');
        $this->assertSame('9999', $resolver->get('price_web'));

        $this->artisan('app:seed-if-fresh')
            ->expectsOutputToContain('Database already seeded')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame('9999', app(SettingResolver::class)->get('price_web'));
        $this->assertSame('12345', app(SettingResolver::class)->get('base_price'));
        $this->assertSame('9999', $this->storedValue('price_web'));
    }

    /**
     * The entrypoint runs the command on *every* start, so a second run on an
     * already-seeded database has to be a no-op — not an error, and not a
     * rewrite.
     */
    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $this->artisan('app:seed-if-fresh')->assertExitCode(Command::SUCCESS);
        $afterFirstRun = $this->settingsSnapshot();
        $usersAfterFirstRun = User::query()->count();

        $this->artisan('app:seed-if-fresh')
            ->expectsOutputToContain('Database already seeded')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame($afterFirstRun, $this->settingsSnapshot());
        $this->assertSame($usersAfterFirstRun, User::query()->count());
    }

    /**
     * A seed that throws must not be reported as success: the entrypoint chains
     * the command with `|| exit 1`, so a swallowed failure would greenlight a
     * start of an instance that has no admin user, and nobody would know.
     */
    public function test_a_failing_seed_does_not_report_success(): void
    {
        Log::spy();

        $this->app->instance(DatabaseSeeder::class, new class extends DatabaseSeeder
        {
            public function run(): void
            {
                throw new RuntimeException('Seeder exploded (test double).');
            }
        });

        // The message pins the cause: without it the test would also pass if the
        // command bailed out for an unrelated reason.
        $this->artisan('app:seed-if-fresh')
            ->expectsOutputToContain('Seeding failed: Seeder exploded (test double).')
            ->assertExitCode(Command::FAILURE);

        // The container log is where an operator would ever look, so the failure
        // has to land there and not only on the console.
        Log::shouldHaveReceived('error')
            ->with('app:seed-if-fresh: seeding failed', Mockery::type('array'));

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * The `settings` rows for the active brand as a comparable snapshot.
     *
     * @return array<string, string|null>
     */
    private function settingsSnapshot(): array
    {
        $rows = DB::table('settings')
            ->where('brand', Brand::B2B->value)
            ->orderBy('key')
            ->get(['key', 'value']);

        $snapshot = [];
        foreach ($rows as $row) {
            $snapshot[$row->key] = $row->value;
        }

        return $snapshot;
    }

    private function storedValue(string $key): ?string
    {
        $value = Setting::query()
            ->where('key', $key)
            ->where('brand', Brand::B2B->value)
            ->value('value');

        return $value === null ? null : (string) $value;
    }
}
