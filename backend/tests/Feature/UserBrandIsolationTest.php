<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Role;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Brand isolation for the user-management endpoints.
 *
 * Trust model: only a cross-brand actor (`brand === null`, e.g. super_admin) may
 * act across brands. Every brand-bound actor (including `admin`) is isolated to
 * their own brand. The brand column is a plain string, so tests use a second
 * brand value (`srp`) that is intentionally NOT part of config('brands').
 */
class UserBrandIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const OTHER_BRAND = 'srp';

    protected function tearDown(): void
    {
        // A test may temporarily register a second brand in config; clear the
        // static brand-config cache so it cannot leak into other tests.
        BrandRegistry::clearCache();
        parent::tearDown();
    }

    private function role(UserRole $role): Role
    {
        return Role::firstOrCreate(['name' => $role->value]);
    }

    private function tokenFor(UserRole $role, ?string $brand): string
    {
        $user = User::factory()->create(['brand' => $brand]);
        $user->roles()->attach($this->role($role));

        return auth('api')->login($user);
    }

    public function test_brand_bound_admin_cannot_update_foreign_brand_user(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN, 'rp');
        $target = User::factory()->create(['brand' => self::OTHER_BRAND, 'flatrate_level' => 'none']);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/users/{$target->id}", ['flatrate_level' => 'web'])
            ->assertStatus(403);

        $this->assertEquals('none', $target->fresh()->flatrate_level);
    }

    public function test_brand_bound_admin_cannot_delete_foreign_brand_user(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN, 'rp');
        $target = User::factory()->create(['brand' => self::OTHER_BRAND]);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->deleteJson("/api/management/users/{$target->id}")
            ->assertStatus(403);

        $this->assertModelExists($target);
    }

    public function test_brand_bound_admin_cannot_manage_brandless_user(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN, 'rp');
        $target = User::factory()->create(['brand' => null]);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/users/{$target->id}", ['flatrate_level' => 'web'])
            ->assertStatus(403);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->deleteJson("/api/management/users/{$target->id}")
            ->assertStatus(403);

        $this->assertModelExists($target);
    }

    public function test_cross_brand_admin_can_update_foreign_brand_user(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN, null);
        $target = User::factory()->create(['brand' => self::OTHER_BRAND, 'flatrate_level' => 'none']);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/users/{$target->id}", ['flatrate_level' => 'web'])
            ->assertOk();

        $this->assertEquals('web', $target->fresh()->flatrate_level);
    }

    public function test_brand_bound_admin_cannot_assign_foreign_brand_gallery(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN, 'rp');
        $target = User::factory()->create(['brand' => 'rp']);
        $foreignGallery = Gallery::factory()->create(['brand' => self::OTHER_BRAND]);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/users/{$target->id}", [
                'gallery_ids' => [$foreignGallery->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('gallery_ids');

        $this->assertDatabaseMissing('user_galleries', [
            'user_id' => $target->id,
            'gallery_id' => $foreignGallery->id,
        ]);
    }

    public function test_brand_bound_admin_cannot_assign_brandless_gallery(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN, 'rp');
        $target = User::factory()->create(['brand' => 'rp']);
        $legacyGallery = Gallery::factory()->create(['brand' => null]);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/users/{$target->id}", [
                'gallery_ids' => [$legacyGallery->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('gallery_ids');
    }

    public function test_brand_bound_admin_cannot_assign_foreign_brand_gallery_group(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN, 'rp');
        $target = User::factory()->create(['brand' => 'rp']);
        $foreignGroup = GalleryGroup::factory()->create(['brand' => self::OTHER_BRAND]);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/users/{$target->id}", [
                'gallery_group_ids' => [$foreignGroup->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('gallery_group_ids');

        $this->assertDatabaseMissing('user_gallery_groups', [
            'user_id' => $target->id,
            'gallery_group_id' => $foreignGroup->id,
        ]);
    }

    public function test_brand_bound_admin_can_assign_same_brand_gallery_and_group(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN, 'rp');
        $target = User::factory()->create(['brand' => 'rp']);
        $gallery = Gallery::factory()->create(['brand' => 'rp']);
        $group = GalleryGroup::factory()->create(['brand' => 'rp']);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/users/{$target->id}", [
                'gallery_ids' => [$gallery->id],
                'gallery_group_ids' => [$group->id],
            ])
            ->assertOk();

        $this->assertDatabaseHas('user_galleries', [
            'user_id' => $target->id,
            'gallery_id' => $gallery->id,
        ]);
        $this->assertDatabaseHas('user_gallery_groups', [
            'user_id' => $target->id,
            'gallery_group_id' => $group->id,
        ]);
    }

    public function test_brand_bound_admin_cannot_assign_foreign_brand(): void
    {
        // The second brand must be a configured brand for the request rule to accept it;
        // the brand-isolation guard is what must reject the cross-brand assignment.
        config(['brands.srp' => ['name' => 'Second Brand', 'theme' => 'rp', 'is_active' => true]]);

        $token = $this->tokenFor(UserRole::ADMIN, 'rp');
        $target = User::factory()->create(['brand' => 'rp']);
        $clientRole = $this->role(UserRole::CLIENT);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/users/{$target->id}", [
                'role_ids' => [$clientRole->id],
                'brand' => self::OTHER_BRAND,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('brand');

        $this->assertEquals('rp', $target->fresh()->brand->value);
    }

    public function test_brand_bound_admin_index_hides_foreign_brand_users(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN, 'rp');
        $ownBrandUser = User::factory()->create(['brand' => 'rp']);
        $foreignBrandUser = User::factory()->create(['brand' => self::OTHER_BRAND]);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/users')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($ownBrandUser->id));
        $this->assertFalse($ids->contains($foreignBrandUser->id));
    }
}
