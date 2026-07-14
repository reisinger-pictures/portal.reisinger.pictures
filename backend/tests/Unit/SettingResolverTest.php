<?php
namespace Tests\Unit;

use App\Enums\Brand;
use App\Models\Setting;
use App\Services\SettingResolver;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingResolverTest extends TestCase
{
    use RefreshDatabase;

    private SettingResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(SettingResolver::class);
    }

    public function test_set_stores_brand_scoped_key(): void
    {
        BrandRegistry::set(Brand::B2B);
        $this->resolver->set('foo', 'bar');

        $this->assertSame('bar', Setting::where('key', 'foo')->where('brand', 'rp')->value('value'));
    }

    public function test_set_stores_unprefixed_key(): void
    {
        BrandRegistry::set(Brand::B2B);
        $this->resolver->set('bank_holder', 'B2B GmbH');

        $this->assertSame('B2B GmbH', Setting::where('key', 'bank_holder')->value('value'));
    }

    public function test_get_reads_brand_scoped_row(): void
    {
        BrandRegistry::set(Brand::B2B);
        Setting::updateOrCreate(['key' => 'foo', 'brand' => 'rp'], ['value' => 'B2B value']);

        $this->assertSame('B2B value', $this->resolver->get('foo'));
    }

    public function test_get_returns_default_when_nothing_found(): void
    {
        BrandRegistry::set(Brand::B2B);
        $this->assertSame('fallback', $this->resolver->get('nonexistent', 'fallback'));
    }

    public function test_getRaw_ignores_brand_prefix(): void
    {
        BrandRegistry::set(Brand::B2B);
        Setting::updateOrCreate(['key' => 'global_key'], ['value' => 'global_value']);

        $this->assertSame('global_value', $this->resolver->getRaw('global_key'));
    }

    public function test_symmetry_read_after_write(): void
    {
        BrandRegistry::set(Brand::B2B);
        $this->resolver->set('watermark_opacity', '0.75');

        $this->assertSame('0.75', $this->resolver->get('watermark_opacity'));
    }
}
