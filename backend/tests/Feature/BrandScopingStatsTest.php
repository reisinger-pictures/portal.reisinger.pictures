<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\DownloadLog;
use App\Models\Gallery;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: statistics and download logs must be brand-scoped for
 * brand-bound admins (brand != null). Only cross-brand users (brand = null,
 * e.g. super_admin) may see all brands.
 */
class BrandScopingStatsTest extends TestCase
{
    use RefreshDatabase;

    private const OTHER_BRAND = 'srp';

    private function adminTokenForBrand(?string $brand): string
    {
        $user = User::factory()->create(['brand' => $brand]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        return auth('api')->login($user);
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_brand_bound_admin_stats_count_only_own_brand(): void
    {
        $token = $this->adminTokenForBrand(self::OTHER_BRAND);

        $ownGallery = Gallery::factory()->create(['brand' => self::OTHER_BRAND]);
        $foreignGallery = Gallery::factory()->create(['brand' => Brand::B2B->value]);

        DownloadLog::create(['gallery_id' => $ownGallery->id, 'item_type' => 'single_image', 'resolution_tier' => 'web']);
        DownloadLog::create(['gallery_id' => $foreignGallery->id, 'item_type' => 'single_image', 'resolution_tier' => 'web']);
        DownloadLog::create(['gallery_id' => $foreignGallery->id, 'item_type' => 'single_image', 'resolution_tier' => 'web']);

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/stats');

        $response->assertOk();
        $this->assertSame(1, $response->json('galleries_count'));
        $this->assertSame(1, $response->json('total_downloads'));
    }

    public function test_cross_brand_admin_stats_count_all_brands(): void
    {
        $token = $this->adminTokenForBrand(null);

        $ownGallery = Gallery::factory()->create(['brand' => self::OTHER_BRAND]);
        $foreignGallery = Gallery::factory()->create(['brand' => Brand::B2B->value]);

        DownloadLog::create(['gallery_id' => $ownGallery->id, 'item_type' => 'single_image', 'resolution_tier' => 'web']);
        DownloadLog::create(['gallery_id' => $foreignGallery->id, 'item_type' => 'single_image', 'resolution_tier' => 'web']);
        DownloadLog::create(['gallery_id' => $foreignGallery->id, 'item_type' => 'single_image', 'resolution_tier' => 'web']);

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/stats');

        $response->assertOk();
        $this->assertSame(2, $response->json('galleries_count'));
        $this->assertSame(3, $response->json('total_downloads'));
    }

    public function test_brand_bound_admin_logs_only_contain_own_brand(): void
    {
        $token = $this->adminTokenForBrand(self::OTHER_BRAND);

        $ownGallery = Gallery::factory()->create(['brand' => self::OTHER_BRAND]);
        $foreignGallery = Gallery::factory()->create(['brand' => Brand::B2B->value]);

        DownloadLog::create([
            'gallery_id' => $ownGallery->id,
            'gallery_name_snapshot' => 'OWN BRAND LOG',
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);
        DownloadLog::create([
            'gallery_id' => $foreignGallery->id,
            'gallery_name_snapshot' => 'FOREIGN BRAND LOG',
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/logs');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertSame('OWN BRAND LOG', $response->json('data.0.gallery_name_snapshot'));
    }

    public function test_cross_brand_admin_logs_contain_all_brands(): void
    {
        $token = $this->adminTokenForBrand(null);

        $ownGallery = Gallery::factory()->create(['brand' => self::OTHER_BRAND]);
        $foreignGallery = Gallery::factory()->create(['brand' => Brand::B2B->value]);

        DownloadLog::create(['gallery_id' => $ownGallery->id, 'item_type' => 'single_image', 'resolution_tier' => 'web']);
        DownloadLog::create(['gallery_id' => $foreignGallery->id, 'item_type' => 'single_image', 'resolution_tier' => 'web']);

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/logs');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }
}
