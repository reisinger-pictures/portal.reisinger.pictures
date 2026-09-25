<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: a brand-bound user must not be able to attach a gallery to a
 * gallery group or org belonging to another brand. `gallery_group_id` and
 * `org_ids` were previously only `exists`-validated.
 *
 * P1-M15 extends this to the *result*: a gallery and its group must always
 * carry the same brand. These tests assert the coherence of the persisted
 * pair, not only the HTTP status.
 */
class BrandScopingGalleryValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
    }

    private function tokenFor(UserRole $role, ?string $brand): string
    {
        $user = User::factory()->create(['brand' => $brand]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role->value]));

        return auth('api')->login($user);
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Brand Scoping Gallery',
            'type' => 'delivery',
        ], $overrides);
    }

    public function test_create_rejects_gallery_group_from_other_brand(): void
    {
        $token = $this->tokenFor(UserRole::PHOTOGRAPHER, 'srp');
        $foreignGroup = GalleryGroup::factory()->create(['brand' => 'rp']);

        $response = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/galleries', $this->payload(['gallery_group_id' => $foreignGroup->id]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('gallery_group_id');
        $this->assertDatabaseMissing('galleries', ['gallery_group_id' => $foreignGroup->id]);
    }

    public function test_create_accepts_gallery_group_from_same_brand(): void
    {
        $token = $this->tokenFor(UserRole::PHOTOGRAPHER, 'srp');
        $ownGroup = GalleryGroup::factory()->create(['brand' => 'srp']);

        $response = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/galleries', $this->payload(['gallery_group_id' => $ownGroup->id]));

        $response->assertStatus(200);

        $gallery = Gallery::where('gallery_group_id', $ownGroup->id)->firstOrFail();
        $this->assertSame('srp', $this->brandId($gallery));
        $this->assertSame($this->brandId($ownGroup), $this->brandId($gallery));
    }

    public function test_create_rejects_org_from_other_brand(): void
    {
        $token = $this->tokenFor(UserRole::PHOTOGRAPHER, 'srp');
        $foreignOrg = Org::factory()->create(['brand' => 'rp']);

        $response = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/galleries', $this->payload(['org_ids' => [$foreignOrg->id]]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('org_ids.0');
    }

    public function test_create_accepts_org_from_same_brand(): void
    {
        $token = $this->tokenFor(UserRole::PHOTOGRAPHER, 'srp');
        $ownOrg = Org::factory()->create(['brand' => 'srp']);

        $response = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/galleries', $this->payload(['org_ids' => [$ownOrg->id]]));

        $response->assertStatus(200);
    }

    public function test_cross_brand_super_admin_reference_stays_coherent_with_the_group_brand(): void
    {
        $token = $this->tokenFor(UserRole::SUPER_ADMIN, null);
        $ownBrandGroup = GalleryGroup::factory()->create(['brand' => 'rp']);

        $response = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/galleries', $this->payload(['gallery_group_id' => $ownBrandGroup->id]));

        $response->assertStatus(200);

        $gallery = Gallery::where('gallery_group_id', $ownBrandGroup->id)->firstOrFail();
        $this->assertSame(Brand::B2B->value, $this->brandId($gallery));
        $this->assertSame($this->brandId($ownBrandGroup), $this->brandId($gallery));
    }

    /**
     * P1-M15, chosen variant: a trusted cross-brand Super-Admin keeps its
     * all-brand reach, but the resulting gallery adopts the *group's* brand
     * instead of the request host brand. The previous behavior persisted a
     * mismatched pair (host brand on the gallery, foreign brand on the group).
     */
    public function test_cross_brand_super_admin_create_adopts_the_foreign_group_brand(): void
    {
        $token = $this->tokenFor(UserRole::SUPER_ADMIN, null);
        $foreignGroup = GalleryGroup::factory()->create(['brand' => 'srp']);

        $response = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/galleries', $this->payload([
                'name' => 'Cross brand gallery',
                'gallery_group_id' => $foreignGroup->id,
            ]));

        $response->assertStatus(200);
        $response->assertJsonPath('gallery.id', Gallery::where('gallery_group_id', $foreignGroup->id)->value('id'));

        $gallery = Gallery::where('gallery_group_id', $foreignGroup->id)->firstOrFail();
        $this->assertSame('srp', $this->brandId($gallery));
        $this->assertSame($this->brandId($foreignGroup), $this->brandId($gallery));
        $this->assertNotSame(Brand::B2B->value, $this->brandId($gallery));
    }

    /**
     * The `AsBrand` cast yields a `Brand` enum for configured ids and the raw
     * string for a brand id that is not in the enum (e.g. the legacy `srp`
     * fixture). Normalize once so the assertions stay representation-agnostic.
     */
    private function brandId(Model $model): ?string
    {
        return BrandRegistry::normalizeId($model->brand);
    }
}
