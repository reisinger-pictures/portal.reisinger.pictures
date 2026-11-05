<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Make a coupon code unique per brand regardless of letter case.
 *
 * MariaDB's `utf8mb4_unicode_ci` column collation made
 * `coupons_brand_code_unique (brand, code)` and `WHERE code = ?` compare
 * case-insensitively. PostgreSQL does neither: the old constraint and the old
 * lookup are case-sensitive, so `(rp, 'SPRING10')` and `(rp, 'spring10')`
 * would be two rows and a customer entering a differently cased code would
 * miss their coupon. The application reads with `LOWER(code)` and the
 * duplicate checks in `CouponStoreRequest` / `CouponUpdateRequest` do the
 * same; this functional index is the structural half, so even a raw write
 * that bypasses validation cannot create a case-only duplicate.
 *
 * PostgreSQL and SQLite support expression indexes, so both get
 * `coupons_brand_lower_code_unique` on `(brand, LOWER(code))`. MariaDB needs
 * no new index: the existing `(brand, code)` unique already enforces the
 * invariant through its case-insensitive collation, and a functional index
 * there would only add a second spelling of the same rule. The old
 * case-sensitive index stays in place on PostgreSQL; an exact duplicate is
 * also a case-only duplicate, so the functional index is the stronger
 * invariant, while the old index still serves exact `(brand, code)` lookups.
 *
 * Schema/backfill/rollback decision: no column is added and no row is
 * rewritten. The migration refuses to continue if the table already contains
 * a case-only duplicate, and reports the offending `(brand, code)` groups
 * instead of deleting or merging coupons; an operator decides which spelling
 * survives. `down()` stays empty because down() methods are never executed in
 * this repository.
 */
return new class extends Migration
{
    private const TABLE = 'coupons';

    private const UNIQUE_INDEX = 'coupons_brand_lower_code_unique';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('V054 requires table ['.self::TABLE.'].');
        }

        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // The column collation already compares `(brand, code)`
            // case-insensitively; adding a functional index would not change
            // the accepted rows.
            return;
        }

        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('V054 does not support database driver ['.$driver.'].');
        }

        $duplicates = DB::table(self::TABLE)
            ->selectRaw('brand, LOWER(code) AS normalized_code, COUNT(*) AS coupon_count')
            ->groupBy('brand')
            ->groupByRaw('LOWER(code)')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $report = $duplicates
                ->map(static fn (object $row): string => sprintf(
                    'brand=%s code=%s count=%d',
                    (string) $row->brand,
                    (string) $row->normalized_code,
                    (int) $row->coupon_count,
                ))
                ->implode('; ');

            Log::error('V054 coupon-code preflight failed', ['duplicates' => $report]);

            throw new RuntimeException(
                'V054 cannot enforce case-insensitive coupon codes because case-only duplicates exist: '.$report
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::UNIQUE_INDEX
            .' ON '.self::TABLE.' (brand, LOWER(code))'
        );
    }

    public function down(): void
    {
        // down() is never executed in this repository.
    }
};
