<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Support\BrandRegistry;
use App\Values\BrandConfig;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class BrandQueueResetTest extends TestCase
{
    /**
     * The Queue::before() callback registered in AppServiceProvider calls
     * BrandRegistry::reset() before every queue job. This simulates the lifecycle
     * of two sequential jobs processed by a long-lived queue worker.
     */
    public function test_brand_reset_lifecycle_between_jobs(): void
    {
        // ── Job 1: test-brand context ──
        $testBrand = new BrandConfig(
            id: 'test-brand',
            name: 'Test Brand',
            theme: 'rp',
            portalName: 'Test Portal',
            impressumUrl: null,
            logoPath: null,
            features: [],
            hostnames: [],
            isActive: true,
        );
        BrandRegistry::set($testBrand);
        $this->assertSame('test-brand', BrandRegistry::config()?->id);

        // ── Queue::before() fires before next job ──
        BrandRegistry::reset();
        $this->assertNull(BrandRegistry::current());

        // ── Job 2: B2B context (no stale test-brand) ──
        BrandRegistry::set(Brand::B2B);
        $this->assertSame(Brand::B2B, BrandRegistry::current());
    }

    public function test_brand_reset_allows_job_to_omit_brand_reconstruction(): void
    {
        // ── Job 1: sets brand ──
        BrandRegistry::set(Brand::B2B);

        // ── Queue::before() fires before next job ──
        BrandRegistry::reset();

        // ── Job 2: does NOT set brand (e.g. DeletePhotoFilesJob) ──
        // BrandRegistry::currentOrDefault() must safely fall back to B2B.
        $this->assertNull(BrandRegistry::current());
        $this->assertSame(Brand::B2B, BrandRegistry::currentOrDefault());
    }

    public function test_consecutive_brand_sets_after_reset(): void
    {
        $testBrand = new BrandConfig(
            id: 'test-brand',
            name: 'Test Brand',
            theme: 'rp',
            portalName: 'Test Portal',
            impressumUrl: null,
            logoPath: null,
            features: [],
            hostnames: [],
            isActive: true,
        );
        BrandRegistry::set(Brand::B2B);
        BrandRegistry::reset();
        BrandRegistry::set($testBrand);
        BrandRegistry::reset();
        BrandRegistry::set(Brand::B2B);

        $this->assertSame(Brand::B2B, BrandRegistry::current());
    }
}
