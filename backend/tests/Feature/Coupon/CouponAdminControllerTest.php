<?php

namespace Tests\Feature\Coupon;

use App\Enums\UserRole;
use App\Http\Middleware\BrandContextMiddleware;
use App\Models\Role;
use App\Models\User;
use App\Support\BrandRegistry;
use App\Values\BrandConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponAdminControllerTest extends TestCase
{
    use RefreshDatabase;

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
    }

    protected function tearDown(): void
    {
        BrandRegistry::set(null);
        parent::tearDown();
    }

    private function superAdminToken(): string
    {
        $superAdmin = User::factory()->create(['brand' => null]);
        $superAdmin->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));

        return auth('api')->login($superAdmin);
    }

    private function photoPackagePayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'PHOTOPKG',
            'type' => 'photo_package',
            'scope_type' => 'global',
            'active' => true,
        ], $overrides);
    }

    /**
     * `package_price_cents` is cents on the wire and cents in the column.
     *
     * The field was named cents and spoke euros: the controller multiplied the
     * incoming value by 100, so a client that sent the number in its own name
     * was silently reinterpreted. This test pins the wire value, the stored
     * column and the response to the *same* number, so a conversion reappearing
     * in either direction fails here rather than in a customer's invoice.
     */
    public function test_store_photo_package_persists_cents_unchanged(): void
    {
        $token = $this->superAdminToken();

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/coupons', $this->photoPackagePayload([
                'package_quantity' => 10,
                'package_price_cents' => 4000, // 40,00 €
            ]));

        $response->assertStatus(201);
        $response->assertJsonPath('coupon.type', 'photo_package');
        $response->assertJsonPath('coupon.package_quantity', 10);
        $response->assertJsonPath('coupon.package_price_cents', 4000);

        $this->assertDatabaseHas('coupons', [
            'code' => 'PHOTOPKG',
            'type' => 'photo_package',
            'package_quantity' => 10,
            'package_price_cents' => 4000,
        ]);
    }

    /**
     * The round trip in both directions: what the admin form reads back must be
     * what it sent. The form divides by 100 to show euros and multiplies by 100
     * to send cents; a backend that scaled on the way in would make the
     * re-opened form show 400,00 € for a 40,00 € package.
     */
    public function test_store_photo_package_round_trips_the_price_unchanged(): void
    {
        $token = $this->superAdminToken();
        $headers = ['Authorization' => "Bearer $token"];

        $create = $this->withHeaders($headers)
            ->postJson('/api/management/coupons', $this->photoPackagePayload([
                'package_quantity' => 10,
                'package_price_cents' => 4000,
            ]));
        $create->assertStatus(201);
        $couponId = $create->json('coupon.id');

        $read = $this->withHeaders($headers)->getJson('/api/management/coupons?page=1&per_page=100');
        $read->assertStatus(200);
        $listed = collect($read->json('data'))->firstWhere('id', $couponId);
        $this->assertNotNull($listed);
        $this->assertSame(4000, $listed['package_price_cents']);

        // Re-saving the same amount (an edit that only re-touches the code)
        // must not move the price either.
        $update = $this->withHeaders($headers)
            ->putJson("/api/management/coupons/{$couponId}", [
                'package_quantity' => 10,
                'package_price_cents' => 4000,
            ]);
        $update->assertStatus(200);
        $update->assertJsonPath('coupon.package_price_cents', 4000);
        $this->assertDatabaseHas('coupons', [
            'id' => $couponId,
            'package_price_cents' => 4000,
        ]);
    }

    /**
     * A fractional cent amount is not a price the system can represent, and
     * rounding it here would make the stored amount differ from the amount that
     * was sent — the exact defect this change removes. It is rejected instead.
     */
    public function test_store_photo_package_rejects_a_fractional_cent_price(): void
    {
        $token = $this->superAdminToken();

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/coupons', $this->photoPackagePayload([
                'package_quantity' => 10,
                'package_price_cents' => 4000.5,
            ]));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('coupons', ['code' => 'PHOTOPKG']);
    }

    /**
     * The `value` column is cents for `fixed`. A whole-euro discount is a
     * four-digit cent amount, and a `percentage` sibling is a percent in the
     * very same column — so the test pins both branches through the same
     * endpoint to prove the discriminator, not the column, decides the unit.
     */
    public function test_store_fixed_coupon_persists_cents_and_leaves_percentage_a_percent(): void
    {
        $token = $this->superAdminToken();
        $headers = ['Authorization' => "Bearer $token"];

        $fixed = $this->withHeaders($headers)->postJson('/api/management/coupons', [
            'code' => 'FIXEDCENTS',
            'type' => 'fixed',
            'value' => 1000, // 10,00 €
            'scope_type' => 'global',
            'active' => true,
        ]);
        $fixed->assertStatus(201);
        $fixed->assertJsonPath('coupon.value', 1000);
        $this->assertDatabaseHas('coupons', ['code' => 'FIXEDCENTS', 'value' => 1000]);

        $percentage = $this->withHeaders($headers)->postJson('/api/management/coupons', [
            'code' => 'PERCENTCENTS',
            'type' => 'percentage',
            'value' => 10, // 10 %
            'scope_type' => 'global',
            'active' => true,
        ]);
        $percentage->assertStatus(201);
        $percentage->assertJsonPath('coupon.value', 10);
        $this->assertDatabaseHas('coupons', ['code' => 'PERCENTCENTS', 'value' => 10]);
    }

    public function test_store_photo_package_missing_quantity_returns_422(): void
    {
        $token = $this->superAdminToken();

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/coupons', $this->photoPackagePayload([
                'package_price_cents' => 4000,
            ]));

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'Package quantity must be at least 1.');
    }

    public function test_store_photo_package_zero_quantity_returns_422(): void
    {
        $token = $this->superAdminToken();

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/coupons', $this->photoPackagePayload([
                'package_quantity' => 0,
                'package_price_cents' => 4000,
            ]));

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'Package quantity must be at least 1.');
    }

    public function test_store_fixed_requires_value(): void
    {
        $token = $this->superAdminToken();

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/coupons', $this->photoPackagePayload([
                'type' => 'fixed',
                'code' => 'FIXEDX',
            ]));

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'Value is required for fixed and percentage coupons.');
    }
}
