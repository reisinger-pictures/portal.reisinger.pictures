<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Pins the V054 database invariant: a coupon code is unique per brand after
 * case folding.
 *
 * The application folds case in the customer lookup and in the management
 * duplicate checks. This test proves the schema refuses a case-only duplicate
 * even for a raw write that bypasses both, which is the only guarantee that
 * survives two concurrent requests and a direct import.
 *
 * `RefreshDatabase` runs the real PostgreSQL migration chain, so the index is
 * the one the deployment gets rather than a fixture rebuilt in the test.
 */
class CouponCodeCaseInsensitiveUniqueTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_rejects_case_only_duplicate_code_in_the_same_brand(): void
    {
        Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'CASEUNIQUE',
            'active' => true,
        ]);

        try {
            // A savepoint keeps the expected PostgreSQL unique violation from
            // aborting the surrounding RefreshDatabase transaction, so the
            // count below can still prove that only one row exists.
            DB::transaction(fn () => Coupon::factory()->create([
                'brand' => 'rp',
                'code' => 'caseunique',
                'active' => true,
            ]));
            $this->fail('The database accepted a case-only duplicate coupon code.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, Coupon::query()->whereRaw('LOWER(code) = ?', ['caseunique'])->count());
        }
    }

    public function test_database_allows_the_same_code_in_another_brand(): void
    {
        $current = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'BRANDSHARED',
            'active' => true,
        ]);
        $other = Coupon::factory()->create([
            'brand' => 'test-brand',
            'code' => 'brandshared',
            'active' => true,
        ]);

        $this->assertNotSame($current->id, $other->id);
        $this->assertSame(
            2,
            Coupon::query()->whereRaw('LOWER(code) = ?', ['brandshared'])->count(),
        );
    }

    /**
     * The index is not created over existing ambiguity: a case-only duplicate
     * is reported and nothing is deleted, merged or re-cased. The operator
     * decides which spelling survives.
     */
    public function test_v054_refuses_case_only_duplicates_without_changing_either_row(): void
    {
        // Model the pre-V054 state: the functional index is absent, so the
        // case-sensitive `(brand, code)` unique accepts both spellings.
        DB::statement('DROP INDEX IF EXISTS coupons_brand_lower_code_unique');

        $first = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'PreflightDuplicate',
            'active' => true,
        ]);
        $second = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'preflightduplicate',
            'active' => true,
        ]);

        try {
            $this->migration()->up();
            $this->fail('V054 accepted a table with a case-only duplicate coupon code.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('preflightduplicate', $exception->getMessage());
        }

        $this->assertDatabaseHas('coupons', ['id' => $first->id, 'code' => 'PreflightDuplicate']);
        $this->assertDatabaseHas('coupons', ['id' => $second->id, 'code' => 'preflightduplicate']);
        $this->assertSame(2, Coupon::query()->whereRaw('LOWER(code) = ?', ['preflightduplicate'])->count());
    }

    private function migration(): object
    {
        return require database_path('migrations/V054__coupon_code_case_insensitive_unique.php');
    }
}
