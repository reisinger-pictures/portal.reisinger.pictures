<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PhotographerStatement;
use App\Models\Role;
use App\Models\User;
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
}
