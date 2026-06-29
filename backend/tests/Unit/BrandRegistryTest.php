<?php
namespace Tests\Unit;

use App\Enums\Brand;
use App\Models\Order;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_fromHost_resolves_atr_production_domain(): void
    {
        $this->assertSame(Brand::ATR, BrandRegistry::fromHost('portal.all-the.rest'));
        $this->assertSame(Brand::ATR, BrandRegistry::fromHost('all-the.rest'));
    }

    public function test_fromHost_resolves_atr_local_dev_host(): void
    {
        $this->assertSame(Brand::ATR, BrandRegistry::fromHost(BrandRegistry::ATR_DEV_HOST));
    }

    public function test_fromHost_defaults_to_b2b_for_unknown_hosts(): void
    {
        $this->assertSame(Brand::B2B, BrandRegistry::fromHost('portal.reisinger.pictures'));
        $this->assertSame(Brand::B2B, BrandRegistry::fromHost('portal.test'));
        $this->assertSame(Brand::B2B, BrandRegistry::fromHost('example.com'));
    }

    public function test_current_returns_null_when_unset(): void
    {
        config(['app.brand' => null]);
        $this->assertNull(BrandRegistry::current());
    }

    public function test_currentOrDefault_falls_back_to_b2b_when_unset(): void
    {
        config(['app.brand' => null]);
        $this->assertSame(Brand::B2B, BrandRegistry::currentOrDefault());
    }

    public function test_current_returns_brand_from_config(): void
    {
        BrandRegistry::set(Brand::ATR);
        $this->assertSame(Brand::ATR, BrandRegistry::current());
        $this->assertSame(Brand::ATR->value, config('app.brand'));
    }

    public function test_isAtr_and_prefix(): void
    {
        BrandRegistry::set(Brand::ATR);
        $this->assertTrue(BrandRegistry::isAtr());
        $this->assertSame('atr_', BrandRegistry::prefix());

        BrandRegistry::set(Brand::B2B);
        $this->assertFalse(BrandRegistry::isAtr());
        $this->assertSame('', BrandRegistry::prefix());
    }

    public function test_prefix_defaults_to_empty_when_unset(): void
    {
        config(['app.brand' => null]);
        $this->assertSame('', BrandRegistry::prefix());
    }

    public function test_set_with_null_clears_config(): void
    {
        BrandRegistry::set(Brand::ATR);
        BrandRegistry::set(null);
        $this->assertNull(config('app.brand'));
    }

    public function test_resolveFromOrder_uses_persisted_brand(): void
    {
        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
            'brand' => Brand::ATR->value,
            'total_amount' => 100,
        ]);
        $this->assertSame(Brand::ATR, BrandRegistry::resolveFromOrder($order));
    }

    public function test_resolveFromOrder_falls_back_to_b2b_for_null_brand(): void
    {
        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
            'brand' => null,
            'total_amount' => 100,
        ]);
        $this->assertSame(Brand::B2B, BrandRegistry::resolveFromOrder($order));
    }

    public function test_enum_label_and_domain_and_prefix(): void
    {
        $this->assertSame('all-the.rest', Brand::ATR->domain());
        $this->assertSame('reisinger.pictures', Brand::B2B->domain());
        $this->assertSame('atr_', Brand::ATR->prefix());
        $this->assertSame('', Brand::B2B->prefix());
    }
}
