<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Services\ContractAuditService;
use App\Enums\Brand;
use App\Support\BrandRegistry;
use Illuminate\Http\Request;

class ContractAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
    }

    public function test_logs_audit_entry_with_ip_and_user_agent(): void
    {
        $contract = Contract::factory()->create(['brand' => Brand::B2B]);
        $signer = ContractSigner::factory()->create(['contract_id' => $contract->id]);

        $request = Request::create('/test', 'GET', [], [], [], [
            'REMOTE_ADDR' => '192.168.1.50',
            'HTTP_USER_AGENT' => 'TestAgent/1.0',
        ]);

        $service = new ContractAuditService();
        $log = $service->log($contract->id, $signer->id, 'signed', $request);

        $this->assertEquals('signed', $log->action);
        $this->assertEquals('192.168.1.50', $log->ip_address);
        $this->assertEquals('TestAgent/1.0', $log->user_agent);
        $this->assertDatabaseHas('contract_audit_logs', [
            'action' => 'signed',
            'contract_id' => $contract->id,
            'contract_signer_id' => $signer->id,
        ]);
    }

    public function test_logs_with_null_signer(): void
    {
        $contract = Contract::factory()->create(['brand' => Brand::B2B]);
        $request = Request::create('/test', 'GET');

        $service = new ContractAuditService();
        $log = $service->log($contract->id, null, 'opened', $request);

        $this->assertEquals('opened', $log->action);
        $this->assertNull($log->contract_signer_id);
    }
}
