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

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::clearCache();
    }

    public function test_fromHost_falls_back_to_b2b_for_former_srp_domain(): void
    {
        $this->assertSame(Brand::B2B, BrandRegistry::fromHost('buy.reisinger.pictures'));
    }

    public function test_fromHost_resolves_via_dev_fallback(): void
    {
        $this->assertSame(Brand::B2B, BrandRegistry::fromHost('srp.localhost'));
    }

    public function test_fromHost_defaults_to_b2b_for_unknown_hosts(): void
    {
        $this->assertSame(Brand::B2B, BrandRegistry::fromHost('portal.reisinger.pictures'));
        $this->assertSame(Brand::B2B, BrandRegistry::fromHost('portal.localhost'));
        $this->assertSame(Brand::B2B, BrandRegistry::fromHost('buy.localhost'));
        $this->assertSame(Brand::B2B, BrandRegistry::fromHost('portal.test'));
        $this->assertSame(Brand::B2B, BrandRegistry::fromHost('example.com'));
    }

    public function test_current_returns_null_when_unset(): void
    {
        BrandRegistry::set(null);
        $this->assertNull(BrandRegistry::current());
    }

    public function test_currentOrDefault_falls_back_to_b2b_when_unset(): void
    {
        BrandRegistry::set(null);
        $this->assertSame(Brand::B2B, BrandRegistry::currentOrDefault());
    }

    public function test_current_returns_brand_from_container(): void
    {
        BrandRegistry::set(Brand::B2B);
        $this->assertSame(Brand::B2B, BrandRegistry::current());
    }

    public function test_prefix_defaults_to_empty_when_unset(): void
    {
        BrandRegistry::set(null);
        $this->assertSame('', BrandRegistry::prefix());
    }

    public function test_set_with_null_clears_brand(): void
    {
        BrandRegistry::set(Brand::B2B);
        BrandRegistry::set(null);
        $this->assertNull(BrandRegistry::current());
    }

    public function test_reset_clears_brand_to_null(): void
    {
        BrandRegistry::set(Brand::B2B);
        BrandRegistry::reset();
        $this->assertNull(BrandRegistry::current());

        BrandRegistry::set(Brand::B2B);
        BrandRegistry::reset();
        $this->assertNull(BrandRegistry::current());
    }

    public function test_resolveFromOrder_uses_persisted_brand(): void
    {
        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
            'brand' => Brand::B2B->value,
            'total_amount' => 100,
        ]);
        $this->assertSame(Brand::B2B, BrandRegistry::resolveFromOrder($order));
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

    public function test_enum_prefix(): void
    {
        $this->assertSame('', Brand::B2B->prefix());
    }

    public function test_enum_id(): void
    {
        $this->assertSame('rp', Brand::B2B->id());
    }

    public function test_enum_domain(): void
    {
        $this->assertSame('portal.reisinger.pictures', Brand::B2B->domain());
    }
}
