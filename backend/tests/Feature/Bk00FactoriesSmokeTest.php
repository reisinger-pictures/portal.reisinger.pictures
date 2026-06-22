<?php

namespace Tests\Feature;

use App\Models\DownloadLog;
use App\Models\InvoiceSnapshot;
use App\Models\LicenseModifier;
use App\Models\LicenseUseCase;
use App\Models\Order;
use App\Models\PayoutPool;
use App\Models\PhotographerStatement;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BK-00 Smoke-Test: stellt sicher, dass jede neue Factory in einer frischen
 * Test-DB lauffähig ist und alle non-nullable Spalten beliefert.
 *
 * Nach Auswahl im Review ggf. durch spezifischere BK-01..10-Tests abzulösen.
 */
class Bk00FactoriesSmokeTest extends TestCase
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
}
