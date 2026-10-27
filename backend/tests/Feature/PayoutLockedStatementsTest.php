<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\PayoutChronologyException;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\PayoutCorrection;
use App\Models\PayoutPool;
use App\Models\Photo;
use App\Models\PhotographerStatement;
use App\Models\Role;
use App\Models\User;
use App\Services\PayoutCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P0-B2 (HIGH) — recalculating a payout month must never destroy locked
 * `approved`/`paid` statements (audit trail).
 */
class PayoutLockedStatementsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): string
    {
        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));

        return auth('api')->login($superAdmin);
    }

    public function test_calculate_preserves_approved_statement(): void
    {
        $token = $this->actingAsSuperAdmin();

        $photographer = User::factory()->create();
        $statement = PhotographerStatement::create([
            'user_id' => $photographer->id,
            'month' => 5,
            'year' => 2026,
            'pool_earnings_cents' => 12345,
            'delta_surcharge_earnings_cents' => 678,
            'earned_amount_cents' => 13023,
            'total_payable_cents' => 13023,
            'status' => 'approved',
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/management/payouts/calculate', [
                'month' => 5,
                'year' => 2026,
                'net_pool_cents' => 100000,
            ])
            ->assertStatus(200);

        $fresh = PhotographerStatement::find($statement->id);
        $this->assertNotNull($fresh, 'Approved statement must not be deleted.');
        $this->assertSame('approved', $fresh->status);
        $this->assertSame(12345, $fresh->pool_earnings_cents);
        $this->assertSame(13023, $fresh->total_payable_cents);
    }

    public function test_calculate_preserves_paid_statement_and_removes_recalculable_rows(): void
    {
        $token = $this->actingAsSuperAdmin();

        $photographer = User::factory()->create();
        $paid = PhotographerStatement::create([
            'user_id' => $photographer->id,
            'month' => 6,
            'year' => 2026,
            'pool_earnings_cents' => 20000,
            'total_payable_cents' => 20000,
            'status' => 'paid',
        ]);

        $pendingPhotographer = User::factory()->create();
        $pending = PhotographerStatement::create([
            'user_id' => $pendingPhotographer->id,
            'month' => 6,
            'year' => 2026,
            'pool_earnings_cents' => 500,
            'total_payable_cents' => 500,
            'status' => 'pending',
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/management/payouts/calculate', [
                'month' => 6,
                'year' => 2026,
                'net_pool_cents' => 100000,
            ])
            ->assertStatus(200);

        $freshPaid = PhotographerStatement::find($paid->id);
        $this->assertNotNull($freshPaid, 'Paid statement must not be deleted.');
        $this->assertSame('paid', $freshPaid->status);
        $this->assertSame(20000, $freshPaid->total_payable_cents);

        // Recalculable (pending/rollover) rows are replaced by the recalculation.
        $this->assertNull(PhotographerStatement::find($pending->id));
    }

    public function test_calculate_rejects_an_earlier_month_after_a_later_month_is_finalized(): void
    {
        $token = $this->actingAsSuperAdmin();
        $photographer = User::factory()->create();
        $july = PhotographerStatement::query()->create([
            'user_id' => $photographer->id,
            'month' => 7,
            'year' => 2026,
            'total_payable_cents' => -750,
            'status' => 'rollover',
        ]);
        $september = PhotographerStatement::query()->create([
            'user_id' => $photographer->id,
            'month' => 9,
            'year' => 2026,
            'pool_earnings_cents' => 0,
            'delta_surcharge_earnings_cents' => 0,
        ]);

        (new PayoutCalculationService)->finalizeStatements(9, 2026);
        $september->refresh();
        $this->assertSame('rollover', $september->status);
        $this->assertSame(-750, $september->total_payable_cents);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/management/payouts/calculate', [
                'month' => 8,
                'year' => 2026,
                'net_pool_cents' => 100000,
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Die Abrechnung für 08/2026 ist gesperrt: Für den späteren Monat 09/2026 existiert bereits eine finale Abrechnung. Bitte rechne die Monate chronologisch ab.',
            );

        $this->assertDatabaseMissing('payout_pools', [
            'month' => 8,
            'year' => 2026,
        ]);
        $this->assertDatabaseMissing('photographer_statements', [
            'user_id' => $photographer->id,
            'month' => 8,
            'year' => 2026,
        ]);
        $this->assertSame(-750, $july->fresh()->total_payable_cents);
        $this->assertSame(-750, $september->fresh()->total_payable_cents);
    }

    public function test_finalize_service_rejects_an_earlier_month_when_a_later_statement_exists(): void
    {
        $photographer = User::factory()->create();
        PhotographerStatement::query()->create([
            'user_id' => $photographer->id,
            'month' => 9,
            'year' => 2026,
            'status' => 'pending',
        ]);

        $this->expectException(PayoutChronologyException::class);
        $this->expectExceptionMessage(
            'Die Abrechnung für 08/2026 ist gesperrt: Für den späteren Monat 09/2026 existiert bereits eine finale Abrechnung. Bitte rechne die Monate chronologisch ab.',
        );

        (new PayoutCalculationService)->finalizeStatements(8, 2026);
    }

    public function test_recalculation_after_calculation_without_pool_amount_keeps_stored_amount(): void
    {
        $token = $this->actingAsSuperAdmin();
        $photographer = User::factory()->create();
        $photo = Photo::factory()->create(['user_id' => $photographer->id]);
        $order = Order::factory()->paid()->create([
            'stripe_fee_cents' => 0,
            'created_at' => '2026-05-10 12:00:00',
        ]);
        InvoiceSnapshot::query()->create([
            'order_id' => $order->id,
            'customer_details' => [
                'items' => [['photoId' => $photo->id, 'price' => 1000, 'tier' => 'web']],
            ],
            'total_net' => 1000,
            'total_gross' => 1200,
            'tax_rate' => 20.00,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/management/payouts/calculate', [
                'month' => 5,
                'year' => 2026,
                'net_pool_cents' => 12345,
            ])
            ->assertOk();

        $this->assertDatabaseHas('photographer_statements', [
            'user_id' => $photographer->id,
            'month' => 5,
            'year' => 2026,
        ]);
        $pool = PayoutPool::query()->where(['month' => 5, 'year' => 2026])->sole();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/management/payouts/calculate', [
                'month' => 5,
                'year' => 2026,
            ])
            ->assertOk();

        $this->assertSame(12345, $pool->fresh()->net_pool_cents);
    }

    public function test_orphan_pool_without_statement_requires_pool_amount(): void
    {
        $token = $this->actingAsSuperAdmin();
        $pool = PayoutPool::query()->create([
            'month' => 5,
            'year' => 2026,
            'net_pool_cents' => 12345,
            'photographer_share_percent' => 50,
        ]);

        $this->assertDatabaseMissing('photographer_statements', [
            'month' => 5,
            'year' => 2026,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/management/payouts/calculate', [
                'month' => 5,
                'year' => 2026,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('net_pool_cents');

        $this->assertSame(12345, $pool->fresh()->net_pool_cents);
        $this->assertDatabaseMissing('photographer_statements', [
            'month' => 5,
            'year' => 2026,
        ]);
    }

    public function test_booked_correction_is_recalculation_evidence_without_statement(): void
    {
        $token = $this->actingAsSuperAdmin();
        $photographer = User::factory()->create();
        $order = Order::factory()->paid()->create([
            'created_at' => '2026-04-10 12:00:00',
        ]);
        $pool = PayoutPool::query()->create([
            'month' => 5,
            'year' => 2026,
            'net_pool_cents' => 12345,
            'photographer_share_percent' => 50,
        ]);
        PayoutCorrection::query()->create([
            'order_id' => $order->id,
            'user_id' => $photographer->id,
            'refund_amount_cents' => 1000,
            'amount_cents' => -500,
            'closed_month' => 4,
            'closed_year' => 2026,
            'settled_at' => now(),
            'settled_month' => 5,
            'settled_year' => 2026,
        ]);

        $this->assertDatabaseMissing('photographer_statements', [
            'month' => 5,
            'year' => 2026,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/management/payouts/calculate', [
                'month' => 5,
                'year' => 2026,
            ])
            ->assertOk();

        $this->assertSame(12345, $pool->fresh()->net_pool_cents);
        $this->assertDatabaseHas('photographer_statements', [
            'user_id' => $photographer->id,
            'month' => 5,
            'year' => 2026,
        ]);
    }

    public function test_recalculation_with_pool_amount_updates_stored_amount(): void
    {
        $token = $this->actingAsSuperAdmin();
        $pool = PayoutPool::query()->create([
            'month' => 5,
            'year' => 2026,
            'net_pool_cents' => 12345,
            'photographer_share_percent' => 50,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/management/payouts/calculate', [
                'month' => 5,
                'year' => 2026,
                'net_pool_cents' => 23456,
            ])
            ->assertOk();

        $this->assertSame(23456, $pool->fresh()->net_pool_cents);
    }

    public function test_first_calculation_requires_pool_amount(): void
    {
        $token = $this->actingAsSuperAdmin();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/management/payouts/calculate', [
                'month' => 5,
                'year' => 2026,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('net_pool_cents');

        $this->assertDatabaseMissing('payout_pools', [
            'month' => 5,
            'year' => 2026,
        ]);
    }
}
