<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\DownloadLog;
use App\Models\Gallery;
use App\Models\PayoutPool;
use App\Models\Photo;
use App\Models\PhotographerStatement;
use App\Models\Role;
use App\Models\User;
use App\Services\PayoutCalculationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;
use Throwable;

/**
 * Regression coverage for the payout natural-key and ZIP-count contracts.
 */
class PayoutNaturalKeyTest extends TestCase
{
    use RefreshDatabase;

    private const POOL_INDEX = 'payout_pools_year_month_unique';

    private const STATEMENT_INDEX = 'photographer_statements_user_id_year_month_unique';

    private const STATEMENT_SUPPORT_INDEX = 'photographer_statements_user_id_fk_support';

    private ?string $temporaryStatementSupportIndex = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
    }

    public function test_v040_installs_unique_indexes_for_pool_and_statement_natural_keys(): void
    {
        $poolIndex = collect(Schema::getIndexes('payout_pools'))
            ->firstWhere('name', self::POOL_INDEX);
        $statementIndex = collect(Schema::getIndexes('photographer_statements'))
            ->firstWhere('name', self::STATEMENT_INDEX);

        $this->assertNotNull($poolIndex);
        $this->assertNotNull($statementIndex);
        $this->assertTrue((bool) $poolIndex['unique']);
        $this->assertTrue((bool) $statementIndex['unique']);
        $this->assertSame(['year', 'month'], $this->indexColumns($poolIndex));
        $this->assertSame(
            ['user_id', 'year', 'month'],
            $this->indexColumns($statementIndex),
        );
    }

    public function test_database_rejects_duplicate_pool_natural_key(): void
    {
        PayoutPool::factory()->create([
            'year' => 2026,
            'month' => 8,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        PayoutPool::factory()->create([
            'year' => 2026,
            'month' => 8,
        ]);
    }

    public function test_database_rejects_duplicate_statement_natural_key(): void
    {
        $photographer = User::factory()->create();
        PhotographerStatement::create([
            'user_id' => $photographer->id,
            'year' => 2026,
            'month' => 8,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        PhotographerStatement::create([
            'user_id' => $photographer->id,
            'year' => 2026,
            'month' => 8,
        ]);
    }

    public function test_v040_duplicate_preflight_reports_without_deleting_financial_rows(): void
    {
        $this->dropPayoutNaturalIndexes();

        $firstPool = null;
        $duplicatePool = null;
        $firstStatement = null;
        $duplicateStatement = null;
        $photographer = null;

        try {
            $firstPool = PayoutPool::factory()->create([
                'year' => 2026,
                'month' => 6,
                'net_pool_cents' => 1000,
            ]);
            $duplicatePool = PayoutPool::factory()->create([
                'year' => 2026,
                'month' => 6,
                'net_pool_cents' => 2000,
            ]);

            $photographer = User::factory()->create();
            $firstStatement = PhotographerStatement::create([
                'user_id' => $photographer->id,
                'year' => 2026,
                'month' => 6,
                'pool_earnings_cents' => 100,
            ]);
            $duplicateStatement = PhotographerStatement::create([
                'user_id' => $photographer->id,
                'year' => 2026,
                'month' => 6,
                'pool_earnings_cents' => 200,
            ]);

            $exception = null;
            try {
                $this->migration()->up();
            } catch (Throwable $caught) {
                $exception = $caught;
            }

            $this->assertInstanceOf(\RuntimeException::class, $exception);
            $this->assertStringContainsString('payout_pools duplicate_groups=1', $exception->getMessage());
            $this->assertStringContainsString(
                'photographer_statements duplicate_groups=1',
                $exception->getMessage(),
            );
            $this->assertStringContainsString($firstPool->id, $exception->getMessage());
            $this->assertStringContainsString($duplicatePool->id, $exception->getMessage());
            $this->assertStringContainsString($firstStatement->id, $exception->getMessage());
            $this->assertStringContainsString($duplicateStatement->id, $exception->getMessage());
            $this->assertStringContainsString(
                'No payout pools or statements were deleted or merged',
                $exception->getMessage(),
            );

            $this->assertDatabaseHas('payout_pools', [
                'id' => $firstPool->id,
                'net_pool_cents' => 1000,
            ]);
            $this->assertDatabaseHas('payout_pools', [
                'id' => $duplicatePool->id,
                'net_pool_cents' => 2000,
            ]);
            $this->assertDatabaseHas('photographer_statements', [
                'id' => $firstStatement->id,
                'pool_earnings_cents' => 100,
            ]);
            $this->assertDatabaseHas('photographer_statements', [
                'id' => $duplicateStatement->id,
                'pool_earnings_cents' => 200,
            ]);
        } finally {
            foreach ([$firstStatement, $duplicateStatement] as $statement) {
                if ($statement !== null) {
                    PhotographerStatement::query()->whereKey($statement->id)->delete();
                }
            }
            foreach ([$firstPool, $duplicatePool] as $pool) {
                if ($pool !== null) {
                    PayoutPool::query()->whereKey($pool->id)->delete();
                }
            }
            if ($photographer !== null) {
                User::query()->whereKey($photographer->id)->delete();
            }

            $this->migration()->up();
            $this->removeTemporaryStatementSupportIndex();
        }

        $this->assertTrue(Schema::hasIndex('payout_pools', self::POOL_INDEX, 'unique'));
        $this->assertTrue(Schema::hasIndex('photographer_statements', self::STATEMENT_INDEX, 'unique'));
    }

    public function test_v040_replaces_a_stale_statement_index_without_dropping_fk_support(): void
    {
        $this->dropPayoutNaturalIndexes();
        $photographer = null;
        $statement = null;

        try {
            Schema::table('photographer_statements', function ($blueprint): void {
                $blueprint->index(['year'], self::STATEMENT_INDEX);
            });

            $this->migration()->up();

            $this->assertTrue(Schema::hasIndex(
                'photographer_statements',
                self::STATEMENT_INDEX,
                'unique',
            ));
            $photographer = User::factory()->create();
            $statement = PhotographerStatement::create([
                'user_id' => $photographer->id,
                'year' => 2026,
                'month' => 10,
            ]);
            $this->assertDatabaseHas('photographer_statements', [
                'user_id' => $photographer->id,
                'year' => 2026,
                'month' => 10,
            ]);
        } finally {
            if ($statement !== null) {
                PhotographerStatement::query()->whereKey($statement->id)->delete();
            }
            if ($photographer !== null) {
                User::query()->whereKey($photographer->id)->delete();
            }

            $this->migration()->up();
            $this->removeTemporaryStatementSupportIndex();
        }
    }

    public function test_create_or_first_keeps_one_winner_when_a_first_insert_race_is_replayed(): void
    {
        $poolIdentity = ['year' => 2026, 'month' => 7];
        $firstPool = PayoutPool::query()->createOrFirst($poolIdentity, [
            'net_pool_cents' => 1000,
            'photographer_share_percent' => 50,
        ]);
        $replayedPool = PayoutPool::query()->createOrFirst($poolIdentity, [
            'net_pool_cents' => 9999,
            'photographer_share_percent' => 50,
        ]);

        $this->assertSame($firstPool->id, $replayedPool->id);
        $this->assertSame(1000, (int) $replayedPool->fresh()->net_pool_cents);
        $this->assertSame(1, PayoutPool::query()->where($poolIdentity)->count());

        $photographer = User::factory()->create();
        $statementIdentity = [
            'user_id' => $photographer->id,
            'year' => 2026,
            'month' => 7,
        ];
        $firstStatement = PhotographerStatement::query()->createOrFirst($statementIdentity, [
            'total_shares_earned' => '1.0000',
            'pool_earnings_cents' => 100,
        ]);
        $replayedStatement = PhotographerStatement::query()->createOrFirst($statementIdentity, [
            'total_shares_earned' => '1.0000',
            'pool_earnings_cents' => 100,
        ]);

        $this->assertSame($firstStatement->id, $replayedStatement->id);
        $this->assertSame(1, PhotographerStatement::query()->where($statementIdentity)->count());
    }

    public function test_direct_pool_calculation_replay_is_idempotent(): void
    {
        $pool = PayoutPool::factory()
            ->forMonth(11, 2026)
            ->withNetPool(1000)
            ->create(['photographer_share_percent' => 100]);
        $gallery = Gallery::factory()->create();
        $photographer = User::factory()->create();
        Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'user_id' => $photographer->id,
        ]);
        $log = DownloadLog::create([
            'user_id' => User::factory()->create()->id,
            'gallery_id' => $gallery->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
            'photo_count' => 1,
        ]);
        DownloadLog::query()->whereKey($log->getKey())->update([
            'created_at' => '2026-11-15 12:00:00',
        ]);

        $service = app(PayoutCalculationService::class);
        $service->calculatePoolShares($pool->fresh());
        $service->calculatePoolShares($pool->fresh());

        $statement = PhotographerStatement::where('user_id', $photographer->id)->sole();
        $this->assertSame('1.0000', $statement->total_shares_earned);
        $this->assertSame(1000, $statement->pool_earnings_cents);
        $this->assertSame(1, PhotographerStatement::query()
            ->where('year', 2026)
            ->where('month', 11)
            ->count());
    }

    public function test_replayed_calculation_keeps_one_pool_and_one_statement(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));
        $token = auth('api')->login($admin);

        $photographer = User::factory()->create();
        $gallery = Gallery::factory()->create();
        Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'user_id' => $photographer->id,
        ]);
        DownloadLog::create([
            'user_id' => User::factory()->create()->id,
            'gallery_id' => $gallery->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
            'photo_count' => 1,
            'created_at' => '2026-09-15 12:00:00',
        ]);

        foreach ([1, 2] as $_) {
            $this->withHeaders(['Authorization' => 'Bearer '.$token])
                ->postJson('/api/management/payouts/calculate', [
                    'month' => 9,
                    'year' => 2026,
                    'net_pool_cents' => 1000,
                ])
                ->assertOk();
        }

        $this->assertSame(1, PayoutPool::query()
            ->where('year', 2026)
            ->where('month', 9)
            ->count());
        $statement = PhotographerStatement::query()
            ->where('user_id', $photographer->id)
            ->where('year', 2026)
            ->where('month', 9)
            ->sole();
        $this->assertSame('1.0000', $statement->total_shares_earned);
        $this->assertSame(500, $statement->pool_earnings_cents);
    }

    public function test_new_full_zip_logs_require_an_explicit_positive_photo_count(): void
    {
        $attributes = [
            'item_type' => 'full_zip',
            'resolution_tier' => 'original',
        ];

        try {
            DownloadLog::create($attributes);
            $this->fail('An omitted full_zip photo_count must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('explicit positive photo_count', $exception->getMessage());
        }

        foreach ([0, -1] as $invalidCount) {
            try {
                DownloadLog::create([
                    ...$attributes,
                    'photo_count' => $invalidCount,
                ]);
                $this->fail('A non-positive full_zip photo_count must be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('explicit positive photo_count', $exception->getMessage());
            }
        }

        $log = DownloadLog::create([
            ...$attributes,
            'photo_count' => 1,
        ]);
        $this->assertSame(1, $log->photo_count);
        $this->assertDatabaseCount('download_logs', 1);
    }

    public function test_legacy_full_zip_row_with_the_historical_default_remains_payout_compatible(): void
    {
        $gallery = Gallery::factory()->create();
        $photographer = User::factory()->create();
        Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'user_id' => $photographer->id,
        ]);
        $downloader = User::factory()->create();

        // Simulate a pre-validation writer: bypass Eloquent and omit the
        // column so the deployed V009 default of 1 is used by the legacy row.
        DB::table('download_logs')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $downloader->id,
            'gallery_id' => $gallery->id,
            'item_type' => 'full_zip',
            'resolution_tier' => 'web',
            'created_at' => '2026-06-15 12:00:00',
        ]);

        $pool = PayoutPool::factory()->forMonth(6, 2026)->withNetPool(1000)->create([
            'photographer_share_percent' => 100,
        ]);
        app(PayoutCalculationService::class)->calculatePoolShares($pool->fresh());

        $this->assertSame('1.0000', $pool->fresh()->total_shares);
        $this->assertSame(1, $pool->fresh()->total_unique_downloads);
        $this->assertSame(
            '1.0000',
            PhotographerStatement::where('user_id', $photographer->id)->sole()->total_shares_earned,
        );
    }

    public function test_non_positive_legacy_full_zip_row_is_ignored_by_payout_calculation(): void
    {
        $pool = PayoutPool::factory()->forMonth(6, 2026)->withNetPool(1000)->create();
        $user = User::factory()->create();

        DB::table('download_logs')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'item_type' => 'full_zip',
            'resolution_tier' => 'original',
            'photo_count' => 0,
            'created_at' => '2026-06-15 12:00:00',
        ]);

        app(PayoutCalculationService::class)->calculatePoolShares($pool->fresh());

        $this->assertSame('0.0000', $pool->fresh()->total_shares);
        $this->assertSame(0, $pool->fresh()->total_unique_downloads);
        $this->assertSame(0, PhotographerStatement::query()->where('month', 6)->count());
    }

    private function migration(): object
    {
        return require database_path('migrations/V040__enforce_payout_natural_keys.php');
    }

    private function dropPayoutNaturalIndexes(): void
    {
        $this->ensureStatementUserIdSupportIndexForTest();

        foreach ([
            ['payout_pools', self::POOL_INDEX],
            ['photographer_statements', self::STATEMENT_INDEX],
        ] as [$table, $index]) {
            if (Schema::hasIndex($table, $index)) {
                Schema::table($table, function ($blueprint) use ($index): void {
                    $blueprint->dropIndex($index);
                });
            }
        }
    }

    private function ensureStatementUserIdSupportIndexForTest(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (Schema::getIndexes('photographer_statements') as $index) {
            if (($index['name'] ?? null) !== self::STATEMENT_INDEX
                && ($this->indexColumns($index)[0] ?? null) === 'user_id') {
                return;
            }
        }

        if (Schema::hasIndex('photographer_statements', self::STATEMENT_SUPPORT_INDEX)) {
            return;
        }

        Schema::table('photographer_statements', function ($blueprint): void {
            $blueprint->index(['user_id'], self::STATEMENT_SUPPORT_INDEX);
        });
        $this->temporaryStatementSupportIndex = self::STATEMENT_SUPPORT_INDEX;
    }

    private function removeTemporaryStatementSupportIndex(): void
    {
        if ($this->temporaryStatementSupportIndex === null
            || ! Schema::hasIndex('photographer_statements', self::STATEMENT_INDEX, 'unique')) {
            return;
        }

        Schema::table('photographer_statements', function ($blueprint): void {
            $blueprint->dropIndex(self::STATEMENT_SUPPORT_INDEX);
        });
        $this->temporaryStatementSupportIndex = null;
    }

    /**
     * @param  array<string, mixed>  $index
     * @return list<string>
     */
    private function indexColumns(array $index): array
    {
        $columns = $index['columns'] ?? [];
        if (is_string($columns)) {
            $columns = explode(',', $columns);
        }

        return array_map(
            static fn (mixed $column): string => strtolower(trim((string) $column)),
            $columns,
        );
    }
}
