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

    public function test_isSrp_returns_false_for_b2b_brand(): void
    {
        config(['app.brand' => 'rp']);
        $this->assertFalse($this->resolver->isSrp());
    }

    public function test_isSrp_returns_true_for_srp_brand(): void
    {
        config(['app.brand' => 'srp']);
        $this->assertTrue($this->resolver->isSrp());
    }

    public function test_prefix_returns_key_as_is_for_b2b(): void
    {
        config(['app.brand' => 'rp']);
        $this->assertSame('bank_holder', $this->resolver->prefix('bank_holder'));
        $this->assertSame('base_price', $this->resolver->prefix('base_price'));
        $this->assertSame('srp_base_price', $this->resolver->prefix('srp_base_price'));
    }

    public function test_prefix_avoids_double_prefixing_for_srp_brand(): void
    {
        config(['app.brand' => 'srp']);
        $this->assertSame('srp_base_price', $this->resolver->prefix('srp_base_price'));
        $this->assertSame('srp_bank_iban', $this->resolver->prefix('srp_bank_iban'));
        $this->assertSame('srp_setup_fee', $this->resolver->prefix('srp_setup_fee'));
    }

    public function test_set_stores_brand_scoped_key_for_srp(): void
    {
        config(['app.brand' => 'srp']);
        $this->resolver->set('foo', 'bar');

        // Stored unprefixed under the SRP brand column (no srp_ key prefixing).
        $this->assertSame('bar', Setting::where('key', 'foo')->where('brand', 'srp')->value('value'));
        $this->assertNull(Setting::where('key', 'foo')->where('brand', 'rp')->value('value'));
    }

    public function test_set_stores_unprefixed_key_for_b2b(): void
    {
        config(['app.brand' => 'rp']);
        $this->resolver->set('bank_holder', 'B2B GmbH');

        $this->assertSame('B2B GmbH', Setting::where('key', 'bank_holder')->value('value'));
        $this->assertNull(Setting::where('key', 'srp_bank_holder')->value('value'));
    }

    public function test_set_avoids_double_prefix_for_already_prefixed_key(): void
    {
        config(['app.brand' => 'srp']);
        $this->resolver->set('srp_base_price', '500');

        $this->assertSame('500', Setting::where('key', 'srp_base_price')->value('value'));
        $this->assertNull(Setting::where('key', 'srp_srp_base_price')->value('value'));
    }

    public function test_get_reads_brand_scoped_row_with_b2b_fallback_for_srp(): void
    {
        config(['app.brand' => 'srp']);
        // B2B ('rp') row serves as fallback; SRP brand row takes precedence.
        Setting::updateOrCreate(['key' => 'foo', 'brand' => 'rp'], ['value' => 'B2B value']);
        Setting::updateOrCreate(['key' => 'foo', 'brand' => 'srp'], ['value' => 'SRP value']);

        $this->assertSame('SRP value', $this->resolver->get('foo'));
    }

    public function test_get_falls_back_to_unprefixed_for_srp(): void
    {
        config(['app.brand' => 'srp']);
        Setting::updateOrCreate(['key' => 'bank_holder'], ['value' => 'B2B GmbH']);

        $this->assertSame('B2B GmbH', $this->resolver->get('bank_holder'));
    }

    public function test_get_returns_default_when_nothing_found(): void
    {
        config(['app.brand' => 'srp']);
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
        config(['app.brand' => 'srp']);
        Setting::updateOrCreate(['key' => 'global_key'], ['value' => 'global_value']);

        $this->assertSame('global_value', $this->resolver->getRaw('global_key'));
    }

    public function test_symmetry_read_after_write_is_brand_isolated_for_srp(): void
    {
        config(['app.brand' => 'srp']);
        $this->resolver->set('watermark_opacity', '0.75');

        // Read under SRP returns the SRP row.
        $this->assertSame('0.75', $this->resolver->get('watermark_opacity'));

        // Read under B2B does NOT see the SRP row (brand isolation).
        config(['app.brand' => 'rp']);
        $this->assertNotSame('0.75', $this->resolver->get('watermark_opacity'));
        $this->assertSame('0.75', Setting::where('key', 'watermark_opacity')->where('brand', 'srp')->value('value'));
    }

    public function test_symmetry_read_after_write_for_b2b(): void
    {
        config(['app.brand' => 'rp']);
        $this->resolver->set('watermark_opacity', '0.50');

        $this->assertSame('0.50', $this->resolver->get('watermark_opacity'));
        $this->assertSame('0.50', Setting::where('key', 'watermark_opacity')->value('value'));
    }
}
