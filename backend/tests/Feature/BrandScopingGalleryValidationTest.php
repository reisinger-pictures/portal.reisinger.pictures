<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\GalleryGroup;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: a brand-bound user must not be able to attach a gallery to a
 * gallery group or org belonging to another brand. `gallery_group_id` and
 * `org_ids` were previously only `exists`-validated.
 */
class BrandScopingGalleryValidationTest extends TestCase
{
    use RefreshDatabase;

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
    }

    public function test_create_accepts_gallery_group_from_same_brand(): void
    {
        $token = $this->tokenFor(UserRole::PHOTOGRAPHER, 'srp');
        $ownGroup = GalleryGroup::factory()->create(['brand' => 'srp']);

        $response = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/galleries', $this->payload(['gallery_group_id' => $ownGroup->id]));

        $response->assertStatus(200);
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

    public function test_cross_brand_user_can_reference_any_brand_group(): void
    {
        $token = $this->tokenFor(UserRole::SUPER_ADMIN, null);
        $foreignGroup = GalleryGroup::factory()->create(['brand' => 'rp']);

        $response = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/galleries', $this->payload(['gallery_group_id' => $foreignGroup->id]));

        $response->assertStatus(200);
    }
}
