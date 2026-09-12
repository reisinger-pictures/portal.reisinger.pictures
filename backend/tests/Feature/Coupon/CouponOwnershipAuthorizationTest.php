<?php

namespace Tests\Feature\Coupon;

use App\Enums\UserRole;
use App\Http\Middleware\BrandContextMiddleware;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for P0-B5: a photographer must only create/list gallery- or
 * group-scoped coupons for galleries/groups they actually manage.
 */
class CouponOwnershipAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(BrandContextMiddleware::class);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function photographer(): array
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        return [$user, auth('api')->login($user)];
    }

    private function payload(): array
    {
        return [
            'code' => 'GCPN'.random_int(100000, 999999),
            'type' => 'fixed',
            'value' => 5,
            'active' => true,
        ];
    }

    public function test_photographer_denied_foreign_gallery_coupon_creation(): void
    {
        $gallery = Gallery::factory()->create(['restricted_photographers' => true]);
        [, $token] = $this->photographer();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/galleries/{$gallery->id}/coupons", $this->payload())
            ->assertStatus(403);

        $this->assertDatabaseCount('coupons', 0);
    }

    public function test_photographer_can_create_coupon_for_assigned_gallery(): void
    {
        $gallery = Gallery::factory()->create(['restricted_photographers' => true]);
        [$user, $token] = $this->photographer();
        $user->photographerGalleries()->attach($gallery->id);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/galleries/{$gallery->id}/coupons", $this->payload())
            ->assertStatus(201);

        $this->assertDatabaseCount('coupons', 1);
    }

    public function test_photographer_denied_foreign_group_coupon_creation(): void
    {
        $group = GalleryGroup::factory()->create();
        [, $token] = $this->photographer();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/gallery-groups/{$group->id}/coupons", $this->payload())
            ->assertStatus(403);

        $this->assertDatabaseCount('coupons', 0);
    }

    public function test_photographer_can_create_coupon_for_assigned_group(): void
    {
        $group = GalleryGroup::factory()->create();
        [$user, $token] = $this->photographer();
        $user->photographerGalleryGroups()->attach($group->id);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/gallery-groups/{$group->id}/coupons", $this->payload())
            ->assertStatus(201);

        $this->assertDatabaseCount('coupons', 1);
    }
}
