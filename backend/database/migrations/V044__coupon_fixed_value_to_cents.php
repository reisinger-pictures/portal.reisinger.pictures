<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Convert `coupons.value` to cents for the `fixed` branch (owner decision
 * 2026-09-28: every monetary amount is cents, in storage and in the API,
 * including whole-euro amounts).
 *
 * `coupons.value` is ONE column whose unit is defined by its sibling
 * discriminator `type`: a count of cents for `fixed`, a percentage for
 * `percentage`. Keeping one field is the decision, so the column keeps its
 * `decimal(10,2)` type (V018) and now holds an integer count of cents on the
 * money branch. That is an awkward column type — a count of cents has no
 * fraction — and it is the acknowledged price of the single-field decision,
 * not an oversight: a schema change to a second money column would need a new
 * API field name, which this decision explicitly rules out.
 *
 * The discriminator is load-bearing, not decoration. `percentage` rows hold a
 * percent and must survive untouched; a backfill that ignores `type` turns
 * every percentage coupon into a hundredfold error (10 % becomes 1000 %), which
 * is a silently wrong *price*, not a display glitch. Rows of any other type
 * (`photo_package` stores a placeholder 0 in this column) are skipped for the
 * same reason. Only `type = 'fixed'` is read and written here.
 *
 * Data decision, documented up front as required by backend/AGENTS.md:
 * - Schema: unchanged. The unit change is a data change, not a column change.
 * - Backfill: `value = ROUND(value * 100)` restricted to `type = 'fixed'`, done
 *   in a single set-based UPDATE after the old values have been read for the
 *   report, so the log states what changed from what.
 * - Reporting: every converted row is logged with its id, code and old value,
 *   plus a summary naming the number of rows that were deliberately skipped for
 *   carrying a different unit. An operator can therefore verify the conversion
 *   against the report instead of against a hunch.
 *
 * Rollback: none, and `down()` stays empty per repo rule (never executed,
 * established 2026-08-03). The inverse is not mechanically derivable anyway —
 * after the conversion a `decimal(10,2)` value of `1000.00` is both "a
 * converted 10.00 € amount" and "a hand-entered amount", so dividing it back
 * would corrupt the second kind. Restoring euros requires a backup taken before
 * this migration, not a `down()`.
 *
 * Idempotency: this is a one-way scaling of live data and is tracked by the
 * `migrations` table like any other migration. A replay is *not* safe (it would
 * scale twice), which is why it is not guarded: the guard would have to guess
 * whether a given row had already been converted, and the only reliable
 * evidence is the migrations table itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `migrate:fresh` runs migrations in file order against an empty
        // database, so the table is always present here. The guard only exists
        // for a manually replayed migration against a partial schema.
        if (! Schema::hasTable('coupons') || ! Schema::hasColumn('coupons', 'value')) {
            Log::warning('V044 coupon value → cents skipped: coupons.value is not present');

            return;
        }

        // Read the old values *before* writing, so the report can state what
        // each row held before rather than what it holds afterwards.
        $fixedRows = DB::table('coupons')
            ->where('type', 'fixed')
            ->orderBy('id')
            ->get(['id', 'code', 'value']);

        $otherTypes = DB::table('coupons')
            ->where('type', '!=', 'fixed')
            ->select('type', DB::raw('count(*) as aggregate'))
            ->groupBy('type')
            ->pluck('aggregate', 'type')
            ->all();

        if ($fixedRows->isEmpty()) {
            Log::info('V044 coupon value → cents: no fixed coupons to convert', [
                'skipped_by_type' => $otherTypes,
            ]);

            return;
        }

        $converted = DB::table('coupons')
            ->where('type', 'fixed')
            ->update(['value' => DB::raw('ROUND(value * 100)')]);

        foreach ($fixedRows as $row) {
            Log::info('V044 coupon value → cents: converted fixed coupon', [
                'id' => $row->id,
                'code' => $row->code,
                'euros_before' => (float) $row->value,
                'cents_after' => (int) round((float) $row->value * 100),
            ]);
        }

        Log::info('V044 coupon value → cents: done', [
            'converted' => $converted,
            // A percentage row is not money: it must appear here, untouched.
            'skipped_because_not_money' => $otherTypes,
        ]);
    }

    public function down(): void
    {
        // Never executed in this repository; the rollback decision is documented
        // in the docblock. The inverse is ambiguous on a `decimal(10,2)` column
        // and would need a pre-migration backup.
    }
};
