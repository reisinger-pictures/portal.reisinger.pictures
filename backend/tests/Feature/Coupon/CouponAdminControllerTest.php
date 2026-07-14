<?php

namespace Tests\Feature\Coupon;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Http\Middleware\BrandContextMiddleware;
use App\Models\Coupon;
use App\Models\CouponUserUsage;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Role;
use App\Models\Org;
use App\Models\User;
use App\Support\BrandRegistry;
use App\Values\BrandConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class CouponAdminControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(BrandContextMiddleware::class);
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

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->roles()->attach(
            Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value])
        );
        $this->token = JWTAuth::fromUser($this->superAdmin);
    }

    protected function tearDown(): void
    {
        BrandRegistry::set(null);
        parent::tearDown();
    }

    protected function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    // ──────────────────────────────────────────────
    //  CRUD Tests — Super Admin
    // ──────────────────────────────────────────────

    public function test_super_admin_can_create_coupon(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/management/coupons', [
                'code' => 'WELCOME10',
                'type' => 'percentage',
                'value' => 10,
                'scope_type' => 'global',
                'active' => true,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('coupons', [
            'code' => 'WELCOME10',
            'brand' => 'test-brand',
        ]);
    }

    public function test_super_admin_can_list_coupons(): void
    {
        Coupon::factory()->count(3)->create(['brand' => 'test-brand']);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/management/coupons');

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');
    }

    public function test_super_admin_can_update_coupon(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'test-brand',
            'code' => 'OLDCODE',
            'value' => 5,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->putJson('/api/management/coupons/' . $coupon->id, [
                'code' => 'NEWCODE',
                'type' => 'percentage',
                'value' => 15,
                'scope_type' => 'global',
                'active' => true,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $coupon->refresh();
        $this->assertSame('NEWCODE', $coupon->code);
        $this->assertSame(15.0, $coupon->value);
    }

    public function test_super_admin_can_delete_unused_coupon(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'test-brand',
            'code' => 'DELETE',
            'used_count' => 0,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->deleteJson('/api/management/coupons/' . $coupon->id);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertDatabaseMissing('coupons', ['id' => $coupon->id]);
    }

    public function test_super_admin_can_delete_used_coupon(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'test-brand',
            'code' => 'USED',
            'used_count' => 3,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->deleteJson('/api/management/coupons/' . $coupon->id);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertDatabaseMissing('coupons', ['id' => $coupon->id]);
    }

    // ──────────────────────────────────────────────
    //  CRUD Tests — Admin
    // ──────────────────────────────────────────────

    public function test_normal_admin_can_manage_coupons(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(
            Role::firstOrCreate(['name' => UserRole::ADMIN->value])
        );
        $token = auth('api')->login($admin);

        Coupon::factory()->count(2)->create(['brand' => 'test-brand']);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/management/coupons');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    public function test_admin_can_list_brand_coupons(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(
            Role::firstOrCreate(['name' => UserRole::ADMIN->value])
        );
        $token = auth('api')->login($admin);

        Coupon::factory()->count(3)->create(['brand' => 'test-brand']);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/management/coupons');

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');
        foreach ($response->json('data') as $coupon) {
            $this->assertSame('test-brand', $coupon['brand']);
        }
    }

    public function test_admin_cannot_see_rp_coupons_on_srp_host(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(
            Role::firstOrCreate(['name' => UserRole::ADMIN->value])
        );
        $token = auth('api')->login($admin);

        Coupon::factory()->count(2)->create(['brand' => 'test-brand']);
        Coupon::factory()->count(3)->create(['brand' => 'rp']);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/management/coupons');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    // ──────────────────────────────────────────────
    //  CRUD Tests — Photographer
    // ──────────────────────────────────────────────

    public function test_photographer_can_create_coupon_for_own_use(): void
    {
        $photographer = User::factory()->create();
        $photographer->roles()->attach(
            Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value])
        );
        $token = auth('api')->login($photographer);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/management/coupons', [
                'code' => 'PHOTO20',
                'type' => 'percentage',
                'value' => 20,
                'scope_type' => 'photographer',
                'active' => true,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('coupons', [
            'code' => 'PHOTO20',
            'created_by' => $photographer->id,
            'max_uses_global' => null,
        ]);
    }

    public function test_photographer_cannot_set_max_uses_global(): void
    {
        $photographer = User::factory()->create();
        $photographer->roles()->attach(
            Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value])
        );
        $token = auth('api')->login($photographer);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/management/coupons', [
                'code' => 'GLOBALX',
                'type' => 'percentage',
                'value' => 10,
                'scope_type' => 'photographer',
                'max_uses_global' => 5,
                'active' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_photographer_can_only_see_own_coupons(): void
    {
        $photographerA = User::factory()->create();
        $photographerA->roles()->attach(
            Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value])
        );
        $tokenA = auth('api')->login($photographerA);

        $createA = $this->withHeaders(['Authorization' => 'Bearer ' . $tokenA])
            ->postJson('/api/management/coupons', [
                'code' => 'PHOTO_A',
                'type' => 'fixed',
                'value' => 10,
                'scope_type' => 'photographer',
                'active' => true,
            ]);
        $createA->assertStatus(201);

        $photographerB = User::factory()->create();
        $photographerB->roles()->attach(
            Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value])
        );
        $tokenB = auth('api')->login($photographerB);

        $createB = $this->withHeaders(['Authorization' => 'Bearer ' . $tokenB])
            ->postJson('/api/management/coupons', [
                'code' => 'PHOTO_B',
                'type' => 'fixed',
                'value' => 20,
                'scope_type' => 'photographer',
                'active' => true,
            ]);
        $createB->assertStatus(201);

        auth('api')->logout();

        $this->assertDatabaseHas('coupons', ['code' => 'PHOTO_A', 'created_by' => $photographerA->id]);
        $this->assertDatabaseHas('coupons', ['code' => 'PHOTO_B', 'created_by' => $photographerB->id]);

        $responseA = $this->actingAs($photographerA, 'api')
            ->getJson('/api/management/coupons');

        $responseA->assertStatus(200);
        $responseA->assertJsonCount(1, 'data');
        $this->assertSame('PHOTO_A', $responseA->json('data.0.code'));

        $responseB = $this->actingAs($photographerB, 'api')
            ->getJson('/api/management/coupons');

        $responseB->assertStatus(200);
        $responseB->assertJsonCount(1, 'data');
        $this->assertSame('PHOTO_B', $responseB->json('data.0.code'));
    }

    public function test_photographer_cannot_update_others_coupon(): void
    {
        $photographerA = User::factory()->create();
        $photographerA->roles()->attach(
            Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value])
        );
        $tokenA = auth('api')->login($photographerA);

        $this->withHeaders(['Authorization' => 'Bearer ' . $tokenA])
            ->postJson('/api/management/coupons', [
                'code' => 'OWNED_BY_A',
                'type' => 'fixed',
                'value' => 15,
                'scope_type' => 'photographer',
                'active' => true,
            ]);

        $coupon = Coupon::where('code', 'OWNED_BY_A')->first();

        $photographerB = User::factory()->create();
        $photographerB->roles()->attach(
            Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value])
        );
        $tokenB = auth('api')->login($photographerB);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $tokenB])
            ->putJson('/api/management/coupons/' . $coupon->id, [
                'code' => 'OWNED_BY_B',
                'type' => 'fixed',
                'value' => 30,
                'scope_type' => 'photographer',
                'active' => true,
            ]);

        $response->assertStatus(403);
    }

    public function test_photographer_cannot_delete_used_coupon(): void
    {
        $photographer = User::factory()->create();
        $photographer->roles()->attach(
            Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value])
        );
        $token = auth('api')->login($photographer);

        $coupon = Coupon::factory()->create([
            'brand' => 'test-brand',
            'code' => 'USED_BY_PHOTO',
            'used_count' => 2,
            'created_by' => $photographer->id,
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->deleteJson('/api/management/coupons/' . $coupon->id);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertDatabaseHas('coupons', ['id' => $coupon->id]);
    }

    // ──────────────────────────────────────────────
    //  Delete — Admin (not super_admin)
    // ──────────────────────────────────────────────

    public function test_admin_can_delete_used_coupon(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(
            Role::firstOrCreate(['name' => UserRole::ADMIN->value])
        );
        $token = auth('api')->login($admin);

        $coupon = Coupon::factory()->create([
            'brand' => 'test-brand',
            'code' => 'ADMINUSED',
            'used_count' => 5,
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->deleteJson('/api/management/coupons/' . $coupon->id);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertDatabaseMissing('coupons', ['id' => $coupon->id]);
    }

    // ──────────────────────────────────────────────
    //  Organisation Scope Coupons
    // ──────────────────────────────────────────────

    public function test_create_coupon_with_organisation_scope(): void
    {
        $org = Org::factory()->create(['brand' => 'test-brand']);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/management/coupons', [
                'code' => 'ORGCREATE',
                'type' => 'percentage',
                'value' => 10,
                'scope_type' => 'organisation',
                'scope_id' => $org->id,
                'active' => true,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('coupons', [
            'code' => 'ORGCREATE',
            'scope_type' => 'organisation',
            'scope_id' => $org->id,
        ]);
    }

    public function test_create_coupon_organisation_scope_invalid_org(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/management/coupons', [
                'code' => 'ORGBAD',
                'type' => 'percentage',
                'value' => 10,
                'scope_type' => 'organisation',
                'scope_id' => '00000000-0000-0000-0000-000000000000',
                'active' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_photographer_cannot_create_organisation_scope_coupon(): void
    {
        $photographer = User::factory()->create();
        $photographer->roles()->attach(
            Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value])
        );
        $token = auth('api')->login($photographer);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/management/coupons', [
                'code' => 'PHOTOORG',
                'type' => 'percentage',
                'value' => 10,
                'scope_type' => 'organisation',
                'active' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    // ──────────────────────────────────────────────
    //  Gallery Coupons Endpoint
    // ──────────────────────────────────────────────

    public function test_gallery_coupons_endpoint(): void
    {
        $gallery = Gallery::factory()->create();

        Coupon::factory()->count(2)->create([
            'brand' => 'test-brand',
            'scope_type' => 'gallery',
            'scope_id' => $gallery->id,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/management/galleries/' . $gallery->id . '/coupons');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    public function test_store_gallery_coupon_endpoint(): void
    {
        $gallery = Gallery::factory()->create();

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/management/galleries/' . $gallery->id . '/coupons', [
                'code' => 'GALLERY50',
                'type' => 'percentage',
                'value' => 50,
                'active' => true,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('coupons', [
            'code' => 'GALLERY50',
            'scope_type' => 'gallery',
            'scope_id' => $gallery->id,
        ]);
    }

    // ──────────────────────────────────────────────
    //  GalleryGroup Coupons Endpoint
    // ──────────────────────────────────────────────

    public function test_group_coupons_endpoint(): void
    {
        $group = GalleryGroup::factory()->create();

        Coupon::factory()->count(2)->create([
            'brand' => 'test-brand',
            'scope_type' => 'meta_gallery',
            'scope_id' => $group->id,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/management/gallery-groups/' . $group->id . '/coupons');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }
}
