<?php
namespace Tests\Unit;

use App\Models\Setting;
use App\Services\SettingResolver;
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

    public function test_isAtr_returns_false_for_b2b_brand(): void
    {
        config(['app.brand' => 'rp']);
        $this->assertFalse($this->resolver->isAtr());
    }

    public function test_isAtr_returns_true_for_atr_brand(): void
    {
        config(['app.brand' => 'atr']);
        $this->assertTrue($this->resolver->isAtr());
    }

    public function test_prefix_returns_key_as_is_for_b2b(): void
    {
        config(['app.brand' => 'rp']);
        $this->assertSame('bank_holder', $this->resolver->prefix('bank_holder'));
        $this->assertSame('base_price', $this->resolver->prefix('base_price'));
        $this->assertSame('atr_base_price', $this->resolver->prefix('atr_base_price'));
    }

    public function test_prefix_adds_atr_prefix_for_atr_brand(): void
    {
        config(['app.brand' => 'atr']);
        $this->assertSame('atr_bank_holder', $this->resolver->prefix('bank_holder'));
        $this->assertSame('atr_watermark_opacity', $this->resolver->prefix('watermark_opacity'));
    }

    public function test_prefix_avoids_double_prefixing_for_atr_brand(): void
    {
        config(['app.brand' => 'atr']);
        $this->assertSame('atr_base_price', $this->resolver->prefix('atr_base_price'));
        $this->assertSame('atr_bank_iban', $this->resolver->prefix('atr_bank_iban'));
        $this->assertSame('atr_setup_fee', $this->resolver->prefix('atr_setup_fee'));
    }

    public function test_set_stores_prefixed_key_for_atr(): void
    {
        config(['app.brand' => 'atr']);
        $this->resolver->set('bank_holder', 'ATR GmbH');

        $this->assertNull(Setting::where('key', 'bank_holder')->value('value'));
        $this->assertSame('ATR GmbH', Setting::where('key', 'atr_bank_holder')->value('value'));
    }

    public function test_set_stores_unprefixed_key_for_b2b(): void
    {
        config(['app.brand' => 'rp']);
        $this->resolver->set('bank_holder', 'B2B GmbH');

        $this->assertSame('B2B GmbH', Setting::where('key', 'bank_holder')->value('value'));
        $this->assertNull(Setting::where('key', 'atr_bank_holder')->value('value'));
    }

    public function test_set_avoids_double_prefix_for_already_prefixed_key(): void
    {
        config(['app.brand' => 'atr']);
        $this->resolver->set('atr_base_price', '500');

        $this->assertSame('500', Setting::where('key', 'atr_base_price')->value('value'));
        $this->assertNull(Setting::where('key', 'atr_atr_base_price')->value('value'));
    }

    public function test_get_reads_prefixed_first_with_fallback_for_atr(): void
    {
        config(['app.brand' => 'atr']);
        Setting::updateOrCreate(['key' => 'bank_holder'], ['value' => 'B2B GmbH']);
        Setting::updateOrCreate(['key' => 'atr_bank_holder'], ['value' => 'ATR GmbH']);

        $this->assertSame('ATR GmbH', $this->resolver->get('bank_holder'));
    }

    public function test_get_falls_back_to_unprefixed_for_atr(): void
    {
        config(['app.brand' => 'atr']);
        Setting::updateOrCreate(['key' => 'bank_holder'], ['value' => 'B2B GmbH']);

        $this->assertSame('B2B GmbH', $this->resolver->get('bank_holder'));
    }

    public function test_get_returns_default_when_nothing_found(): void
    {
        config(['app.brand' => 'atr']);
        $this->assertSame('fallback', $this->resolver->get('nonexistent', 'fallback'));
    }

    public function test_get_returns_prefixed_value_for_b2b(): void
    {
        config(['app.brand' => 'rp']);
        Setting::updateOrCreate(['key' => 'bank_holder'], ['value' => 'B2B GmbH']);

        $this->assertSame('B2B GmbH', $this->resolver->get('bank_holder'));
    }

    public function test_getRaw_ignores_brand_prefix(): void
    {
        config(['app.brand' => 'atr']);
        Setting::updateOrCreate(['key' => 'global_key'], ['value' => 'global_value']);

        $this->assertSame('global_value', $this->resolver->getRaw('global_key'));
    }

    public function test_symmetry_read_after_write_for_atr(): void
    {
        config(['app.brand' => 'atr']);
        $this->resolver->set('watermark_opacity', '0.75');

        $this->assertSame('0.75', $this->resolver->get('watermark_opacity'));
        $this->assertSame('0.75', Setting::where('key', 'atr_watermark_opacity')->value('value'));
    }

    public function test_symmetry_read_after_write_for_b2b(): void
    {
        config(['app.brand' => 'rp']);
        $this->resolver->set('watermark_opacity', '0.50');

        $this->assertSame('0.50', $this->resolver->get('watermark_opacity'));
        $this->assertSame('0.50', Setting::where('key', 'watermark_opacity')->value('value'));
    }
}
