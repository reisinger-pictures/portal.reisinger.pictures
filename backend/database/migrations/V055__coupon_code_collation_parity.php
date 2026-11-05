<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Compare coupon codes the way MariaDB's `utf8mb4_unicode_ci` does: case-,
 * accent- and trailing-space-insensitively, per brand.
 *
 * V054 added `coupons_brand_lower_code_unique` on `(brand, LOWER(code))`.
 * `LOWER()` folds case but not accents, so on PostgreSQL `Café` and `CAFE`
 * remained two coupons while MariaDB treats them as one. V055 closes that gap
 * with a named ICU collation at primary strength (`und-u-ks-level1`, which
 * ignores case and accents) and a unique index on
 * `(brand, RTRIM(code) COLLATE "coupons_code_ci")`.
 *
 * The `RTRIM()` is not decoration: MariaDB's `utf8mb4_unicode_ci` is a PAD
 * SPACE collation, so `'CODE'` and `'CODE '` compare equal there. PostgreSQL's
 * NO PAD text comparison and ICU collations do not ignore trailing spaces, so
 * the index and the lookup both trim trailing spaces before applying the
 * collation. Leading spaces remain significant, exactly as they are in
 * MariaDB's comparison.
 *
 * The stronger index is created first and the weaker V054 index is dropped
 * only afterwards, so an interrupted or replayed run never leaves the table
 * without an invariant. On MariaDB the column collation already provides the
 * comparison and the existing `(brand, code)` unique index enforces it, so
 * this migration has no schema work there. SQLite keeps V054's `LOWER()` index
 * because the test engine has no ICU collation; SQLite-only tests therefore
 * retain the case-only, NO PAD approximation.
 *
 * Schema/backfill/rollback decision: no column is added and no row is
 * rewritten. Before creating the index the migration preflights
 * collation-equivalent `(brand, code)` pairs and aborts with a report when one
 * exists, instead of deleting, merging or re-casing coupons; an operator
 * decides which spelling survives. `down()` stays empty because down()
 * methods are never executed in this repository.
 *
 * This is deliberately an approximation of MariaDB's collation, not a claim
 * of byte-for-byte equivalence: ICU's UCA version and MariaDB's
 * `utf8mb4_unicode_ci` version can assign some characters to different
 * equivalence classes. The trailing-space rule is preserved with `RTRIM`,
 * but the documented contract is the tested one, not "identical to MySQL".
 * Those limits are documented in `features/ecommerce/08-srp-coupon-system.md`
 * §7 rather than hidden by tightening the accepted code alphabet.
 */
return new class extends Migration
{
    private const TABLE = 'coupons';

    private const COLLATION = 'coupons_code_ci';

    private const UNIQUE_INDEX = 'coupons_brand_code_ci_unique';

    private const LEGACY_UNIQUE_INDEX = 'coupons_brand_lower_code_unique';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'code')) {
            throw new RuntimeException('V055 requires table ['.self::TABLE.'] with a [code] column.');
        }

        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // The PAD SPACE column collation already compares codes case-,
            // accent- and trailing-space-insensitively, and
            // `coupons_brand_code_unique` enforces the invariant. A functional
            // or collated index would only repeat the same rule.
            return;
        }

        if ($driver === 'sqlite') {
            // SQLite has no ICU collation here. Keep V054's `(brand,
            // LOWER(code))` index; it is the strongest invariant available on
            // that engine and remains acceptable for SQLite-only tests.
            return;
        }

        if ($driver !== 'pgsql') {
            throw new RuntimeException('V055 does not support database driver ['.$driver.'].');
        }

        $this->ensureCollation();

        $duplicates = DB::select(
            'SELECT brand, STRING_AGG(code, \', \' ORDER BY code) AS codes, COUNT(*) AS coupon_count'
            .' FROM '.self::TABLE
            .' GROUP BY brand, RTRIM(code) COLLATE "'.self::COLLATION.'"'
            .' HAVING COUNT(*) > 1'
            .' ORDER BY brand, codes'
        );

        if ($duplicates !== []) {
            $report = collect($duplicates)
                ->map(static fn (object $row): string => sprintf(
                    'brand=%s codes=%s count=%d',
                    (string) $row->brand,
                    (string) $row->codes,
                    (int) $row->coupon_count,
                ))
                ->implode('; ');

            Log::error('V055 coupon-code collation preflight failed', ['duplicates' => $report]);

            throw new RuntimeException(
                'V055 cannot enforce case-, accent- and trailing-space-insensitive coupon codes because collation-equivalent duplicates exist: '.$report
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::UNIQUE_INDEX
            .' ON '.self::TABLE.' (brand, RTRIM(code) COLLATE "'.self::COLLATION.'")'
        );

        // Only after the stronger index exists: dropping the weaker one first
        // would open a window without any per-brand code invariant.
        DB::statement('DROP INDEX IF EXISTS '.self::LEGACY_UNIQUE_INDEX);
    }

    public function down(): void
    {
        // down() is never executed in this repository.
    }

    /**
     * Create the named collation and verify that a replay picked up the same
     * definition rather than a foreign collation that happens to share the
     * name.
     */
    private function ensureCollation(): void
    {
        try {
            DB::statement(
                'CREATE COLLATION IF NOT EXISTS '.self::COLLATION
                ." (provider = icu, locale = 'und-u-ks-level1', deterministic = false)"
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'V055 requires a PostgreSQL build with ICU collation support to create ['.self::COLLATION.'].',
                0,
                $exception,
            );
        }

        $collation = DB::selectOne(
            'SELECT c.collprovider AS provider, c.collisdeterministic AS is_deterministic, '
            .'c.colliculocale AS icu_locale'
            .' FROM pg_collation c'
            .' JOIN pg_namespace n ON n.oid = c.collnamespace'
            .' WHERE c.collname = ? AND n.nspname = current_schema()'
            .' LIMIT 1',
            [self::COLLATION]
        );

        if ($collation === null) {
            throw new RuntimeException('V055 could not create collation ['.self::COLLATION.'].');
        }

        $deterministic = filter_var($collation->is_deterministic, FILTER_VALIDATE_BOOLEAN);

        if ($collation->provider !== 'i'
            || $deterministic
            || $collation->icu_locale !== 'und-u-ks-level1') {
            throw new RuntimeException(
                'V055 found collation ['.self::COLLATION.'] with an incompatible definition; expected ICU locale [und-u-ks-level1] with nondeterministic comparisons.'
            );
        }
    }
};
