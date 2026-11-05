<?php

namespace Tests\Feature\Contract;

use App\Enums\Brand;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Deterministic, sequential contract for SEC-20261018-12.
 *
 * Deliberately runs only against a real PostgreSQL database and deliberately
 * does not use RefreshDatabase: it must never migrate:fresh a database it does
 * not own. DatabaseTransactions is used instead, so the parallel runner still
 * switches this class to the per-worker database and migrates it once, while
 * every row this test writes is rolled back at teardown. A filtered run still
 * expects an already-migrated database (the PostgreSQL contract job migrates
 * before running it):
 *
 *   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=<port> \
 *   DB_DATABASE=<db> DB_USERNAME=<user> DB_PASSWORD=<password> \
 *   php artisan test --filter ContractContentHeartbeatPostgresTest
 *
 * This asserts the strict bound for sequential polling. The route uses
 * App\Http\Middleware\AtomicThrottleRequests (count first, decide after), so
 * the bound also holds for simultaneous requests; this test cannot observe
 * that, because it issues one request at a time by design. The real
 * concurrency measurement lives in
 * tests/scripts/contract-content-postgres-probe.sh, and the deterministic
 * middleware contract is pinned by AtomicThrottleRequestsTest.
 */
class ContractContentHeartbeatPostgresTest extends TestCase
{
    use DatabaseTransactions;

    public function test_postgres_contract_content_limiter_bounds_heartbeat_writes(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This contention-policy test requires a real PostgreSQL connection.');
        }

        BrandRegistry::set(Brand::B2B);
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $template = Contract::factory()->template()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
            'items' => [],
            'discounts' => [],
            'terms_html' => '<p>Template terms</p>',
            'available_roles' => ['Model'],
            'expires_at' => now()->addDays(30),
        ]);
        $contract = Contract::factory()->active()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
            'template_id' => $template->id,
            'items' => [[
                'type' => 'item',
                'description' => 'Contract content',
                'qty' => 1,
                'price' => 10000,
            ]],
            'discounts' => [],
            'terms_html' => '<p>Active contract terms</p>',
            'available_roles' => ['Model'],
            'expires_at' => now()->addDays(20),
            'closes_at' => now()->addDays(10),
        ]);
        $token = Str::random(64);
        $signer = ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'personal_token' => $token,
            'status' => 'joined',
            'roles' => ['Model'],
        ]);

        try {
            for ($poll = 0; $poll < 30; $poll++) {
                $this->getJson('/api/contracts/sign/'.$token)
                    ->assertOk()
                    ->assertJsonPath('contract.terms_html', '<p>Active contract terms</p>');
            }

            $this->getJson('/api/contracts/sign/'.$token)->assertStatus(429);

            $this->assertSame(30, DB::table('contract_audit_logs')
                ->where('contract_signer_id', $signer->id)
                ->where('action', 'heartbeat')
                ->count());
        } finally {
            DB::table('contract_audit_logs')->whereIn('contract_id', [$contract->id, $template->id])->delete();
            DB::table('contract_signers')->where('contract_id', $contract->id)->delete();
            Contract::query()->whereKey($contract->id)->delete();
            Contract::query()->whereKey($template->id)->delete();
            $user->delete();
        }
    }
}
