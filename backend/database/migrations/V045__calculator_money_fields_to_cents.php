<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `calc_base_price` and `calc_hourly_rate` move from euros to cents.
 *
 * Owner decision 2026-09-28: every monetary amount is cents, in storage and in
 * the API, whole-euro amounts included. These were the last two of the nine
 * money fields `GET /api/settings/license-terms` serves that were not —
 * `V016__calculator_settings_defaults` seeded `'50'` / `'80'` as euros, and
 * `DatabaseSeeder` still declared them that way. Every other money key
 * (`base_price`, `setup_fee`, `privacy_fee`, `extra_image_fee`, `price_*`) has
 * been cents all along.
 *
 * This is a **value** change, not a type change: `settings.value` is a `text`
 * column, and `'50'` is a perfectly valid cent amount (0,50 €). No schema is
 * touched, so this is a data-only migration.
 *
 * Why the backfill is not a blind `× 100`
 * ---------------------------------------
 * A row that already holds a cent-conform value — say `5000`, entered through
 * the UI as "5000 €" before the frontend understood the unit — would be turned
 * into `500000` (5000,00 €) by an unconditional `× 100`, and nothing anywhere
 * would notice. For these two keys that state cannot have been reached through
 * the application: their contract has always been euros, so no client ever had
 * a reason to send a cent amount for them. "Cannot have happened" is not a
 * guard, though, so this migration does three things instead of trusting it:
 *
 *   1. reads every affected row, for every brand, before writing anything;
 *   2. logs each row it is about to convert together with its old value, so the
 *      run leaves an auditable trace rather than a silent number change;
 *   3. aborts on any value `>= 1000` — a shooting package base price or hourly
 *      rate of 1000 € or more is not a configuration, it is a unit error, and
 *      multiplying it would compound the error by another factor of 100.
 *
 * Rollback
 * --------
 * `down()` is intentionally empty. Per `backend/AGENTS.md` migration policy,
 * `down()` is never executed in this repository. A reverse conversion would
 * also be unsound: it cannot distinguish a row that this migration wrote
 * (converted from euros) from a row that was already in cents, and dividing the
 * second by 100 would be the very corruption step 3 above exists to prevent.
 * Restoring the pre-conversion values is a data decision with a known source
 * (`git log` on `DatabaseSeeder.php` and the rows this migration logged), not
 * an arithmetic inversion.
 */
return new class extends Migration
{
    /**
     * The settings keys whose stored amount changes unit. Deliberately narrow:
     * the counts (`calc_images_per_hour`, `calc_outdoor_images_per_hour`) and
     * the dimensionless factor (`calc_flatrate_multiplier`) are not money and
     * are not touched — the owner decision named factors and counts as
     * explicitly excluded.
     */
    private const EURO_MONEY_KEYS = ['calc_base_price', 'calc_hourly_rate'];

    /**
     * Smallest stored value that is treated as a unit error rather than as a
     * euro amount. Chosen so that it sits far above any conceivable base price
     * or hourly rate in euros and far below any cent-conform value the frontend
     * could plausibly have written for these keys.
     */
    private const CENT_VALUE_RED_FLAG = 1000;

    public function up(): void
    {
        $conversions = $this->planConversions();

        if ($conversions === []) {
            $this->report('nothing to convert', []);

            return;
        }

        // Validation happens in planConversions() before any write, so an abort
        // leaves the table exactly as it was — the writes below can only fail
        // on a database error, and they are still wrapped so a partial pass
        // cannot survive.
        DB::transaction(function () use ($conversions): void {
            foreach ($conversions as $conversion) {
                DB::table('settings')
                    ->where('key', $conversion['key'])
                    ->where('brand', $conversion['brand'])
                    ->update(['value' => (string) $conversion['cents']]);
            }
        });

        $this->report('converted', $conversions);
    }

    /**
     * `down()` is never executed in this repository — see the class docblock
     * for why a reverse conversion would be unsound rather than merely absent.
     */
    public function down(): void
    {
        //
    }

    /**
     * Read every affected row, reject the ones that are already cent-conform,
     * and return the conversions to perform — without writing anything.
     *
     * @return list<array{key: string, brand: string|null, old: string, euros: float, cents: int}>
     */
    private function planConversions(): array
    {
        $rows = DB::table('settings')
            ->whereIn('key', self::EURO_MONEY_KEYS)
            ->orderBy('key')
            ->orderBy('brand')
            ->get(['key', 'brand', 'value']);

        $conversions = [];
        $rejected = [];

        foreach ($rows as $row) {
            $old = (string) $row->value;

            if (! is_numeric($old)) {
                // Not convertible and not comparable: leave it untouched and
                // say so, rather than letting `(int)` launder it into 0 — a
                // 0 € base price is a free quote, not a default.
                $rejected[] = sprintf('%s [brand=%s] is not numeric: %s', $row->key, $row->brand ?? 'NULL', var_export($old, true));

                continue;
            }

            $amount = (float) $old;

            if ($amount >= self::CENT_VALUE_RED_FLAG) {
                $rejected[] = sprintf(
                    '%s [brand=%s] holds %s, which is at or above %d and therefore already looks like cents. Multiplying it would turn it into %d. Fix the row by hand before running this migration.',
                    $row->key,
                    $row->brand ?? 'NULL',
                    $old,
                    self::CENT_VALUE_RED_FLAG,
                    (int) round($amount * 100),
                );

                continue;
            }

            if ($amount < 0) {
                $rejected[] = sprintf('%s [brand=%s] is negative (%s) and cannot be a price', $row->key, $row->brand ?? 'NULL', $old);

                continue;
            }

            $conversions[] = [
                'key' => $row->key,
                'brand' => $row->brand,
                'old' => $old,
                'euros' => $amount,
                'cents' => (int) round($amount * 100),
            ];
        }

        if ($rejected !== []) {
            // Every offending row is named with its value, in the log and in
            // the exception the operator actually reads, so the decision can
            // be made per row instead of by re-running `migrate` to find out.
            // Same pattern as V039's preflight: log the report, then throw it.
            $message = "V045 refused to convert the calculator money fields:\n  - ".implode("\n  - ", $rejected);
            Log::error('V045 calculator money fields: backfill aborted', ['rejected' => $rejected]);

            throw new RuntimeException($message);
        }

        return $conversions;
    }

    /**
     * Log the outcome of the run. A unit conversion that leaves no trace beyond
     * a changed number is indistinguishable from a bug the next time the row is
     * read, so every converted row is reported with the value it had and the
     * value it now holds.
     *
     * @param  list<array{key: string, brand: string|null, old: string, euros: float, cents: int}>  $conversions
     */
    private function report(string $outcome, array $conversions): void
    {
        $rows = array_map(
            static fn (array $conversion): array => [sprintf(
                '%s (brand=%s): %s -> %d',
                $conversion['key'],
                $conversion['brand'] ?? 'NULL',
                $conversion['old'],
                $conversion['cents'],
            )],
            $conversions,
        );

        Log::info('V045 calculator money fields: euros -> cents', [
            'outcome' => $outcome,
            'report' => $rows,
        ]);
    }
};
