<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractAuditLog;
use App\Models\ContractSigner;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FactoryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('factoryProvider')]
    public function test_factory_creates_row(string $modelClass, string $assertField): void
    {
        $model = $modelClass::factory()->create();
        $table = (new $modelClass)->getTable();

        $this->assertDatabaseHas($table, [$assertField => $model->$assertField]);

        if ($model instanceof Contract) {
            $this->assertNotEmpty($model->items);
            $this->assertIsArray($model->available_roles);
        }

        if ($model instanceof ContractSigner) {
            $this->assertIsArray($model->roles);
        }
    }

    public static function factoryProvider(): array
    {
        return [
            'Product'               => [Product::class, 'id'],
            'Setting'               => [Setting::class, 'key'],
            'LicenseUseCase'        => [LicenseUseCase::class, 'id'],
            'LicenseModifier'       => [LicenseModifier::class, 'id'],
            'Tenant'                => [Tenant::class, 'id'],
            'Order'                 => [Order::class, 'id'],
            'InvoiceSnapshot'       => [InvoiceSnapshot::class, 'invoice_number'],
            'DownloadLog'           => [DownloadLog::class, 'id'],
            'PayoutPool'            => [PayoutPool::class, 'id'],
            'PhotographerStatement' => [PhotographerStatement::class, 'id'],
            'Contract'              => [Contract::class, 'id'],
            'ContractSigner'        => [ContractSigner::class, 'id'],
            'ContractAuditLog'      => [ContractAuditLog::class, 'id'],
        ];
    }
}
