<?php

namespace Tests\Feature;

use App\Models\DownloadLog;
use App\Models\InvoiceSnapshot;
use App\Models\LicenseModifier;
use App\Models\LicenseUseCase;
use App\Models\Order;
use App\Models\PayoutPool;
use App\Models\PhotographerStatement;
use App\Models\Contract;
use App\Models\ContractAuditLog;
use App\Models\ContractSigner;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ensures every factory is runnable in a fresh test database
 * and populates all non-nullable columns.
 */
class FactoryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_factory_creates_row(): void
    {
        $product = Product::factory()->create();
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_setting_factory_creates_row(): void
    {
        $setting = Setting::factory()->create();
        $this->assertDatabaseHas('settings', ['key' => $setting->key]);
    }

    public function test_license_use_case_factory_creates_row(): void
    {
        $useCase = LicenseUseCase::factory()->create();
        $this->assertDatabaseHas('license_use_cases', ['id' => $useCase->id]);
    }

    public function test_license_modifier_factory_creates_row(): void
    {
        $modifier = LicenseModifier::factory()->create();
        $this->assertDatabaseHas('license_modifiers', ['id' => $modifier->id]);
    }

    public function test_tenant_factory_creates_row(): void
    {
        $tenant = Tenant::factory()->create();
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    }

    public function test_order_factory_creates_row(): void
    {
        $order = Order::factory()->create();
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_invoice_snapshot_factory_creates_row(): void
    {
        $snapshot = InvoiceSnapshot::factory()->create();
        $this->assertDatabaseHas('invoice_snapshots', ['invoice_number' => $snapshot->invoice_number]);
    }

    public function test_download_log_factory_creates_row(): void
    {
        $log = DownloadLog::factory()->create();
        $this->assertDatabaseHas('download_logs', ['id' => $log->id]);
    }

    public function test_payout_pool_factory_creates_row(): void
    {
        $pool = PayoutPool::factory()->create();
        $this->assertDatabaseHas('payout_pools', ['id' => $pool->id]);
    }

    public function test_photographer_statement_factory_creates_row(): void
    {
        $statement = PhotographerStatement::factory()->create();
        $this->assertDatabaseHas('photographer_statements', ['id' => $statement->id]);
    }

    public function test_contract_factory_creates_row(): void
    {
        $contract = Contract::factory()->create();
        $this->assertDatabaseHas('contracts', ['id' => $contract->id]);
        $this->assertNotEmpty($contract->items);
        $this->assertIsArray($contract->available_roles);
    }

    public function test_contract_signer_factory_creates_row(): void
    {
        $signer = ContractSigner::factory()->create();
        $this->assertDatabaseHas('contract_signers', ['id' => $signer->id]);
        $this->assertIsArray($signer->roles);
    }

    public function test_contract_audit_log_factory_creates_row(): void
    {
        $log = ContractAuditLog::factory()->create();
        $this->assertDatabaseHas('contract_audit_logs', ['id' => $log->id]);
    }
}
