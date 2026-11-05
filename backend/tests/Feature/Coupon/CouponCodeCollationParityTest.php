<?php

namespace Tests\Feature\Coupon;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Http\Middleware\BrandContextMiddleware;
use App\Models\Coupon;
use App\Models\Role;
use App\Models\User;
use App\Services\CouponService;
use App\Support\BrandRegistry;
use App\Values\BrandConfig;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Pins the V055 collation-parity contract on PostgreSQL: coupon codes compare
 * case- and accent-insensitively, with trailing spaces ignored, per brand — in
 * customer lookups, in management duplicate checks, and in the database's
 * unique index.
 *
 * MariaDB's PAD SPACE `utf8mb4_unicode_ci` made this implicit. V054 only folded
 * case on PostgreSQL; V055 adds a named ICU nondeterministic collation plus
 * `RTRIM()` so `Café`, `CAFE` and `cafesale ` are the same code. SQLite has no
 * such collation and keeps V054's case-only `LOWER()` approximation, so these
 * tests skip there instead of claiming parity the engine cannot provide.
 */
class CouponCodeCollationParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('V055 collation parity requires PostgreSQL ICU collations.');
        }

        $this->withoutMiddleware(BrandContextMiddleware::class);
        BrandRegistry::set(new BrandConfig(
            id: 'test-brand',
            name: 'Test Brand',
            theme: 'rp',
            portalName: 'Test Portal',
            impressumUrl: null,
            logoPath: null,
            features: [],
            hostnames: [],
            isActive: true,
        ));
    }

    protected function tearDown(): void
    {
        BrandRegistry::set(null);
        parent::tearDown();
    }

    public function test_lookup_matches_accented_and_differently_cased_code(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'CaféSale',
            'active' => true,
        ]);

        [$found, $error] = (new CouponService)->findValidCoupon('cafesale', Brand::B2B);

        $this->assertNotNull($found);
        $this->assertNull($error);
        $this->assertSame($coupon->id, $found->id);
        $this->assertSame('CaféSale', $found->code, 'The lookup must not rewrite the stored spelling.');

        [$accented, $accentedError] = (new CouponService)->findValidCoupon('CAFÉSALE', Brand::B2B);

        $this->assertNotNull($accented);
        $this->assertNull($accentedError);
        $this->assertSame($coupon->id, $accented->id);
    }

    public function test_database_rejects_accent_and_case_variant_duplicate_in_the_same_brand(): void
    {
        Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'CaféSale',
            'active' => true,
        ]);

        try {
            // A savepoint keeps the expected PostgreSQL unique violation from
            // aborting the surrounding RefreshDatabase transaction, so the
            // count below can still prove that only one row exists.
            DB::transaction(fn () => Coupon::factory()->create([
                'brand' => 'rp',
                'code' => 'CAFESALE',
                'active' => true,
            ]));
            $this->fail('The database accepted a collation-equivalent duplicate coupon code.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(
                1,
                Coupon::query()->whereCodeMatches('cafesale')->count(),
            );
        }
    }

    public function test_database_allows_the_same_accented_code_in_another_brand(): void
    {
        $current = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'CaféSale',
            'active' => true,
        ]);
        $other = Coupon::factory()->create([
            'brand' => 'test-brand',
            'code' => 'cafesale',
            'active' => true,
        ]);

        $this->assertNotSame($current->id, $other->id);
        $this->assertSame(2, Coupon::query()->whereCodeMatches('CAFÉSALE')->count());
    }

    public function test_v055_refuses_collation_equivalent_duplicates_without_changing_either_row(): void
    {
        // Model the pre-V055 state: V054's case-only index exists and the
        // stronger index is absent. V054 accepts both spellings because the
        // accent and the trailing space survive `LOWER()`.
        DB::statement('DROP INDEX IF EXISTS coupons_brand_code_ci_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS coupons_brand_lower_code_unique ON coupons (brand, LOWER(code))');

        $first = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'CaféSale',
            'active' => true,
        ]);
        $second = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'CAFESALE ',
            'active' => true,
        ]);

        try {
            $this->migration()->up();
            $this->fail('V055 accepted a table with collation-equivalent duplicate coupon codes.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('CAFESALE', $exception->getMessage());
        }

        $this->assertDatabaseHas('coupons', ['id' => $first->id, 'code' => 'CaféSale']);
        $this->assertDatabaseHas('coupons', ['id' => $second->id, 'code' => 'CAFESALE ']);
        $this->assertSame(2, Coupon::query()->whereCodeMatches('cafesale')->count());
    }

    public function test_v055_refuses_a_same_named_icu_collation_with_the_wrong_strength(): void
    {
        $schema = 'coupon_v055_wrong_collation_'.str_replace('-', '', (string) Str::uuid());
        $connection = DB::connection();
        $quotedSchema = $connection->getQueryGrammar()->wrapTable($schema);
        $originalSearchPath = (string) $connection->selectOne('SHOW search_path')->search_path;
        $connection->statement('CREATE SCHEMA '.$quotedSchema);

        try {
            $connection->selectOne(
                "SELECT set_config('search_path', ?, false)",
                [$quotedSchema.', public'],
            );
            $connection->statement(
                "CREATE COLLATION coupons_code_ci (provider = icu, locale = 'und-u-ks-level2', deterministic = false)",
            );
            $connection->statement(
                'CREATE TABLE coupons (brand TEXT NOT NULL, code TEXT NOT NULL)',
            );

            try {
                $this->migration()->up();
                $this->fail('V055 accepted a same-named ICU collation with case-sensitive strength.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('incompatible definition', $exception->getMessage());
            }
        } finally {
            $connection->selectOne(
                "SELECT set_config('search_path', ?, false)",
                [$originalSearchPath],
            );
            $connection->statement('DROP SCHEMA IF EXISTS '.$quotedSchema.' CASCADE');
        }
    }

    public function test_lookup_and_database_apply_mariadb_pad_space_to_trailing_spaces(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'CaféSale',
            'active' => true,
        ]);

        [$found, $error] = (new CouponService)->findValidCoupon('cafesale ', Brand::B2B);

        $this->assertNotNull($found);
        $this->assertNull($error);
        $this->assertSame($coupon->id, $found->id);

        try {
            DB::transaction(fn () => Coupon::factory()->create([
                'brand' => 'rp',
                'code' => 'CAFESALE ',
                'active' => true,
            ]));
            $this->fail('The database accepted a trailing-space duplicate coupon code.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, Coupon::query()->whereCodeMatches('cafesale')->count());
        }
    }

    public function test_store_rejects_accent_variant_duplicate_code(): void
    {
        $token = $this->superAdminToken();
        $headers = ['Authorization' => "Bearer $token"];
        $payload = [
            'type' => 'percentage',
            'value' => 10,
            'scope_type' => 'global',
            'active' => true,
        ];

        $this->withHeaders($headers)
            ->postJson('/api/management/coupons', ['code' => 'CaféSale', ...$payload])
            ->assertStatus(201);

        $duplicate = $this->withHeaders($headers)
            ->postJson('/api/management/coupons', ['code' => 'cafesale', ...$payload]);

        $duplicate->assertStatus(422);
        $duplicate->assertJsonPath('error', 'A coupon with this code already exists for this brand.');
        $this->assertSame(1, Coupon::query()->whereCodeMatches('cafesale')->count());
    }

    public function test_update_rejects_accent_variant_duplicate_but_allows_re_casing_its_own_code(): void
    {
        $token = $this->superAdminToken();
        $headers = ['Authorization' => "Bearer $token"];
        $payload = [
            'type' => 'percentage',
            'value' => 10,
            'scope_type' => 'global',
            'active' => true,
        ];

        $first = $this->withHeaders($headers)
            ->postJson('/api/management/coupons', ['code' => 'CaféSale', ...$payload])
            ->assertStatus(201)
            ->json('coupon');
        $second = $this->withHeaders($headers)
            ->postJson('/api/management/coupons', ['code' => 'OtherCode', ...$payload])
            ->assertStatus(201)
            ->json('coupon');

        $duplicate = $this->withHeaders($headers)
            ->putJson("/api/management/coupons/{$second['id']}", ['code' => 'cafesale']);

        $duplicate->assertStatus(422);
        $duplicate->assertJsonPath('error', 'A coupon with this code already exists for this brand.');
        $this->assertDatabaseHas('coupons', ['id' => $second['id'], 'code' => 'OtherCode']);

        // Renaming a coupon to another spelling of its own code stays valid;
        // the duplicate check excludes the row being updated.
        $own = $this->withHeaders($headers)
            ->putJson("/api/management/coupons/{$first['id']}", ['code' => 'cafésale']);

        $own->assertStatus(200);
        $own->assertJsonPath('coupon.code', 'cafésale');
        $this->assertDatabaseHas('coupons', ['id' => $first['id'], 'code' => 'cafésale']);
    }

    private function superAdminToken(): string
    {
        $superAdmin = User::factory()->create(['brand' => null]);
        $superAdmin->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));

        return auth('api')->login($superAdmin);
    }

    private function migration(): object
    {
        return require database_path('migrations/V055__coupon_code_collation_parity.php');
    }
}
