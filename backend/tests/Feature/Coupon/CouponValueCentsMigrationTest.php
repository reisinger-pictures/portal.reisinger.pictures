<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Contract coverage for V044, which converts `coupons.value` from euros to
 * cents for the `fixed` branch.
 *
 * The migration is the one place where a wrong unit becomes *stored* data
 * rather than a wrong display, and the failure mode is silent: a fixed coupon
 * that is a hundredfold too cheap, or a percentage coupon that is a hundredfold
 * too generous. The second is the dangerous one, and it only exists because
 * `value` is one column serving two units.
 *
 * Every test here therefore seeds BOTH types. A migration that ignored the
 * discriminator would pass a test that only seeded `fixed` — and that is
 * exactly the bug this file exists to make impossible.
 */
class CouponValueCentsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/V044__coupon_fixed_value_to_cents.php');
    }

    /** Raw column read, bypassing the model's `float` cast. */
    private function rawValue(int $couponId): float
    {
        return (float) DB::table('coupons')->where('id', $couponId)->value('value');
    }

    private function seedLegacyCoupons(): array
    {
        // The pre-V044 state: a `fixed` value in euros, a `percentage` value in
        // percent, both in the same `decimal(10,2)` column.
        $fixed = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'LEGACYFIXED',
            'type' => 'fixed',
            'value' => 10.00,
            'active' => true,
        ]);
        $fixedWithCents = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'LEGACYFIXEDCENTS',
            'type' => 'fixed',
            'value' => 7.35,
            'active' => true,
        ]);
        $percentage = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'LEGACYPERCENT',
            'type' => 'percentage',
            'value' => 10,
            'active' => true,
        ]);
        $percentageFractional = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'LEGACYPERCENTFRAC',
            'type' => 'percentage',
            'value' => 12.5,
            'active' => true,
        ]);
        $package = Coupon::factory()->photoPackage(10, 4000)->create([
            'brand' => 'rp',
            'code' => 'LEGACYPACKAGE',
            'active' => true,
        ]);

        return compact('fixed', 'fixedWithCents', 'percentage', 'percentageFractional', 'package');
    }

    public function test_it_converts_fixed_coupons_from_euros_to_cents(): void
    {
        $fixed = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'LEGACYFIXED',
            'type' => 'fixed',
            'value' => 10.00,
            'active' => true,
        ]);

        $this->assertSame(10.0, $this->rawValue($fixed->id));

        $this->migration()->up();

        $this->assertSame(1000.0, $this->rawValue($fixed->id));
    }

    public function test_it_preserves_the_cents_of_a_fixed_coupon_that_had_them(): void
    {
        // 7,35 € has to become 735, not 700 and not 73500. Rounding in the
        // wrong direction here is a price error of 35 cents, which no
        // percentage-only test would ever notice.
        $fixed = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'LEGACYFIXEDCENTS',
            'type' => 'fixed',
            'value' => 7.35,
            'active' => true,
        ]);

        $this->migration()->up();

        $this->assertSame(735.0, $this->rawValue($fixed->id));
    }

    public function test_it_does_not_touch_a_percentage_coupon(): void
    {
        $percentage = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'LEGACYPERCENT',
            'type' => 'percentage',
            'value' => 10,
            'active' => true,
        ]);
        $percentageFractional = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'LEGACYPERCENTFRAC',
            'type' => 'percentage',
            'value' => 12.5,
            'active' => true,
        ]);

        $this->migration()->up();

        // A percentage is not money. 10 % must stay 10 %; scaling it would turn
        // a tenth off into a hundred times the cart.
        $this->assertSame(10.0, $this->rawValue($percentage->id));
        $this->assertSame(12.5, $this->rawValue($percentageFractional->id));
    }

    public function test_it_does_not_touch_a_photo_package_coupon(): void
    {
        // `photo_package` stores the placeholder 0 in `value` and the money in
        // `package_price_cents`, which is already cents and is not this
        // migration's business.
        $package = Coupon::factory()->photoPackage(10, 4000)->create([
            'brand' => 'rp',
            'code' => 'LEGACYPACKAGE',
            'active' => true,
        ]);

        $this->migration()->up();

        $this->assertSame(0.0, $this->rawValue($package->id));
        $this->assertSame(4000, (int) DB::table('coupons')->where('id', $package->id)->value('package_price_cents'));
    }

    /**
     * The discriminator is proven, not assumed: the *same* run converts one row
     * and leaves its sibling alone. Split across the tests above, a migration
     * that hard-coded a single type could still satisfy them; in one run it
     * cannot.
     */
    public function test_it_converts_only_the_money_branch_of_a_mixed_table(): void
    {
        $rows = $this->seedLegacyCoupons();

        $this->migration()->up();

        $this->assertSame(1000.0, $this->rawValue($rows['fixed']->id), 'fixed 10,00 € → 1000 cents');
        $this->assertSame(735.0, $this->rawValue($rows['fixedWithCents']->id), 'fixed 7,35 € → 735 cents');
        $this->assertSame(10.0, $this->rawValue($rows['percentage']->id), 'percentage 10 stays 10');
        $this->assertSame(12.5, $this->rawValue($rows['percentageFractional']->id), 'percentage 12,5 stays 12,5');
        $this->assertSame(0.0, $this->rawValue($rows['package']->id), 'photo_package placeholder stays 0');
    }

    /**
     * The conversion boundary, end to end: the value which produced a 10 €
     * discount before the migration produces the same discount after it.
     *
     * The pre-migration discount is a *historical fact about the data*, not
     * something the changed code can still produce — asking `applyCoupon` for
     * it before the migration would only re-assert the new read path against
     * the old column, and would pass or fail for the wrong reason. So the test
     * pins the number the customer actually had (1000 cents off 3500) against
     * the migrated row, and separately pins that the un-migrated row is now
     * read as 10 cents — which is precisely what makes the backfill
     * load-bearing. A migration that skipped this row, or a read path that kept
     * multiplying, both fail here: the first leaves `value = 10.00` and
     * discounts 10 cents, the second discounts 100000 (clamped to the cart, so
     * the customer pays nothing).
     */
    public function test_the_ten_euro_discount_survives_the_migration(): void
    {
        $fixed = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'LEGACYFIXED',
            'type' => 'fixed',
            'value' => 10.00, // the pre-migration state: euros
            'active' => true,
        ]);

        $items = [['itemId' => 'item-1', 'priceCents' => 3500, 'tier' => 'srp']];
        $service = app(CouponService::class);

        $this->migration()->up();

        $migrated = $fixed->fresh();
        $this->assertSame(1000.0, (float) $migrated->value, 'the column now holds 1000');

        $after = $service->applyCoupon($migrated, $items, 3500);
        $this->assertSame(1000, $after['discountCents'], '10 € before, 10 € after');
        $this->assertSame(2500, $after['totalCents']);
    }

    /**
     * The negative of the test above, and the reason the migration is not
     * optional bookkeeping: the same column value that means 10 € after the
     * backfill means 10 cents before it. There is no way to tell the two apart
     * from the number alone, which is why the conversion has to run exactly
     * once, on every `fixed` row, before any of them is read.
     */
    public function test_the_un_migrated_value_would_be_read_as_cents(): void
    {
        $notMigrated = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'NOTMIGRATED',
            'type' => 'fixed',
            'value' => 10.00, // euros, if the migration had not run
            'active' => true,
        ]);

        $items = [['itemId' => 'item-1', 'priceCents' => 3500, 'tier' => 'srp']];

        $this->assertSame(
            10,
            app(CouponService::class)->applyCoupon($notMigrated, $items, 3500)['discountCents'],
        );
    }

    /**
     * The percentage boundary across the same migration. 10 % of 3500 is 350
     * before and after; if the backfill had scaled it, this would be 35000,
     * clamped to the cart total — a free order.
     */
    public function test_the_ten_percent_discount_survives_the_migration(): void
    {
        $percentage = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'LEGACYPERCENT',
            'type' => 'percentage',
            'value' => 10,
            'active' => true,
        ]);

        $items = [['itemId' => 'item-1', 'priceCents' => 3500, 'tier' => 'srp']];
        $service = app(CouponService::class);

        $this->assertSame(350, $service->applyCoupon($percentage, $items, 3500)['discountCents']);

        $this->migration()->up();

        $after = $service->applyCoupon($percentage->fresh(), $items, 3500);
        $this->assertSame(350, $after['discountCents']);
        $this->assertSame(3150, $after['totalCents']);
    }

    /**
     * The migration has to be able to tell an operator what it did, because a
     * data migration that converts money silently is not auditable.
     */
    public function test_it_reports_every_converted_row_with_its_old_value(): void
    {
        $fixed = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'LEGACYFIXED',
            'type' => 'fixed',
            'value' => 10.00,
            'active' => true,
        ]);
        Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'LEGACYPERCENT',
            'type' => 'percentage',
            'value' => 10,
            'active' => true,
        ]);

        $messages = [];
        Log::listen(function ($message) use (&$messages): void {
            $messages[] = $message;
        });

        $this->migration()->up();

        $converted = array_values(array_filter(
            $messages,
            static fn ($message): bool => $message->message === 'V044 coupon value → cents: converted fixed coupon'
                && ($message->context['id'] ?? null) === $fixed->id,
        ));

        $this->assertCount(1, $converted, 'the fixed row is reported with its old value');
        $this->assertSame('LEGACYFIXED', $converted[0]->context['code']);
        $this->assertSame(10.0, $converted[0]->context['euros_before']);
        $this->assertSame(1000, $converted[0]->context['cents_after']);

        $summary = array_values(array_filter(
            $messages,
            static fn ($message): bool => $message->message === 'V044 coupon value → cents: done',
        ));
        $this->assertCount(1, $summary);
        $this->assertSame(1, $summary[0]->context['converted']);
        // The skipped rows are named, so an operator can see that the percent
        // they see in the UI is still a percent and was never in scope.
        $this->assertSame(['percentage' => 1], $summary[0]->context['skipped_because_not_money']);
    }

    public function test_it_is_a_no_op_on_an_empty_coupon_table(): void
    {
        $this->assertSame(0, DB::table('coupons')->count());

        $this->migration()->up();

        $this->assertSame(0, DB::table('coupons')->count());
    }
}
